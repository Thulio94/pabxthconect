<?php

namespace App\Services\Pbx;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Models\SipTrunk;
use Illuminate\Support\Facades\Storage;

class AmiEventProcessor
{
    public function __construct(private readonly CallRecordMatcher $matcher)
    {
    }

    public function process(array $event): void
    {
        match ($event['Event'] ?? '') {
            'Newchannel' => $this->newChannel($event),
            'DialBegin' => $this->dialBegin($event),
            'BridgeEnter' => $this->bridgeEnter($event),
            'MixMonitorStop' => $this->mixMonitorStop($event),
            'Hangup' => $this->hangup($event),
            default => null,
        };
    }

    private function newChannel(array $event): void
    {
        $extension = $this->extensionFromChannel($event['Channel'] ?? '');
        $uniqueId = $event['Uniqueid'] ?? null;
        if (! $extension || ! $uniqueId) return;

        $number = preg_replace('/\D+/', '', (string) ($event['Exten'] ?? ''));
        if ($number === '') return;
        $call = CallRecord::query()->where('asterisk_uniqueid', $uniqueId)->first();
        if (! $call) {
            // O webphone grava imediatamente como contingÃªncia. Quando o evento
            // AMI chega, ele passa a ser o mesmo registro, sem duplicar histÃ³rico.
            $call = $this->matcher->recentFor($extension, $number);
        }
        if ($call) {
            $call->update(['asterisk_uniqueid' => $uniqueId, 'asterisk_linkedid' => $event['Linkedid'] ?? $uniqueId, 'status' => 'dialing']);
        } else {
            $call = CallRecord::create([
                'tenant_id' => $extension->tenant_id,
                'extension_id' => $extension->id,
                'asterisk_uniqueid' => $uniqueId,
                'asterisk_linkedid' => $event['Linkedid'] ?? $uniqueId,
                'direction' => 'outbound',
                'from_number' => (string) $extension->number,
                'to_number' => $number,
                'status' => 'dialing',
                'started_at' => now(),
            ]);
        }

        if ($extension->tenant->record_calls) {
            $deleteAfter = $extension->tenant->recording_retention_days
                ? now()->addDays($extension->tenant->recording_retention_days) : null;
            $recording = Recording::firstOrNew(['call_record_id' => $call->id]);
            // Replace an unavailable browser fallback placeholder with the WAV
            // path that MixMonitor writes in the shared recordings volume.
            if (! $recording->available_at) {
                $recording->fill([
                    'storage_disk' => 'pbx_recordings',
                    'path' => "tenant-{$extension->tenant_id}/{$uniqueId}.wav",
                    'mime_type' => 'audio/wav',
                    'size_bytes' => null,
                    'delete_after' => $recording->delete_after ?? $deleteAfter,
                    'deleted_at' => null,
                ])->save();
            }
        }
    }

    private function dialBegin(array $event): void
    {
        $call = $this->callFromChannel($event['Channel'] ?? '', $event['Uniqueid'] ?? null);
        if (! $call) return;
        $trunk = $this->trunkFromChannel($event['DestChannel'] ?? '');
        $call->update(['sip_trunk_id' => $trunk?->id, 'dialed_uri' => $event['DestChannel'] ?? null, 'status' => 'ringing']);
    }

    private function bridgeEnter(array $event): void
    {
        $call = $this->callFromChannel($event['Channel'] ?? '', $event['Uniqueid'] ?? null);
        if ($call && ! $call->answered_at) $call->update(['answered_at' => now(), 'status' => 'answered']);
    }

    private function hangup(array $event): void
    {
        $uniqueId = $event['Uniqueid'] ?? null;
        $linkedId = $event['Linkedid'] ?? null;
        $call = CallRecord::query()
            ->where(function ($query) use ($uniqueId, $linkedId) {
                if ($uniqueId) $query->where('asterisk_uniqueid', $uniqueId);
                if ($linkedId) $query->orWhere('asterisk_linkedid', $linkedId);
            })
            ->latest('id')
            ->first();
        if (! $call || $call->ended_at) return;
        $endedAt = now();
        $call->update([
            'ended_at' => $endedAt,
            'duration_seconds' => max(0, $call->started_at?->diffInSeconds($endedAt) ?? 0),
            'status' => $call->answered_at ? 'completed' : 'failed',
            'hangup_cause' => $event['Cause-txt'] ?? $event['Cause'] ?? null,
        ]);
        $this->finalizeRecording($call, 20);
    }

    private function mixMonitorStop(array $event): void
    {
        $call = $this->callFromIdentifiers($event['Uniqueid'] ?? null, $event['Linkedid'] ?? null);
        if ($call) $this->finalizeRecording($call, 5);
    }

    private function finalizeRecording(CallRecord $call, int $attempts = 1): void
    {
        $recording = $call->recording;
        if (! $recording || $recording->available_at) return;

        $nativePath = $call->asterisk_uniqueid && ! str_starts_with($call->asterisk_uniqueid, 'web-')
            ? "tenant-{$call->tenant_id}/{$call->asterisk_uniqueid}.wav"
            : $recording->path;
        $disk = Storage::disk('pbx_recordings');
        for ($attempt = 0; $attempt < $attempts && ! $disk->exists($nativePath); $attempt++) usleep(100_000);
        if (! $disk->exists($nativePath)) return;

        clearstatcache(true, $disk->path($nativePath));
        $recording->update([
            'storage_disk' => 'pbx_recordings',
            'path' => $nativePath,
            'mime_type' => 'audio/wav',
            'size_bytes' => $disk->size($nativePath),
            'available_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function callFromIdentifiers(?string $uniqueId, ?string $linkedId): ?CallRecord
    {
        if (! $uniqueId && ! $linkedId) return null;
        return CallRecord::query()->where(function ($query) use ($uniqueId, $linkedId) {
            if ($uniqueId) $query->where('asterisk_uniqueid', $uniqueId);
            if ($linkedId) $query->orWhere('asterisk_linkedid', $linkedId);
        })->latest('id')->first();
    }

    private function extensionFromChannel(string $channel): ?Extension
    {
        if (preg_match('/PJSIP\/(t\d+-e\d+)-/', $channel, $matches)) {
            return Extension::query()->with('tenant')->where('sip_username', $matches[1])->first();
        }
        if (preg_match('/PJSIP\/ext-(\d+)-/', $channel, $matches)) {
            return Extension::query()->with('tenant')->find($matches[1]);
        }
        return null;
    }

    private function trunkFromChannel(string $channel): ?SipTrunk
    {
        if (! preg_match('/PJSIP\/trunk-(\d+)-/', $channel, $matches)) return null;
        return SipTrunk::find($matches[1]);
    }

    private function callFromChannel(string $channel, ?string $uniqueId): ?CallRecord
    {
        if ($uniqueId && ($call = CallRecord::query()->where('asterisk_uniqueid', $uniqueId)->first())) return $call;
        $extension = $this->extensionFromChannel($channel);
        if (! $extension || ! preg_match('/-(\d+)$/', $channel, $matches)) return null;
        return CallRecord::query()->where('asterisk_uniqueid', 'like', '%'.$matches[1])->where('extension_id', $extension->id)->latest('id')->first();
    }
}
