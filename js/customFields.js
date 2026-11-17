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
    { id: 'multiselect', label: t('verein', 'Multiple selection') },
    { id: 'checkbox', label: t('verein', 'Yes/no') },
  ]
}

export function fieldTypeLabel(type) {
  return fieldTypeOptions().find(o => o.id === type)?.label || type
}

// types whose value is one or several of the field's choices (CustomField::CHOICE_TYPES)
export const CHOICE_TYPES = ['select', 'multiselect']

/**
 * The choices of a multiple selection, stored as a JSON list (CustomField::valueList()); a value that is no list,
 * e.g. kept from the time the field was a single selection, is one choice.
 */
export function valueList(value) {
  if (Array.isArray(value)) return value
  if (value === null || value === undefined || String(value).trim() === '') return []
  try {
    const list = JSON.parse(value)
    if (Array.isArray(list)) return list.map(String).filter(v => v.trim() !== '')
  } catch (e) {
    // not JSON: a single choice
  }
  return [String(value)]
}

/** A stored value as a person reads it: dates in the account's format, a checked box as "Yes", several choices separated by commas. */
export function formatFieldValue(type, value) {
  if (value === null || value === undefined || value === '') return '–'
  if (type === 'checkbox') return t('verein', 'Yes')
  if (type === 'date') return formatDate(value)
  if (type === 'multiselect') return valueList(value).join(', ') || '–'
  return String(value)
}

/**
 * The conditions the member list can filter an own field by: filled in or not, each choice of a selection,
 * yes/no for a checkbox, and "contains" (with a text) for the other types.
 */
export function fieldFilterOptions(field) {
  if (!field) return []
  if (field.type === 'checkbox') {
    return [
      { id: 'yes', label: t('verein', 'Yes') },
      { id: 'no', label: t('verein', 'No') },
    ]
  }
  const options = [
    { id: 'filled', label: t('verein', 'Filled in') },
    { id: 'empty', label: t('verein', 'Not filled in') },
  ]
  if (CHOICE_TYPES.includes(field.type)) {
    return options.concat((field.options || []).map(o => ({ id: 'is:' + o, label: o })))
  }
  return options.concat([{ id: 'contains', label: t('verein', 'Contains …') }])
}

/** Whether a member's value of the field meets a condition from fieldFilterOptions() (no condition = every member). */
export function matchesFieldFilter(member, field, condition, text = '') {
  if (!field || !condition) return true
  const raw = (member.custom && !Array.isArray(member.custom) ? member.custom : {})[field.id]
  const filled = raw !== null && raw !== undefined && raw !== '' && (field.type !== 'multiselect' || valueList(raw).length > 0)
  if (condition === 'yes') return filled
  if (condition === 'no' || condition === 'empty') return !filled
  if (condition === 'filled') return filled
  if (condition.startsWith('is:')) {
    const choice = condition.slice(3).toLowerCase()
    return filled && valueList(field.type === 'multiselect' ? raw : [raw]).some(v => String(v).toLowerCase() === choice)
  }
  if (condition === 'contains') {
    const needle = String(text || '').trim().toLowerCase()
    if (!needle) return true
    // dates are compared as the user reads them and as stored (YYYY-MM-DD), so "2026" and "03.2026" both work
    const shown = formatFieldValue(field.type, raw).toLowerCase()
    return filled && (shown.includes(needle) || String(raw).toLowerCase().includes(needle))
  }
  return true
}

/** The API sends an empty object as [] (PHP); the form needs a plain object to bind to. */
export function customValues(member) {
  const values = member?.custom
  return values && !Array.isArray(values) ? { ...values } : {}
}
