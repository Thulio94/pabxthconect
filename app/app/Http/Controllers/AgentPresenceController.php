<?php

namespace App\Http\Controllers;

use App\Models\Extension;
use App\Models\ExtensionPresence;
use App\Models\PauseReason;
use App\Services\OperatorActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentPresenceController extends Controller
{
    public function heartbeat(Request $request, OperatorActivityRecorder $activity): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $extension = Extension::findOrFail($agent['extension_id']);
        $data = $request->validate(['state' => ['nullable', Rule::in(['available', 'offline'])]]);
        $presence = ExtensionPresence::firstOrNew(['extension_id' => $extension->id]);
        $nextState = $data['state'] ?? 'available';
        if ($presence->state !== 'paused' && $presence->state !== $nextState) {
            $presence->state = $nextState;
            $presence->state_since = now();
        }
        $presence->state_since ??= now();
        $presence->heartbeat_at = now();
        $presence->save();
        $activity->heartbeat($extension, $request->user(), $agent['operator_session_id'] ?? null);
        return response()->json(['state' => $presence->state, 'pause_reason_id' => $presence->pause_reason_id, 'state_since' => $presence->state_since?->toIso8601String()]);
    }

    public function pause(Request $request, OperatorActivityRecorder $activity): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $extension = Extension::findOrFail($agent['extension_id']);
        $data = $request->validate(['pause_reason_id' => ['required', 'integer']]);
        $pause = PauseReason::query()->whereKey($data['pause_reason_id'])->where('tenant_id', $extension->tenant_id)->where('is_active', true)->first();
        if (! $pause) return response()->json(['message' => 'Esta pausa não pertence à empresa do agente.'], 422);
        ExtensionPresence::updateOrCreate(['extension_id' => $extension->id], ['pause_reason_id' => $pause->id, 'state' => 'paused', 'state_since' => now(), 'heartbeat_at' => now()]);
        $activity->startPause($extension, $request->user(), $pause);
        return response()->json(['message' => 'Pausa iniciada.']);
    }

    public function resume(Request $request, OperatorActivityRecorder $activity): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $extension = Extension::findOrFail($agent['extension_id']);
        ExtensionPresence::updateOrCreate(['extension_id' => $extension->id], ['pause_reason_id' => null, 'state' => 'available', 'state_since' => now(), 'heartbeat_at' => now()]);
        $activity->closePause($extension, $request->user());
        return response()->json(['message' => 'Agente disponível.']);
    }
}
