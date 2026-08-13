<?php

namespace App\Services\Pbx;

use App\Models\Recording;
use Illuminate\Support\Facades\Storage;

class RecordingReconciler
{
    public function reconcile(int $limit = 500): int
    {
        $disk = Storage::disk('pbx_recordings');
        $recovered = 0;

        Recording::query()
            ->with('call')
            ->whereNull('available_at')
            ->whereNull('deleted_at')
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (Recording $recording) use ($disk, &$recovered): void {
                $call = $recording->call;
                if (! $call?->asterisk_uniqueid || str_starts_with($call->asterisk_uniqueid, 'web-')) {
                    return;
                }
                if (! $call->answered_at) {
                    return;
                }

                $path = "tenant-{$call->tenant_id}/{$call->asterisk_uniqueid}.wav";
                if (! $disk->exists($path) || $disk->size($path) <= 44) {
                    return;
                }

                clearstatcache(true, $disk->path($path));
                $recording->update([
                    'storage_disk' => 'pbx_recordings',
                    'path' => $path,
                    'mime_type' => 'audio/wav',
                    'size_bytes' => $disk->size($path),
                    'available_at' => now(),
                    'deleted_at' => null,
                ]);
                $recovered++;
            });

        return $recovered;
    }
}
