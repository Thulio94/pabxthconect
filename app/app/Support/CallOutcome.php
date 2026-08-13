<?php

namespace App\Support;

final class CallOutcome
{
    public static function label(?string $status): string
    {
        return match ($status) {
            'completed' => 'Atendida',
            'answered' => 'Em atendimento',
            'no_answer' => 'Não atendida',
            'busy' => 'Ocupado',
            'voicemail' => 'Caixa de mensagens',
            'invalid_number' => 'Número não existe',
            'rejected' => 'Recusada',
            'cancelled' => 'Cancelada',
            'unavailable' => 'Indisponível',
            'ringing' => 'Tocando',
            'dialing' => 'Chamando',
            default => 'Não completada',
        };
    }

    public static function fromSip(?int $code, ?string $reason = null, string $fallback = 'failed'): string
    {
        $detail = mb_strtolower((string) $reason);
        if (self::mentionsVoicemail($detail)) {
            return 'voicemail';
        }

        return match ($code) {
            404, 410, 484 => 'invalid_number',
            408 => 'no_answer',
            480, 500, 502, 503, 504 => 'unavailable',
            486, 600 => 'busy',
            487 => 'cancelled',
            603 => 'rejected',
            default => self::fromCause($reason, $fallback),
        };
    }

    public static function fromCause(string|int|null $cause, string $fallback = 'failed'): string
    {
        $detail = mb_strtolower(trim((string) $cause));
        if (self::mentionsVoicemail($detail)) {
            return 'voicemail';
        }
        if (preg_match('/(^|\D)(1|2|3|22|28)(\D|$)/', $detail)
            || self::contains($detail, ['unallocated', 'no route', 'not found', 'invalid number', 'não existe'])) {
            return 'invalid_number';
        }
        if (preg_match('/(^|\D)17(\D|$)/', $detail) || str_contains($detail, 'busy')) {
            return 'busy';
        }
        if (preg_match('/(^|\D)(18|19)(\D|$)/', $detail)
            || self::contains($detail, ['no answer', 'no user responding', 'request timeout', 'sem resposta'])) {
            return 'no_answer';
        }
        if (preg_match('/(^|\D)21(\D|$)/', $detail) || self::contains($detail, ['rejected', 'decline'])) {
            return 'rejected';
        }
        if (preg_match('/(^|\D)(27|34|38|41|42|47|58)(\D|$)/', $detail)
            || self::contains($detail, ['unavailable', 'congestion', 'network out'])) {
            return 'unavailable';
        }

        return $fallback;
    }

    private static function mentionsVoicemail(string $detail): bool
    {
        return self::contains($detail, ['voicemail', 'voice mail', 'mailbox', 'caixa postal', 'caixa de mensagens']);
    }

    private static function contains(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
