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
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\CallStateReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSupervisionController extends Controller
{
    public function index(Request $request): View
    {
        $tenantQuery = Tenant::query()->when($request->user()->isTenantAdmin(), fn ($query) => $query->whereKey($request->user()->tenant_id));
        $tenantId = $request->user()->isTenantAdmin()
            ? $request->user()->tenant_id
            : ($request->integer('tenant_id') ?: (clone $tenantQuery)->where('status', 'active')->value('id'));

        return view('admin.supervision', [
            'tenants' => $tenantQuery->orderBy('name')->get(['id', 'name', 'status']),
            'selectedTenantId' => $tenantId,
            'credentials' => $this->supervisorCredentials($request),
        ]);
    }

    public function agents(Request $request, CallStateReconciler $callState): JsonResponse
    {
        $tenantId = (int) $request->validate(['tenant_id' => ['required', Rule::exists('tenants', 'id')]])['tenant_id'];
        $this->authorizeTenant($request, $tenantId);
        [$dayStart, $dayEnd] = $this->operationDayBounds();
        $staleBefore = now()->subSeconds(45);
        $presenceAvailable = Schema::hasColumns('extension_presences', ['extension_id', 'state', 'state_since', 'heartbeat_at']);
        $relations = ['user:id,name,email'];
        if ($presenceAvailable) {
            $relations[] = Schema::hasTable('pause_reasons') ? 'presence.pauseReason' : 'presence';
        }

        $extensions = Extension::query()->with($relations)
            ->where('tenant_id', $tenantId)->where('status', 'active')->orderBy('number')->get();
        $extensionIds = $extensions->pluck('id');

        try {
            $callState->reconcile($extensionIds);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $calls = $this->supervisionMetric(function () use ($extensionIds, $dayStart) {
            return CallRecord::query()->whereIn('extension_id', $extensionIds)
                ->where('started_at', '>=', $dayStart)->where('started_at', '<', $dayStart->copy()->addDay())
                ->get()->groupBy('extension_id');
        });
        $sessions = Schema::hasColumns('operator_sessions', ['extension_id', 'logged_in_at', 'last_seen_at', 'logged_out_at'])
            ? $this->supervisionMetric(function () use ($extensionIds, $dayStart, $dayEnd) {
                return OperatorSession::query()->whereIn('extension_id', $extensionIds)->where('logged_in_at', '<=', $dayEnd)
                    ->where(fn ($query) => $query->whereNull('logged_out_at')->orWhere('logged_out_at', '>=', $dayStart))
                    ->get()->groupBy('extension_id');
            }) : collect();
        $pauses = Schema::hasColumns('operator_pause_sessions', ['extension_id', 'started_at', 'ended_at'])
            ? $this->supervisionMetric(function () use ($extensionIds, $dayStart, $dayEnd) {
                return OperatorPauseSession::query()->whereIn('extension_id', $extensionIds)->where('started_at', '<=', $dayEnd)
                    ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart))
                    ->get()->groupBy('extension_id');
            }) : collect();

        $supervisorUserId = (int) $request->user()->id;
        $agents = $extensions->map(function (Extension $extension) use ($presenceAvailable, $staleBefore, $dayStart, $dayEnd, $calls, $sessions, $pauses, $supervisorUserId) {
            $agentCalls = $calls->get($extension->id, collect());
            $call = $agentCalls->whereNull('ended_at')->filter(function (CallRecord $item) {
                return $item->status === 'answered'
                    || (in_array($item->status, ['dialing', 'ringing'], true) && $item->started_at?->gte(now()->subSeconds(40)));
            })->sortByDesc('id')->first();
            $presence = $presenceAvailable ? $extension->presence : null;
            $online = $presence?->heartbeat_at?->gte($staleBefore) ?? false;
            $state = $call ? ($call->status === 'answered' ? 'talking' : 'calling') : ($online ? ($presence->state === 'paused' ? 'paused' : 'available') : 'offline');
            $since = $state === 'offline' ? null : ($call?->answered_at ?? $call?->started_at ?? $presence?->state_since ?? $presence?->heartbeat_at);

            return [
                'id' => $extension->id,
                'number' => (string) $extension->number,
                'name' => $extension->user?->name ?? 'Sem usuário',
                'email' => $extension->user?->email,
                'state' => $state,
                'status_label' => match ($state) {
                    'talking' => 'Falando', 'calling' => 'Chamando', 'paused' => $presence?->pauseReason?->name ?? 'Em pausa',
                    'available' => 'Disponível', default => 'Offline',
                },
                'status_color' => $state === 'paused' ? ($presence?->pauseReason?->color ?? '#f4b000') : null,
                'since' => $since?->toIso8601String(),
                'call' => $call ? ['id' => $call->id, 'number' => $call->to_number, 'status' => $call->status, 'started_at' => $call->started_at?->toIso8601String()] : null,
                'calls_today' => $agentCalls->count(),
                'answered_today' => $agentCalls->whereNotNull('answered_at')->count(),
                'average_seconds' => (int) round($agentCalls->where('duration_seconds', '>', 0)->avg('duration_seconds') ?? 0),
                'talk_seconds' => (int) $agentCalls->sum(fn (CallRecord $item) => $item->answered_at ? max(0, (int) floor($item->answered_at->diffInSeconds($item->ended_at ?? now()))) : 0),
                'logged_seconds' => (int) $sessions->get($extension->id, collect())->sum(fn (OperatorSession $item) => $this->sessionSeconds($item, $dayStart, $dayEnd, $online)),
                'pause_seconds' => (int) $pauses->get($extension->id, collect())->sum(fn (OperatorPauseSession $item) => $this->intervalSeconds($item->started_at, $item->ended_at ?? ($online ? now() : ($presence?->heartbeat_at ?? now())), $dayStart, $dayEnd)),
                'can_force_logout' => $online && (int) $extension->user_id !== $supervisorUserId,
            ];
        });

        return response()->json(['agents' => $agents, 'generated_at' => now()->toIso8601String(), 'degraded' => false]);
    }

    private function supervisionMetric(callable $query)
    {
        try {
            return $query();
        } catch (\Throwable $exception) {
            report($exception);

            return collect();
        }
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
        $data = $request->validate([
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

    public function forceLogout(Request $request, Extension $extension, OperatorActivityRecorder $activity): JsonResponse
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

    public function storePause(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'name' => ['required', 'string', 'max:80', Rule::unique('pause_reasons')->where(fn ($query) => $query->where('tenant_id', $request->input('tenant_id')))],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_minutes' => ['nullable', 'integer', 'between:1,480'],
        ]);
        PauseReason::create([...$data, 'is_active' => true]);

        return back()->with('status', 'Pausa cadastrada para a empresa.');
    }

    public function updatePause(Request $request, PauseReason $pauseReason): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('pause_reasons')->where(fn ($query) => $query->where('tenant_id', $pauseReason->tenant_id))->ignore($pauseReason)],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'max_minutes' => ['nullable', 'integer', 'between:1,480'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $pauseReason->update([...$data, 'is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Pausa atualizada.');
    }

    public function destroyPause(PauseReason $pauseReason): RedirectResponse
    {
        $pauseReason->delete();

        return back()->with('status', 'Pausa excluída.');
    }

    private function supervisorCredentials(Request $request): array
    {
        $extension = $request->user()->pbxExtension()->firstOrFail();

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
