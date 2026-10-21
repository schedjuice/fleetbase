import Parcel2uSpxCsvExportButtonComponent from '../components/parcel2u/spx-csv-export-button';

/**
 * Registers the "Export SPX CSV" toolbar button component on the HOST
 * application container. The button is referenced by string name from the
 * fleetops-engine orders controller actionButtons (pnpm patch).
 *
 * Engine-side registration happens in wire-engine-dependencies.js: router-
 * mounted engine instances never run the extension-manager boot hooks, so
 * universe.whenEngineLoaded() callbacks do not fire for them.
 *
 * See register-parcel2u-order-tab for the class-not-ExtensionComponent caveat.
 */
export function initialize(appInstance) {
    appInstance.register('component:parcel2u/spx-csv-export-button', Parcel2uSpxCsvExportButtonComponent);
}

export default {
    name: 'register-parcel2u-order-export',
    after: 'setup-extensions',
    initialize,
};
