<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\OperatorActivityLog;
use App\Models\OperatorPauseSession;
use App\Models\OperatorSession;
use App\Models\PauseReason;
use App\Models\SupervisionSession;
use App\Models\Tenant;
use App\Services\AgentSupervisionSnapshot;
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\CallStateReconciler;
use App\Services\Pbx\TurnCredentialFactory;
use App\Services\PhoneLicenseManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminSupervisionController extends Controller
{
    public function index(Request $request, TurnCredentialFactory $turnCredentials): View
    {
        $tenantQuery = Tenant::query()->when($request->user()->isTenantAdmin(), fn ($query) => $query->whereKey($request->user()->tenant_id));
        $tenantId = $request->user()->isTenantAdmin()
            ? $request->user()->tenant_id
            : ($request->integer('tenant_id') ?: (clone $tenantQuery)->where('status', 'active')->value('id'));

        $supervisorExtension = $request->user()->pbxExtension()->firstOrFail();

        return view('admin.supervision', [
            'tenants' => $tenantQuery->orderBy('name')->get(['id', 'name', 'status']),
            'selectedTenantId' => $tenantId,
            'credentials' => $this->supervisorCredentials($supervisorExtension),
            'iceServers' => $turnCredentials->forExtension($supervisorExtension),
        ]);
    }

    public function agents(Request $request, AgentSupervisionSnapshot $snapshot): JsonResponse
    {
        $tenantId = (int) $request->validate(['tenant_id' => ['required', Rule::exists('tenants', 'id')]])['tenant_id'];
        $this->authorizeTenant($request, $tenantId);

        return response()->json($snapshot->forTenant($tenantId, (int) $request->user()->id));
    }

    public function daily(Request $request, Extension $extension): JsonResponse
    {
        $this->authorizeTenant($request, $extension->tenant_id);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        [$dayStart, $dayEnd] = $this->operationDayBounds($data['date'] ?? null);
        $online = $extension->presence?->heartbeat_at?->gte(now()->subSeconds(45)) ?? false;

        $sessions = OperatorSession::query()->where('extension_id', $extension->id)->where('logged_in_at', '<=', $dayEnd)
            ->where(fn ($query) => $query->whereNull('logged_out_at')->orWhere('logged_out_at', '>=', $dayStart))->get();
        $pauses = OperatorPauseSession::query()->where('extension_id', $extension->id)->where('started_at', '<=', $dayEnd)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart))->get();
        $calls = CallRecord::query()->where('extension_id', $extension->id)->whereBetween('started_at', [$dayStart, $dayEnd])->get();
        $logs = OperatorActivityLog::query()->where('extension_id', $extension->id)->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->where('action', 'not like', 'call_%')->latest('occurred_at')->limit(200)->get();

        $pauseBreakdown = $pauses->groupBy('pause_name')->map(fn ($items, $name) => [
            'name' => $name,
            'count' => $items->count(),
            'seconds' => (int) $items->sum(fn (OperatorPauseSession $item) => $this->intervalSeconds($item->started_at, $item->ended_at ?? ($online ? now() : ($extension->presence?->heartbeat_at ?? $dayEnd)), $dayStart, $dayEnd)),
        ])->sortByDesc('seconds')->values();

        $timeline = $logs->map(fn (OperatorActivityLog $log) => ['action' => $log->action, 'description' => $log->description, 'occurred_at' => $log->occurred_at?->toIso8601String(), 'metadata' => $log->metadata])
            ->concat($calls->map(fn (CallRecord $call) => [
                'action' => 'pbx_call_'.$call->status,
                'description' => "Ligação para {$call->to_number}: {$call->resultLabel()}.",
                'occurred_at' => $call->started_at?->toIso8601String(),
                'metadata' => ['call_record_id' => $call->id, 'duration_seconds' => $call->effectiveDurationSeconds()],
            ]))->sortByDesc('occurred_at')->take(200)->values();

        return response()->json([
            'operator' => ['id' => $extension->id, 'number' => (string) $extension->number, 'name' => $extension->user?->name, 'email' => $extension->user?->email],
            'date' => $dayStart->toDateString(),
            'summary' => [
                'logged_seconds' => (int) $sessions->sum(fn (OperatorSession $item) => $this->sessionSeconds($item, $dayStart, $dayEnd, $online)),
                'calls' => $calls->count(),
                'answered' => $calls->whereNotNull('answered_at')->count(),
                'talk_seconds' => (int) $calls->sum(fn (CallRecord $item) => $item->answered_at ? $item->answered_at->diffInSeconds($item->ended_at ?? $dayEnd) : 0),
                'pause_seconds' => (int) $pauseBreakdown->sum('seconds'),
                'sessions' => $sessions->count(),
            ],
            'pause_breakdown' => $pauseBreakdown,
            'timeline' => $timeline,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function supervise(Request $request, Extension $extension, CallStateReconciler $callState): JsonResponse
    {
        $this->authorizeTenant($request, $extension->tenant_id);
        $data = $this->validateAdminInput($request, [
            'mode' => ['required', Rule::in(['listen', 'whisper', 'barge'])],
            'supervision_session_id' => ['nullable', 'integer', Rule::exists('supervision_sessions', 'id')],
        ]);
        abort_unless($extension->status === 'active', 422, 'O ramal não está ativo.');
        try {
            $callState->reconcile(collect([$extension->id]));
        } catch (\Throwable $exception) {
            // A indisponibilidade momentanea do AMI nao pode impedir uma
            // supervisao cuja chamada ativa ja esta registrada no banco.
            report($exception);
        }
        $call = CallRecord::query()->where('extension_id', $extension->id)->whereNull('ended_at')
            ->where(function ($query) {
                $query->where('status', 'answered')->orWhere(function ($attempt) {
                    $attempt->whereIn('status', ['ringing', 'dialing'])->where('started_at', '>=', now()->subSeconds(40));
                });
            })->latest('id')->first();
        abort_unless($call, 422, 'Este agente não possui uma chamada ativa.');

        $session = isset($data['supervision_session_id'])
            ? SupervisionSession::query()->whereKey($data['supervision_session_id'])
                ->where('supervisor_user_id', $request->user()->id)
                ->where('target_extension_id', $extension->id)
                ->whereNull('ended_at')->firstOrFail()
            : null;

        if ($session) {
            $session->update(['call_record_id' => $call->id, 'mode' => $data['mode'], 'status' => 'active']);
        } else {
            $session = SupervisionSession::create([
                'supervisor_user_id' => $request->user()->id,
                'target_extension_id' => $extension->id,
                'call_record_id' => $call->id,
                'mode' => $data['mode'],
                'status' => 'active',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'started_at' => now(),
            ]);
        }

        return response()->json([
            'session_id' => $session->id,
            'call_id' => $call->id,
            'mode' => $data['mode'],
            'dial_number' => '*8'.match ($data['mode']) {
                'listen' => '1', 'whisper' => '2', 'barge' => '3'
            }.$extension->id,
            'message' => match ($data['mode']) {
                'listen' => 'Escuta iniciada. A ação foi registrada na auditoria.',
                'whisper' => 'Sussurro iniciado. Somente o agente ouvirá o supervisor.',
                'barge' => 'Conferência iniciada. Todos os participantes ouvirão o supervisor.',
            },
        ]);
    }

    public function forceLogout(Request $request, Extension $extension, OperatorActivityRecorder $activity, PhoneLicenseManager $licenses): JsonResponse
    {
        $this->authorizeTenant($request, $extension->tenant_id);
        abort_unless($extension->status === 'active', 422, 'O ramal não está ativo.');
        abort_if((int) $extension->user_id === (int) $request->user()->id, 422, 'Use a opção Sair para encerrar a sua própria sessão.');

        $user = $extension->user()->firstOrFail();
        $sessions = OperatorSession::query()
            ->where('extension_id', $extension->id)
            ->whereNull('logged_out_at')
            ->get();

        foreach ($sessions->pluck('session_key')->filter()->unique() as $sessionKey) {
            $request->session()->getHandler()->destroy((string) $sessionKey);
        }

        $licenses->releaseForExtension($extension);
        $activity->forceLogout($extension, $user, $request->user());

        return response()->json([
            'message' => "A sessão de {$user->name} foi encerrada. O telefone será desconectado automaticamente.",
        ]);
    }

    public function finish(Request $request, SupervisionSession $supervisionSession): JsonResponse
    {
        abort_unless($supervisionSession->supervisor_user_id === $request->user()->id, 403);
        $supervisionSession->update(['status' => 'ended', 'ended_at' => now()]);

        return response()->json(['message' => 'Supervisão encerrada.']);
    }

    public function storePause(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validateAdminInput($request, [
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'name' => ['required', 'string', 'max:80', Rule::unique('pause_reasons')->where(fn ($query) => $query->where('tenant_id', $request->input('tenant_id')))],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_minutes' => ['nullable', 'integer', 'between:1,480'],
        ]);
        PauseReason::create([...$data, 'is_active' => true]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Pausa cadastrada para a empresa.', 'pause_html' => $this->pauseListHtml((int) $data['tenant_id'])], 201);
        }

        return back()->with('status', 'Pausa cadastrada para a empresa.');
    }

    public function updatePause(Request $request, PauseReason $pauseReason): RedirectResponse|JsonResponse
    {
        $data = $this->validateAdminInput($request, [
            'name' => ['required', 'string', 'max:80', Rule::unique('pause_reasons')->where(fn ($query) => $query->where('tenant_id', $pauseReason->tenant_id))->ignore($pauseReason)],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_minutes' => ['nullable', 'integer', 'between:1,480'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $pauseReason->update([...$data, 'is_active' => $request->boolean('is_active')]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Pausa atualizada.', 'pause_html' => $this->pauseListHtml($pauseReason->tenant_id)]);
        }

        return back()->with('status', 'Pausa atualizada.');
    }

    public function destroyPause(Request $request, PauseReason $pauseReason): RedirectResponse|JsonResponse
    {
        $tenantId = $pauseReason->tenant_id;
        $pauseReason->delete();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Pausa excluída.', 'pause_html' => $this->pauseListHtml($tenantId)]);
        }

        return back()->with('status', 'Pausa excluída.');
    }

    private function pauseListHtml(int $tenantId): string
    {
        $pauses = PauseReason::query()->where('tenant_id', $tenantId)->orderBy('name')->get();

        return view('admin.partials.pause-list', compact('pauses'))->render();
    }

    private function validateAdminInput(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                throw $exception;
            }

            throw new HttpResponseException(response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422));
        }
    }

    private function supervisorCredentials(Extension $extension): array
    {
        return ['sip_user' => $extension->sip_username, 'sip_pass' => $extension->sip_secret, 'sip_host' => config('pbx.sip_domain'), 'sip_ws_uri' => config('pbx.websocket_url')];
    }

    private function authorizeTenant(Request $request, int $tenantId): void
    {
        if ($request->user()->isTenantAdmin()) {
            abort_unless((int) $request->user()->tenant_id === $tenantId, 403);
        }
    }

    private function sessionSeconds(OperatorSession $session, $dayStart, $dayEnd, bool $online): int
    {
        $end = $session->logged_out_at ?? ($online ? now() : ($session->last_seen_at ?? $session->logged_in_at));

        return $this->intervalSeconds($session->logged_in_at, $end, $dayStart, $dayEnd);
    }

    private function intervalSeconds($start, $end, $dayStart, $dayEnd): int
    {
        $from = $start->greaterThan($dayStart) ? $start : $dayStart;
        $to = $end->lessThan($dayEnd) ? $end : $dayEnd;

        return $to->greaterThan($from) ? (int) floor($from->diffInSeconds($to)) : 0;
    }

    private function operationDayBounds(?string $date = null): array
    {
        $timezone = config('app.display_timezone', 'America/Sao_Paulo');
        $localNow = now($timezone);
        $localStart = $date
            ? Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()
            : $localNow->copy()->startOfDay();
        $localEnd = $localStart->isSameDay($localNow) ? $localNow : $localStart->copy()->endOfDay();

        return [$localStart->utc(), $localEnd->utc()];
    }
}
