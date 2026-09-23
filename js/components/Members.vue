<template>
  <div class="members-container">
    <!-- Alert Komponente -->
    <Alert
      ref="alertRef"
      type="error"
      :message="alertError"
      :errors="alertErrors"
    />

    <!-- Form für neues Mitglied -->
    <div class="form-section">
      <h2>Neues Mitglied hinzufügen</h2>
      <form @submit.prevent="addMember" class="member-form">
        <NcTextField
          :model-value="formData.name"
          @update:model-value="formData.name = $event"
          type="text"
          label="Name"
          placeholder="Max Mustermann"
          required
        />
        <NcTextField
          :model-value="formData.email"
          @update:model-value="formData.email = $event"
          type="email"
          label="E-Mail"
          placeholder="max@example.com"
          required
        />
        <NcTextField
          :model-value="formData.address"
          @update:model-value="formData.address = $event"
          type="text"
          label="Adresse"
        />
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
        <NcSelect
          v-model="formData.role"
          :options="roleOptions"
          :reduce="option => option.id"
          label="label"
          input-label="Rolle"
          :clearable="false"
        />
        <NcButton type="submit" variant="primary" :disabled="loading">
          {{ loading ? 'Wird gespeichert...' : 'Hinzufügen' }}
        </NcButton>
      </form>
    </div>

    <!-- Members Table -->
    <div class="table-section">
      <div class="section-header">
        <h2>Mitgliederliste</h2>
        <div class="export-buttons">
          <NcButton @click="exportMembersAsCsv" variant="secondary" title="Mitglieder als CSV herunterladen">
            📊 CSV Export
          </NcButton>
          <NcButton @click="exportMembersAsPdf" variant="secondary" title="Mitglieder als PDF herunterladen">
            📄 PDF Export
          </NcButton>
        </div>
      </div>
      <div class="table-wrapper">
        <table class="members-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>E-Mail</th>
              <th>Adresse</th>
              <th>IBAN</th>
              <th>Rolle</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in members" :key="member.id" :class="{ editing: editingId === member.id }">
              <td v-if="editingId !== member.id">{{ member.name }}</td>
              <td v-if="editingId === member.id" class="cell-field">
                <NcTextField :model-value="editData.name" @update:model-value="editData.name = $event" label="Name" />
              </td>

              <td v-if="editingId !== member.id">{{ member.email }}</td>
              <td v-if="editingId === member.id" class="cell-field">
                <NcTextField :model-value="editData.email" @update:model-value="editData.email = $event" type="email" label="E-Mail" />
              </td>

              <td v-if="editingId !== member.id">{{ member.address || '-' }}</td>
              <td v-if="editingId === member.id" class="cell-field">
                <NcTextField :model-value="editData.address" @update:model-value="editData.address = $event" label="Adresse" />
              </td>

              <td v-if="editingId !== member.id">{{ member.iban || '-' }}</td>
              <td v-if="editingId === member.id" class="cell-field">
                <NcTextField :model-value="editData.iban" @update:model-value="editData.iban = $event" label="IBAN" />
              </td>

              <td v-if="editingId !== member.id">
                <span :class="['role-badge', member.role]">{{ member.role }}</span>
              </td>
              <td v-if="editingId === member.id" class="cell-field">
                <NcSelect
                  v-model="editData.role"
                  :options="roleOptions"
                  :reduce="option => option.id"
                  label="label"
                  input-label="Rolle"
                  :clearable="false"
                />
              </td>

              <td class="actions">
                <NcButton
                  v-if="editingId !== member.id"
                  @click="startEdit(member)"
                  variant="secondary"
                >
                  Bearbeiten
                </NcButton>
                <NcButton
                  v-else
                  @click="saveEdit(member.id)"
                  variant="primary"
                  :disabled="loading"
                >
                  Speichern
                </NcButton>
                <NcButton
                  v-if="editingId === member.id"
                  @click="cancelEdit"
                  variant="tertiary"
                >
                  Abbrechen
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
      <p v-if="members.length === 0" class="empty-state">Keine Mitglieder vorhanden</p>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'
import { api } from '../api'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Alert from './Alert.vue'

export default {
  name: 'Members',
  components: {
    NcButton,
    NcTextField,
    NcSelect,
    Alert
  },
  setup() {
    const members = ref([])
    const loading = ref(false)
    const editingId = ref(null)
    const alertError = ref('')
    const alertErrors = ref([])
    const alertRef = ref(null)

    const roleOptions = [
      { id: 'member', label: 'Mitglied' },
      { id: 'admin', label: 'Administrator' },
      { id: 'treasurer', label: 'Kassierer' }
    ]

    const formData = ref({
      name: '',
      email: '',
      address: '',
      iban: '',
  bic: '',
  role: 'member'
    })

    const editData = ref({})

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
      } finally {
        loading.value = false
      }
    }

    const addMember = async () => {
      loading.value = true
      alertError.value = ''
      alertErrors.value = []
      try {
        const response = await api.post('members', formData.value)
        if (response.data.status === 'error') {
          alertError.value = response.data.message
          alertErrors.value = response.data.errors || []
          if (alertRef.value) alertRef.value.open()
        } else {
          formData.value = { name: '', email: '', address: '', iban: '', bic: '', role: 'member' }
          await fetchMembers()
        }
      } catch (error) {
        alertError.value = error.message || 'Fehler beim Hinzufügen des Mitglieds'
        alertErrors.value = []
        if (alertRef.value) alertRef.value.open()
        console.error('Error adding member:', error)
      } finally {
        loading.value = false
      }
    }

    const startEdit = async (member) => {
      editingId.value = member.id
      editData.value = { ...member }

      try {
        const response = await api.getMember(member.id)
        const latest = response.data?.data || response.data?.member
        if (latest) {
          editData.value = { ...latest }
        }
      } catch (error) {
        console.error('Error loading member details:', error)
        alertError.value = 'Fehler beim Laden des Mitglieds'
        alertErrors.value = []
        if (alertRef.value) alertRef.value.open()
      }
    }

    const saveEdit = async (id) => {
      loading.value = true
      try {
        await api.put(`members/${id}`, editData.value)
        editingId.value = null
        await fetchMembers()
      } catch (error) {
        console.error('Error updating member:', error)
      } finally {
        loading.value = false
      }
    }

    const cancelEdit = () => {
      editingId.value = null
      editData.value = {}
    }

    const deleteMember = async (id) => {
      if (!confirm('Soll dieses Mitglied wirklich gelöscht werden?')) return
      
      loading.value = true
      try {
        await api.delete(`members/${id}`)
        await fetchMembers()
      } catch (error) {
        console.error('Error deleting member:', error)
      } finally {
        loading.value = false
      }
    }

    const exportMembersAsCsv = async () => {
      try {
        const response = await axios.get(generateUrl('/apps/verein/export/members/csv'), {
          responseType: 'blob'
        })
        downloadFile(response.data, 'members.csv', 'text/csv')
        alert('Mitglieder als CSV exportiert')
      } catch (error) {
        console.error('Error exporting CSV:', error)
        alert('Fehler beim CSV-Export')
      }
    }

    const exportMembersAsPdf = async () => {
      try {
        const response = await axios.get(generateUrl('/apps/verein/export/members/pdf'), {
          responseType: 'blob'
        })
        downloadFile(response.data, 'members.pdf', 'application/pdf')
        alert('Mitglieder als PDF exportiert')
      } catch (error) {
        console.error('Error exporting PDF:', error)
        alert('Fehler beim PDF-Export')
      }
    }

    const downloadFile = (blob, filename, mimeType) => {
      const url = window.URL.createObjectURL(new Blob([blob], { type: mimeType }))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', filename)
      document.body.appendChild(link)
      link.click()
      link.parentNode.removeChild(link)
      window.URL.revokeObjectURL(url)
    }

    return {
      members,
      loading,
      editingId,
      formData,
      editData,
      roleOptions,
      addMember,
      startEdit,
      saveEdit,
      cancelEdit,
      deleteMember,
      exportMembersAsCsv,
      exportMembersAsPdf,
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
    /* three-column layout: form + two lists on wide screens */
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 2rem;
    align-items: start;
  }
}

.form-section,
.table-section {
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(10px);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 20px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);

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

.cell-field {
  min-width: 160px;
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
