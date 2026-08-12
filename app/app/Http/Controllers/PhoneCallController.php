<?php

namespace App\Http\Controllers;

use App\Models\PhoneCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhoneCallController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $data = $request->validate([
            'direction' => ['required', Rule::in(['incoming', 'outgoing'])],
            'remote_number' => ['nullable', 'string', 'max:80'],
        ]);

        $call = PhoneCall::create([
            'tenant_id' => $agent['tenant_id'],
            'extension' => $agent['extension'],
            'direction' => $data['direction'],
            'remote_number' => $data['remote_number'] ?? null,
            'status' => $data['direction'] === 'incoming' ? 'ringing' : 'dialing',
            'started_at' => now(),
        ]);

        return response()->json($this->payload($call), 201);
    }

    public function update(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        $this->authorizeSession($request, $phoneCall);
        $data = $request->validate([
            'status' => ['required', Rule::in(['answered', 'completed', 'failed', 'rejected', 'cancelled'])],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        if ($data['status'] === 'answered' && $phoneCall->answered_at === null) {
            $phoneCall->answered_at = now();
        }

        if (in_array($data['status'], ['completed', 'failed', 'rejected', 'cancelled'], true)) {
            $phoneCall->ended_at = now();
            $phoneCall->duration_seconds = $data['duration_seconds'] ?? 0;
        }

        $phoneCall->status = $data['status'];
        $phoneCall->save();

        return response()->json($this->payload($phoneCall));
    }

    public function uploadRecording(Request $request, PhoneCall $phoneCall): JsonResponse
    {
        $this->authorizeSession($request, $phoneCall);
        abort_unless($phoneCall->tenant()->value('record_calls'), 403);

        $request->validate([
            'recording' => ['required', 'file', 'max:51200', 'mimetypes:audio/webm,audio/ogg,audio/mp4,video/webm,application/octet-stream'],
        ]);

        $file = $request->file('recording');
        $extension = match ($file->getMimeType()) {
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            default => 'webm',
        };
        $directory = "recordings/{$phoneCall->tenant_id}/".Str::slug($phoneCall->extension);
        $filename = Str::uuid().'.'.$extension;
        $path = $file->storeAs($directory, $filename, 'local');

        $phoneCall->update([
            'recording_path' => $path,
            'recording_mime' => $file->getMimeType(),
            'recording_size' => $file->getSize(),
        ]);

        return response()->json($this->payload($phoneCall->fresh()));
    }

    public function recording(Request $request, PhoneCall $phoneCall): StreamedResponse
    {
        $this->authorizeSession($request, $phoneCall);
        abort_unless($phoneCall->recording_path && Storage::disk('local')->exists($phoneCall->recording_path), 404);

        return Storage::disk('local')->response(
            $phoneCall->recording_path,
            "chamada-{$phoneCall->id}.webm",
            ['Cache-Control' => 'private, no-store'],
        );
    }

    private function authorizeSession(Request $request, PhoneCall $phoneCall): void
    {
        $agent = $request->session()->get('sip_agent');

        abort_unless(
            (int) $agent['tenant_id'] === $phoneCall->tenant_id && $agent['extension'] === $phoneCall->extension,
            404,
        );
    }

    private function payload(PhoneCall $call): array
    {
        return [
            'id' => $call->id,
            'remote_number' => $call->remote_number,
            'direction' => $call->direction,
            'status' => $call->status,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->duration_seconds,
            'has_recording' => $call->recording_path !== null,
            'recording_url' => $call->recording_path ? route('phone.calls.recording', $call) : null,
        ];
    }
}
