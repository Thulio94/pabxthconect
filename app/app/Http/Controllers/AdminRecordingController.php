<?php

namespace App\Http\Controllers;

use App\Models\Recording;
use App\Models\Tenant;
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

        $recordings = Recording::query()->with(['call.extension.user', 'call.tenant'])
            ->whereNotNull('available_at')->whereNull('deleted_at')
            ->whereHas('call', function ($query) use ($filters) {
                $query->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('started_at', '>=', $date))
                    ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('started_at', '<=', $date))
                    ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
                    ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                    ->when($filters['phone'] ?? null, function ($q, $phone) {
                        $digits = preg_replace('/\D+/', '', $phone);
                        $q->where(fn ($numbers) => $numbers->where('to_number', 'like', '%'.$digits.'%')->orWhere('from_number', 'like', '%'.$digits.'%'));
                    });
            })->latest('created_at')->paginate(25)->withQueryString();

        return view('admin.recordings', ['recordings' => $recordings, 'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name']), 'filters' => $filters]);
    }

    public function play(Recording $recording)
    {
        abort_unless($recording->available_at && ! $recording->deleted_at, 404);
        abort_unless(Storage::disk($recording->storage_disk)->exists($recording->path), 404);
        return Storage::disk($recording->storage_disk)->response($recording->path, basename($recording->path), ['Content-Type' => $recording->mime_type ?: 'audio/wav']);
    }
}
