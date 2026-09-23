<template>
  <div class="members-container">
    <!-- Alert Komponente -->
    <Alert
      ref="alertRef"
      type="error"
      :message="alertError"
      :errors="alertErrors"
    />

    <!-- Form für neues/zu bearbeitendes Mitglied -->
    <div class="form-section">
      <h2>{{ editingId ? 'Mitglied bearbeiten' : 'Neues Mitglied hinzufügen' }}</h2>
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
        <NcTextField
          :model-value="formData.memberNumber"
          @update:model-value="formData.memberNumber = $event"
          type="text"
          label="Mitgliedsnummer"
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
          placeholder="max@example.com"
          required
        />

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
              <th>Alter</th>
              <th>Mitglied seit</th>
              <th>Rolle</th>
              <th>Status</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in filteredMembers" :key="member.id" :class="{ editing: editingId === member.id }">
              <td>{{ member.memberNumber || '-' }}</td>
              <td>{{ displayName(member) }}</td>
              <td>{{ member.email }}</td>
              <td>{{ member.city || '-' }}</td>
              <td>{{ member.age !== null && member.age !== undefined ? member.age + ' J.' : '-' }}</td>
              <td>{{ member.membershipYears !== null && member.membershipYears !== undefined ? member.membershipYears + ' J.' : '-' }}</td>
              <td>
                <span :class="['role-badge', member.role]">{{ roleLabel(member.role) }}</span>
              </td>
              <td class="status-cell">
                <span v-if="member.deceased" class="status-badge deceased">Verstorben</span>
                <span v-else-if="member.isFormer" class="status-badge former">Ehemalig</span>
                <span v-else class="status-badge active">Aktiv</span>
                <span v-if="member.foundingMember" class="status-badge founding" title="Gründungsmitglied">★</span>
              </td>
              <td class="actions">
                <NcButton @click="startEdit(member)" variant="secondary">
                  Bearbeiten
                </NcButton>
                <NcButton
                  @click="deleteMember(member.id)"
                  variant="error"
                  :disabled="loading"
                >
                  Löschen
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
import { showSuccess, showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Alert from './Alert.vue'
import ExportButtons from './ExportButtons.vue'

const emptyFormData = () => ({
  salutation: null,
  firstName: '',
  name: '',
  memberNumber: '',
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
  bic: ''
})

export default {
  name: 'Members',
  components: {
    NcButton,
    NcTextField,
    NcSelect,
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

      try {
        const response = await api.getMember(member.id)
        const latest = response.data?.data || response.data?.member
        if (latest) {
          Object.assign(formData, emptyFormData(), latest)
        }
      } catch (error) {
        console.error('Error loading member details:', error)
        showError(extractErrorMessage(error, 'Fehler beim Laden des Mitglieds'))
      }
    }

    const cancelEdit = () => {
      editingId.value = null
      Object.assign(formData, emptyFormData())
    }

    const deleteMember = async (id) => {
      if (!confirm('Soll dieses Mitglied wirklich gelöscht werden?')) return

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
