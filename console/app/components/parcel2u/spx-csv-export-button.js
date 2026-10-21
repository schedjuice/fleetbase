import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { format } from 'date-fns';

import download from '@fleetbase/ember-core/utils/download';

const PAGE_SIZE = 100;
const MAX_PAGES = 10;

/**
 * "Export SPX CSV" toolbar button for the Fleet-Ops orders index.
 *
 * Rendered from the fleetops-engine orders controller actionButtons
 * (component slot of tabular-actions). Exports the selected orders, or —
 * with an empty selection — the orders matching the current table filters,
 * capped at PAGE_SIZE * MAX_PAGES records.
 */
export default class Parcel2uSpxCsvExportButtonComponent extends Component {
    @service fetch;
    @service notifications;
    @service store;
    @service tableContext;

    @tracked isExporting = false;

    get ordersController() {
        return getOwner(this).lookup('controller:operations/orders/index') ?? null;
    }

    /**
     * Current table filters read off the orders index controller query params.
     */
    get filterParams() {
        const controller = this.ordersController;
        const uiOnlyParams = ['page', 'limit', 'layout', 'drawerOpen', 'drawerTab', 'orderPanelOpen'];
        const params = {};

        for (const key of controller?.queryParams ?? []) {
            if (uiOnlyParams.includes(key)) continue;

            const value = controller[key];

            if (value === undefined || value === null || value === '') continue;

            params[key] = value;
        }

        return params;
    }

    @action
    async exportSpxCsv() {
        if (this.isExporting) return;

        this.isExporting = true;

        try {
            const selections = await this.resolveSelections();

            if (!selections) return;

            await this.downloadCsv(selections);
            this.notifications.success(`Exported ${selections.length} orders to SPX CSV`);
        } catch (error) {
            this.notifications.error(error?.message ?? 'Unable to export SPX booking CSV');
        } finally {
            this.isExporting = false;
        }
    }

    /**
     * Selected row ids, or ids paged from the active filters. Resolves null
     * when the export should abort — the abort toast is raised here.
     */
    async resolveSelections() {
        let selected = [];

        try {
            selected = this.tableContext.getSelectedIds?.() ?? [];
        } catch {
            // no table has registered with the table-context service yet
        }

        if (selected.length > 0) return selected;

        const firstPage = await this.store.query('order', { ...this.filterParams, per_page: PAGE_SIZE, page: 1 });
        const total = Number(firstPage.meta?.total ?? 0);

        if (total > PAGE_SIZE * MAX_PAGES) {
            this.notifications.error('Too many orders — select rows or narrow the filters');

            return null;
        }

        if (total === 0) {
            this.notifications.error('No orders selected to export');

            return null;
        }

        const ids = firstPage.map((order) => order.id);

        for (let page = 2; page <= MAX_PAGES && ids.length < total; page++) {
            const records = await this.store.query('order', { ...this.filterParams, per_page: PAGE_SIZE, page });

            ids.push(...records.map((order) => order.id));
        }

        return ids;
    }

    /**
     * Mirrors fetch.download()'s call shape but checks response.ok first: download()
     * saves the response blob unconditionally, so an error JSON body would be
     * written to disk as a .csv instead of surfaced as a toast.
     */
    async downloadCsv(selections) {
        const response = await fetch(`${this.fetch.host}/${this.fetch.namespace}/orders/spx-booking-export`, {
            method: 'POST',
            headers: this.fetch.getHeaders(),
            credentials: this.fetch.credentials,
            body: JSON.stringify({ selections }),
        });

        if (!response.ok) {
            throw new Error(await this.parseErrorMessage(response));
        }

        const blob = await response.blob();
        const fileName = this.getFileNameFromResponse(response) ?? `spx-booking-${format(new Date(), 'yyyyMMdd')}.csv`;

        download(blob, fileName, 'text/csv');
    }

    async parseErrorMessage(response) {
        try {
            const { message } = await response.json();

            return message ?? `Unable to export SPX booking CSV (HTTP ${response.status})`;
        } catch {
            return `Unable to export SPX booking CSV (HTTP ${response.status})`;
        }
    }

    getFileNameFromResponse(response) {
        const results = /filename=(.*)/.exec(response.headers.get('content-disposition') ?? '');

        return results?.[1]?.replaceAll('"', '') ?? null;
    }
}
