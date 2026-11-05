import Component from '@glimmer/component';
import groupParcelsByShop from '../../utils/parcel2u/group-parcels-by-shop';

/**
 * "Cargo Shops" tab for Parcel2U orders: which parcels the driver collects at
 * each cargo shop stop. Rendered inside the Fleet-Ops engine context, so it
 * injects no services and uses no console-only components or helpers.
 */
export default class Parcel2uOrderCargoShopsComponent extends Component {
    get order() {
        return this.args.order ?? this.args.resource;
    }

    get groups() {
        const { shops, unassigned } = groupParcelsByShop(this.order?.payload);
        const groups = shops.map(({ place, parcels }, index) => ({
            title: `Stop ${index + 1}: ${place.name ?? 'Cargo shop'}`,
            address: place.address,
            phone: place.phone,
            parcels: parcels.map(toRow),
            payment: formatShopPayment(parcels),
            unmatched: false,
        }));

        if (unassigned.length) {
            groups.push({ title: 'Not matched to a shop stop', parcels: unassigned.map(toRow), payment: formatShopPayment(unassigned), unmatched: true });
        }

        return groups;
    }
}

function toRow(entity) {
    const weight = entity.weight ? `${entity.weight} ${entity.weight_unit ?? ''}`.trim() : null;

    return { id: entity.public_id ?? entity.id, name: entity.name || 'Parcel', weight, description: entity.description, payment: formatShopPayment([entity]) };
}

/** Sum of `meta.cargo_shop_payment` (integer cents) as money, or null when nothing is owed. */
function formatShopPayment(entities) {
    const cents = entities.reduce((sum, entity) => sum + (Number(entity?.meta?.cargo_shop_payment) || 0), 0);
    if (cents <= 0) return null;

    const currency = entities.find((entity) => entity?.currency)?.currency ?? 'SGD';

    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(cents / 100);
}
