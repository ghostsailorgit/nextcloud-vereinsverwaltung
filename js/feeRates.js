/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { t } from '@nextcloud/l10n'
import { formatDate, formatMoney } from './format'

export const hasAgeTiers = (rate) => Array.isArray(rate.ageTiers) && rate.ageTiers.length > 0

/** the day a category's age counts on, in the reader's date format */
export const ageCutoffLabel = (rate) => formatDate(`2024-${rate.ageCutoff || '12-31'}`, { day: 'numeric', month: 'numeric' })

/** one text for all tiers of a category: age 0-5, 6-13, from 18 */
export const ageTierSummary = (rate) => rate.ageTiers.map((tier, index) => {
  const next = rate.ageTiers[index + 1]
  const amount = formatMoney(tier.amount)
  return next
    ? t('verein', '{from}–{to}: {amount}', { from: tier.fromAge, to: next.fromAge - 1, amount })
    : t('verein', 'from {age}: {amount}', { age: tier.fromAge, amount })
}).join(' · ')
