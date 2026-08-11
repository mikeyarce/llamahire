/*
 * Bundle the Views hook because LlamaHire supports WordPress versions that do
 * not yet register a wp-views script handle. Its WordPress dependencies remain
 * external and are listed in the generated asset manifest.
 */
export { useView } from '../node_modules/@wordpress/views/build-module/use-view.mjs';
