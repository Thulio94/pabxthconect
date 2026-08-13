<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PbxRecordingController extends Controller
{
    public function __invoke(Request $request, CallRecord $callRecord)
    {
        $agent = $request->session()->get('sip_agent');
        abort_unless($callRecord->tenant_id === ($agent['tenant_id'] ?? null) && $callRecord->extension_id === ($agent['extension_id'] ?? null), 404);
        $recording = $callRecord->recording;
        abort_unless($recording?->isPlayable() && Storage::disk($recording->storage_disk)->exists($recording->path), 404);

        return response()->file(Storage::disk($recording->storage_disk)->path($recording->path), ['Content-Type' => $recording->mime_type ?: 'audio/wav']);
    }
}
