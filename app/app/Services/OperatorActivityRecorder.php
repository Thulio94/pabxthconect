<?php

namespace App\Services;

use App\Models\Extension;
use App\Models\OperatorActivityLog;
use App\Models\OperatorPauseSession;
use App\Models\OperatorSession;
use App\Models\PauseReason;
use App\Models\User;
use Illuminate\Http\Request;

class OperatorActivityRecorder
{
    public function login(Request $request, User $user, Extension $extension): OperatorSession
    {
        $now = now();
        $this->closeStaleOpenRecords($extension, $now);

        $session = OperatorSession::create([
            'tenant_id' => $extension->tenant_id,
            'user_id' => $user->id,
            'extension_id' => $extension->id,
            'session_key' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'logged_in_at' => $now,
            'last_seen_at' => $now,
        ]);
        $this->log($extension, $user, 'login', 'Entrou na plataforma.');

        return $session;
    }

    public function heartbeat(Extension $extension, User $user, ?int $operatorSessionId): void
    {
        if ($operatorSessionId) {
            OperatorSession::query()->whereKey($operatorSessionId)->where('extension_id', $extension->id)
                ->whereNull('logged_out_at')->update(['last_seen_at' => now(), 'updated_at' => now()]);
            return;
        }

        $session = OperatorSession::query()->where('extension_id', $extension->id)->whereNull('logged_out_at')->latest('id')->first();
        if ($session) {
            $session->update(['last_seen_at' => now()]);
            return;
        }

        OperatorSession::create([
            'tenant_id' => $extension->tenant_id,
            'user_id' => $user->id,
            'extension_id' => $extension->id,
            'logged_in_at' => now(),
            'last_seen_at' => now(),
        ]);
        $this->log($extension, $user, 'session_detected', 'Sessão ativa identificada pela presença do operador.');
    }

    public function logout(Extension $extension, User $user, ?int $operatorSessionId): void
    {
        $now = now();
        $query = OperatorSession::query()->where('extension_id', $extension->id)->whereNull('logged_out_at');
        if ($operatorSessionId) $query->whereKey($operatorSessionId);
        $query->update(['last_seen_at' => $now, 'logged_out_at' => $now, 'updated_at' => $now]);
        $this->closePause($extension, $user, $now);
        $this->log($extension, $user, 'logout', 'Saiu da plataforma.', occurredAt: $now);
    }

    public function startPause(Extension $extension, User $user, PauseReason $pause): void
    {
        $now = now();
        $this->closePause($extension, $user, $now, false);
        OperatorPauseSession::create([
            'tenant_id' => $extension->tenant_id,
            'user_id' => $user->id,
            'extension_id' => $extension->id,
            'pause_reason_id' => $pause->id,
            'pause_name' => $pause->name,
            'started_at' => $now,
        ]);
        $this->log($extension, $user, 'pause_started', "Iniciou a pausa {$pause->name}.", ['pause_reason_id' => $pause->id], $now);
    }

    public function closePause(Extension $extension, User $user, $at = null, bool $writeLog = true): void
    {
        $at ??= now();
        $pause = OperatorPauseSession::query()->where('extension_id', $extension->id)->whereNull('ended_at')->latest('id')->first();
        if (! $pause) return;
        $pause->update(['ended_at' => $at]);
        if ($writeLog) $this->log($extension, $user, 'pause_ended', "Encerrou a pausa {$pause->pause_name}.", ['pause_session_id' => $pause->id], $at);
    }

    public function log(Extension $extension, User $user, string $action, string $description, array $metadata = [], $occurredAt = null): void
    {
        OperatorActivityLog::create([
            'tenant_id' => $extension->tenant_id,
            'user_id' => $user->id,
            'extension_id' => $extension->id,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function closeStaleOpenRecords(Extension $extension, $at): void
    {
        OperatorSession::query()->where('extension_id', $extension->id)->whereNull('logged_out_at')
            ->get()->each(function (OperatorSession $session) use ($at) {
                $closedAt = $session->last_seen_at && $session->last_seen_at->lt($at) ? $session->last_seen_at : $at;
                $session->update(['logged_out_at' => $closedAt]);
            });
        OperatorPauseSession::query()->where('extension_id', $extension->id)->whereNull('ended_at')
            ->update(['ended_at' => $at, 'updated_at' => $at]);
    }
}
