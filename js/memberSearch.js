/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// lower case without accents, so "muller" finds "Müller"; "ß" has no decomposition of its own
const normalize = (text) => String(text ?? '')
  .toLowerCase()
  .replace(/ß/g, 'ss')
  .normalize('NFD')
  .replace(/\p{Diacritic}/gu, '')

/**
 * Whether a member matches what was typed into the list's search box: every word has to occur somewhere,
 * in any order ("anna muster", "muster 66111"). Phone numbers also match without their blanks and slashes.
 */
export function matchesMemberSearch(member, query) {
  const words = normalize(query).split(/\s+/).filter(Boolean)
  if (words.length === 0) return true
  const phone = String(member.phone ?? '')
  const text = normalize([
    member.id, member.firstName, member.name, member.email, phone, phone.replace(/\D/g, ''),
    member.street, member.postalCode, member.city
  ].join(' '))
  return words.every(word => text.includes(word))
}
