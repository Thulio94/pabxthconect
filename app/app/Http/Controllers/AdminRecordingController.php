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
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'phone' => ['nullable', 'string', 'max:30'], 'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'status' => ['nullable', 'in:answered,failed,cancelled,busy,no_answer'],
        ]);
        if ($request->user()->isTenantAdmin()) {
            if (isset($filters['tenant_id']) && (int) $filters['tenant_id'] !== (int) $request->user()->tenant_id) {
                abort(403);
            }
            $filters['tenant_id'] = $request->user()->tenant_id;
        }

        $timezone = config('app.display_timezone', 'America/Sao_Paulo');
        $fromUtc = isset($filters['from']) ? Carbon::createFromFormat('Y-m-d', $filters['from'], $timezone)->startOfDay()->utc() : null;
        $toUtc = isset($filters['to']) ? Carbon::createFromFormat('Y-m-d', $filters['to'], $timezone)->endOfDay()->utc() : null;

        $recordings = Recording::query()->with(['call.extension.user', 'call.tenant'])
            ->whereNotNull('available_at')->whereNull('deleted_at')
            ->whereHas('call', function ($query) use ($filters, $fromUtc, $toUtc) {
                $query->when($fromUtc, fn ($q, $date) => $q->where('started_at', '>=', $date))
                    ->when($toUtc, fn ($q, $date) => $q->where('started_at', '<=', $date))
                    ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
                    ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
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

        $tenants = Tenant::query()->when($request->user()->isTenantAdmin(), fn ($query) => $query->whereKey($request->user()->tenant_id))->orderBy('name')->get(['id', 'name']);

        return view('admin.recordings', ['recordings' => $recordings, 'tenants' => $tenants, 'filters' => $filters]);
    }

    public function play(Request $request, Recording $recording)
    {
        if ($request->user()->isTenantAdmin()) {
            abort_unless((int) $recording->call?->tenant_id === (int) $request->user()->tenant_id, 403);
        }
        abort_unless($recording->available_at && ! $recording->deleted_at, 404);
        abort_unless(Storage::disk($recording->storage_disk)->exists($recording->path), 404);

        return Storage::disk($recording->storage_disk)->response($recording->path, basename($recording->path), ['Content-Type' => $recording->mime_type ?: 'audio/wav']);
    }
}
