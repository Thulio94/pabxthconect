<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\OperatorPauseSession;
use App\Models\OperatorSession;
use App\Models\PhoneLicenseLease;
use App\Services\Pbx\CallStateReconciler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AgentSupervisionSnapshot
{
    public function __construct(
        private readonly CallStateReconciler $callState,
        private readonly PhoneLicenseLeaseReaper $licenseReaper,
        private readonly PhoneLicenseManager $licenses,
    ) {}

    public function forTenant(int $tenantId, int $viewerId): array
    {
        $this->licenseReaper->reap($tenantId);
        $onlineExtensionIds = PhoneLicenseLease::query()->where('tenant_id', $tenantId)
            ->where('last_seen_at', '>=', $this->licenses->staleBefore())->pluck('extension_id')->flip();
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
            $this->callState->reconcile($extensionIds);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $calls = $this->supervisionMetric(fn () => CallRecord::query()->whereIn('extension_id', $extensionIds)
            ->where('started_at', '>=', $dayStart)->where('started_at', '<', $dayStart->copy()->addDay())
            ->get()->groupBy('extension_id'));
        $sessions = Schema::hasColumns('operator_sessions', ['extension_id', 'logged_in_at', 'last_seen_at', 'logged_out_at'])
            ? $this->supervisionMetric(fn () => OperatorSession::query()->whereIn('extension_id', $extensionIds)->where('logged_in_at', '<=', $dayEnd)
                ->where(fn ($query) => $query->whereNull('logged_out_at')->orWhere('logged_out_at', '>=', $dayStart))
                ->get()->groupBy('extension_id')) : collect();
        $pauses = Schema::hasColumns('operator_pause_sessions', ['extension_id', 'started_at', 'ended_at'])
            ? $this->supervisionMetric(fn () => OperatorPauseSession::query()->whereIn('extension_id', $extensionIds)->where('started_at', '<=', $dayEnd)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart))
                ->get()->groupBy('extension_id')) : collect();

        $agents = $extensions->map(function (Extension $extension) use ($presenceAvailable, $staleBefore, $dayStart, $dayEnd, $calls, $sessions, $pauses, $viewerId, $onlineExtensionIds) {
            $agentCalls = $calls->get($extension->id, collect());
            $presence = $presenceAvailable ? $extension->presence : null;
            $online = $onlineExtensionIds->has($extension->id) && ($presence?->heartbeat_at?->gte($staleBefore) ?? false);
            $call = $online ? $agentCalls->whereNull('ended_at')->filter(function (CallRecord $item) {
                return $item->status === 'answered'
                    || (in_array($item->status, ['dialing', 'ringing'], true) && $item->started_at?->gte(now()->subSeconds(40)));
            })->sortByDesc('id')->first() : null;
            $state = $call ? ($call->status === 'answered' ? 'talking' : 'calling') : ($online ? ($presence->state === 'paused' ? 'paused' : 'available') : 'offline');
            $since = $state === 'offline' ? null : ($call?->answered_at ?? $call?->started_at ?? $presence?->state_since ?? $presence?->heartbeat_at);
            if ($since?->lt($dayStart)) {
                $since = $dayStart->copy();
            }

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
                'can_force_logout' => $online && (int) $extension->user_id !== $viewerId,
            ];
        })->values();

        return ['agents' => $agents, 'generated_at' => now()->toIso8601String(), 'degraded' => false];
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

    private function sessionSeconds(OperatorSession $session, Carbon $dayStart, Carbon $dayEnd, bool $online): int
    {
        $end = $session->logged_out_at ?? ($online ? now() : ($session->last_seen_at ?? $session->logged_in_at));

        return $this->intervalSeconds($session->logged_in_at, $end, $dayStart, $dayEnd);
    }

    private function intervalSeconds(Carbon $start, Carbon $end, Carbon $dayStart, Carbon $dayEnd): int
    {
        $from = $start->greaterThan($dayStart) ? $start : $dayStart;
        $to = $end->lessThan($dayEnd) ? $end : $dayEnd;

        return $to->greaterThan($from) ? (int) floor($from->diffInSeconds($to)) : 0;
    }

    private function operationDayBounds(): array
    {
        $timezone = config('app.display_timezone', 'America/Sao_Paulo');
        $localNow = now($timezone);
        $localStart = $localNow->copy()->startOfDay();
        $localEnd = $localNow->copy();

        return [$localStart->utc(), $localEnd->utc()];
    }
}
