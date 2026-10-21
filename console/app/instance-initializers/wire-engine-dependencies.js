import { isArray } from '@ember/array';

import Parcel2uSpxCsvExportButtonComponent from '../components/parcel2u/spx-csv-export-button';

/**
 * Wires declared engine dependencies onto the application instance's engine
 * builder, so router-mounted engines (router.js this.mount(...)) boot with
 * their host-service mappings.
 *
 * The universe extension-manager installs this same wiring on the object its
 * #getApplication() resolves, which can be the Application rather than the
 * running instance — engines built through the router then boot with no
 * `dependencies` mappings and fail the "A dependency mapping for
 * 'services.store' must be declared on this engine's parent" assertion.
 * Mirroring the wiring on the instance covers every build path.
 *
 * The same wrapper hands host component classes to engine code: router-built
 * engine instances never run the extension-manager boot hooks (so
 * universe.whenEngineLoaded callbacks do not fire), and the engine container
 * cannot instantiate host-registered component classes, so the class travels
 * through a reopened engine controller property that the patched engine
 * template descriptor reads (the class-not-string tabular-actions slot).
 */
export function initialize(appInstance) {
    if (appInstance._engineDependenciesWired) return;
    appInstance._engineDependenciesWired = true;

    const originalBuildChildEngineInstance = appInstance.buildChildEngineInstance;

    appInstance.buildChildEngineInstance = function (name, options) {
        const engineInstance = originalBuildChildEngineInstance.call(this, name, options);
        const declared = engineInstance.base?.dependencies;

        if (isArray(declared?.services) && !engineInstance.dependencies?.services) {
            const services = {};

            for (const declaredService of declared.services) {
                let dependencyName = declaredService;
                let hostName = declaredService;

                if (typeof declaredService === 'object') {
                    dependencyName = Object.keys(declaredService)[0];
                    hostName = declaredService[dependencyName];
                }

                // hostRouter aliases the host router service (see universe
                // extension-manager's setupEngineParentDependenciesBeforeBoot).
                const hostServiceName = hostName === 'hostRouter' ? 'router' : hostName;

                services[dependencyName] = appInstance.lookup(`service:${hostServiceName}`) ?? hostName;
            }

            const externalRoutes = {};
            if (isArray(declared.externalRoutes)) {
                for (const externalRoute of declared.externalRoutes) {
                    externalRoutes[externalRoute] = externalRoute;
                }
            }

            engineInstance.dependencies = {
                ...engineInstance.dependencies,
                services,
                externalRoutes: Object.keys(externalRoutes).length > 0 ? externalRoutes : (engineInstance.dependencies?.externalRoutes ?? {}),
            };
        }

        if (name === '@fleetbase/fleetops-engine') {
            engineInstance.boot().then(() => {
                const ordersIndexController = engineInstance.resolveRegistration('controller:operations/orders/index');

                // Adding a new property is the supported reopen case — the
                // patched actionButtons getter reads it as a component class.
                ordersIndexController?.reopen?.({
                    spxBookingCsvExportButtonComponent: Parcel2uSpxCsvExportButtonComponent,
                });
            });
        }

        return engineInstance;
    };
}

export default {
    name: 'wire-engine-dependencies',
    before: 'load-extensions',
    initialize,
};
