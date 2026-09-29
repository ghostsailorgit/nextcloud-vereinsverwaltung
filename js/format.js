/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/*
 * Numbers, amounts and dates in the reader's locale (Nextcloud's locale setting of the account, e.g. en-US, en-GB,
 * de-DE) - never a fixed format in the text. Amounts are always euros (SEPA).
 */
import { getCanonicalLocale, t } from '@nextcloud/l10n'

const locale = () => {
  try {
    return getCanonicalLocale() || undefined
  } catch (e) {
    return undefined
  }
}

/** 1234.5 -> "€1,234.50" (en-US) / "1.234,50 €" (de-DE) */
export const formatMoney = (value) => {
  const n = Number(value)
  if (value === null || value === undefined || value === '' || isNaN(n)) return '–'
  return new Intl.NumberFormat(locale(), { style: 'currency', currency: 'EUR' }).format(n)
}

/** 1234.5 -> "1,234.5" / "1.234,5" */
export const formatNumber = (value, fractionDigits) => {
  const n = Number(value)
  if (isNaN(n)) return '–'
  const options = fractionDigits === undefined ? {} : { minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits }
  return new Intl.NumberFormat(locale(), options).format(n)
}

// "2026-12-01" or "2026-12-01 00:00:00": a calendar day - read it as local midnight, not UTC, or it can show the day before
const toDate = (value) => {
  if (value instanceof Date) return value
  const s = String(value)
  const day = s.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (day) return new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
  return new Date(s.replace(' ', 'T'))
}

/** "2026-12-01" -> "12/1/2026" (en-US) / "1.12.2026" (de-DE); empty -> "–" */
export const formatDate = (value, options = { day: 'numeric', month: 'numeric', year: 'numeric' }) => {
  if (!value) return '–'
  const d = toDate(value)
  return isNaN(d) ? String(value) : d.toLocaleDateString(locale(), options)
}

/** date and time, e.g. for a Unix timestamp in seconds or a stored timestamp */
export const formatDateTime = (value) => {
  if (value === null || value === undefined || value === '') return '–'
  const d = typeof value === 'number' ? new Date(value * 1000) : toDate(value)
  return isNaN(d) ? String(value) : d.toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' })
}

/** the stored title (internal id, German) as the reader's label */
export const salutationLabel = (value) => ({
  Herr: t('verein', 'Mr'),
  Frau: t('verein', 'Ms'),
  Divers: t('verein', 'Mx'),
  Firma: t('verein', 'Company'),
}[value] || value || '')
