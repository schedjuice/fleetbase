import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const POINTS_PATH = 'parcel2u/spx-dropoff-points';

/**
 * "SPX Drop-off" tab for Parcel2U NDD orders: the driver drops parcels at a
 * tagged SPX drop-off point and SPX delivers to the recipient kept in order
 * meta (recipient_*).
 *
 * Rendered inside the Fleet-Ops engine context, so only services forwarded
 * from the host (@fleetbase/ember-core exports/services) are injected.
 */
export default class Parcel2uOrderSpxDropoffComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked points = [];
    @tracked selectedPointId = null;
    @tracked placeToTag = null;
    @tracked isLoading = false;
    @tracked isSaving = false;

    constructor() {
        super(...arguments);

        if (this.isNdd) {
            this.loadPoints();
        }
    }

    get order() {
        return this.args.order ?? this.args.resource;
    }

    get meta() {
        return this.order?.meta ?? {};
    }

    get isNdd() {
        return this.meta.delivery_type === 'NDD';
    }

    get dropoff() {
        return this.order?.payload?.dropoff;
    }

    get recipient() {
        const meta = this.meta;
        const lines = [meta.recipient_street, meta.recipient_building, meta.recipient_unit_no, meta.recipient_postal_code].filter(Boolean);

        if (!lines.length) return null;

        return { name: meta.recipient_name, phone: meta.recipient_phone, address: lines.join(', ') };
    }

    get selectedPoint() {
        return this.points.find((point) => point.public_id === this.selectedPointId) ?? null;
    }

    @action
    async loadPoints() {
        this.isLoading = true;

        try {
            const { places } = await this.fetch.get(POINTS_PATH);
            this.points = places ?? [];
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to load SPX drop-off points');
        } finally {
            this.isLoading = false;
        }
    }

    @action
    selectPoint(event) {
        this.selectedPointId = event.target.value || null;
    }

    @action
    async saveDropoff() {
        if (!this.selectedPointId || this.isSaving) return;

        this.isSaving = true;

        try {
            await this.fetch.patch(`parcel2u/orders/${this.order.id}/spx-dropoff`, { place: this.selectedPointId });
            this.notifications.success(`Dropoff set to ${this.selectedPoint?.name ?? 'the SPX drop-off point'}`);
            this.selectedPointId = null;
            // The order serializer embeds payload (and its dropoff), so one reload refreshes both.
            await this.order.reload();
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to set the SPX drop-off point');
        } finally {
            this.isSaving = false;
        }
    }

    @action
    async tagPlace() {
        const place = this.placeToTag;
        if (!place) return;

        try {
            await this.fetch.put(`${POINTS_PATH}/${place.public_id ?? place.id}`);
            this.notifications.success(`${place.name ?? 'Place'} is now an SPX drop-off point`);
            this.placeToTag = null;
            await this.loadPoints();
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to add the SPX drop-off point');
        }
    }

    @action
    async untagPoint(point) {
        try {
            await this.fetch.delete(`${POINTS_PATH}/${point.public_id}`);
            this.notifications.success(`${point.name ?? 'Place'} removed from SPX drop-off points`);
            if (this.selectedPointId === point.public_id) {
                this.selectedPointId = null;
            }
            await this.loadPoints();
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to remove the SPX drop-off point');
        }
    }

    @action
    choosePlaceToTag(place) {
        this.placeToTag = place;
    }
}
