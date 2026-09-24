import { reactive, computed } from 'vue'
import axios from '@nextcloud/axios'
import { absoluteUrl } from '../absoluteUrl'

const STORAGE_KEY = 'verein.currentClubId'

/**
 * Which club (Verein) the user is currently working in. Everything in the
 * app - members, fees, statistics, exports, roles, SEPA - is scoped to this
 * club; api.js sends its id with every request.
 */
export const clubState = reactive({
  clubs: [],
  currentId: null,
  isAdmin: false,
  loaded: false,
  // self-service record of the person linked to the logged-in account ({ linked: false } if none)
  me: null
})

export const currentClub = computed(() => clubState.clubs.find(c => c.id === clubState.currentId) || null)

/** Whether the user holds the permission in the current club. */
export function can(permission) {
  return !!currentClub.value && currentClub.value.permissions.includes(permission)
}

export function setCurrentClub(id) {
  clubState.currentId = id
  try {
    window.localStorage.setItem(STORAGE_KEY, String(id))
  } catch (e) {
    // storage unavailable - selection just isn't remembered
  }
}

export async function loadMe() {
  try {
    const response = await axios.get(absoluteUrl('/apps/verein/me'))
    clubState.me = response.data
  } catch (e) {
    clubState.me = { linked: false }
  }
}

export async function loadClubs() {
  const response = await axios.get(absoluteUrl('/apps/verein/clubs'))
  clubState.clubs = response.data.clubs || []
  clubState.isAdmin = !!response.data.isAdmin

  let remembered = null
  try {
    remembered = parseInt(window.localStorage.getItem(STORAGE_KEY), 10)
  } catch (e) {
    // ignore
  }
  const stillValid = clubState.clubs.some(c => c.id === (clubState.currentId ?? remembered))
  if (stillValid) {
    clubState.currentId = clubState.currentId ?? remembered
  } else {
    clubState.currentId = clubState.clubs[0]?.id ?? null
  }
  clubState.loaded = true
}
