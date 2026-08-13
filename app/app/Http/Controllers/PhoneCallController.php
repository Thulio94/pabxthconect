<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\CallRecordMatcher;
use App\Support\CallOutcome;
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
            'status' => ['required', Rule::in(['answered', 'completed', 'failed', 'rejected', 'cancelled', 'busy', 'no_answer', 'voicemail', 'invalid_number', 'unavailable'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'sip_code' => ['nullable', 'integer', 'between:100,699'],
            'reason_phrase' => ['nullable', 'string', 'max:255'],
        ]);

        $requestedStatus = $data['status'];
        $status = in_array($requestedStatus, ['failed', 'rejected', 'cancelled'], true) && ! $callRecord->answered_at
            ? CallOutcome::fromSip($data['sip_code'] ?? null, $data['reason_phrase'] ?? null, $requestedStatus)
            : $requestedStatus;
        $terminal = ['completed', 'failed', 'rejected', 'cancelled', 'busy', 'no_answer', 'voicemail', 'invalid_number', 'unavailable'];
        if ($callRecord->ended_at && in_array($callRecord->status, $terminal, true)) {
            $status = $callRecord->status;
        }

        $updates = ['status' => $status];
        if ($status === 'answered' && $callRecord->answered_at === null) {
            $updates['answered_at'] = now();
        }
        if (in_array($status, $terminal, true)) {
            $updates['ended_at'] = now();
            $updates['duration_seconds'] = $callRecord->answered_at
                ? max((int) ($data['duration_seconds'] ?? 0), $callRecord->effectiveDurationSeconds())
                : 0;
            if (isset($data['sip_code']) || isset($data['reason_phrase'])) {
                $updates['hangup_cause'] = trim('SIP '.($data['sip_code'] ?? '').' '.($data['reason_phrase'] ?? ''));
            }
        }
        $callRecord->update($updates);

        $activity->log($extension, $request->user(), 'call_'.$status, CallOutcome::label($status).'.', ['number' => $callRecord->to_number, 'call_record_id' => $callRecord->id]);

        return response()->json($this->payload($callRecord->fresh('recording')));
    }

    public function uploadRecording(Request $request, CallRecord $callRecord): JsonResponse
    {
        $this->authorizeSession($request, $callRecord);
        abort_unless($callRecord->tenant()->value('record_calls'), 403);
        abort_unless($callRecord->answered_at, 422, 'Esta chamada não foi atendida e não possui áudio válido.');
        $request->validate(['recording' => ['required', 'file', 'max:51200', 'mimetypes:audio/webm,audio/ogg,audio/mp4,video/webm,application/octet-stream']]);

        $file = $request->file('recording');
        $extension = match ($file->getMimeType()) {
            'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', default => 'webm',
        };
        $recording = $callRecord->recording ?? new Recording(['call_record_id' => $callRecord->id]);
        $path = "tenant-{$callRecord->tenant_id}/browser-{$callRecord->id}.{$extension}";
        Storage::disk('pbx_recordings')->put($path, $file->getContent());
        $recording->fill([
            'storage_disk' => 'pbx_recordings', 'path' => $path, 'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(), 'available_at' => now(), 'deleted_at' => null,
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
        abort_unless($callRecord->tenant_id === ($agent['tenant_id'] ?? null) && $callRecord->extension_id === ($agent['extension_id'] ?? null), 404);

        return $this->extensionFromSession($agent);
    }

    private function payload(CallRecord $call): array
    {
        $recording = $call->recording;
        $playable = $recording?->isPlayable() ?? false;

        return [
            'id' => $call->id,
            'remote_number' => $call->direction === 'inbound' ? $call->from_number : $call->to_number,
            'direction' => $call->direction,
            'status' => $call->status,
            'result_label' => $call->resultLabel(),
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->effectiveDurationSeconds(),
            'has_recording' => $playable,
            'recording_url' => $playable ? route('phone.call-records.recording', $call) : null,
        ];
    }
}
