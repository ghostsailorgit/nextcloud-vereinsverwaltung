<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="clubs-page">
    <h2>{{ t('verein', 'Club: {name}', { name: club ? club.name : '–' }) }}</h2>

    <!-- Club data -->
    <div v-if="club && canManage" class="card">
      <h3>{{ t('verein', 'Club data') }}</h3>
      <form class="grid" @submit.prevent="saveClub">
        <NcTextField :model-value="form.name" @update:model-value="form.name = $event" :label="t('verein', 'Name of the club')" required />
        <NcTextField :model-value="form.street" @update:model-value="form.street = $event" :label="t('verein', 'Street')" />
        <NcTextField :model-value="form.postalCode" @update:model-value="form.postalCode = $event" :label="t('verein', 'Postal code')" />
        <NcTextField :model-value="form.city" @update:model-value="form.city = $event" :label="t('verein', 'City')" />
        <NcTextField
          class="wide"
          :model-value="form.documentsPath"
          @update:model-value="form.documentsPath = $event"
          :label="t('verein', 'Team folder in Files')"
          :placeholder="t('verein', '/My club')"
          :helper-text="t('verein', 'Path of the club\'s folder, where documents and the signed SEPA mandates are stored.')"
        />
        <NcTextField
          class="wide"
          :model-value="form.calendarGroups"
          @update:model-value="form.calendarGroups = $event"
          :label="t('verein', 'Nextcloud groups for the club calendar')"
          :placeholder="t('verein', 'board, members')"
          :helper-text="t('verein', 'Comma-separated. Members of these Nextcloud groups automatically see the calendar “Club events” with birthdays and membership anniversaries in their Calendar app and on their phones (read-only). Birthdays are personal data – only enter groups that should see them.')"
        />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ t('verein', 'Save') }}</NcButton>
        </div>
      </form>
    </div>
    <p v-else-if="club" class="hint">{{ t('verein', 'You are not allowed to change the club data.') }}</p>

    <!-- Bank accounts -->
    <div v-if="club && canManage" class="card">
      <h3>{{ t('verein', 'Bank accounts') }}</h3>
      <p class="hint">
        {{ t('verein', 'A club can have several bank accounts. The label, BIC and creditor ID are used for the SEPA export.') }}
      </p>
      <div v-if="club.accounts.length" class="table-scroll">
        <table class="accounts">
          <thead>
            <tr><th>{{ t('verein', 'Label') }}</th><th>IBAN</th><th>BIC</th><th>{{ t('verein', 'Creditor ID') }}</th><th>{{ t('verein', 'Actions') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="a in club.accounts" :key="a.id">
              <td>{{ a.label || '–' }} <span v-if="a.isDefault" class="badge">{{ t('verein', 'default') }}</span></td>
              <td>{{ a.iban }}</td>
              <td>{{ a.bic || '–' }}</td>
              <td>{{ a.creditorId || '–' }}</td>
              <td>
                <div class="row-actions">
                  <NcButton variant="secondary" @click="editAccount(a)">{{ t('verein', 'Edit') }}</NcButton>
                  <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: a.label || a.iban })">
                    <NcActionButton close-after-click @click="removeAccount(a)">{{ t('verein', 'Delete') }}</NcActionButton>
                  </NcActions>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="hint">{{ t('verein', 'No account yet.') }}</p>

      <h4>{{ accountForm.id ? t('verein', 'Edit bank account') : t('verein', 'Add bank account') }}</h4>
      <form class="grid" @submit.prevent="saveAccount">
        <NcTextField :model-value="accountForm.label" @update:model-value="accountForm.label = $event" :label="t('verein', 'Label')" :placeholder="t('verein', 'e.g. club account')" />
        <NcTextField :model-value="accountForm.iban" @update:model-value="accountForm.iban = $event" label="IBAN" required />
        <NcTextField :model-value="accountForm.bic" @update:model-value="accountForm.bic = $event" label="BIC" />
        <NcTextField :model-value="accountForm.creditorId" @update:model-value="accountForm.creditorId = $event" :label="t('verein', 'Creditor identifier (SEPA)')" placeholder="DE98ZZZ09999999999" />
        <label class="checkbox-field">
          <input v-model="accountForm.isDefault" type="checkbox" />
          <span>{{ t('verein', 'Default account') }}</span>
        </label>
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ accountForm.id ? t('verein', 'Save') : t('verein', 'Add') }}</NcButton>
          <NcButton v-if="accountForm.id" type="button" variant="tertiary" @click="resetAccountForm">{{ t('verein', 'Cancel') }}</NcButton>
        </div>
      </form>
    </div>

    <!-- Fee categories -->
    <div v-if="club && canManage" class="card">
      <h3>{{ t('verein', 'Fee categories') }}</h3>
      <p class="hint">
        {{ t('verein', 'Each fee category has an annual fee. The default category applies to members without a category of their own (member form). An amount of 0 means fee-exempt (e.g. honorary members). The categories are used by the annual fee run (“Finances” tab).') }}
      </p>
      <div v-if="club.feeRates && club.feeRates.length" class="table-scroll">
        <table class="accounts">
          <thead><tr><th>{{ t('verein', 'Fee category') }}</th><th>{{ t('verein', 'Annual fee') }}</th><th>{{ t('verein', 'Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in club.feeRates" :key="r.id">
              <td>{{ r.name }} <span v-if="r.isDefault" class="badge">{{ t('verein', 'default') }}</span></td>
              <td>{{ formatMoney(r.amount) }}</td>
              <td>
                <div class="row-actions">
                  <NcButton variant="secondary" @click="editRate(r)">{{ t('verein', 'Edit') }}</NcButton>
                  <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: r.name })">
                    <NcActionButton close-after-click @click="removeRate(r)">{{ t('verein', 'Delete') }}</NcActionButton>
                  </NcActions>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="hint">{{ t('verein', 'No category yet.') }}</p>

      <h4>{{ rateForm.id ? t('verein', 'Edit fee category') : t('verein', 'Add fee category') }}</h4>
      <form class="grid" @submit.prevent="saveRate">
        <NcTextField :model-value="rateForm.name" @update:model-value="rateForm.name = $event" :label="t('verein', 'Name')" :placeholder="t('verein', 'e.g. adults')" required />
        <NcTextField :model-value="rateForm.amount" @update:model-value="rateForm.amount = $event" type="number" min="0" step="0.01" :label="t('verein', 'Annual fee in €')" placeholder="24" required />
        <label class="checkbox-field">
          <input v-model="rateForm.isDefault" type="checkbox" />
          <span>{{ t('verein', 'Default category') }}</span>
        </label>
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ rateForm.id ? t('verein', 'Save') : t('verein', 'Add') }}</NcButton>
          <NcButton v-if="rateForm.id" type="button" variant="tertiary" @click="resetRateForm">{{ t('verein', 'Cancel') }}</NcButton>
        </div>
      </form>
    </div>

    <!-- Automatic rights -->
    <div v-if="club && canManageRoles" class="card">
      <h3>{{ t('verein', 'Automatic permissions') }}</h3>
      <p class="hint">
        {{ t('verein', 'Members with a linked Nextcloud account (member form) automatically get a role in this club according to their position. These permissions end automatically when someone leaves or dies. Empty = no automatic permissions. Roles assigned in the “Roles” tab are kept.') }}
      </p>
      <form class="grid" @submit.prevent="saveMapping">
        <NcSelect
          v-for="m in mappingRows"
          :key="m.key"
          v-model="mapping[m.key]"
          :options="roleOptions"
          :reduce="r => r.id"
          label="name"
          :input-label="m.label"
          :placeholder="t('verein', 'no automatic role')"
        />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ t('verein', 'Save') }}</NcButton>
        </div>
      </form>
    </div>

    <Backups v-if="isAdmin" />

    <!-- Nextcloud administrators: add / remove clubs -->
    <div v-if="isAdmin" class="card">
      <h3>{{ t('verein', 'Manage clubs (administrator)') }}</h3>
      <form class="grid" @submit.prevent="createClub">
        <NcTextField :model-value="newClubName" @update:model-value="newClubName = $event" :label="t('verein', 'Name of the new club')" required />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ t('verein', 'Create club') }}</NcButton>
        </div>
      </form>
      <p v-if="club" class="delete-row">
        <NcButton variant="error" :disabled="busy" @click="deleteClub">{{ t('verein', 'Delete “{name}”', { name: club.name }) }}</NcButton>
        <span class="hint">{{ t('verein', 'Only possible when the club has no members left.') }}</span>
      </p>
    </div>
  </div>
</template>

<script>
import { reactive, ref, computed, watch, onMounted } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Backups from './Backups.vue'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '../format'
import { clubState, currentClub, loadClubs, setCurrentClub, can } from '../store/club'

const emptyAccount = () => ({ id: null, label: '', iban: '', bic: '', creditorId: '', isDefault: false })

export default {
  name: 'Clubs',
  components: { NcActionButton, NcActions, NcButton, NcTextField, NcSelect, Backups },
  setup() {
    const busy = ref(false)
    const newClubName = ref('')
    const accountForm = reactive(emptyAccount())
    const form = reactive({ name: '', street: '', postalCode: '', city: '', documentsPath: '', calendarGroups: '' })

    const club = computed(() => currentClub.value)
    const isAdmin = computed(() => clubState.isAdmin)
    const canManage = computed(() => can('verein.club.manage'))
    const canManageRoles = computed(() => can('verein.role.manage'))

    // automatic rights: membership role -> app role
    const roleOptions = ref([])
    const mapping = reactive({ member: null, treasurer: null, admin: null })
    const mappingRows = [
      { key: 'admin', label: t('verein', 'Role for the board') },
      { key: 'treasurer', label: t('verein', 'Role for the treasurer') },
      { key: 'member', label: t('verein', 'Role for members') }
    ]
    const fillMapping = () => {
      const m = club.value?.roleMapping || {}
      mapping.member = m.member ?? null
      mapping.treasurer = m.treasurer ?? null
      mapping.admin = m.admin ?? null
    }
    onMounted(async () => {
      if (!canManageRoles.value) return
      try {
        const res = await api.get('roles')
        roleOptions.value = Array.isArray(res.data) ? res.data : []
      } catch (e) {
        roleOptions.value = []
      }
    })

    const fillForm = () => {
      const c = club.value
      Object.assign(form, {
        name: c?.name || '',
        street: c?.street || '',
        postalCode: c?.postalCode || '',
        city: c?.city || '',
        documentsPath: c?.documentsPath || '',
        calendarGroups: (c?.calendarGroups || []).join(', ')
      })
    }
    watch(club, () => { fillForm(); fillMapping() }, { immediate: true })

    const run = async (action, okMessage) => {
      busy.value = true
      try {
        await action()
        if (okMessage) showSuccess(okMessage)
        return true
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Action failed')))
        return false
      } finally {
        busy.value = false
      }
    }

    const saveClub = () => run(async () => {
      await api.updateClub(club.value.id, { ...form })
      await loadClubs()
    }, t('verein', 'Club data saved'))

    const saveMapping = () => run(async () => {
      await api.put(`clubs/${club.value.id}/role-mapping`, {
        member: mapping.member ?? '',
        treasurer: mapping.treasurer ?? '',
        admin: mapping.admin ?? ''
      })
      await loadClubs()
    }, t('verein', 'Automatic permissions saved'))

    const createClub = async () => {
      let createdId = null
      const ok = await run(async () => {
        const res = await api.createClub({ name: newClubName.value })
        createdId = res.data?.data?.id
        await loadClubs()
      }, t('verein', 'Club created'))
      if (ok) {
        newClubName.value = ''
        if (createdId) setCurrentClub(createdId)
      }
    }

    const deleteClub = async () => {
      if (!(await confirmAction(t('verein', 'Delete club'), t('verein', 'Delete club “{name}”? Its bank accounts and role assignments will be lost.', { name: club.value.name }), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      const ok = await run(async () => {
        await api.deleteClub(club.value.id)
        clubState.currentId = null
        await loadClubs()
      }, t('verein', 'Club deleted'))
      if (!ok) await loadClubs()
    }

    // fee categories
    const rateForm = reactive({ id: null, name: '', amount: '', isDefault: false })
    const resetRateForm = () => Object.assign(rateForm, { id: null, name: '', amount: '', isDefault: false })
    const editRate = (r) => Object.assign(rateForm, { id: r.id, name: r.name, amount: String(r.amount), isDefault: r.isDefault })
    const saveRate = async () => {
      const ok = await run(async () => {
        const payload = { name: rateForm.name, amount: rateForm.amount, isDefault: rateForm.isDefault }
        if (rateForm.id) {
          await api.put(`clubs/${club.value.id}/fee-rates/${rateForm.id}`, payload)
        } else {
          await api.post(`clubs/${club.value.id}/fee-rates`, payload)
        }
        await loadClubs()
      }, t('verein', 'Fee category saved'))
      if (ok) resetRateForm()
    }
    const removeRate = async (r) => {
      if (!(await confirmAction(t('verein', 'Delete fee category'), t('verein', 'Delete fee category “{name}”?', { name: r.name }), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      await run(async () => {
        await api.delete(`clubs/${club.value.id}/fee-rates/${r.id}`)
        await loadClubs()
      }, t('verein', 'Fee category deleted'))
    }

    const resetAccountForm = () => Object.assign(accountForm, emptyAccount())
    // null (label, BIC, creditor ID not set) would make NcTextField render nothing, so empty fields stay ''
    const editAccount = (a) => Object.assign(accountForm, emptyAccount(), Object.fromEntries(Object.entries(a).filter(([, v]) => v !== null)))

    const saveAccount = async () => {
      const ok = await run(async () => {
        const payload = { ...accountForm }
        if (accountForm.id) {
          await api.updateClubAccount(club.value.id, accountForm.id, payload)
        } else {
          await api.createClubAccount(club.value.id, payload)
        }
        await loadClubs()
      }, t('verein', 'Bank account saved'))
      if (ok) resetAccountForm()
    }

    const removeAccount = async (a) => {
      if (!(await confirmAction(t('verein', 'Delete bank account'), t('verein', 'Delete bank account {iban}?', { iban: a.iban }), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      await run(async () => {
        await api.deleteClubAccount(club.value.id, a.id)
        await loadClubs()
      }, t('verein', 'Bank account deleted'))
    }

    return {
      t,
      formatMoney,
      rateForm, saveRate, editRate, removeRate, resetRateForm,
      busy, club, isAdmin, canManage, canManageRoles, roleOptions, mapping, mappingRows, saveMapping, form, accountForm, newClubName,
      saveClub, createClub, deleteClub, saveAccount, editAccount, removeAccount, resetAccountForm
    }
  }
}
</script>

<style scoped>
.clubs-page { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px;
}
.card h3 { margin-top: 0; }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 12px;
  align-items: start;
}
/* NcSelect's own min-width (260px) is wider than a grid cell */
.grid :deep(.v-select.select) { min-width: 0; width: 100%; }
.actions { grid-column: 1 / -1; display: flex; gap: 8px; }
.hint { color: var(--color-text-maxcontrast); }
.accounts { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.accounts th, .accounts td { padding: 8px; text-align: left; border-bottom: 1px solid var(--color-border); }
.row-actions { display: flex; gap: 4px; align-items: center; }
/* fields with a long explanation get the full width instead of a narrow column */
.wide { grid-column: 1 / -1; }
.badge {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  border-radius: 8px;
  padding: 1px 8px;
  font-size: 12px;
  margin-left: 6px;
}
.checkbox-field { display: flex; gap: 8px; align-items: center; }
.delete-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 16px; }
</style>
