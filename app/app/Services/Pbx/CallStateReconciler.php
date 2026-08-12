<?php

namespace App\Services\Pbx;

use App\Models\CallRecord;
use Illuminate\Support\Collection;

class CallStateReconciler
{
    public function __construct(private readonly AmiClient $ami)
    {
    }

    /**
     * Corrige registros abertos quando um evento Hangup foi perdido. Para uma
     * chamada atendida, a fonte final de verdade Ã© o canal ativo do Asterisk.
     */
    public function reconcile(Collection $extensionIds): void
    {
        if ($extensionIds->isEmpty()) return;

        $now = now();
        $ringingCutoff = $now->copy()->subSeconds(config('pbx.call_state.ringing_timeout_seconds', 90));
        $staleAttempts = CallRecord::query()
            ->whereIn('extension_id', $extensionIds)
            ->whereNull('ended_at')
            ->whereIn('status', ['dialing', 'ringing'])
            ->where('started_at', '<=', $ringingCutoff)
            ->get();

        $staleAttempts->each(fn (CallRecord $call) => $this->finish($call, $now, 'failed', 'Tentativa expirada sem confirmaÃ§Ã£o do Asterisk.'));

        $answeredCutoff = $now->copy()->subSeconds(config('pbx.call_state.answered_check_after_seconds', 90));
        $answeredCalls = CallRecord::query()
            ->whereIn('extension_id', $extensionIds)
            ->whereNull('ended_at')
            ->where('status', 'answered')
            ->whereNotNull('answered_at')
            ->where('answered_at', '<=', $answeredCutoff)
            ->get();

        if ($answeredCalls->isEmpty()) return;

        // Sem AMI, preservamos a chamada atendida: nÃ£o se pode encerrar uma
        // conversa real apenas porque o painel ficou temporariamente sem acesso.
        $activeExtensionIds = $this->ami->activeExtensionIds();
        if ($activeExtensionIds === null) return;

        $active = array_flip($activeExtensionIds);
        $answeredCalls->filter(fn (CallRecord $call) => ! isset($active[$call->extension_id]))
            ->each(fn (CallRecord $call) => $this->finish($call, $now, 'completed', 'Canal nÃ£o encontrado no Asterisk; encerrada por reconciliaÃ§Ã£o.'));
    }

    private function finish(CallRecord $call, $endedAt, string $status, string $cause): void
    {
        $call->update([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, $call->started_at?->diffInSeconds($endedAt) ?? 0),
            'status' => $status,
            'hangup_cause' => $cause,
        ]);
    }
}
