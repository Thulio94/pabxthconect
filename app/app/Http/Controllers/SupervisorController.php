<?php

namespace App\Http\Controllers;

use App\Models\Extension;
use App\Models\PhoneLicenseLease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PhoneLicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SupervisorController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);

        return view('supervisor.dashboard', compact('tenant'));
    }

    public function agents(Request $request, PhoneLicenseManager $licenses): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $staleBefore = $licenses->staleBefore();
        $presenceAvailable = Schema::hasColumns('extension_presences', ['extension_id', 'heartbeat_at']);

        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', 'agent')
            ->with(['pbxExtension' => fn ($query) => $query->where('tenant_id', $tenantId)])
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'email']);
        $extensionIds = $users->pluck('pbxExtension.id')->filter()->values();
        $freshLeases = PhoneLicenseLease::query()->where('tenant_id', $tenantId)
            ->whereIn('extension_id', $extensionIds)->where('last_seen_at', '>=', $staleBefore)
            ->pluck('extension_id')->flip();
        $freshPresence = $presenceAvailable
            ? Extension::query()->whereIn('id', $extensionIds)->whereHas('presence', fn ($query) => $query->where('heartbeat_at', '>=', now()->subSeconds(45)))->pluck('id')->flip()
            : collect();

        $agents = $users->map(function (User $user) use ($freshLeases, $freshPresence): array {
            $extension = $user->pbxExtension;
            $online = $extension?->status === 'active'
                && $freshLeases->has($extension->id)
                && $freshPresence->has($extension->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'extension' => $extension ? (string) $extension->number : null,
                'status' => $online ? 'online' : 'offline',
                'status_label' => $online ? 'Online' : 'Offline',
            ];
        })->values();

        return response()->json([
            'agents' => $agents,
            'online' => $agents->where('status', 'online')->count(),
            'offline' => $agents->where('status', 'offline')->count(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
