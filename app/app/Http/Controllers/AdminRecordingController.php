<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Models\Recording;
use App\Models\Tenant;
use App\Services\Pbx\CallRecordMatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminRecordingController extends Controller
{
    public function index(Request $request)
    {
        return $this->recordingList($request, false);
    }

    public function supervisorIndex(Request $request)
    {
        abort_unless($request->user()->isSupervisor(), 403);

        return $this->recordingList($request, true);
    }

    private function recordingList(Request $request, bool $supervisor)
    {
        $rules = [
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:answered,completed,failed,cancelled,busy,no_answer,voicemail,invalid_number,rejected,unavailable'],
        ];
        if (! $supervisor) {
            $rules['tenant_id'] = ['nullable', 'integer', 'exists:tenants,id'];
        }
        $filters = $request->validate($rules);
        $tenantId = $supervisor ? (int) $request->user()->tenant_id : ($filters['tenant_id'] ?? null);

        if (! $supervisor && $request->user()->isTenantAdmin()) {
            if (isset($filters['tenant_id']) && (int) $filters['tenant_id'] !== (int) $request->user()->tenant_id) {
                abort(403);
            }
            $tenantId = (int) $request->user()->tenant_id;
            $filters['tenant_id'] = $tenantId;
        }

        $timezone = config('app.display_timezone', 'America/Sao_Paulo');
        $fromUtc = isset($filters['from']) ? Carbon::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay()->utc() : null;
        $toUtc = isset($filters['to']) ? Carbon::createFromFormat('Y-m-d', $filters['to'], $timezone)->endOfDay()->utc() : null;

        $recordings = Recording::query()->with(['call.extension.user', 'call.tenant'])
            ->whereNotNull('available_at')->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where(fn ($wav) => $wav->where('mime_type', 'audio/wav')->where('size_bytes', '>', 44))
                    ->orWhere(fn ($other) => $other->where('mime_type', '!=', 'audio/wav')->where('size_bytes', '>', 0));
            })
            ->whereHas('call', function ($query) use ($filters, $fromUtc, $toUtc, $tenantId, $supervisor) {
                $query->whereNotNull('answered_at')
                    ->when($fromUtc, fn ($q, $date) => $q->where('started_at', '>=', $date))
                    ->when($toUtc, fn ($q, $date) => $q->where('started_at', '<=', $date))
                    ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
                    ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                    ->when($supervisor, fn ($q) => $q->whereHas('extension', fn ($extension) => $extension
                        ->where('tenant_id', $tenantId)
                        ->whereHas('user', fn ($user) => $user->where('tenant_id', $tenantId)->where('role', 'agent'))))
                    ->when($filters['phone'] ?? null, function ($q, $phone) {
                        $digits = preg_replace('/\D+/', '', $phone);
                        $q->where(fn ($numbers) => $numbers->where('to_number', 'like', '%'.$digits.'%')->orWhere('from_number', 'like', '%'.$digits.'%'));
                    });
            })->latest('created_at')->paginate(25)->withQueryString();

        $recordings->getCollection()->each(function (Recording $recording): void {
            $call = $recording->call;
            $duration = $call?->effectiveDurationSeconds() ?? 0;

            // Recover duration from historical duplicates created before phone
            // formats (national and E.164) were matched as the same call.
            if ($call && $duration === 0 && $call->started_at) {
                $duration = (int) CallRecord::query()
                    ->where('extension_id', $call->extension_id)
                    ->whereKeyNot($call->id)
                    ->whereBetween('started_at', [$call->started_at->copy()->subMinutes(2), $call->started_at->copy()->addMinutes(2)])
                    ->get()
                    ->filter(fn ($candidate) => CallRecordMatcher::samePhoneNumber($candidate->to_number, $call->to_number))
                    ->max(fn ($candidate) => $candidate->effectiveDurationSeconds());
            }

            $recording->setAttribute('display_duration_seconds', $duration);
        });

        $tenants = Tenant::query()
            ->when($supervisor || $request->user()->isTenantAdmin(), fn ($query) => $query->whereKey($request->user()->tenant_id))
            ->orderBy('name')->get(['id', 'name']);

        return view('admin.recordings', [
            'recordings' => $recordings,
            'tenants' => $tenants,
            'filters' => $filters,
            'isSupervisor' => $supervisor,
        ]);
    }

    public function play(Request $request, Recording $recording)
    {
        if ($request->user()->isTenantAdmin()) {
            abort_unless((int) $recording->call?->tenant_id === (int) $request->user()->tenant_id, 403);
        }

        return $this->stream($recording);
    }

    public function supervisorPlay(Request $request, Recording $recording)
    {
        abort_unless($request->user()->isSupervisor(), 403);
        $call = $recording->call()->with('extension.user')->first();
        $tenantId = (int) $request->user()->tenant_id;
        abort_unless(
            $call
                && (int) $call->tenant_id === $tenantId
                && (int) $call->extension?->tenant_id === $tenantId
                && (int) $call->extension?->user?->tenant_id === $tenantId
                && $call->extension?->user?->isAgent(),
            403,
        );

        return $this->stream($recording);
    }

    private function stream(Recording $recording)
    {
        abort_unless($recording->isPlayable(), 404);
        abort_unless(Storage::disk($recording->storage_disk)->exists($recording->path), 404);

        $response = Storage::disk($recording->storage_disk)
            ->response($recording->path, basename($recording->path), ['Content-Type' => $recording->mime_type ?: 'audio/wav']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
