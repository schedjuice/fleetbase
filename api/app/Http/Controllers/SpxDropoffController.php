<?php

namespace App\Http\Controllers;

use App\Services\SpxBookingCsvExport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SPX drop-off points for Parcel2U NDD orders: the route runs pickup (cargo
 * shop), then the tagged warehouse place as a waypoint, then the customer as
 * the dropoff. The driver stops at the warehouse and SPX delivers to the
 * customer. Everything is scoped to the session company.
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

        $model = Order::with(['payload.dropoff', 'payload.waypointMarkers'])
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

        // Orders routed before the waypoint fix had their customer dropoff replaced by the SPX point.
        $current        = $model->payload->dropoff;
        $restoreDropoff = $current?->type === self::PLACE_TYPE;
        if ($restoreDropoff && !filled(data_get($model->meta, 'recipient_street')) && !filled(data_get($model->meta, 'recipient_postal_code'))) {
            return $this->unprocessable('The customer address is missing. Set the dropoff back to the customer first.');
        }

        DB::transaction(function () use ($model, $place, $current, $restoreDropoff) {
            $recipient = $restoreDropoff ? $this->recipientPlace((array) $model->meta) : $current;
            if ($restoreDropoff && $recipient) {
                $model->payload->setDropoff($recipient, ['save' => true]);
            }

            $model->meta = SpxBookingCsvExport::withRecipientSnapshot((array) $model->meta, $recipient);
            $model->save();
            // updateWaypoints replaces any earlier SPX point, so re-assigning never stacks stops.
            $model->payload->updateWaypoints([['place_uuid' => $place->uuid, 'type' => 'dropoff']]);
        });

        $model->payload->load('dropoff');

        return response()->json([
            'waypoint' => $this->placeJson($place),
            'dropoff'  => $model->payload->dropoff ? $this->placeJson($model->payload->dropoff) : null,
            'meta'     => $model->meta,
        ]);
    }

    /**
     * The customer place for an order whose dropoff was repointed to an SPX
     * point: reuse the original place when it still matches the recipient_*
     * snapshot, else create it from that snapshot.
     */
    private function recipientPlace(array $meta): ?Place
    {
        $street = trim((string) ($meta['recipient_street'] ?? ''));
        $postal = trim((string) ($meta['recipient_postal_code'] ?? ''));
        if ($street === '' && $postal === '') {
            return null;
        }

        $existing = $this->places()
            ->where('street1', $street)
            ->where('postal_code', $postal)
            ->where(fn ($query) => $query->whereNull('type')->orWhere('type', '!=', self::PLACE_TYPE))
            ->latest()
            ->first();

        return $existing ?? Place::createFromMixed([
            'name'        => trim((string) ($meta['recipient_name'] ?? '')),
            'phone'       => trim((string) ($meta['recipient_phone'] ?? '')),
            'street1'     => $street,
            'street2'     => trim((string) ($meta['recipient_unit_no'] ?? '')),
            'building'    => trim((string) ($meta['recipient_building'] ?? '')),
            'postal_code' => $postal,
            'city'        => 'Singapore',
            'country'     => 'SG',
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
