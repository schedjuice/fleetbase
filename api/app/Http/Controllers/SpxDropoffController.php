<?php

namespace App\Http\Controllers;

use App\Services\SpxBookingCsvExport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SPX drop-off points for Parcel2U NDD orders: the driver drops parcels at a
 * tagged Fleet-Ops place and SPX delivers to the recipient recorded in order
 * meta (recipient_*). Everything is scoped to the session company.
 */
class SpxDropoffController extends Controller
{
    public const PLACE_TYPE = 'spx-dropoff';

    private const TERMINAL_STATUSES = ['completed', 'canceled', 'cancelled', 'expired'];

    public function index(): JsonResponse
    {
        $places = $this->places()->where('type', self::PLACE_TYPE)->orderBy('name')->get();

        return response()->json(['places' => $places->map(fn (Place $place) => $this->placeJson($place))->values()]);
    }

    public function tag(string $place): JsonResponse
    {
        return $this->setType($place, self::PLACE_TYPE);
    }

    public function untag(string $place): JsonResponse
    {
        return $this->setType($place, null);
    }

    public function assignToOrder(Request $request, string $order): JsonResponse
    {
        $place = $this->findPlace((string) $request->input('place'));
        if (!$place || $place->type !== self::PLACE_TYPE) {
            return $this->unprocessable('Choose a tagged SPX drop-off point.');
        }

        $model = Order::with(['payload.dropoff'])
            ->where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('public_id', $order)->orWhere('uuid', $order))
            ->first();
        if (!$model || !$model->payload) {
            return $this->unprocessable('Order not found.');
        }

        if (data_get($model->meta, 'delivery_type') !== 'NDD') {
            return $this->unprocessable('Only NDD orders go to an SPX drop-off point.');
        }

        // shortcut: the running fleetops-api (0.6.64) has no OrderEdit, so this mirrors its dropoff lock
        // (terminal statuses only) and skips its distance/driver-push follow-ups; use OrderEdit once on 0.6.74+.
        $status = strtolower((string) $model->status);
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return $this->unprocessable(sprintf('The order is %s and can no longer be changed.', $status));
        }

        // A dropoff that is already an SPX point is not the recipient, so it is never snapshotted.
        $current = $model->payload->dropoff;
        $recipient = $current?->type === self::PLACE_TYPE ? null : $current;

        DB::transaction(function () use ($model, $place, $recipient) {
            $model->meta = SpxBookingCsvExport::withRecipientSnapshot((array) $model->meta, $recipient);
            $model->save();
            $model->payload->setDropoff($place, ['save' => true]);
        });

        return response()->json([
            'dropoff' => $this->placeJson($place),
            'meta'    => $model->meta,
        ]);
    }

    private function setType(string $id, ?string $type): JsonResponse
    {
        $place = $this->findPlace($id);
        if (!$place) {
            return $this->unprocessable('Place not found.');
        }

        $place->update(['type' => $type]);

        return response()->json(['place' => $this->placeJson($place)]);
    }

    private function places()
    {
        return Place::where('company_uuid', session('company'));
    }

    private function findPlace(string $id): ?Place
    {
        if ($id === '') {
            return null;
        }

        return $this->places()->where(fn ($query) => $query->where('public_id', $id)->orWhere('uuid', $id))->first();
    }

    private function placeJson(Place $place): array
    {
        return $place->only(['uuid', 'public_id', 'name', 'type', 'street1', 'street2', 'building', 'city', 'postal_code', 'country', 'phone', 'address']);
    }

    private function unprocessable(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
