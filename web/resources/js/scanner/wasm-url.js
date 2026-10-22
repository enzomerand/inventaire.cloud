/**
 * The address of the WebAssembly file of the barcode reader.
 *
 * Vite copies the file into the build (`/build/assets/`, with a hash in its name) and hands its address over: the reader
 * is served by this very instance, never by a CDN (the library's own default is jsDelivr). A module of its own, imported
 * only when a scan starts (see `decoder.js`), so that nothing about the reader is in the code every page loads.
 */
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

export default wasmUrl;
