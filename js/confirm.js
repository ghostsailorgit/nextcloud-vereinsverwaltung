/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { getDialogBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

/**
 * Asks with Nextcloud's own confirmation dialog (instead of the browser's confirm()) and resolves to true only
 * when the confirm button was pressed - closing the dialog in any other way counts as "no".
 *
 * Built with the dialog builder rather than showConfirmation(): that one always shows a blue primary button (so
 * a destructive action did not look like one) and never settles when the dialog is closed with X or Escape.
 *
 * @param {string} name heading of the dialog
 * @param {string} text the question, with the consequences
 * @param {object} [options]
 * @param {string} [options.labelConfirm] text of the confirm button, name the action (e.g. "Löschen")
 * @param {'info'|'warning'|'error'} [options.severity] 'error' for destructive actions (red confirm button)
 * @return {Promise<boolean>}
 */
export async function confirmAction(name, text, { labelConfirm = t('verein', 'Confirm'), severity = 'warning' } = {}) {
  let confirmed = false
  try {
    await getDialogBuilder(name)
      .setText(text)
      .setSeverity(severity)
      .setButtons([
        { label: t('verein', 'Cancel'), variant: 'tertiary', callback: () => {} },
        { label: labelConfirm, variant: severity === 'error' ? 'error' : 'primary', callback: () => { confirmed = true } },
      ])
      .build()
      .show()
  } catch (e) {
    return false
  }
  return confirmed
}
