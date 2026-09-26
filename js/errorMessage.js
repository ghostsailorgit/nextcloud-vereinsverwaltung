/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Extracts a human-readable message from a failed axios request.
// Backend controllers use two different JSON shapes:
//   MemberController/FinanceController: { status: 'error', message, errors: [...] }
//   RoleController (ApiController-based): { error: '...' }
export function extractErrorMessage(error, fallback) {
  const data = error?.response?.data
  if (data?.errors?.length) return data.errors.join(', ')
  if (data?.message) return data.message
  if (data?.error) return data.error
  return error?.message || fallback
}
