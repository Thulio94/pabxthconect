<?php

namespace App\Services\Pbx;

use App\Models\CallRecord;
use Illuminate\Support\Collection;

class CallStateReconciler
{
    public function __construct(private readonly AmiClient $ami) {}

    /** Corrige registros abertos quando eventos do Asterisk foram perdidos. */
    public function reconcile(Collection $extensionIds): void
    {
        if ($extensionIds->isEmpty()) {
            return;
        }

        $now = now();
        $ringingTimeout = min(40, max(1, (int) config('pbx.call_state.ringing_timeout_seconds', 40)));
        $ringingCutoff = $now->copy()->subSeconds($ringingTimeout);
        CallRecord::query()
            ->whereIn('extension_id', $extensionIds)
            ->whereNull('ended_at')
            ->whereIn('status', ['dialing', 'ringing'])
            ->where('started_at', '<=', $ringingCutoff)
            ->get()
            ->each(fn (CallRecord $call) => $this->finish($call, $now, 'no_answer', 'Tempo de chamada esgotado sem atendimento.'));

        $answeredCutoff = $now->copy()->subSeconds(config('pbx.call_state.answered_check_after_seconds', 90));
        $answeredCalls = CallRecord::query()
            ->whereIn('extension_id', $extensionIds)
            ->whereNull('ended_at')
            ->where('status', 'answered')
            ->whereNotNull('answered_at')
            ->where('answered_at', '<=', $answeredCutoff)
            ->get();

        if ($answeredCalls->isEmpty()) {
            return;
        }
        $activeExtensionIds = $this->ami->activeExtensionIds();
        if ($activeExtensionIds === null) {
            return;
        }

        $active = array_flip($activeExtensionIds);
        $answeredCalls->filter(fn (CallRecord $call) => ! isset($active[$call->extension_id]))
            ->each(fn (CallRecord $call) => $this->finish($call, $now, 'completed', 'Canal encerrado e reconciliado com o Asterisk.'));
    }

    private function finish(CallRecord $call, $endedAt, string $status, string $cause): void
    {
        $call->update([
            'ended_at' => $endedAt,
            'duration_seconds' => $call->answered_at
                ? max(0, (int) floor($call->answered_at->diffInSeconds($endedAt)))
                : 0,
            'status' => $status,
            'hangup_cause' => $cause,
        ]);
    }
}
