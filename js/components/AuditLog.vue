<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="audit-log">
    <h2>{{ t('verein', 'Audit log') }}</h2>
    <p class="hint">
      {{ t('verein', 'Who changed what and when. Personal data (name, address, email, IBAN, date of birth, mandate …) never appears here in plain text, only as “changed”. Entries about people are kept for 10 years, everything else for 30 days.') }}
    </p>

    <div class="filters">
      <NcSelect
        :model-value="entityType"
        :options="typeOptions"
        :reduce="o => o.id"
        label="label"
        :input-label="t('verein', 'Area')"
        :clearable="false"
        @update:model-value="setType"
      />
    </div>

    <p v-if="loading && !entries.length">{{ t('verein', 'Loading audit log…') }}</p>
    <p v-else-if="!entries.length" class="empty-state">{{ t('verein', 'No entries.') }}</p>

    <div v-else class="table-scroll">
      <table class="log-table">
        <thead>
          <tr><th>{{ t('verein', 'Time') }}</th><th>{{ t('verein', 'Who') }}</th><th>{{ t('verein', 'What') }}</th><th>{{ t('verein', 'Record') }}</th><th>{{ t('verein', 'Changes') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="e in entries" :key="e.id">
            <td class="nowrap">{{ formatTime(e.createdAt) }}</td>
            <td>{{ e.actorDisplayName || e.actorUserId || t('verein', 'System') }}</td>
            <td>{{ t('verein', '{area} {action}', { area: typeLabel(e.entityType), action: actionLabel(e) }) }}</td>
            <td>{{ subject(e) }}</td>
            <td>
              <ul v-if="changeLines(e).length" class="changes">
                <li v-for="(line, i) in changeLines(e)" :key="i" :class="{ redacted: line.redacted }">
                  <span v-if="line.field" class="field">{{ line.field }}:</span> {{ line.text }}
                </li>
              </ul>
              <span v-else class="hint">–</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="hasMore" class="more">
      <NcButton variant="secondary" :disabled="loading" @click="load(true)">{{ t('verein', 'Load older entries') }}</NcButton>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { t, n } from '@nextcloud/l10n'
import { formatMoney, formatDate as formatDay, formatDateTime } from '../format'
import { api } from '../api'
import { can } from '../store/club'
import { extractErrorMessage } from '../errorMessage'

const TYPES = {
  member: t('verein', 'Person'),
  membership: t('verein', 'Membership'),
  fee: t('verein', 'Fee'),
  fee_run: t('verein', 'Annual fee run'),
  fee_rate: t('verein', 'Fee category'),
  club: t('verein', 'Club'),
  club_account: t('verein', 'Bank account'),
  user_role: t('verein', 'Role assignment'),
  role: t('verein', 'Role')
}

const ACTIONS = {
  create: t('verein', 'created'),
  update: t('verein', 'changed'),
  delete: t('verein', 'deleted'),
  deactivate: t('verein', 'deactivated'),
  activate: t('verein', 'activated'),
  anonymize: t('verein', 'anonymized'),
  mark_paid: t('verein', 'marked as paid'),
  flag_overdue: t('verein', 'marked as overdue'),
  dunning: t('verein', 'reminder sent'),
  dunning_email: t('verein', 'reminders sent by email')
}

const FIELDS = {
  name: t('verein', 'Name'), firstName: t('verein', 'First name'), fullName: t('verein', 'Name'), salutation: t('verein', 'Title'),
  address: t('verein', 'Address'), street: t('verein', 'Street'), postalCode: t('verein', 'Postal code'), city: t('verein', 'City'),
  email: t('verein', 'Email'), iban: 'IBAN', bic: 'BIC', birthDate: t('verein', 'Date of birth'), age: t('verein', 'Age'),
  role: t('verein', 'Position'), joinDate: t('verein', 'Joined'), leaveDate: t('verein', 'Left'), foundingMember: t('verein', 'Founding member'),
  deactivated: t('verein', 'Deactivated'), deceased: t('verein', 'Deceased'), userId: t('verein', 'Nextcloud account'), feeRateId: t('verein', 'Fee category'),
  mandateReference: t('verein', 'Mandate reference'), mandateDate: t('verein', 'Date of signature'), mandateFile: t('verein', 'Mandate file'), amount: t('verein', 'Amount'),
  status: t('verein', 'Status'), dueDate: t('verein', 'Due on'), description: t('verein', 'Comment'), period: t('verein', 'Fee year'), memberId: t('verein', 'Person'),
  isDefault: t('verein', 'default'), label: t('verein', 'Label'), creditorId: t('verein', 'Creditor ID'), permissions: t('verein', 'Permissions'),
  roleId: t('verein', 'Role'), documentsPath: t('verein', 'Team folder'), calendarGroups: t('verein', 'Calendar groups'), paidDate: t('verein', 'Paid on'),
  roleMapping: t('verein', 'Automatic permissions')
}
const DATE_FIELDS = ['joinDate', 'leaveDate', 'dueDate', 'paidDate', 'birthDate', 'mandateDate']

const ROLES = { member: t('verein', 'Member'), treasurer: t('verein', 'Treasurer'), admin: t('verein', 'Board') }
const STATUS = { open: t('verein', 'unpaid'), paid: t('verein', 'paid'), overdue: t('verein', 'overdue'), cancelled: t('verein', 'canceled') }
// technical fields that say nothing to a reader
// (fullName and age are derived from name and birth date and would only repeat them)
const HIDDEN = ['id', 'clubId', 'createdAt', 'updatedAt', 'grantedAt', 'grantedBy', 'membershipYears', 'isFormer',
  'anonymizedAt', 'userDisplayName', 'userExists', 'fullName', 'age']

export default {
  name: 'AuditLog',
  components: { NcButton, NcSelect },
  setup() {
    const entries = ref([])
    const hasMore = ref(false)
    const loading = ref(false)
    const entityType = ref('')
    const safeFields = ref({})
    const names = ref({}) // member id -> name, only if the user may see members

    // role definitions are global (no club) and never appear in a club's log, so they are no filter option
    const typeOptions = [{ id: '', label: t('verein', 'All areas') },
      ...Object.entries(TYPES).filter(([id]) => id !== 'role').map(([id, label]) => ({ id, label }))]

    const load = async (older = false) => {
      loading.value = true
      try {
        const params = {}
        if (entityType.value) params.entityType = entityType.value
        if (older && entries.value.length) params.beforeId = entries.value[entries.value.length - 1].id
        const res = await api.get('audit-log', { params })
        entries.value = older ? [...entries.value, ...res.data.data] : res.data.data
        hasMore.value = !!res.data.hasMore
        safeFields.value = res.data.safeFields || {}
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The audit log could not be loaded')))
      } finally {
        loading.value = false
      }
    }

    const loadNames = async () => {
      if (!can('verein.member.view')) return
      try {
        const res = await api.get('members')
        const map = {}
        for (const m of res.data.members || []) {
          map[m.id] = [m.firstName, m.name].filter(Boolean).join(' ')
        }
        names.value = map
      } catch (e) {
        // names are a convenience; the log is readable without them
      }
    }

    const setType = (value) => {
      entityType.value = value
      load()
    }

    const typeLabel = (type) => TYPES[type] || type
    const actionLabel = (e) => (e.entityType === 'fee_run' && e.action === 'create') ? t('verein', 'carried out') : (ACTIONS[e.action] || e.action)

    const person = (id) => (id ? (names.value[id] || t('verein', 'Person #{id}', { id })) : '')
    const plain = (v) => (v && typeof v === 'object' && 'new' in v ? v.new : v)

    const subject = (e) => {
      const c = e.changes || {}
      switch (e.entityType) {
        case 'member': return person(e.entityId)
        case 'membership': return person(plain(c.memberId))
        case 'fee': return e.entityId ? `${person(plain(c.memberId)) || t('verein', 'Fee')} (#${e.entityId})` : ''
        case 'user_role': return plain(c.userId) || ''
        case 'fee_rate':
        case 'role':
        case 'club': return plain(c.name) || `#${e.entityId}`
        case 'club_account': return plain(c.label) || `#${e.entityId}`
        default: return e.entityId ? `#${e.entityId}` : ''
      }
    }

    const money = formatMoney
    const value = (field, v) => {
      if (v === null || v === undefined || v === '') return t('verein', 'empty')
      if (typeof v === 'boolean') return v ? t('verein', 'yes') : t('verein', 'no')
      if (field === 'role') return ROLES[v] || v
      if (field === 'status') return STATUS[v] || v
      if (field === 'amount' || field === 'total') return money(v)
      if (field === 'memberId') return person(v)
      if (DATE_FIELDS.includes(field)) return formatDate(v)
      if (Array.isArray(v)) return v.length ? v.join(', ') : t('verein', 'none')
      if (typeof v === 'object') return JSON.stringify(v)
      return String(v)
    }

    const changeLines = (e) => {
      const c = e.changes
      if (!c || typeof c !== 'object') return []
      if (e.entityType === 'fee_run') {
        return [{ text: n('verein', '{year}: %n fee, {total} in total, due {date}', '{year}: %n fees, {total} in total, due {date}', c.created, { year: c.year, total: money(c.total || 0), date: formatDate(c.dueDate) }) + (c.prorata ? ', ' + t('verein', 'pro rata') : '') }]
      }
      if (e.action === 'dunning') {
        const levels = Object.entries(c.levels || {}).map(([label, count]) => `${count}× ${label}`).join(', ')
        return [{ text: n('verein', '%n letter', '%n letters', c.letters) + ' ' + n('verein', 'for %n fee, {total} in total', 'for %n fees, {total} in total', c.count, { total: money(c.total || 0) }) + (levels ? ' (' + levels + ')' : '') }]
      }
      if (e.action === 'dunning_email') {
        const parts = [n('verein', '%n email sent', '%n emails sent', c.sent || 0)]
        if (c.failed) parts.push(n('verein', '%n could not be sent', '%n could not be sent', c.failed))
        if (c.withoutEmail) parts.push(n('verein', '%n without an email address', '%n without an email address', c.withoutEmail))
        return [{ text: parts.join(', ') }]
      }
      if (e.action === 'mark_paid' || e.action === 'flag_overdue') {
        return [{ text: n('verein', '%n fee', '%n fees', c.count) }]
      }
      const safe = safeFields.value[e.entityType] // undefined = type is not redacted at all
      const lines = []
      let redactedOnCreate = false
      for (const [field, v] of Object.entries(c)) {
        if (HIDDEN.includes(field)) continue
        const label = FIELDS[field] || field
        const isRedacted = safe && !safe.includes(field) &&
          (v === true || (v && typeof v === 'object' && v.redacted === true))
        if (isRedacted) {
          // on create every personal field is replaced by "true", even the empty ones - listing them one by one
          // would suggest they were filled in, so they collapse into one line; on update each changed field is named
          if (e.action === 'update') {
            lines.push({ field: label, text: t('verein', 'changed (content not logged)'), redacted: true })
          } else {
            redactedOnCreate = true
          }
        } else if (v && typeof v === 'object' && 'old' in v && 'new' in v) {
          lines.push({ field: label, text: `${value(field, v.old)} → ${value(field, v.new)}` })
        } else if (v !== null && v !== '' && !(Array.isArray(v) && !v.length) && !(e.action === 'create' && v === false)) {
          lines.push({ field: label, text: value(field, v) })
        }
      }
      if (redactedOnCreate) {
        lines.push({ field: t('verein', 'Personal details'), text: t('verein', 'recorded (content not logged)'), redacted: true })
      }
      return lines
    }

    const formatDate = (v) => (/^\d{4}-\d{2}-\d{2}/.test(v || '') ? formatDay(String(v).slice(0, 10)) : (v || ''))
    // stored in UTC (PHP's date() under Nextcloud), shown in the browser's local time
    const formatTime = (v) => {
      const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(v || '')
      if (!m) return v
      return formatDateTime(new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5])))
    }

    onMounted(() => {
      loadNames()
      load()
    })

    return { t, entries, hasMore, loading, entityType, typeOptions, load, setType, typeLabel, actionLabel, subject, changeLines, formatTime }
  }
}
</script>

<style scoped>
.audit-log {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
}
.audit-log h2 { margin-top: 0; }
.hint { color: var(--color-text-maxcontrast); margin-bottom: 16px; }
.filters { max-width: 320px; margin-bottom: 12px; }
.log-table { width: 100%; border-collapse: collapse; }
.log-table th, .log-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.nowrap { white-space: nowrap; }
.changes { margin: 0; padding-left: 0; list-style: none; }
.changes .field { color: var(--color-text-maxcontrast); }
.changes .redacted { color: var(--color-text-maxcontrast); font-style: italic; }
.more { margin-top: 12px; }
.empty-state { color: var(--color-text-maxcontrast); }
</style>
