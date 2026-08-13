<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Extension;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentDashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $agent = $request->session()->get('sip_agent');
        $extension = Extension::query()->with('tenant')->find($agent['extension_id'] ?? null);
        $tenant = $extension?->tenant;
        if (! $extension || $extension->tenant_id !== $agent['tenant_id'] || $extension->user_id !== ($agent['user_id'] ?? null) || $extension->status !== 'active' || ! $tenant || $tenant->status !== 'active') {
            $request->session()->forget('sip_agent');

            return redirect()->route('phone.login')->withErrors(['email' => 'O ramal não está mais disponível. Informe as credenciais novamente.']);
        }

        // Always use the current encrypted credential from the extension record.
        // This keeps the browser and Asterisk aligned immediately after a password rotation.
        $credentials = ['sip_user' => $extension->sip_username, 'sip_pass' => $extension->sip_secret, 'sip_host' => config('pbx.sip_domain'), 'sip_ws_uri' => config('pbx.websocket_url')];
        [$filters, $historyInfiniteEnabled] = $this->historyFilters($request);
        $historyPage = $this->historyQuery($agent, $filters, $historyInfiniteEnabled)->cursorPaginate(25);
        $history = collect($historyPage->items());
        $nextHistoryCursor = $historyInfiniteEnabled ? $historyPage->nextCursor()?->encode() : null;

        $pauseReasons = $tenant->pauseReasons()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color', 'max_minutes']);

        return view('agent.dashboard', compact('agent', 'tenant', 'credentials', 'history', 'filters', 'historyInfiniteEnabled', 'nextHistoryCursor', 'pauseReasons'));
    }

    public function history(Request $request): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        [$filters, $historyInfiniteEnabled] = $this->historyFilters($request);
        if (! $historyInfiniteEnabled) {
            return response()->json(['calls' => [], 'next_cursor' => null]);
        }
        $historyPage = $this->historyQuery($agent, $filters, true)->cursorPaginate(25);

        return response()->json(['calls' => collect($historyPage->items())->map(fn (CallRecord $call) => $this->callPayload($call))->values(), 'next_cursor' => $historyPage->nextCursor()?->encode()]);
    }

    private function historyFilters(Request $request): array
    {
        $allowedStatuses = ['completed', 'failed', 'rejected', 'cancelled', 'answered', 'ringing', 'dialing'];
        $normalizeDate = static function (mixed $value): ?string {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };
        $filters = ['from' => $normalizeDate($request->input('from')), 'to' => $normalizeDate($request->input('to')), 'phone' => preg_replace('/\D+/', '', (string) $request->input('phone')), 'result' => in_array($request->input('result'), $allowedStatuses, true) ? $request->input('result') : null];
        if ($filters['from'] && $filters['to'] && Carbon::parse($filters['from'])->gt(Carbon::parse($filters['to']))) {
            [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
        }

        return [$filters, collect($filters)->contains(fn ($value) => $value !== null && $value !== '')];
    }

    private function historyQuery(array $agent, array $filters, bool $hasActiveFilters)
    {
        $timezone = config('app.display_timezone', 'America/Sao_Paulo');
        $defaultStart = now($timezone)->startOfDay()->utc();
        $defaultEnd = now($timezone)->endOfDay()->utc();
        $filterStart = $filters['from'] ? Carbon::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay()->utc() : null;
        $filterEnd = $filters['to'] ? Carbon::createFromFormat('Y-m-d', $filters['to'], $timezone)->endOfDay()->utc() : null;

        return CallRecord::query()->with('recording')->where('tenant_id', $agent['tenant_id'])->where('extension_id', $agent['extension_id'])
            ->when(! $hasActiveFilters, fn ($query) => $query->whereBetween('started_at', [$defaultStart, $defaultEnd]))
            ->when($filterStart, fn ($query, $from) => $query->where('started_at', '>=', $from))
            ->when($filterEnd, fn ($query, $to) => $query->where('started_at', '<=', $to))
            ->when($filters['phone'], fn ($query, $phone) => $query->where('to_number', 'like', "%{$phone}%"))
            ->when($filters['result'], fn ($query, $result) => $query->where('status', $result))->orderByDesc('started_at')->orderByDesc('id');
    }

    private function callPayload(CallRecord $call): array
    {
        return ['id' => $call->id, 'remote_number' => $call->to_number, 'direction' => $call->direction, 'status' => $call->status, 'started_at' => $call->started_at?->toIso8601String(), 'answered_at' => $call->answered_at?->toIso8601String(), 'ended_at' => $call->ended_at?->toIso8601String(), 'duration_seconds' => $call->duration_seconds, 'has_recording' => $call->recording?->available_at !== null, 'recording_url' => $call->recording?->available_at ? route('phone.call-records.recording', $call) : null];
    }
}
