/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { showConfirmation } from '@nextcloud/dialogs'

/**
 * Asks with Nextcloud's own confirmation dialog (instead of the browser's confirm()) and resolves to true only
 * when the confirm button was pressed - closing the dialog in any other way counts as "no".
 *
 * @param {string} name heading of the dialog
 * @param {string} text the question, with the consequences
 * @param {object} [options]
 * @param {string} [options.labelConfirm] text of the confirm button, name the action (e.g. "Löschen")
 * @param {'info'|'warning'|'error'} [options.severity] 'error' for destructive actions
 * @return {Promise<boolean>}
 */
export async function confirmAction(name, text, { labelConfirm = 'Bestätigen', severity = 'warning' } = {}) {
  try {
    return (await showConfirmation({ name, text, labelConfirm, labelReject: 'Abbrechen', severity })) === true
  } catch (e) {
    return false
  }
}
