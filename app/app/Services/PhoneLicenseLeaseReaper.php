<?php

namespace App\Services;

use App\Models\PhoneLicenseLease;
use Illuminate\Support\Carbon;

class PhoneLicenseLeaseReaper
{
    public function __construct(
        private readonly PhoneLicenseManager $licenses,
        private readonly OperatorActivityRecorder $activity,
    ) {}

    public function reap(?int $tenantId = null): int
    {
        $staleBefore = $this->licenses->staleBefore();
        $reaped = 0;

        PhoneLicenseLease::query()
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $staleBefore))
            ->with(['extension.user', 'extension.presence'])
            ->orderBy('id')
            ->chunkById(100, function ($leases) use ($staleBefore, &$reaped): void {
                foreach ($leases as $candidate) {
                    $expired = $this->licenses->releaseIfExpired($candidate->id, $staleBefore);
                    if (! $expired) {
                        continue;
                    }

                    $extension = $candidate->extension;
                    $user = $extension?->user;
                    if ($extension && $user) {
                        $lastSeenAt = $candidate->last_seen_at
                            ?? $candidate->created_at
                            ?? Carbon::now()->subSeconds(PhoneLicenseManager::HEARTBEAT_TIMEOUT_SECONDS);

                        $this->activity->expirePhoneSession($extension, $user, $candidate->session_key, $lastSeenAt);
                    }

                    $reaped++;
                }
            });

        return $reaped;
    }
}
