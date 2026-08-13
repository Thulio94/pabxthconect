<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\CallRecordMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PhoneCallController extends Controller
{
    public function store(Request $request, OperatorActivityRecorder $activity, CallRecordMatcher $matcher): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $data = $request->validate([
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'remote_number' => ['nullable', 'string', 'max:80'],
        ]);
        $extension = $this->extensionFromSession($agent);
        $number = preg_replace('/\D+/', '', (string) ($data['remote_number'] ?? ''));
        $call = $matcher->recentFor($extension, $number, 'asterisk');
        $call ??= CallRecord::create([
            'tenant_id' => $extension->tenant_id,
            'extension_id' => $extension->id,
            'asterisk_uniqueid' => $uniqueId = 'web-'.Str::uuid(),
            'asterisk_linkedid' => $uniqueId,
            'direction' => $data['direction'] === 'incoming' ? 'inbound' : 'outbound',
            'from_number' => $data['direction'] === 'incoming' ? $number : (string) $extension->number,
            'to_number' => $data['direction'] === 'incoming' ? (string) $extension->number : $number,
            'status' => $data['direction'] === 'incoming' ? 'ringing' : 'dialing',
            'started_at' => now(),
        ]);

        if ($extension->tenant->record_calls) {
            $deleteAfter = $extension->tenant->recording_retention_days
                ? now()->addDays($extension->tenant->recording_retention_days) : null;
            Recording::firstOrCreate(['call_record_id' => $call->id], [
                'call_record_id' => $call->id,
                'storage_disk' => 'pbx_recordings',
                'path' => "tenant-{$extension->tenant_id}/browser-{$call->id}.webm",
                'delete_after' => $deleteAfter,
            ]);
        }

        $activity->log($extension, $request->user(), 'call_started', 'Iniciou uma chamada.', ['direction' => $data['direction'], 'number' => $number, 'call_record_id' => $call->id]);
        return response()->json($this->payload($call), 201);
    }

    public function update(Request $request, CallRecord $callRecord, OperatorActivityRecorder $activity): JsonResponse
    {
        $extension = $this->authorizeSession($request, $callRecord);
        $data = $request->validate([
            'status' => ['required', Rule::in(['answered', 'completed', 'failed', 'rejected', 'cancelled'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);
        $updates = ['status' => $data['status']];
        if ($data['status'] === 'answered' && $callRecord->answered_at === null) $updates['answered_at'] = now();
        if (in_array($data['status'], ['completed', 'failed', 'rejected', 'cancelled'], true)) {
            $updates['ended_at'] = now();
            $measured = (int) ($data['duration_seconds'] ?? 0);
            $updates['duration_seconds'] = max($measured, $callRecord->effectiveDurationSeconds());
        }
        $callRecord->update($updates);

        $labels = ['answered' => 'Atendeu a chamada.', 'completed' => 'Finalizou a chamada.', 'failed' => 'Chamada não completada.', 'rejected' => 'Recusou a chamada.', 'cancelled' => 'Cancelou a chamada.'];
        $activity->log($extension, $request->user(), 'call_'.$data['status'], $labels[$data['status']], ['number' => $callRecord->to_number, 'call_record_id' => $callRecord->id]);
        return response()->json($this->payload($callRecord->fresh('recording')));
    }

    public function uploadRecording(Request $request, CallRecord $callRecord): JsonResponse
    {
        $this->authorizeSession($request, $callRecord);
        abort_unless($callRecord->tenant()->value('record_calls'), 403);
        $request->validate(['recording' => ['required', 'file', 'max:51200', 'mimetypes:audio/webm,audio/ogg,audio/mp4,video/webm,application/octet-stream']]);

        $file = $request->file('recording');
        $extension = match ($file->getMimeType()) {
            'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', default => 'webm',
        };
        $recording = $callRecord->recording ?? new Recording(['call_record_id' => $callRecord->id]);
        $path = "tenant-{$callRecord->tenant_id}/browser-{$callRecord->id}.{$extension}";
        Storage::disk('pbx_recordings')->put($path, $file->getContent());
        $recording->fill([
            'storage_disk' => 'pbx_recordings',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'available_at' => now(),
            'deleted_at' => null,
        ]);
        if (! $recording->delete_after && $callRecord->tenant?->recording_retention_days) {
            $recording->delete_after = now()->addDays($callRecord->tenant->recording_retention_days);
        }
        $recording->save();

        return response()->json($this->payload($callRecord->fresh('recording')));
    }

    private function extensionFromSession(array $agent): Extension
    {
        $extension = Extension::query()->with('tenant')->find($agent['extension_id'] ?? null);
        abort_unless($extension && $extension->tenant_id === ($agent['tenant_id'] ?? null), 403);
        return $extension;
    }

    private function authorizeSession(Request $request, CallRecord $callRecord): Extension
    {
        $agent = $request->session()->get('sip_agent');
        abort_unless(
            $callRecord->tenant_id === ($agent['tenant_id'] ?? null) && $callRecord->extension_id === ($agent['extension_id'] ?? null),
            404,
        );
        return $this->extensionFromSession($agent);
    }

    private function payload(CallRecord $call): array
    {
        $recording = $call->recording;
        return [
            'id' => $call->id,
            'remote_number' => $call->direction === 'inbound' ? $call->from_number : $call->to_number,
            'direction' => $call->direction,
            'status' => $call->status,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->effectiveDurationSeconds(),
            'has_recording' => $recording?->available_at !== null,
            'recording_url' => $recording?->available_at ? route('phone.call-records.recording', $call) : null,
        ];
    }
}
