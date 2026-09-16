import { MenuItem } from '@fleetbase/ember-core/contracts';

import Parcel2uOrderPaymentProofComponent from '../components/parcel2u/order-payment-proof';

/**
 * Registers a Fleet-Ops order detail tab that renders Parcel2U payment proof images.
 *
 * Console-hosted components must be passed as a class — not ExtensionComponent
 * pointing at @fleetbase/fleetops-engine (that re-boots Fleet-Ops from inside
 * Fleet-Ops and triggers the services.store parent-mapping assertion).
 */
export function initialize(appInstance) {
    appInstance.register('component:parcel2u/order-payment-proof', Parcel2uOrderPaymentProofComponent);

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
        }),
    );
}

export default {
    name: 'register-parcel2u-order-tab',
    after: 'setup-extensions',
    initialize,
};
