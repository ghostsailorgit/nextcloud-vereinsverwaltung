/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Builds a URL below this Nextcloud's root, e.g. absoluteUrl('/apps/verein/clubs').
//
// The page template (templates/main.php) puts the installation's URL prefix on the #app element
// (data-url-root): "" for an install at the domain root with pretty URLs, "/nextcloud/index.php"
// for a subfolder install without them, and so on. Nextcloud's own generateUrl() cannot be used
// here: on this app's page its bootstrap script does not run (Content-Security-Policy nonce
// mismatch), so window.OC and the web root are not available.
export function absoluteUrl(path) {
  const root = document.getElementById('app')?.dataset.urlRoot ?? ''
  return root + (path.startsWith('/') ? path : '/' + path)
}
