/**
 * Groups a Parcel2U payload's parcels under the cargo shop they are collected
 * from. Shops are the pickup place followed by `pickup` waypoints, in stop
 * order. The order sync tags each entity with `meta.pickup_place_id`, the
 * shop place's public id. An untagged entity belongs to the pickup, the same
 * default the sync uses. A tag naming no current stop goes to `unassigned`,
 * so a rerouted order never hides a parcel.
 *
 * @returns {{ shops: Array<{ place: object, parcels: object[] }>, unassigned: object[] }}
 */
export default function groupParcelsByShop(payload) {
    const pickup = payload?.pickup ?? null;
    const waypoints = Array.from(payload?.waypoints ?? []).filter((waypoint) => waypoint?.type === 'pickup');
    const shops = [pickup, ...waypoints].filter(Boolean).map((place) => ({ place, parcels: [] }));
    const shopByPlaceId = new Map(shops.map((shop) => [shop.place.public_id, shop]));
    const unassigned = [];

    for (const entity of Array.from(payload?.entities ?? [])) {
        const placeId = entity?.meta?.pickup_place_id ?? pickup?.public_id;
        const shop = shopByPlaceId.get(placeId);
        if (shop) {
            shop.parcels.push(entity);
        } else {
            unassigned.push(entity);
        }
    }

    return { shops, unassigned };
}
