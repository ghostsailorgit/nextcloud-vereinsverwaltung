/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { t } from '@nextcloud/l10n'
import { formatDate } from './format'

// the types a club's own field can have (CustomField::TYPES)
export function fieldTypeOptions() {
  return [
    { id: 'text', label: t('verein', 'Text') },
    { id: 'number', label: t('verein', 'Number') },
    { id: 'date', label: t('verein', 'Date') },
    { id: 'select', label: t('verein', 'Selection') },
    { id: 'checkbox', label: t('verein', 'Yes/no') },
  ]
}

export function fieldTypeLabel(type) {
  return fieldTypeOptions().find(o => o.id === type)?.label || type
}

/** A stored value as a person reads it: dates in the account's format, a checked box as "Yes". */
export function formatFieldValue(type, value) {
  if (value === null || value === undefined || value === '') return '–'
  if (type === 'checkbox') return t('verein', 'Yes')
  if (type === 'date') return formatDate(value)
  return String(value)
}

/** The API sends an empty object as [] (PHP); the form needs a plain object to bind to. */
export function customValues(member) {
  const values = member?.custom
  return values && !Array.isArray(values) ? { ...values } : {}
}
