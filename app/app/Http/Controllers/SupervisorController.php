<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AgentSupervisionSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupervisorController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);

        return view('admin.supervision', [
            'tenants' => collect([$tenant]),
            'selectedTenantId' => $tenant->id,
            'isReadOnly' => true,
            'agentsUrl' => route('supervisor.agents'),
        ]);
    }

    public function agents(Request $request, AgentSupervisionSnapshot $snapshot): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $snapshotData = $snapshot->forTenant($tenantId, (int) $request->user()->id);
        $snapshotData['online'] = $snapshotData['agents']->where('state', '!=', 'offline')->count();
        $snapshotData['offline'] = $snapshotData['agents']->where('state', 'offline')->count();
        $snapshotData['agents'] = $snapshotData['agents']->map(function (array $agent) {
            $agent['extension'] = $agent['number'];
            $agent['status'] = $agent['state'] === 'offline' ? 'offline' : 'online';

            return collect($agent)->except(['call', 'can_force_logout'])->all();
        });

        return response()->json($snapshotData)->header('Cache-Control', 'no-store, private');
    }
}
