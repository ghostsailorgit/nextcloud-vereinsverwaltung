<template>
  <div class="finance-container">
    <FeeRun v-if="canWriteFinance" @done="fetchFees" />

    <!-- Form für neue Gebühr -->
    <div class="form-section">
      <h2>Neue Gebühr hinzufügen</h2>
      <form @submit.prevent="addFee" class="fee-form">
        <NcSelect
          v-model="formData.memberId"
          :options="sortedMembers"
          :reduce="member => member.id"
          label="fullName"
          input-label="Mitglied"
          placeholder="-- Mitglied wählen --"
        />
        <NcTextField
          :model-value="formData.amount"
          @update:model-value="formData.amount = Number($event)"
          type="number"
          label="Betrag"
          placeholder="0.00"
          required
        />
        <label class="date-field">
          <span>Fälligkeitsdatum</span>
          <input
            v-model="formData.dueDate"
            type="datetime-local"
            required
            class="form-input"
          />
        </label>
        <NcSelect
          v-model="formData.status"
          :options="statusOptions"
          :reduce="option => option.id"
          label="label"
          input-label="Status"
          :clearable="false"
        />
        <NcButton type="submit" variant="primary" :disabled="loading">
          {{ loading ? 'Wird gespeichert...' : 'Hinzufügen' }}
        </NcButton>
      </form>
    </div>

    <!-- Statistics -->
    <div class="stats-section">
      <div class="stat-card">
        <div class="stat-label">Gesamt ausstehend</div>
        <div class="stat-value">{{ totalOutstanding.toFixed(2) }} €</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Bezahlt</div>
        <div class="stat-value">{{ totalPaid.toFixed(2) }} €</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Anzahl Gebühren</div>
        <div class="stat-value">{{ fees.length }}</div>
      </div>
    </div>

    <!-- Fees Table -->
    <div class="table-section">
      <div class="section-header">
        <h2>Gebührenliste</h2>
        <div class="export-buttons">
          <ExportButtons resource="fees" inline />
        </div>
      </div>
      <div class="table-wrapper">
        <table class="fees-table">
          <thead>
            <tr>
              <th>Mitglied</th>
              <th>Betrag</th>
              <th>Status</th>
              <th>Fällig am</th>
              <th>Bezahlt am</th>
              <th>Aktionen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fee in fees" :key="fee.id" :class="{ editing: editingId === fee.id, [fee.status]: true }">
              <td>{{ getMemberName(fee.memberId) }}</td>

              <td v-if="editingId !== fee.id">{{ fee.amount.toFixed(2) }} €</td>
              <td v-if="editingId === fee.id" class="cell-field">
                <NcTextField :model-value="editData.amount" @update:model-value="editData.amount = Number($event)" type="number" label="Betrag" />
              </td>

              <td v-if="editingId !== fee.id">
                <span :class="['status-badge', fee.status]">{{ getStatusLabel(fee.status) }}</span>
              </td>
              <td v-if="editingId === fee.id" class="cell-field">
                <NcSelect
                  v-model="editData.status"
                  :options="statusOptions"
                  :reduce="option => option.id"
                  label="label"
                  input-label="Status"
                  :clearable="false"
                />
              </td>

              <td v-if="editingId !== fee.id">{{ formatDate(fee.dueDate) }}</td>
              <td v-if="editingId === fee.id" class="cell-field">
                <label class="date-field">
                  <span>Fälligkeitsdatum</span>
                  <input v-model="editData.dueDate" type="datetime-local" class="form-input-inline" />
                </label>
              </td>

              <td>{{ fee.paidDate ? formatDate(fee.paidDate) : '-' }}</td>

              <td class="actions">
                <NcButton
                  v-if="editingId !== fee.id"
                  @click="startEdit(fee)"
                  variant="secondary"
                >
                  Bearbeiten
                </NcButton>
                <NcButton
                  v-else
                  @click="saveEdit(fee.id)"
                  variant="primary"
                  :disabled="loading"
                >
                  Speichern
                </NcButton>
                <NcButton
                  v-if="editingId === fee.id"
                  @click="cancelEdit"
                  variant="tertiary"
                >
                  Abbrechen
                </NcButton>
                <NcButton
                  @click="deleteFee(fee.id)"
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
      <p v-if="fees.length === 0" class="empty-state">Keine Gebühren vorhanden</p>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, computed } from 'vue'
import { api } from '../api'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ExportButtons from './ExportButtons.vue'
import FeeRun from './FeeRun.vue'
import { can } from '../store/club'
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'

export default {
  name: 'Finance',
  components: { NcButton, NcTextField, NcSelect, ExportButtons, FeeRun },
  setup() {
    const canWriteFinance = computed(() => can('verein.finance.write'))
    const fees = ref([])
    const members = ref([])
    const loading = ref(false)
    const editingId = ref(null)

    // backend (ValidationService::validateFeeStatus) only accepts these 4 values
    const statusOptions = [
      { id: 'open', label: 'Offen' },
      { id: 'paid', label: 'Bezahlt' },
      { id: 'overdue', label: 'Überfällig' },
      { id: 'cancelled', label: 'Storniert' }
    ]

    const formData = ref({
      memberId: '',
      amount: '',
      status: 'open',
      dueDate: ''
    })

    const editData = ref({})

    onMounted(async () => {
      await fetchMembers()
      await fetchFees()
    })

    const fetchMembers = async () => {
      try {
        const response = await api.get('members')
        members.value = response.data.members || []
      } catch (error) {
        console.error('Error fetching members:', error)
        showError(extractErrorMessage(error, 'Fehler beim Laden der Mitglieder'))
      }
    }

    const fetchFees = async () => {
      loading.value = true
      try {
        const response = await api.get('finance')
        fees.value = response.data.fees || []
      } catch (error) {
        console.error('Error fetching fees:', error)
        showError(extractErrorMessage(error, 'Fehler beim Laden der Gebühren'))
      } finally {
        loading.value = false
      }
    }

    const addFee = async () => {
      loading.value = true
      try {
        await api.post('finance', formData.value)
        formData.value = { memberId: '', amount: '', status: 'open', dueDate: '' }
        showSuccess('Gebühr hinzugefügt')
        await fetchFees()
      } catch (error) {
        console.error('Error adding fee:', error)
        showError(extractErrorMessage(error, 'Fehler beim Hinzufügen der Gebühr'))
      } finally {
        loading.value = false
      }
    }

    const startEdit = (fee) => {
      editingId.value = fee.id
      editData.value = { ...fee }
    }

    const saveEdit = async (id) => {
      loading.value = true
      try {
        await api.put(`finance/${id}`, editData.value)
        editingId.value = null
        showSuccess('Gebühr aktualisiert')
        await fetchFees()
      } catch (error) {
        console.error('Error updating fee:', error)
        showError(extractErrorMessage(error, 'Fehler beim Aktualisieren der Gebühr'))
      } finally {
        loading.value = false
      }
    }

    const cancelEdit = () => {
      editingId.value = null
      editData.value = {}
    }

    const deleteFee = async (id) => {
      if (!confirm('Soll diese Gebühr wirklich gelöscht werden?')) return

      loading.value = true
      try {
        await api.delete(`finance/${id}`)
        showSuccess('Gebühr gelöscht')
        await fetchFees()
      } catch (error) {
        console.error('Error deleting fee:', error)
        showError(extractErrorMessage(error, 'Fehler beim Löschen der Gebühr'))
      } finally {
        loading.value = false
      }
    }

    const getMemberName = (memberId) => {
      const member = members.value.find(m => m.id === memberId)
      return member ? member.fullName : `Mitglied #${memberId}`
    }

    const getStatusLabel = (status) => {
      const labels = {
        open: 'Offen',
        paid: 'Bezahlt',
        overdue: 'Überfällig',
        cancelled: 'Storniert'
      }
      return labels[status] || status
    }

    const formatDate = (dateString) => {
      if (!dateString) return '-'
      return new Date(dateString).toLocaleDateString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      })
    }

    const sortedMembers = computed(() => {
      return [...members.value].sort((a, b) => {
        return a.name.localeCompare(b.name, 'de') || (a.firstName || '').localeCompare(b.firstName || '', 'de')
      })
    })

    const totalOutstanding = computed(() => {
      return fees.value
        .filter(f => f.status !== 'paid')
        .reduce((sum, f) => sum + (f.amount || 0), 0)
    })

    const totalPaid = computed(() => {
      return fees.value
        .filter(f => f.status === 'paid')
        .reduce((sum, f) => sum + (f.amount || 0), 0)
    })

    // Export is handled by <ExportButtons /> now

    return {
      canWriteFinance,
      fetchFees,
      fees,
      members,
      sortedMembers,
      loading,
      editingId,
      formData,
      editData,
      statusOptions,
      totalOutstanding,
      totalPaid,
      addFee,
      startEdit,
      saveEdit,
      cancelEdit,
      deleteFee,
      getMemberName,
      getStatusLabel,
      formatDate
    }
  }
}
</script>

<style scoped lang="scss">
.finance-container {
  /* Use full width with responsive layout */
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 2rem;

  @media (min-width: 1200px) {
    /* two-column layout for wide screens: form on the left, stats+table
       stacked on the right. Explicit placement is required here - with 3
       direct children (form/stats/table) but only 2 grid columns, default
       grid auto-flow wraps the table onto a new row starting back at
       column 1, trapping it in the narrow 320px track instead of the wide
       one (it then only grows via its own internal horizontal scrollbar). */
    display: grid;
    grid-template-columns: 320px 1fr;
    grid-template-rows: auto 1fr;
    gap: 2rem;
    align-items: start;
  }
}

@media (min-width: 1200px) {
  .form-section {
    grid-column: 1;
    grid-row: 1 / -1;
  }

  .stats-section {
    grid-column: 2;
    grid-row: 1;
  }

  .table-section {
    grid-column: 2;
    grid-row: 2;
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

.fee-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  align-items: end;
}

.date-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
  color: var(--color-text-secondary);
}

.form-input,
.form-input-inline {
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

.form-input-inline {
  width: 100%;
}

.cell-field {
  min-width: 160px;
}

.stats-section {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 20px;
}

.stat-card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: 16px;
  text-align: center;

  .stat-label {
    color: var(--color-text-secondary);
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 8px;
  }

  .stat-value {
    color: var(--color-primary);
    font-size: 24px;
    font-weight: 700;
  }
}

.table-wrapper {
  overflow-x: auto;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  gap: 16px;
  flex-wrap: wrap;

  h2 {
    margin: 0;
    flex: 1;
  }
}

.export-buttons {
  display: flex;
  gap: 8px;
}

.fees-table {
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

      &.paid {
        opacity: 0.7;
      }

      &.overdue {
        background: var(--color-error-light);
      }

      td {
        padding: 12px;
        color: var(--color-text);
      }
    }
  }
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;

  &.open {
    background: var(--color-warning-light);
    color: var(--color-warning);
  }

  &.paid {
    background: var(--color-success-light);
    color: var(--color-success);
  }

  &.overdue {
    background: var(--color-error-light);
    color: var(--color-error);
  }

  &.cancelled {
    background: var(--color-background-darker);
    color: var(--color-text-secondary);
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
