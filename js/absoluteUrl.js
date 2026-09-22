import { generateUrl } from '@nextcloud/router'

// generateUrl()'s automatic webroot detection (window._oc_webroot) does not
// resolve correctly on this app's page for reasons not fully root-caused
// (see project notes - a Content-Security-Policy nonce mismatch prevents
// Nextcloud's own bootstrap script from running, leaving window._oc_webroot
// unset). Force baseURL explicitly instead. This install is confirmed to be
// at the domain root.
export function absoluteUrl(path, params) {
  return generateUrl(path, params, { baseURL: '' })
}
