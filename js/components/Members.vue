<template>
  <div class="members-container" :class="{ 'no-form': !canManage }">
    <!-- Alert Komponente -->
    <Alert
      ref="alertRef"
      type="error"
      :message="alertError"
      :errors="alertErrors"
    />

    <!-- Form für neues/zu bearbeitendes Mitglied -->
    <div v-if="canManage" class="form-section">
      <h2>{{ editingId ? 'Mitglied bearbeiten' : 'Neues Mitglied hinzufügen' }}</h2>

      <!-- Add a person who is already a member of another club (no duplicate) -->
      <div v-if="!editingId" class="lookup-box">
        <h3 class="form-subheader">Person aus einem anderen Verein übernehmen</h3>
        <NcTextField
          :model-value="lookupQuery"
          @update:model-value="onLookupInput"
          type="text"
          label="Name suchen"
          placeholder="Nachname oder Vorname"
        />
        <label class="date-field">
          <span>Eintrittsdatum in diesen Verein</span>
          <input v-model="lookupJoinDate" type="date" class="form-input" />
        </label>
        <ul v-if="lookupResults.length" class="lookup-results">
          <li v-for="r in lookupResults" :key="r.id">
            <span>{{ r.fullName }} <small>({{ r.birthDate || 'kein Geburtsdatum' }}, {{ r.city || 'kein Ort' }})</small></span>
            <NcButton variant="secondary" :disabled="loading" @click="attachExisting(r)">Übernehmen</NcButton>
          </li>
        </ul>
        <p v-else-if="lookupQuery.trim().length >= 2 && lookupDone" class="hint">
          Keine passende Person in deinen anderen Vereinen gefunden.
        </p>
      </div>

      <form @submit.prevent="saveMember" class="member-form">
        <h3 class="form-subheader">Persönliche Daten</h3>
        <NcSelect
          v-model="formData.salutation"
          :options="salutationOptions"
          input-label="Anrede"
          placeholder="-- wählen --"
        />
        <NcTextField
          :model-value="formData.firstName"
          @update:model-value="formData.firstName = $event"
          type="text"
          label="Vorname"
          placeholder="Max"
        />
        <NcTextField
          :model-value="formData.name"
          @update:model-value="formData.name = $event"
          type="text"
          label="Name"
          placeholder="Mustermann"
          required
        />
        <label class="date-field">
          <span>Geburtsdatum</span>
          <input v-model="formData.birthDate" type="date" class="form-input" />
        </label>

        <h3 class="form-subheader">Adresse</h3>
        <NcTextField
          :model-value="formData.street"
          @update:model-value="formData.street = $event"
          type="text"
          label="Straße"
        />
        <NcTextField
          :model-value="formData.postalCode"
          @update:model-value="formData.postalCode = $event"
          type="text"
          label="PLZ"
        />
        <NcTextField
          :model-value="formData.city"
          @update:model-value="formData.city = $event"
          type="text"
          label="Ort"
        />
        <NcTextField
          :model-value="formData.email"
          @update:model-value="formData.email = $event"
          type="email"
          label="E-Mail"
          placeholder="max@example.com (optional)"
        />

        <div class="account-link">
          <NcSelectUsers
            v-model="selectedUser"
            :options="userOptions"
            input-label="Verknüpftes Nextcloud-Konto"
            :disabled="!canManageRoles"
            placeholder="Name oder Benutzername eingeben"
            @search="onUserSearch"
            @update:model-value="onUserPicked"
          />
          <p class="hint">
            Optional. Verknüpft dieses Mitglied mit seinem Nextcloud-Login (z. B. Vorstandsmitglieder). Damit kann es unter „Meine Daten“ seine Daten einsehen; Vereinsrolle und Konto bestimmen zusammen die automatischen Rechte.
            <strong v-if="!canManageRoles">Rolle und Konto ändern darf nur, wer Rollen verwalten darf.</strong>
            <span v-if="!formData.userId && userOptions.length">Vorschläge nach Namen stehen im Dropdown.</span>
          </p>
        </div>

        <h3 class="form-subheader">Mitgliedschaft</h3>
        <label class="date-field">
          <span>Eintrittsdatum</span>
          <input v-model="formData.joinDate" type="date" class="form-input" />
        </label>
        <label class="date-field">
          <span>Austrittsdatum</span>
          <input v-model="formData.leaveDate" type="date" class="form-input" />
        </label>
        <NcSelect
          v-model="formData.role"
          :options="roleOptions"
          :reduce="option => option.id"
          label="label"
          input-label="Rolle"
          :clearable="false"
          :disabled="!canManageRoles"
        />
        <NcSelect
          v-model="formData.feeRateId"
          :options="feeRateOptions"
          :reduce="r => r.id"
          label="label"
          input-label="Beitragskategorie"
          placeholder="Standard des Vereins"
        />
        <label class="checkbox-field">
          <input v-model="formData.foundingMember" type="checkbox" />
          <span>Gründungsmitglied</span>
        </label>
        <label class="checkbox-field">
          <input v-model="formData.deceased" type="checkbox" />
          <span>Verstorben</span>
        </label>

        <h3 class="form-subheader">Bankverbindung</h3>
        <NcTextField
          :model-value="formData.iban"
          @update:model-value="formData.iban = $event"
          type="text"
          label="IBAN"
        />
        <NcTextField
          :model-value="formData.bic"
          @update:model-value="formData.bic = $event"
          type="text"
          label="BIC"
        />

        <h3 class="form-subheader">SEPA-Lastschriftmandat (für diesen Verein)</h3>
        <NcTextField
          :model-value="formData.mandateReference"
          @update:model-value="formData.mandateReference = $event"
          type="text"
          label="Mandatsreferenz"
          placeholder="leer = automatisch"
        />
        <label class="date-field">
          <span>Unterschriftsdatum</span>
          <input v-model="formData.mandateDate" type="date" class="form-input" />
        </label>
        <div class="mandate-file">
          <span>Unterschriebenes Mandat (PDF)</span>
          <div class="mandate-file-row">
            <a v-if="formData.mandateFile" :href="mandateFileUrl(formData.mandateFile)" target="_blank" rel="noopener">{{ formData.mandateFile }}</a>
            <span v-else class="hint">keine Datei verknüpft</span>
            <NcButton type="button" variant="secondary" @click="pickMandateFile">Datei wählen</NcButton>
            <NcButton v-if="formData.mandateFile" type="button" variant="tertiary" @click="formData.mandateFile = ''">Entfernen</NcButton>
          </div>
        </div>

        <div class="form-actions">
          <NcButton type="submit" variant="primary" :disabled="loading">
            {{ loading ? 'Wird gespeichert...' : (editingId ? 'Speichern' : 'Hinzufügen') }}
          </NcButton>
          <NcButton v-if="editingId" type="button" variant="tertiary" @click="cancelEdit">
            Abbrechen
          </NcButton>
        </div>
      </form>
    </div>

    <!-- Members Table -->
    <div class="table-section">
      <div class="section-header">
        <h2>Mitgliederliste</h2>
        <div class="export-buttons">
          <ExportButtons resource="members" inline />
        </div>
      </div>

      <div class="category-filter">
        <button
          v-for="cat in categories"
          :key="cat.id"
          type="button"
          :class="['category-button', { active: category === cat.id }]"
          @click="category = cat.id"
        >
          {{ cat.label }} ({{ cat.count }})
        </button>
      </div>

      <div class="table-wrapper">
        <table class="members-table">
          <thead>
            <tr>
              <th>Nr.</th>
              <th>Name</th>
              <th>E-Mail</th>
              <th>Ort</th>
              <th>NC-Konto</th>
              <th>Alter</th>
              <th>Mitglied seit</th>
              <th>Rolle</th>
              <th>Status</th>
              <th v-if="canManage">Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in filteredMembers" :key="member.id" :class="{ editing: editingId === member.id }">
              <td>{{ member.id }}</td>
              <td>{{ displayName(member) }}</td>
              <td>{{ member.email }}</td>
              <td>{{ member.city || '-' }}</td>
              <td>
                <span v-if="member.userId" :title="member.userId">{{ member.userDisplayName }}<span v-if="member.userExists === false" class="hint"> (Konto fehlt)</span></span>
                <span v-else class="hint">–</span>
              </td>
              <td>{{ member.age !== null && member.age !== undefined ? member.age + ' J.' : '-' }}</td>
              <td>{{ member.membershipYears !== null && member.membershipYears !== undefined ? member.membershipYears + ' J.' : '-' }}</td>
              <td>
                <span :class="['role-badge', member.role]">{{ roleLabel(member.role) }}</span>
              </td>
              <td class="status-cell">
                <span v-if="member.deceased" class="status-badge deceased">Verstorben</span>
                <span v-else-if="member.isFormer" class="status-badge former">Ehemalig</span>
                <span
                  v-else-if="member.deactivated"
                  class="status-badge deactivated"
                  title="Keine Beiträge, keine Geburtstagstermine und keine automatischen Rechte, bis das Mitglied wieder aktiviert wird"
                >Deaktiviert</span>
                <span v-else class="status-badge active">Aktiv</span>
                <span v-if="member.foundingMember" class="status-badge founding" title="Gründungsmitglied">★</span>
              </td>
              <td v-if="canManage" class="actions">
                <NcButton @click="startEdit(member)" variant="secondary">
                  Bearbeiten
                </NcButton>
                <NcButton
                  v-if="canManageRoles && member.deactivated"
                  @click="activateMember(member.id)"
                  variant="secondary"
                  :disabled="loading"
                >
                  Aktivieren
                </NcButton>
                <NcButton
                  v-else-if="canManageRoles"
                  @click="deactivateMember(member.id)"
                  variant="secondary"
                  :disabled="loading"
                >
                  Deaktivieren
                </NcButton>
                <NcButton
                  @click="deleteMember(member.id)"
                  variant="error"
                  :disabled="loading"
                >
                  Aus Verein entfernen
                </NcButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="filteredMembers.length === 0" class="empty-state">Keine Mitglieder in dieser Kategorie</p>
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '../api'
import { showSuccess, showError, getFilePickerBuilder } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import { absoluteUrl } from '../absoluteUrl'
import { currentClub, can } from '../store/club'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import Alert from './Alert.vue'
import ExportButtons from './ExportButtons.vue'

const emptyFormData = () => ({
  salutation: null,
  firstName: '',
  name: '',
  birthDate: '',
  street: '',
  postalCode: '',
  city: '',
  email: '',
  joinDate: '',
  leaveDate: '',
  role: 'member',
  foundingMember: false,
  deceased: false,
  iban: '',
  bic: '',
  mandateReference: '',
  mandateDate: '',
  mandateFile: '',
  userId: '',
  feeRateId: null
})

export default {
  name: 'Members',
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    NcSelectUsers,
    Alert,
    ExportButtons
  },
  setup() {
    const members = ref([])
    const loading = ref(false)
    const editingId = ref(null)
    const alertError = ref('')
    const alertErrors = ref([])
    const alertRef = ref(null)
    const category = ref('active')

    const roleOptions = [
      { id: 'member', label: 'Mitglied' },
      { id: 'admin', label: 'Vorstand' },
      { id: 'treasurer', label: 'Kassierer' }
    ]

    const salutationOptions = ['Herr', 'Frau', 'Divers', 'Firma']

    const formData = reactive(emptyFormData())
    const canManage = computed(() => can('verein.member.manage'))
    const canManageRoles = computed(() => can('verein.role.manage'))

    // fee categories of the current club, for the membership section
    const feeRateOptions = computed(() =>
      (currentClub.value?.feeRates || []).map(r => ({
        id: r.id,
        label: r.name + ' (' + Number(r.amount).toFixed(2).replace('.', ',') + ' €)' + (r.isDefault ? ' – Standard' : '')
      }))
    )

    // Link to a Nextcloud account
    const selectedUser = ref(null)
    const userOptions = ref([])
    let userSearchTimer = null

    const runUserSearch = async (query) => {
      try {
        const response = await api.get('members/users', { params: { query } })
        userOptions.value = (response.data.users || []).map(u => ({
          ...u,
          subname: u.linkedTo ? `${u.id} – bereits verknüpft mit ${u.linkedTo}` : u.id
        }))
      } catch (error) {
        userOptions.value = []
      }
    }

    const onUserSearch = (query) => {
      if (userSearchTimer) clearTimeout(userSearchTimer)
      if (!query || query.trim().length < 2) return
      userSearchTimer = setTimeout(() => runUserSearch(query.trim()), 300)
    }

    const setSelectedUserFrom = (m) => {
      selectedUser.value = m?.userId
        ? { id: m.userId, user: m.userId, displayName: m.userDisplayName || m.userId, subname: m.userId }
        : null
    }

    const onUserPicked = (user) => {
      if (user && user.linkedToId && user.linkedToId !== editingId.value) {
        showError(`${user.displayName} ist bereits mit ${user.linkedTo} verknüpft`)
        setSelectedUserFrom(formData)
        return
      }
      formData.userId = user ? user.id : ''
    }

    // "Add existing person from another club"
    const lookupQuery = ref('')
    const lookupJoinDate = ref('')
    const lookupResults = ref([])
    const lookupDone = ref(false)
    let lookupTimer = null

    const onLookupInput = (value) => {
      lookupQuery.value = value
      lookupDone.value = false
      if (lookupTimer) clearTimeout(lookupTimer)
      if (value.trim().length < 2) {
        lookupResults.value = []
        return
      }
      lookupTimer = setTimeout(async () => {
        try {
          const response = await api.get('members/lookup', { params: { query: value.trim() } })
          lookupResults.value = response.data.members || []
        } catch (error) {
          lookupResults.value = []
        } finally {
          lookupDone.value = true
        }
      }, 300)
    }

    const attachExisting = async (person) => {
      loading.value = true
      try {
        await api.post('memberships', {
          memberId: person.id,
          joinDate: lookupJoinDate.value,
          role: 'member'
        })
        showSuccess(person.fullName + ' wurde dem Verein hinzugefügt')
        lookupQuery.value = ''
        lookupResults.value = []
        lookupJoinDate.value = ''
        await fetchMembers()
      } catch (error) {
        showError(extractErrorMessage(error, 'Person konnte nicht hinzugefügt werden'))
      } finally {
        loading.value = false
      }
    }

    // Signed mandate PDFs live in the club's team folder in Nextcloud Files
    const pickMandateFile = async () => {
      try {
        const start = currentClub.value?.documentsPath || '/'
        const path = await getFilePickerBuilder('Unterschriebenes SEPA-Mandat wählen')
          .setMultiSelect(false)
          .setMimeTypeFilter(['application/pdf', 'image/jpeg', 'image/png'])
          .startAt(start)
          .allowDirectories(false)
          .build()
          .pick()
        if (path) formData.mandateFile = path
      } catch (error) {
        // dialog closed without a selection
      }
    }

    const mandateFileUrl = (path) => {
      const idx = path.lastIndexOf('/')
      const dir = idx > 0 ? path.slice(0, idx) : '/'
      const file = path.slice(idx + 1)
      return absoluteUrl('/apps/files/files?dir=' + encodeURIComponent(dir) + '&openfile=true&scrollto=' + encodeURIComponent(file))
    }

    onMounted(async () => {
      await fetchMembers()
    })

    const fetchMembers = async () => {
      loading.value = true
      try {
        const response = await api.get('members')
        members.value = response.data.members || []
      } catch (error) {
        console.error('Error fetching members:', error)
        showError(extractErrorMessage(error, 'Fehler beim Laden der Mitglieder'))
      } finally {
        loading.value = false
      }
    }

    const categories = computed(() => {
      const active = members.value.filter(m => !m.isFormer).length
      const former = members.value.filter(m => m.isFormer).length
      return [
        { id: 'active', label: 'Aktiv', count: active },
        { id: 'former', label: 'Ehemalig', count: former },
        { id: 'all', label: 'Alle', count: members.value.length }
      ]
    })

    const filteredMembers = computed(() => {
      if (category.value === 'active') return members.value.filter(m => !m.isFormer)
      if (category.value === 'former') return members.value.filter(m => m.isFormer)
      return members.value
    })

    const displayName = (member) => {
      const prefix = member.salutation ? member.salutation + ' ' : ''
      return prefix + (member.firstName ? member.firstName + ' ' : '') + member.name
    }

    const saveMember = async () => {
      loading.value = true
      alertError.value = ''
      alertErrors.value = []
      try {
        const response = editingId.value
          ? await api.put(`members/${editingId.value}`, formData)
          : await api.post('members', formData)

        if (response.data.status === 'error') {
          alertError.value = response.data.message
          alertErrors.value = response.data.errors || []
          if (alertRef.value) alertRef.value.open()
        } else {
          showSuccess(editingId.value ? 'Mitglied aktualisiert' : 'Mitglied hinzugefügt')
          cancelEdit()
          await fetchMembers()
        }
      } catch (error) {
        const data = error.response?.data
        alertError.value = data?.message || error.message || 'Fehler beim Speichern des Mitglieds'
        alertErrors.value = data?.errors || []
        if (alertRef.value) alertRef.value.open()
        console.error('Error saving member:', error)
      } finally {
        loading.value = false
      }
    }

    const startEdit = async (member) => {
      editingId.value = member.id
      Object.assign(formData, emptyFormData(), member)
      setSelectedUserFrom(member)
      userOptions.value = []

      try {
        const response = await api.getMember(member.id)
        const latest = response.data?.data || response.data?.member
        if (latest) {
          Object.assign(formData, emptyFormData(), latest)
          setSelectedUserFrom(latest)
        }
        // Suggest matching Nextcloud accounts by the member's name
        if (!formData.userId) runUserSearch(formData.name)
      } catch (error) {
        console.error('Error loading member details:', error)
        showError(extractErrorMessage(error, 'Fehler beim Laden des Mitglieds'))
      }
    }

    const cancelEdit = () => {
      editingId.value = null
      Object.assign(formData, emptyFormData())
      selectedUser.value = null
      userOptions.value = []
    }

    const deleteMember = async (id) => {
      if (!confirm('Soll dieses Mitglied aus dem Verein entfernt werden? Seine Beiträge in diesem Verein werden ebenfalls gelöscht; die Person bleibt in anderen Vereinen erhalten.')) return

      loading.value = true
      try {
        await api.delete(`members/${id}`)
        showSuccess('Mitglied gelöscht')
        if (editingId.value === id) cancelEdit()
        await fetchMembers()
      } catch (error) {
        console.error('Error deleting member:', error)
        showError(extractErrorMessage(error, 'Fehler beim Löschen des Mitglieds'))
      } finally {
        loading.value = false
      }
    }

    const deactivateMember = async (id) => {
      if (!confirm('Dieses Mitglied deaktivieren? Es wird nicht mehr für Beiträge und SEPA-Einzug berücksichtigt, die Geburtstags- und Jubiläumstermine entfallen und automatisch abgeleitete Rechte (aus der Vereinsfunktion) werden ausgesetzt. Nichts wird gelöscht; mit „Aktivieren“ ist alles wieder da.')) return

      loading.value = true
      try {
        await api.post(`members/${id}/deactivate`)
        showSuccess('Mitglied deaktiviert')
        await fetchMembers()
      } catch (error) {
        console.error('Error deactivating member:', error)
        showError(extractErrorMessage(error, 'Fehler beim Deaktivieren des Mitglieds'))
      } finally {
        loading.value = false
      }
    }

    const activateMember = async (id) => {
      loading.value = true
      try {
        await api.post(`members/${id}/activate`)
        showSuccess('Mitglied aktiviert')
        await fetchMembers()
      } catch (error) {
        console.error('Error activating member:', error)
        showError(extractErrorMessage(error, 'Fehler beim Aktivieren des Mitglieds'))
      } finally {
        loading.value = false
      }
    }

    const roleLabel = (role) => {
      return roleOptions.find(r => r.id === role)?.label || role
    }

    return {
      members,
      loading,
      editingId,
      formData,
      roleOptions,
      salutationOptions,
      category,
      categories,
      filteredMembers,
      displayName,
      roleLabel,
      saveMember,
      startEdit,
      cancelEdit,
      deleteMember,
      deactivateMember,
      activateMember,
      canManage,
      canManageRoles,
      feeRateOptions,
      selectedUser,
      userOptions,
      onUserSearch,
      onUserPicked,
      lookupQuery,
      lookupJoinDate,
      lookupResults,
      lookupDone,
      onLookupInput,
      attachExisting,
      pickMandateFile,
      mandateFileUrl,
      alertRef,
      alertError,
      alertErrors
    }
  }
}
</script>

<style scoped lang="scss">
.members-container {
  /* Use full width on all screens with responsive padding */
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 2rem;

  @media (min-width: 1200px) {
    /* two-column layout: form + list on wide screens */
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 2rem;
    align-items: start;
  }
}

.members-container.no-form { display: flex; }

.form-section,
.table-section {
  background: var(--color-main-background);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 20px;
  border: 1px solid var(--color-border);
  box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));

  h2 {
    margin-top: 0;
    margin-bottom: 16px;
    font-size: 18px;
    color: var(--color-text);
  }
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;

  h2 {
    margin: 0;
    font-size: 18px;
    color: var(--color-text);
  }
}

.export-buttons {
  display: flex;
  gap: 8px;
}

@media (min-width: 1100px) {
  .form-section { margin-bottom: 0; }
}

.member-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  align-items: end;
}

.form-subheader {
  grid-column: 1 / -1;
  margin: 8px 0 -4px;
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--color-text-secondary);

  &:first-child {
    margin-top: 0;
  }
}

.lookup-box {
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-border);
  display: grid;
  gap: 12px;
}

.lookup-results {
  list-style: none;
  margin: 0;
  padding: 0;

  li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid var(--color-border);
  }
}

.mandate-file {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.mandate-file-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.hint {
  color: var(--color-text-maxcontrast);
}

.account-link {
  grid-column: 1 / -1;
}

.form-actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 8px;
}

.date-field,
.checkbox-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
  color: var(--color-text-secondary);
}

.checkbox-field {
  flex-direction: row;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;

  span {
    color: var(--color-text);
    font-size: 14px;
  }
}

.form-input {
  padding: 8px 12px;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  background: var(--color-main-background);
  color: var(--color-text);
  font-size: 14px;

  &:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px var(--color-primary-light);
  }
}

.category-filter {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.category-button {
  padding: 6px 14px;
  border-radius: 16px;
  border: 1px solid var(--color-border);
  background: var(--color-background-hover);
  color: var(--color-text);
  font-size: 13px;
  cursor: pointer;

  &.active {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-primary-text, #fff);
  }
}

.table-wrapper {
  overflow-x: auto;
}

.members-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 14px;

  thead {
    background: var(--color-background-hover);
    border-bottom: 2px solid var(--color-border);

    th {
      padding: 12px;
      text-align: left;
      font-weight: 600;
      color: var(--color-text);
    }
  }

  tbody {
    tr {
      border-bottom: 1px solid var(--color-border);
      transition: background 0.2s;

      &:hover {
        background: var(--color-background-hover);
      }

      &.editing {
        background: var(--color-primary-light);
      }

      td {
        padding: 12px;
        color: var(--color-text);
      }
    }
  }
}

.role-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;

  &.member {
    background: var(--color-primary-light);
    color: var(--color-primary);
  }

  &.admin {
    background: var(--color-error-light);
    color: var(--color-error);
  }

  &.treasurer {
    background: var(--color-success-light);
    color: var(--color-success);
  }
}

.status-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;

  &.active {
    background: var(--color-success-light);
    color: var(--color-success);
  }

  &.former {
    background: var(--color-warning-light);
    color: var(--color-warning);
  }

  &.deceased {
    background: var(--color-background-darker);
    color: var(--color-text-secondary);
  }

  &.deactivated {
    background: var(--color-background-darker);
    color: var(--color-text-maxcontrast);
  }

  &.founding {
    background: none;
    padding: 0;
    color: var(--color-warning);
    font-size: 14px;
  }
}

.actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.empty-state {
  text-align: center;
  color: var(--color-text-secondary);
  padding: 40px 20px;
}
</style>
