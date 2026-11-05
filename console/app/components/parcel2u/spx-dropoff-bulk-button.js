import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';

/**
 * "Set Warehouse Drop-off point" toolbar dropdown for the Fleet-Ops orders index: applies
 * one tagged SPX drop-off point to every selected order through the per-order
 * endpoint, which rejects non-NDD, terminal and untagged cases itself.
 *
 * Rendered by spx-csv-export-button (the toolbar slot the fleetops-engine
 * patch exposes), so it shares that component's engine context.
 */
export default class Parcel2uSpxDropoffBulkButtonComponent extends Component {
    @service fetch;
    @service notifications;
    @service store;
    @service tableContext;

    @tracked points = [];
    @tracked isLoading = false;
    @tracked isApplying = false;

    @action
    async loadPoints() {
        this.isLoading = true;

        try {
            const { places } = await this.fetch.get('parcel2u/spx-dropoff-points');
            this.points = places ?? [];
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to load SPX drop-off points');
        } finally {
            this.isLoading = false;
        }
    }

    selectedIds() {
        try {
            return this.tableContext.getSelectedIds?.() ?? [];
        } catch {
            // no table has registered with the table-context service yet
            return [];
        }
    }

    @action
    async applyPoint(point) {
        if (this.isApplying) return;

        const ids = this.selectedIds();
        if (ids.length === 0) {
            this.notifications.warning('Select the orders to send to an SPX drop-off point');
            return;
        }

        this.isApplying = true;
        const skipped = [];

        try {
            // Sequential so a large selection does not flood the API.
            for (const id of ids) {
                try {
                    await this.fetch.patch(`parcel2u/orders/${id}/spx-dropoff`, { place: point.public_id });
                } catch (error) {
                    const label = this.store.peekRecord('order', id)?.public_id ?? id;
                    skipped.push(`${label}: ${error?.message ?? 'failed'}`);
                }
            }
        } finally {
            this.isApplying = false;
        }

        const updated = ids.length - skipped.length;
        if (updated > 0) {
            this.notifications.success(`Set ${point.name} as dropoff on ${updated} order${updated === 1 ? '' : 's'}`);
        }
        if (skipped.length > 0) {
            this.notifications.error(`Skipped ${skipped.length}: ${skipped.join('; ')}`);
        }

        getOwner(this).lookup('service:order-actions')?.refresh?.();
    }
}
