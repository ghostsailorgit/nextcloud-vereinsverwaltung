import axios from '@nextcloud/axios'
import { absoluteUrl } from './absoluteUrl'
import { clubState } from './store/club'

const instance = axios.create({
  baseURL: absoluteUrl('/apps/verein/'),
  withCredentials: true,
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded'
  }
})

// Transform plain objects to URL-encoded payloads for Nextcloud controllers,
// and tell the backend which club the request is about (everything except
// the club administration itself is scoped to the currently selected club)
instance.interceptors.request.use(config => {
  const clubId = clubState.currentId
  if (clubId && !/^clubs(\/|$)/.test(config.url || '')) {
    const method = (config.method || 'get').toLowerCase()
    if (method === 'get' || method === 'delete') {
      config.params = { ...config.params, clubId }
    } else {
      config.data = { ...(config.data && typeof config.data === 'object' ? config.data : {}), clubId }
    }
  }
  if (config.data && typeof config.data === 'object' && !FormData.prototype.isPrototypeOf(config.data)) {
    const params = new URLSearchParams()
    for (const [key, value] of Object.entries(config.data)) {
      params.append(key, value ?? '')
    }
    config.data = params
  }
  return config
})

// Add error handler
instance.interceptors.response.use(
  response => response,
  error => {
    console.error('API Error:', error.response?.data || error.message)
    return Promise.reject(error)
  }
)

export const api = {
  // Members
  getMembers() {
    return instance.get('members')
  },
  getMember(id) {
    return instance.get(`members/${id}`)
  },
  createMember(data) {
    return instance.post('members', data)
  },
  updateMember(id, data) {
    return instance.put(`members/${id}`, data)
  },
  deleteMember(id) {
    return instance.delete(`members/${id}`)
  },

  // Statistics
  getMemberStatistics() {
    return instance.get('statistics/members')
  },
  getFeeStatistics() {
    return instance.get('statistics/fees')
  },

  // Clubs (club administration - not scoped to the selected club)
  getClubs() {
    return instance.get('clubs')
  },
  createClub(data) {
    return instance.post('clubs', data)
  },
  updateClub(id, data) {
    return instance.put(`clubs/${id}`, data)
  },
  deleteClub(id) {
    return instance.delete(`clubs/${id}`)
  },
  createClubAccount(clubId, data) {
    return instance.post(`clubs/${clubId}/accounts`, data)
  },
  updateClubAccount(clubId, accountId, data) {
    return instance.put(`clubs/${clubId}/accounts/${accountId}`, data)
  },
  deleteClubAccount(clubId, accountId) {
    return instance.delete(`clubs/${clubId}/accounts/${accountId}`)
  },

  // Fees
  getFees() {
    return instance.get('finance')
  },
  getFee(id) {
    return instance.get(`finance/${id}`)
  },
  createFee(data) {
    return instance.post('finance', data)
  },
  updateFee(id, data) {
    return instance.put(`finance/${id}`, data)
  },
  deleteFee(id) {
    return instance.delete(`finance/${id}`)
  },

  // Generic methods for component flexibility
  get(endpoint) {
    return instance.get(endpoint)
  },
  post(endpoint, data) {
    return instance.post(endpoint, data)
  },
  put(endpoint, data) {
    return instance.put(endpoint, data)
  },
  delete(endpoint) {
    return instance.delete(endpoint)
  }
}

export default api
