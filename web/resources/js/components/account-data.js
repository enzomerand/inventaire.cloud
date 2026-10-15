/**
 * Alpine component of the data card of the account page: downloading everything the application holds about the
 * account (GDPR). The file comes from the API as a blob and is handed to the browser to save. The endpoint comes
 * from the markup (`data-url` on the root), because the CSP build of Alpine does not allow Blade to inject values
 * into expressions.
 */
import { HttpError, http } from '../lib/http.js';
import { notify } from '../lib/notify.js';
import { saveFile } from '../lib/page.js';

/**
 * @returns {Record<string, any>}
 */
export default function accountData() {
    return {
        /** True while the file is being fetched; blocks double clicks. */
        busy: false,

        /** Endpoint of the export (GET), from `data-url` on the component root. */
        get url() {
            return this.$root.dataset.url;
        },

        /**
         * Downloads the export, named after the day like the server names it.
         *
         * @returns {Promise<void>}
         */
        async download() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const file = await http.get(this.url, { responseType: 'blob' });

                saveFile(file, 'inventaire-export-' + new Date().toISOString().slice(0, 10) + '.json');
            } catch (error) {
                // Anything else than an HttpError is a cancelled request: nothing to tell.
                if (error instanceof HttpError && !error.reported) {
                    notify('danger', error.message);
                }
            } finally {
                this.busy = false;
            }
        },
    };
}
