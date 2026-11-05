import { module, test } from 'qunit';
import groupParcelsByShop from '@fleetbase/console/utils/parcel2u/group-parcels-by-shop';

const place = (public_id, extra = {}) => ({ public_id, name: public_id, ...extra });
const parcel = (name, pickup_place_id) => ({ name, meta: pickup_place_id ? { pickup_place_id } : {} });
const names = (parcels) => parcels.map((p) => p.name);

module('Unit | Utility | parcel2u/group-parcels-by-shop', function () {
    test('groups parcels under the pickup and each pickup waypoint, in stop order', function (assert) {
        const { shops, unassigned } = groupParcelsByShop({
            pickup: place('place_a'),
            waypoints: [place('place_b', { type: 'pickup' }), place('place_spx', { type: 'dropoff' }), place('place_c', { type: 'pickup' })],
            entities: [parcel('A1', 'place_a'), parcel('B1', 'place_b'), parcel('C1', 'place_c'), parcel('B2', 'place_b')],
        });

        assert.deepEqual(
            shops.map((shop) => shop.place.public_id),
            ['place_a', 'place_b', 'place_c'],
            'non-pickup waypoints are not shops'
        );
        assert.deepEqual(
            shops.map((shop) => names(shop.parcels)),
            [['A1'], ['B1', 'B2'], ['C1']]
        );
        assert.deepEqual(unassigned, []);
    });

    test('untagged parcels from orders synced before shop tagging fall back to the pickup', function (assert) {
        const { shops, unassigned } = groupParcelsByShop({
            pickup: place('place_a'),
            entities: [{ name: 'Old', meta: null }, parcel('Untagged')],
        });

        assert.deepEqual(names(shops[0].parcels), ['Old', 'Untagged']);
        assert.deepEqual(unassigned, []);
    });

    test('a parcel tagged with a shop no longer on the route is surfaced, not dropped', function (assert) {
        const { shops, unassigned } = groupParcelsByShop({
            pickup: place('place_a'),
            entities: [parcel('A1', 'place_a'), parcel('Gone', 'place_removed')],
        });

        assert.deepEqual(names(shops[0].parcels), ['A1']);
        assert.deepEqual(names(unassigned), ['Gone']);
    });

    test('a payload without a pickup has no shops and keeps every parcel visible', function (assert) {
        assert.deepEqual(groupParcelsByShop(undefined), { shops: [], unassigned: [] });

        const { shops, unassigned } = groupParcelsByShop({ pickup: null, entities: [parcel('Loose')] });
        assert.deepEqual(shops, []);
        assert.deepEqual(names(unassigned), ['Loose']);
    });
});
