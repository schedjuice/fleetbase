import ArrayProxy from '@ember/array/proxy';
import { A } from '@ember/array';

/**
 * TEMPORARY diagnostics + verification scaffolding. Remove after verification.
 *
 * 1. Exposes the running application instance on window for live registry
 *    probes, records uncaught errors to localStorage.
 * 2. Stubs store.query('order') with fake records so the Fleet-Ops orders
 *    index renders while the parallel backend work has GET /int/v1/orders
 *    returning 500 HTML. Configure via window.__spxFakeOrders.
 */
export function initialize(appInstance) {
    window.__fleetbaseAppInstance = appInstance;

    const record = (message) => {
        try {
            const errors = JSON.parse(localStorage.getItem('zcode-boot-errors') ?? '[]');
            errors.push(String(message).slice(0, 1500));
            localStorage.setItem('zcode-boot-errors', JSON.stringify(errors.slice(-5)));
        } catch {
            // diagnostics only — never throw from an error handler
        }
    };

    window.addEventListener('error', (event) => record(event.message));
    window.addEventListener('unhandledrejection', (event) => record(event.reason?.stack ?? event.reason));

    const store = appInstance.lookup('service:store');
    const originalQuery = store.query.bind(store);

    store.query = function (modelName, params) {
        if (modelName !== 'order' || window.__spxStubEnabled === false) {
            return originalQuery(modelName, params);
        }

        const fake = window.__spxFakeOrders ?? { total: 2, ids: ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'] };
        const records = fake.ids.map((id, index) => ({
            id,
            public_id: `P2U-${1000 + index}`,
            status: 'created',
            type: 'default',
            item_count: 1,
        }));

        const proxy = ArrayProxy.create({ content: A(records) });
        proxy.set('meta', { total: fake.total, per_page: 100, current_page: Number(params?.page ?? 1), last_page: 1 });

        return proxy;
    };
}

export default {
    name: 'zcode-capture-boot-errors',
    initialize,
};
