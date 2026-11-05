import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get, set } from '@ember/object';
import { next } from '@ember/runloop';

// The order config key (ndd, sdd-same-day-delivery); list rows do not carry meta.delivery_type.
const deliveryType = (order) => order?.type ?? null;
const typeLabel = (type) => (type ? type.split('-')[0].toUpperCase() : 'untyped');

/**
 * Keeps the Fleet-Ops orders selection to one Parcel2U delivery type: once an
 * NDD (or SDD) order is ticked, ticking an order of another type unticks it
 * again, so every bulk action runs on a single type.
 *
 * The shared ember-ui table has no per-row selectability hook, so this reacts
 * to the rows' `checked` flags (set via Ember `set` by the table) instead of
 * patching the table.
 */
export default class Parcel2uOrderSelectionTypeLockComponent extends Component {
    @service notifications;
    @service tableContext;

    lockedType = null;

    get orders() {
        return Array.from(this.tableContext.table?.rows ?? []);
    }

    get checkedOrders() {
        return this.orders.filter((order) => get(order, 'checked'));
    }

    // Autotracked through get(order, 'checked'), so did-update reruns enforce on every tick.
    get selectionKey() {
        return this.checkedOrders.map((order) => order.id).join(',');
    }

    @action
    enforce() {
        const checked = this.checkedOrders;

        if (checked.length === 0) {
            this.lockedType = null;
            return;
        }

        if (!checked.some((order) => deliveryType(order) === this.lockedType)) {
            this.lockedType = deliveryType(checked[0]);
        }

        const mismatched = checked.filter((order) => deliveryType(order) !== this.lockedType);
        if (mismatched.length === 0) return;

        // `checked` was read while rendering; unticking in the same render trips Ember's backtracking assertion.
        next(() => mismatched.forEach((order) => set(order, 'checked', false)));

        this.notifications.warning(`Only ${typeLabel(this.lockedType)} orders can be selected together. Unticked ${mismatched.length} other order${mismatched.length === 1 ? '' : 's'}.`);
    }
}
