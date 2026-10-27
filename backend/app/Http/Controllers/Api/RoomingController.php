<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoomingController extends Controller
{
    /**
     * GET /api/v1/ops/rooming-lists
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $query = DB::table('ops_rooming_lists')
            ->join('booking_reservations', 'ops_rooming_lists.reservation_id', '=', 'booking_reservations.id')
            ->leftJoin('catalog_providers', 'ops_rooming_lists.hotel_provider_id', '=', 'catalog_providers.id')
            ->where('ops_rooming_lists.tenant_id', $tenantId)
            ->select(
                'ops_rooming_lists.*',
                'booking_reservations.code as reservation_code',
                'booking_reservations.title as reservation_title',
                'catalog_providers.name as hotel_name'
            )
            ->orderBy('ops_rooming_lists.check_in', 'asc');

        if ($request->filled('reservation_id')) {
            $query->where('ops_rooming_lists.reservation_id', $request->reservation_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /api/v1/ops/rooming-lists
     * Crear rooming list para un hotel (PROC-034)
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $validated = $request->validate([
            'reservation_id' => 'required|uuid|exists:booking_reservations,id',
            'hotel_provider_id' => 'nullable|uuid|exists:catalog_providers,id',
            'hotel_name' => 'nullable|string|max:150',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $listId = Str::uuid7()->toString();

        DB::table('ops_rooming_lists')->insert([
            'id' => $listId,
            'tenant_id' => $tenantId,
            'reservation_id' => $validated['reservation_id'],
            'hotel_provider_id' => $validated['hotel_provider_id'] ?? null,
            'hotel_name' => $validated['hotel_name'] ?? 'Hotel Principal',
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
            'status' => 'borrador',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Rooming list creada en borrador.',
            'data' => ['id' => $listId],
        ], 201);
    }

    /**
     * GET /api/v1/ops/rooming-lists/{id}
     * Detalle con habitaciones y distribución de pasajeros
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        $list = DB::table('ops_rooming_lists')
            ->join('booking_reservations', 'ops_rooming_lists.reservation_id', '=', 'booking_reservations.id')
            ->where('ops_rooming_lists.tenant_id', $tenantId)
            ->where('ops_rooming_lists.id', $id)
            ->select('ops_rooming_lists.*', 'booking_reservations.code as reservation_code', 'booking_reservations.title as reservation_title')
            ->first();

        if (!$list) return response()->json(['message' => 'Rooming list no encontrada.'], 404);

        $rooms = DB::table('ops_rooming_rooms')->where('rooming_list_id', $list->id)->get();
        $guests = DB::table('ops_rooming_guests')
            ->join('booking_passengers', 'ops_rooming_guests.passenger_id', '=', 'booking_passengers.id')
            ->where('ops_rooming_guests.rooming_list_id', $list->id)
            ->select('ops_rooming_guests.*', 'booking_passengers.full_name', 'booking_passengers.pax_type', 'booking_passengers.doc_number')
            ->get();

        return response()->json([
            'rooming_list' => $list,
            'rooms' => $rooms,
            'guests' => $guests,
        ]);
    }

    /**
     * POST /api/v1/ops/rooming-lists/{id}/rooms
     * Agregar habitación y asignar pasajeros validando capacidad (OPS-BR-32)
     */
    public function addRoom(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $list = DB::table('ops_rooming_lists')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$list) return response()->json(['message' => 'Rooming list no encontrada.'], 404);

        $validated = $request->validate([
            'room_number' => 'required|string|max:10',
            'room_type' => 'required|string|in:Simple,Doble,Doble twin,Matrimonial,Triple,Junior suite',
            'passenger_ids' => 'required|array|min:1',
            'passenger_ids.*' => 'uuid|exists:booking_passengers,id',
        ]);

        $capacity = match ($validated['room_type']) {
            'Simple' => 1,
            'Triple' => 3,
            default => 2,
        };

        // OPS-BR-32: Capacidad
        if (count($validated['passenger_ids']) > $capacity) {
            return response()->json([
                'error' => 'OPS-BR-32',
                'message' => "La habitación {$validated['room_type']} admite un máximo de {$capacity} pasajeros.",
            ], 422);
        }

        return DB::transaction(function () use ($tenantId, $list, $validated, $capacity) {
            $roomId = Str::uuid7()->toString();

            DB::table('ops_rooming_rooms')->insert([
                'id' => $roomId,
                'tenant_id' => $tenantId,
                'rooming_list_id' => $list->id,
                'hotel_provider_id' => $list->hotel_provider_id ?? Str::uuid7()->toString(),
                'room_number' => $validated['room_number'],
                'room_type' => $validated['room_type'],
                'capacity' => $capacity,
                'status' => 'confirmada',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($validated['passenger_ids'] as $paxId) {
                // OPS-BR-30: Evitar que el pasajero esté asignado dos veces en la misma lista
                DB::table('ops_rooming_guests')->where('rooming_list_id', $list->id)->where('passenger_id', $paxId)->delete();

                DB::table('ops_rooming_guests')->insert([
                    'id' => Str::uuid7()->toString(),
                    'tenant_id' => $tenantId,
                    'room_id' => $roomId,
                    'rooming_list_id' => $list->id,
                    'passenger_id' => $paxId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Marcar lista como modificada si ya estaba confirmada
            if ($list->status === 'confirmada' || $list->status === 'enviada') {
                DB::table('ops_rooming_lists')->where('id', $list->id)->update([
                    'status' => 'modificada',
                    'updated_at' => now(),
                ]);
            }

            return response()->json(['message' => 'Habitación agregada y pasajeros asignados con éxito.']);
        });
    }

    /**
     * POST /api/v1/ops/rooming-lists/{id}/confirm
     */
    public function confirm(string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');

        DB::table('ops_rooming_lists')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update([
                'status' => 'confirmada',
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Rooming list confirmada exitosamente.']);
    }

    /**
     * POST /api/v1/ops/rooming-lists/{id}/send
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $tenantId = app('current_tenant_id');
        $user = $request->user();

        $list = DB::table('ops_rooming_lists')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (!$list) return response()->json(['message' => 'Lista no encontrada.'], 404);

        DB::table('ops_rooming_lists')->where('id', $list->id)->update([
            'status' => 'enviada',
            'sent_at' => now(),
            'sent_by' => $user->id,
            'sent_version' => $list->sent_version + 1,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Rooming list despachada formalmente al hotel.']);
    }
}