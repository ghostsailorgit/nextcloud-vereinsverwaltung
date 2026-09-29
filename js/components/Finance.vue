<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <div class="finance-container">
    <!-- Statistics -->
    <div class="stats-section">
      <div class="stat-card">
        <div class="stat-label">{{ t('verein', 'Total outstanding') }}</div>
        <div class="stat-value">{{ formatMoney(totalOutstanding) }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">{{ t('verein', 'Paid') }}</div>
        <div class="stat-value">{{ formatMoney(totalPaid) }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">{{ t('verein', 'Number of fees') }}</div>
        <div class="stat-value">{{ fees.length }}</div>
      </div>
    </div>

    <div v-if="canWriteFinance" class="tools">
      <FeeRun @done="fetchFees" />
      <Dunning @done="fetchFees" />
    </div>

    <!-- Add a single fee: opened from the list header -->
    <div v-if="canWriteFinance && showForm" ref="formSection" class="form-section">
      <h2>{{ t('verein', 'Add fee') }}</h2>
      <form @submit.prevent="addFee" class="fee-form">
        <NcSelect
          v-model="formData.memberId"
          :options="sortedMembers"
          :reduce="member => member.id"
          label="fullName"
          :input-label="t('verein', 'Member')"
          :placeholder="t('verein', 'Select member…')"
        />
        <NcTextField
          :model-value="formData.amount"
          @update:model-value="formData.amount = Number($event)"
          type="number"
          :label="t('verein', 'Amount')"
          placeholder="0.00"
          required
        />
        <label class="date-field">
          <span>{{ t('verein', 'Due date') }}</span>
          <input
            v-model="formData.dueDate"
            type="date"
            required
            class="form-input"
          />
        </label>
        <NcSelect
          v-model="formData.status"
          :options="statusOptions"
          :reduce="option => option.id"
          label="label"
          :input-label="t('verein', 'Status')"
          :clearable="false"
        />
        <div class="form-actions">
          <NcButton type="submit" variant="primary" :disabled="loading">
            {{ loading ? t('verein', 'Saving…') : t('verein', 'Add') }}
          </NcButton>
          <NcButton type="button" variant="tertiary" @click="showForm = false">{{ t('verein', 'Cancel') }}</NcButton>
        </div>
      </form>
    </div>

    <!-- Fees Table -->
    <div class="table-section">
      <div class="section-header">
        <h2>{{ t('verein', 'Fee list') }}</h2>
        <div class="export-buttons">
          <NcButton v-if="canWriteFinance" variant="primary" @click="openForm">{{ t('verein', 'Add fee') }}</NcButton>
          <ExportButtons resource="fees" inline />
        </div>
      </div>
      <div class="table-wrapper">
        <table class="fees-table sticky-actions">
          <thead>
            <tr>
              <th>{{ t('verein', 'Member') }}</th>
              <th>{{ t('verein', 'Amount') }}</th>
              <th>{{ t('verein', 'Status') }}</th>
              <th>{{ t('verein', 'Due on') }}</th>
              <th>{{ t('verein', 'Paid on') }}</th>
              <th v-if="canWriteFinance">{{ t('verein', 'Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fee in fees" :key="fee.id" :class="{ editing: editingId === fee.id, [fee.status]: true }">
              <td>{{ getMemberName(fee.memberId) }}</td>

              <td v-if="editingId !== fee.id">{{ formatMoney(fee.amount) }}</td>
              <td v-if="editingId === fee.id" class="cell-field">
                <NcTextField :model-value="editData.amount" @update:model-value="editData.amount = Number($event)" type="number" :label="t('verein', 'Amount')" />
              </td>

              <td v-if="editingId !== fee.id">
                <span :class="['status-badge', fee.status]">{{ getStatusLabel(fee.status) }}</span>
                <span
                  v-if="fee.dunningLevel > 0"
                  class="dunning-badge"
                  :title="fee.lastDunnedAt ? t('verein', 'last reminder on {date}', { date: formatDay(fee.lastDunnedAt) }) : ''"
                >{{ dunningLabel(fee.dunningLevel) }}</span>
              </td>
              <td v-if="editingId === fee.id" class="cell-field">
                <NcSelect
                  v-model="editData.status"
                  :options="statusOptions"
                  :reduce="option => option.id"
                  label="label"
                  :input-label="t('verein', 'Status')"
                  :clearable="false"
                />
              </td>

              <td v-if="editingId !== fee.id">{{ formatDate(fee.dueDate) }}</td>
              <td v-if="editingId === fee.id" class="cell-field">
                <label class="date-field">
                  <span>{{ t('verein', 'Due date') }}</span>
                  <input v-model="editData.dueDate" type="date" class="form-input-inline" />
                </label>
              </td>

              <td>{{ fee.paidDate ? formatDate(fee.paidDate) : '-' }}</td>

              <td v-if="canWriteFinance">
                <div class="actions">
                  <template v-if="editingId !== fee.id">
                    <NcButton @click="startEdit(fee)" variant="secondary">
                      {{ t('verein', 'Edit') }}
                    </NcButton>
                    <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: getMemberName(fee.memberId) })" :disabled="loading">
                      <NcActionButton close-after-click @click="deleteFee(fee.id)">
                        {{ t('verein', 'Delete') }}
                      </NcActionButton>
                    </NcActions>
                  </template>
                  <template v-else>
                    <NcButton @click="saveEdit(fee.id)" variant="primary" :disabled="loading">
                      {{ t('verein', 'Save') }}
                    </NcButton>
                    <NcButton @click="cancelEdit" variant="tertiary">
                      {{ t('verein', 'Cancel') }}
                    </NcButton>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="fees.length === 0" class="empty-state">{{ t('verein', 'No fees yet') }}</p>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, computed, nextTick } from 'vue'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import { t } from '@nextcloud/l10n'
import { formatMoney, formatDate } from '../format'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ExportButtons from './ExportButtons.vue'
import FeeRun from './FeeRun.vue'
import Dunning, { DUNNING_LEVELS } from './Dunning.vue'
import { can } from '../store/club'
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'

export default {
  name: 'Finance',
  components: { NcActionButton, NcActions, NcButton, NcTextField, NcSelect, ExportButtons, FeeRun, Dunning },
  setup() {
    const canWriteFinance = computed(() => can('verein.finance.write'))
    const fees = ref([])
    const members = ref([])
    const loading = ref(false)
    const editingId = ref(null)

    // backend (ValidationService::validateFeeStatus) only accepts these 4 values
    const statusOptions = [
      { id: 'open', label: t('verein', 'Unpaid') },
      { id: 'paid', label: t('verein', 'Paid') },
      { id: 'overdue', label: t('verein', 'Overdue') },
      { id: 'cancelled', label: t('verein', 'Canceled') }
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
        showError(extractErrorMessage(error, t('verein', 'Error loading the members')))
      }
    }

    const fetchFees = async () => {
      loading.value = true
      try {
        const response = await api.get('finance')
        fees.value = response.data.fees || []
      } catch (error) {
        console.error('Error fetching fees:', error)
        showError(extractErrorMessage(error, t('verein', 'Error loading the fees')))
      } finally {
        loading.value = false
      }
    }

    const showForm = ref(false)
    const formSection = ref(null)
    const openForm = () => {
      showForm.value = true
      nextTick(() => formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
    }

    const addFee = async () => {
      loading.value = true
      try {
        await api.post('finance', formData.value)
        formData.value = { memberId: '', amount: '', status: 'open', dueDate: '' }
        showSuccess(t('verein', 'Fee added'))
        showForm.value = false
        await fetchFees()
      } catch (error) {
        console.error('Error adding fee:', error)
        showError(extractErrorMessage(error, t('verein', 'Error adding the fee')))
      } finally {
        loading.value = false
      }
    }

    const startEdit = (fee) => {
      editingId.value = fee.id
      // a due date is a day (the column is a datetime, the fee run stores midnight)
      editData.value = { ...fee, dueDate: (fee.dueDate || '').slice(0, 10) }
    }

    const saveEdit = async (id) => {
      loading.value = true
      try {
        await api.put(`finance/${id}`, editData.value)
        editingId.value = null
        showSuccess(t('verein', 'Fee updated'))
        await fetchFees()
      } catch (error) {
        console.error('Error updating fee:', error)
        showError(extractErrorMessage(error, t('verein', 'Error updating the fee')))
      } finally {
        loading.value = false
      }
    }

    const cancelEdit = () => {
      editingId.value = null
      editData.value = {}
    }

    const deleteFee = async (id) => {
      if (!(await confirmAction(t('verein', 'Delete fee'), t('verein', 'Delete this fee?'), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return

      loading.value = true
      try {
        await api.delete(`finance/${id}`)
        showSuccess(t('verein', 'Fee deleted'))
        await fetchFees()
      } catch (error) {
        console.error('Error deleting fee:', error)
        showError(extractErrorMessage(error, t('verein', 'Error deleting the fee')))
      } finally {
        loading.value = false
      }
    }

    const getMemberName = (memberId) => {
      const member = members.value.find(m => m.id === memberId)
      return member ? member.fullName : t('verein', 'Member #{id}', { id: memberId })
    }

    const getStatusLabel = (status) => {
      return statusOptions.find(o => o.id === status)?.label || status
    }

    const dunningLabel = (level) => DUNNING_LEVELS[level] || t('verein', 'Reminder level {level}', { level })
    // a stored timestamp: only its calendar day
    const formatDay = (v) => formatDate(String(v || '').slice(0, 10))

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
      t,
      canWriteFinance,
      showForm,
      formSection,
      openForm,
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
      dunningLabel,
      formatDay,
      formatDate,
      formatMoney
    }
  }
}
</script>

<style scoped lang="scss">
.finance-container {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

/* annual fee run and payment reminders next to each other when there is room */
.tools {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr));
  gap: 2rem;
  align-items: start;

  > * { margin-bottom: 0; min-width: 0; }
}

.form-section,
.table-section {
  background: var(--color-main-background);
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 20px;
  border: 1px solid var(--color-border);

  @media (max-width: 600px) {
    padding: 16px 12px;
  }
  box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));

  h2 {
    margin-top: 0;
    margin-bottom: 16px;
    font-size: 18px;
    color: var(--color-text);
  }
}

.form-actions {
  grid-column: 1 / -1;
  display: flex;
  gap: 8px;
}

.fee-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  align-items: end;

  /* NcSelect's own min-width (260px) is wider than a grid cell */
  :deep(.v-select.select) {
    min-width: 0;
    width: 100%;
  }
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
  flex-wrap: wrap;
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

      /* the row color is a variable so the sticky actions cell can paint exactly the same (App.vue) */
      &:hover {
        --row-tint: var(--color-background-hover);
      }

      &.editing {
        --row-tint: var(--color-primary-light);
      }

      &.paid {
        opacity: 0.7;
      }

      &.overdue {
        --row-tint: var(--color-error-light);
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

.dunning-badge {
  display: inline-block;
  margin-left: 6px;
  font-size: 12px;
  color: var(--color-error);
  white-space: nowrap;
}

.actions {
  display: flex;
  gap: 4px;
  align-items: center;
}

.empty-state {
  text-align: center;
  color: var(--color-text-secondary);
  padding: 40px 20px;
}
</style>
