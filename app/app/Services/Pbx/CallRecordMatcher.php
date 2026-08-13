<?php

namespace App\Services\Pbx;

use App\Models\CallRecord;
use App\Models\Extension;

class CallRecordMatcher
{
    public function recentFor(Extension $extension, string $number, string $source = 'browser'): ?CallRecord
    {
        $query = CallRecord::query()
            ->where('extension_id', $extension->id)
            ->whereNull('ended_at')
            ->where('started_at', '>=', now()->subMinutes(2));

        $source === 'browser'
            ? $query->where('asterisk_uniqueid', 'like', 'web-%')
            : $query->where('asterisk_uniqueid', 'not like', 'web-%');

        return $query->latest('id')->get()
            ->first(fn (CallRecord $call) => self::samePhoneNumber($call->to_number, $number));
    }

    public static function samePhoneNumber(?string $left, ?string $right): bool
    {
        $left = preg_replace('/\D+/', '', (string) $left);
        $right = preg_replace('/\D+/', '', (string) $right);
        if ($left === '' || $right === '') return false;
        if ($left === $right) return true;
        $left = self::nationalNumber($left);
        $right = self::nationalNumber($right);
        return strlen($left) >= 10 && strlen($right) >= 10 && $left === $right;
    }

    private static function nationalNumber(string $number): string
    {
        if (str_starts_with($number, '0055') && strlen($number) >= 14) $number = substr($number, 4);
        elseif (str_starts_with($number, '55') && in_array(strlen($number), [12, 13], true)) $number = substr($number, 2);
        if (str_starts_with($number, '0') && in_array(strlen($number), [11, 12], true)) $number = substr($number, 1);
        return $number;
    }
}
