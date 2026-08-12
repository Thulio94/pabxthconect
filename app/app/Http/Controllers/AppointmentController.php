<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agent = $request->session()->get('sip_agent');
        $appointments = Appointment::query()
            ->where('user_id', $agent['user_id'])
            ->where('extension_id', $agent['extension_id'])
            ->where('status', 'pending')
            ->orderBy('scheduled_for')->limit(20)->get();

        Appointment::query()
            ->whereIn('id', $appointments->where('scheduled_for', '<=', now())->pluck('id'))
            ->whereNull('notified_at')->update(['notified_at' => now()]);

        return response()->json([
            'appointments' => $appointments->map(fn (Appointment $appointment) => $this->payload($appointment)),
            'server_now' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string'],
            'scheduled_for' => ['required', 'date_format:Y-m-d\\TH:i'],
        ]);
        $phone = preg_replace('/\D+/', '', $data['phone']);
        if (! in_array(strlen($phone), [10, 11], true)) {
            return response()->json(['message' => 'Informe um telefone com DDD, contendo 10 ou 11 dígitos.', 'errors' => ['phone' => ['Telefone inválido.']]], 422);
        }
        $scheduledFor = Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_for'], $this->timezone())->utc();
        if ($scheduledFor->lt(now()->subMinute())) {
            return response()->json(['message' => 'Escolha uma data e hora futuras.', 'errors' => ['scheduled_for' => ['Horário já passou.']]], 422);
        }

        $agent = $request->session()->get('sip_agent');
        $appointment = Appointment::create([
            'tenant_id' => $agent['tenant_id'],
            'user_id' => $agent['user_id'],
            'extension_id' => $agent['extension_id'],
            'name' => trim($data['name']),
            'phone' => $phone,
            'scheduled_for' => $scheduledFor,
            'status' => 'pending',
        ]);

        return response()->json(['appointment' => $this->payload($appointment)], 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);
        abort_unless($appointment->status === 'pending', 409);
        $data = $request->validate([
            'action' => ['required', Rule::in(['snooze', 'complete'])],
            'minutes' => ['nullable', 'integer', Rule::in([5, 10, 15, 30, 60, 1440])],
        ]);

        if ($data['action'] === 'snooze') {
            $minutes = (int) ($data['minutes'] ?? 10);
            $appointment->update([
                'scheduled_for' => now()->addMinutes($minutes),
                'snooze_count' => $appointment->snooze_count + 1,
                'notified_at' => null,
            ]);
        } else {
            $appointment->update(['status' => 'completed', 'completed_at' => now()]);
        }

        return response()->json(['appointment' => $this->payload($appointment->fresh())]);
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeAppointment($request, $appointment);
        $appointment->update(['status' => 'cancelled']);
        return response()->json([], 204);
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        $agent = $request->session()->get('sip_agent');
        abort_unless(
            $appointment->tenant_id === (int) $agent['tenant_id']
            && $appointment->user_id === (int) $agent['user_id']
            && $appointment->extension_id === (int) $agent['extension_id'],
            404,
        );
    }

    private function payload(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'name' => $appointment->name,
            'phone' => $appointment->phone,
            'scheduled_for' => $appointment->scheduled_for->toIso8601String(),
            'scheduled_label' => $appointment->scheduled_for->copy()->timezone($this->timezone())->format('d/m/Y H:i'),
            'status' => $appointment->status,
            'snooze_count' => $appointment->snooze_count,
            'is_due' => $appointment->status === 'pending' && $appointment->scheduled_for->lte(now()),
        ];
    }

    private function timezone(): string
    {
        return 'America/Sao_Paulo';
    }
}
