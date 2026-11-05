import { MenuItem } from '@fleetbase/ember-core/contracts';

import Parcel2uOrderPaymentProofComponent from '../components/parcel2u/order-payment-proof';
import Parcel2uOrderSpxDropoffComponent from '../components/parcel2u/order-spx-dropoff';
import Parcel2uOrderCargoShopsComponent from '../components/parcel2u/order-cargo-shops';

/**
 * Registers the Parcel2U Fleet-Ops order detail tabs: payment proof images, the
 * SPX drop-off point picker for NDD orders, and parcels grouped by cargo shop.
 *
 * Console-hosted components must be passed as a class — not ExtensionComponent
 * pointing at @fleetbase/fleetops-engine (that re-boots Fleet-Ops from inside
 * Fleet-Ops and triggers the services.store parent-mapping assertion).
 */
export function initialize(appInstance) {
    appInstance.register('component:parcel2u/order-payment-proof', Parcel2uOrderPaymentProofComponent);
    appInstance.register('component:parcel2u/order-spx-dropoff', Parcel2uOrderSpxDropoffComponent);
    appInstance.register('component:parcel2u/order-cargo-shops', Parcel2uOrderCargoShopsComponent);

    const universe = appInstance.lookup('service:universe');
    const menuService = universe.getService('menu-service');

    menuService.registerMenuItem(
        'fleet-ops:component:order:details',
        new MenuItem({
            title: 'Payment Proof',
            route: 'operations.orders.index.details.virtual',
            component: Parcel2uOrderPaymentProofComponent,
            icon: 'receipt',
            slug: 'payment-proof',
            priority: 6,
        })
    );

    menuService.registerMenuItem(
        'fleet-ops:component:order:details',
        new MenuItem({
            title: 'SPX Drop-off',
            route: 'operations.orders.index.details.virtual',
            component: Parcel2uOrderSpxDropoffComponent,
            icon: 'warehouse',
            slug: 'spx-dropoff',
            priority: 7,
        })
    );

    menuService.registerMenuItem(
        'fleet-ops:component:order:details',
        new MenuItem({
            title: 'Cargo Shops',
            route: 'operations.orders.index.details.virtual',
            component: Parcel2uOrderCargoShopsComponent,
            icon: 'store',
            slug: 'cargo-shops',
            priority: 5,
        })
    );
}

export default {
    name: 'register-parcel2u-order-tab',
    after: 'setup-extensions',
    initialize,
};
