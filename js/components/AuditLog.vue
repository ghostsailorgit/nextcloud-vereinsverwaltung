<template>
  <div class="audit-log">
    <h2>Änderungsprotokoll</h2>
    <p class="hint">
      Wer hat wann was geändert. Personenbezogene Angaben (Name, Anschrift, E-Mail, IBAN, Geburtsdatum, Mandat …)
      stehen hier nie im Klartext, sondern nur als „geändert“. Einträge zu Personen werden 10 Jahre aufbewahrt,
      alles andere 30 Tage.
    </p>

    <div class="filters">
      <NcSelect
        :model-value="entityType"
        :options="typeOptions"
        :reduce="o => o.id"
        label="label"
        input-label="Bereich"
        :clearable="false"
        @update:model-value="setType"
      />
    </div>

    <p v-if="loading && !entries.length">Lade Protokoll…</p>
    <p v-else-if="!entries.length" class="empty-state">Keine Einträge.</p>

    <table v-else class="log-table">
      <thead>
        <tr><th>Zeitpunkt</th><th>Wer</th><th>Was</th><th>Betrifft</th><th>Änderungen</th></tr>
      </thead>
      <tbody>
        <tr v-for="e in entries" :key="e.id">
          <td class="nowrap">{{ formatTime(e.createdAt) }}</td>
          <td>{{ e.actorDisplayName || e.actorUserId || 'System' }}</td>
          <td>{{ typeLabel(e.entityType) }} {{ actionLabel(e) }}</td>
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

    <div v-if="hasMore" class="more">
      <NcButton variant="secondary" :disabled="loading" @click="load(true)">Ältere Einträge laden</NcButton>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { api } from '../api'
import { can } from '../store/club'
import { extractErrorMessage } from '../errorMessage'

const TYPES = {
  member: 'Person',
  membership: 'Mitgliedschaft',
  fee: 'Beitrag',
  fee_run: 'Beitragslauf',
  fee_rate: 'Beitragskategorie',
  club: 'Verein',
  club_account: 'Bankkonto',
  user_role: 'Rollenzuweisung',
  role: 'Rolle'
}

const ACTIONS = {
  create: 'angelegt',
  update: 'geändert',
  delete: 'gelöscht',
  deactivate: 'deaktiviert',
  activate: 'aktiviert',
  anonymize: 'anonymisiert',
  mark_paid: 'als bezahlt markiert',
  flag_overdue: 'als überfällig markiert',
  dunning: 'gemahnt'
}

const FIELDS = {
  name: 'Name', firstName: 'Vorname', fullName: 'Name', salutation: 'Anrede', address: 'Adresse', street: 'Straße',
  postalCode: 'PLZ', city: 'Ort', email: 'E-Mail', iban: 'IBAN', bic: 'BIC', birthDate: 'Geburtsdatum', age: 'Alter',
  role: 'Funktion', joinDate: 'Eintritt', leaveDate: 'Austritt', foundingMember: 'Gründungsmitglied',
  deactivated: 'Deaktiviert', deceased: 'Verstorben', userId: 'Nextcloud-Konto', feeRateId: 'Beitragskategorie',
  mandateReference: 'Mandatsreferenz', mandateDate: 'Mandatsdatum', mandateFile: 'Mandatsdatei', amount: 'Betrag',
  status: 'Status', dueDate: 'Fällig am', description: 'Bemerkung', period: 'Beitragsjahr', memberId: 'Person',
  isDefault: 'Standard', label: 'Bezeichnung', creditorId: 'Gläubiger-ID', permissions: 'Berechtigungen',
  roleId: 'Rolle', documentsPath: 'Team-Ordner', calendarGroups: 'Kalendergruppen', paidDate: 'Bezahlt am',
  roleMapping: 'Automatische Rechte'
}
const DATE_FIELDS = ['joinDate', 'leaveDate', 'dueDate', 'paidDate', 'birthDate', 'mandateDate']

const ROLES = { member: 'Mitglied', treasurer: 'Kassierer', admin: 'Vorstand' }
const STATUS = { open: 'offen', paid: 'bezahlt', overdue: 'überfällig', cancelled: 'storniert' }
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
    const typeOptions = [{ id: '', label: 'Alle Bereiche' },
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
        showError(extractErrorMessage(error, 'Protokoll konnte nicht geladen werden'))
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

    const typeLabel = (t) => TYPES[t] || t
    const actionLabel = (e) => (e.entityType === 'fee_run' && e.action === 'create') ? 'ausgeführt' : (ACTIONS[e.action] || e.action)

    const person = (id) => (id ? (names.value[id] || `Person #${id}`) : '')
    const plain = (v) => (v && typeof v === 'object' && 'new' in v ? v.new : v)

    const subject = (e) => {
      const c = e.changes || {}
      switch (e.entityType) {
        case 'member': return person(e.entityId)
        case 'membership': return person(plain(c.memberId))
        case 'fee': return e.entityId ? `${person(plain(c.memberId)) || 'Beitrag'} (#${e.entityId})` : ''
        case 'user_role': return plain(c.userId) || ''
        case 'fee_rate':
        case 'role':
        case 'club': return plain(c.name) || `#${e.entityId}`
        case 'club_account': return plain(c.label) || `#${e.entityId}`
        default: return e.entityId ? `#${e.entityId}` : ''
      }
    }

    const money = (v) => Number(v).toFixed(2).replace('.', ',') + ' €'
    const value = (field, v) => {
      if (v === null || v === undefined || v === '') return 'leer'
      if (typeof v === 'boolean') return v ? 'ja' : 'nein'
      if (field === 'role') return ROLES[v] || v
      if (field === 'status') return STATUS[v] || v
      if (field === 'amount' || field === 'total') return money(v)
      if (field === 'memberId') return person(v)
      if (DATE_FIELDS.includes(field)) return formatDate(v)
      if (Array.isArray(v)) return v.length ? v.join(', ') : 'keine'
      if (typeof v === 'object') return JSON.stringify(v)
      return String(v)
    }

    const changeLines = (e) => {
      const c = e.changes
      if (!c || typeof c !== 'object') return []
      if (e.entityType === 'fee_run') {
        return [{ text: `${c.year}: ${c.created} Beiträge, zusammen ${money(c.total || 0)}, fällig ${formatDate(c.dueDate)}${c.prorata ? ', anteilig' : ''}` }]
      }
      if (e.action === 'dunning') {
        const levels = Object.entries(c.levels || {}).map(([label, n]) => `${n}× ${label}`).join(', ')
        return [{ text: `${c.letters} Schreiben für ${c.count} Beiträge, zusammen ${money(c.total || 0)}${levels ? ' (' + levels + ')' : ''}` }]
      }
      if (e.action === 'mark_paid' || e.action === 'flag_overdue') {
        return [{ text: `${c.count} Beiträge` }]
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
            lines.push({ field: label, text: 'geändert (Inhalt nicht protokolliert)', redacted: true })
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
        lines.push({ field: 'Personenangaben', text: 'erfasst (Inhalt nicht protokolliert)', redacted: true })
      }
      return lines
    }

    const formatDate = (v) => {
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(v || '')
      return m ? `${m[3]}.${m[2]}.${m[1]}` : (v || '')
    }
    const formatTime = (v) => {
      const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(v || '')
      return m ? `${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]}` : v
    }

    onMounted(() => {
      loadNames()
      load()
    })

    return { entries, hasMore, loading, entityType, typeOptions, load, setType, typeLabel, actionLabel, subject, changeLines, formatTime }
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
.hint { color: var(--color-text-maxcontrast); }
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
