import Component from '@glimmer/component';

/**
 * Payment proof tab for Parcel2U orders synced into Fleet-Ops.
 *
 * Rendered inside the Fleet-Ops engine context — avoid injecting services
 * that are not forwarded from the host (see Ledger order-invoice pattern).
 *
 * Reads `meta.payment_proof_url`, legacy `meta.attachments.payment_proof_url`,
 * or order Documents (`files` with type payment_proof).
 */
export default class Parcel2uOrderPaymentProofComponent extends Component {
    get order() {
        return this.args.order ?? this.args.resource;
    }

    get meta() {
        return this.order?.meta ?? {};
    }

    get imageUrl() {
        const meta = this.meta;

        if (typeof meta.payment_proof_url === 'string' && meta.payment_proof_url.trim()) {
            return meta.payment_proof_url;
        }

        const nested = meta.attachments;
        if (nested && typeof nested === 'object' && typeof nested.payment_proof_url === 'string') {
            return nested.payment_proof_url;
        }

        const files = this.order?.files;
        if (Array.isArray(files)) {
            const proof = files.find((file) => file?.type === 'payment_proof');
            if (typeof proof?.url === 'string' && proof.url.trim()) {
                return proof.url;
            }
        }

        return null;
    }

    get hasProof() {
        return Boolean(this.imageUrl);
    }
}
