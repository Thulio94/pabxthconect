<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\CallRecordMatcher;
use App\Support\CallOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'media_diagnostics' => ['nullable', 'array'],
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
        if (isset($data['media_diagnostics'])) {
            $updates['media_diagnostics'] = array_replace_recursive(
                $callRecord->media_diagnostics ?? [],
                ['browser' => $this->sanitizeMediaDiagnostics($data['media_diagnostics'])],
            );
        }
        $callRecord->update($updates);

        $activity->log($extension, $request->user(), 'call_'.$status, CallOutcome::label($status).'.', ['number' => $callRecord->to_number, 'call_record_id' => $callRecord->id]);

        return response()->json($this->payload($callRecord->fresh('recording')));
    }

    public function uploadRecording(Request $request, CallRecord $callRecord): JsonResponse
    {
        // The Asterisk MixMonitor WAV is the authoritative two-way recording.
        // Accepting browser WebM uploads here allowed a one-sided local capture
        // to overwrite it, so legacy clients are deliberately rejected.
        $this->authorizeSession($request, $callRecord);

        return response()->json([
            'message' => 'A gravação é gerada pelo PBX após a chamada ser encerrada.',
        ], 410);
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

    /**
     * Keep only aggregate WebRTC health data. Candidate addresses, credentials
     * and SDP never belong in application storage.
     */
    private function sanitizeMediaDiagnostics(array $diagnostics): array
    {
        $webrtc = is_array($diagnostics['webrtc'] ?? null) ? $diagnostics['webrtc'] : [];
        $integer = static fn (string $key): int => max(0, min(1000000000, (int) ($webrtc[$key] ?? 0)));

        return [
            'checked_at' => now()->toIso8601String(),
            'state' => in_array($diagnostics['state'] ?? null, ['accepted', 'healthy', 'degraded', 'failed'], true) ? $diagnostics['state'] : 'degraded',
            'webrtc' => [
                'inbound_packets' => $integer('inbound_packets'),
                'outbound_packets' => $integer('outbound_packets'),
                'inbound_bytes' => $integer('inbound_bytes'),
                'outbound_bytes' => $integer('outbound_bytes'),
                'packets_lost' => $integer('packets_lost'),
                'jitter_ms' => max(0, min(60000, (int) ($webrtc['jitter_ms'] ?? 0))),
                'inbound_codec' => preg_replace('/[^A-Za-z0-9_\-\/]/', '', (string) ($webrtc['inbound_codec'] ?? '')),
                'outbound_codec' => preg_replace('/[^A-Za-z0-9_\-\/]/', '', (string) ($webrtc['outbound_codec'] ?? '')),
                'candidate_protocol' => in_array($webrtc['candidate_protocol'] ?? null, ['udp', 'tcp', 'tls'], true) ? $webrtc['candidate_protocol'] : null,
                'candidate_type' => in_array($webrtc['candidate_type'] ?? null, ['host', 'srflx', 'relay', 'prflx'], true) ? $webrtc['candidate_type'] : null,
            ],
        ];
    }
}
