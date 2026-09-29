<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <div class="members-container">
    <!-- Alert Komponente -->
    <Alert
      ref="alertRef"
      type="error"
      :message="alertError"
      :errors="alertErrors"
    />

    <!-- Add/edit form: only open while adding or editing, so the list gets the full width -->
    <div v-if="canManage && showForm" ref="formSection" class="form-section">
      <h2>{{ editingId ? t('verein', 'Edit member') : t('verein', 'Add member') }}</h2>

      <!-- Add a person who is already a member of another club (no duplicate) -->
      <div v-if="!editingId" class="lookup-box">
        <h3 class="form-subheader">{{ t('verein', 'Add a person from another club') }}</h3>
        <NcTextField
          :model-value="lookupQuery"
          @update:model-value="onLookupInput"
          type="text"
          :label="t('verein', 'Search name')"
          :placeholder="t('verein', 'Last name or first name')"
        />
        <label class="date-field">
          <span>{{ t('verein', 'Date joined (this club)') }}</span>
          <input v-model="lookupJoinDate" type="date" class="form-input" />
        </label>
        <ul v-if="lookupResults.length" class="lookup-results">
          <li v-for="r in lookupResults" :key="r.id">
            <span>{{ r.fullName }} <small>({{ r.birthDate ? formatDate(r.birthDate) : t('verein', 'no date of birth') }}, {{ r.city || t('verein', 'no city') }})</small></span>
            <NcButton variant="secondary" :disabled="loading" @click="attachExisting(r)">{{ t('verein', 'Add to this club') }}</NcButton>
          </li>
        </ul>
        <p v-else-if="lookupQuery.trim().length >= 2 && lookupDone" class="hint">
          {{ t('verein', 'No matching person found in your other clubs.') }}
        </p>
      </div>

      <form @submit.prevent="saveMember" class="member-form">
        <h3 class="form-subheader">{{ t('verein', 'Personal data') }}</h3>
        <NcSelect
          v-model="formData.salutation"
          :options="salutationOptions"
          :reduce="option => option.id"
          label="label"
          :input-label="t('verein', 'Title')"
          :placeholder="t('verein', 'Select…')"
        />
        <NcTextField
          :model-value="formData.firstName"
          @update:model-value="formData.firstName = $event"
          type="text"
          :label="t('verein', 'First name')"
          placeholder="Max"
        />
        <NcTextField
          :model-value="formData.name"
          @update:model-value="formData.name = $event"
          type="text"
          :label="t('verein', 'Last name')"
          placeholder="Mustermann"
          required
        />
        <label class="date-field">
          <span>{{ t('verein', 'Date of birth') }}</span>
          <input v-model="formData.birthDate" type="date" class="form-input" />
        </label>

        <h3 class="form-subheader">{{ t('verein', 'Address') }}</h3>
        <NcTextField
          :model-value="formData.street"
          @update:model-value="formData.street = $event"
          type="text"
          :label="t('verein', 'Street')"
        />
        <NcTextField
          :model-value="formData.postalCode"
          @update:model-value="formData.postalCode = $event"
          type="text"
          :label="t('verein', 'Postal code')"
        />
        <NcTextField
          :model-value="formData.city"
          @update:model-value="formData.city = $event"
          type="text"
          :label="t('verein', 'City')"
        />
        <NcTextField
          :model-value="formData.email"
          @update:model-value="formData.email = $event"
          type="email"
          :label="t('verein', 'Email')"
          :placeholder="t('verein', 'max@example.com (optional)')"
        />

        <div class="account-link">
          <NcSelectUsers
            v-model="selectedUser"
            :options="userOptions"
            :input-label="t('verein', 'Linked Nextcloud account')"
            :disabled="!canManageRoles"
            :placeholder="t('verein', 'Enter a name or account name')"
            @search="onUserSearch"
            @update:model-value="onUserPicked"
          />
          <p class="hint">
            {{ t('verein', 'Optional. Links this member to their Nextcloud account (e.g. for board members). They can then see their data under “My data”; the position and the linked account determine the automatic permissions.') }}
            <strong v-if="!canManageRoles">{{ t('verein', 'Only people allowed to manage roles can change the position and the linked account.') }}</strong>
            <span v-if="!formData.userId && userOptions.length">{{ t('verein', 'Matching names are suggested in the list.') }}</span>
          </p>
        </div>

        <h3 class="form-subheader">{{ t('verein', 'Membership') }}</h3>
        <label class="date-field">
          <span>{{ t('verein', 'Date joined') }}</span>
          <input v-model="formData.joinDate" type="date" class="form-input" />
        </label>
        <label class="date-field">
          <span>{{ t('verein', 'Date left') }}</span>
          <input v-model="formData.leaveDate" type="date" class="form-input" />
        </label>
        <NcSelect
          v-model="formData.role"
          :options="roleOptions"
          :reduce="option => option.id"
          label="label"
          :input-label="t('verein', 'Position')"
          :clearable="false"
          :disabled="!canManageRoles"
        />
        <NcSelect
          v-model="formData.feeRateId"
          class="fee-rate-select"
          :options="feeRateOptions"
          :reduce="r => r.id"
          label="label"
          :input-label="t('verein', 'Fee category')"
          :placeholder="t('verein', 'Club default')"
        />
        <label class="checkbox-field">
          <input v-model="formData.foundingMember" type="checkbox" />
          <span>{{ t('verein', 'Founding member') }}</span>
        </label>
        <label class="checkbox-field">
          <input v-model="formData.deceased" type="checkbox" />
          <span>{{ t('verein', 'Deceased') }}</span>
        </label>

        <h3 class="form-subheader">{{ t('verein', 'Bank details') }}</h3>
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

        <h3 class="form-subheader">{{ t('verein', 'SEPA direct debit mandate (for this club)') }}</h3>
        <NcTextField
          :model-value="formData.mandateReference"
          @update:model-value="formData.mandateReference = $event"
          type="text"
          :label="t('verein', 'Mandate reference')"
          :placeholder="t('verein', 'empty = automatic')"
        />
        <label class="date-field">
          <span>{{ t('verein', 'Date of signature') }}</span>
          <input v-model="formData.mandateDate" type="date" class="form-input" />
        </label>
        <div class="mandate-file">
          <span>{{ t('verein', 'Signed mandate (PDF)') }}</span>
          <div class="mandate-file-row">
            <a v-if="formData.mandateFile" :href="mandateFileUrl(formData.mandateFile)" target="_blank" rel="noopener">{{ formData.mandateFile }}</a>
            <span v-else class="hint">{{ t('verein', 'no file linked') }}</span>
            <NcButton type="button" variant="secondary" @click="pickMandateFile">{{ t('verein', 'Choose file') }}</NcButton>
            <NcButton v-if="formData.mandateFile" type="button" variant="tertiary" @click="formData.mandateFile = ''">{{ t('verein', 'Remove') }}</NcButton>
          </div>
        </div>

        <div class="form-actions">
          <NcButton type="submit" variant="primary" :disabled="loading">
            {{ loading ? t('verein', 'Saving…') : (editingId ? t('verein', 'Save') : t('verein', 'Add')) }}
          </NcButton>
          <NcButton type="button" variant="tertiary" @click="cancelEdit">
            {{ t('verein', 'Cancel') }}
          </NcButton>
        </div>
      </form>
    </div>

    <!-- Members Table -->
    <MemberImport v-if="canManage && showImport" @done="fetchMembers" />

    <div class="table-section">
      <div class="section-header">
        <h2>{{ t('verein', 'Member list') }}</h2>
        <div class="export-buttons">
          <NcButton v-if="canManage" variant="primary" @click="startAdd">
            {{ t('verein', 'Add member') }}
          </NcButton>
          <NcButton v-if="canManage" variant="secondary" @click="showImport = !showImport">
            {{ showImport ? t('verein', 'Close import') : t('verein', 'Import CSV') }}
          </NcButton>
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
        <table class="members-table sticky-actions">
          <thead>
            <tr>
              <th>{{ t('verein', '#') }}</th>
              <th>{{ t('verein', 'Name') }}</th>
              <th>{{ t('verein', 'Email') }}</th>
              <th>{{ t('verein', 'City') }}</th>
              <th>{{ t('verein', 'Account') }}</th>
              <th>{{ t('verein', 'Age') }}</th>
              <th>{{ t('verein', 'Member since') }}</th>
              <th>{{ t('verein', 'Position') }}</th>
              <th>{{ t('verein', 'Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in filteredMembers" :key="member.id" :class="{ editing: editingId === member.id }">
              <td>{{ member.id }}</td>
              <td>{{ displayName(member) }}</td>
              <td>{{ member.email }}</td>
              <td>{{ member.city || '-' }}</td>
              <td>
                <span v-if="member.userId" :title="member.userId">{{ member.userDisplayName }}<span v-if="member.userExists === false" class="hint"> ({{ t('verein', 'account no longer exists') }})</span></span>
                <span v-else class="hint">–</span>
              </td>
              <td>{{ member.age !== null && member.age !== undefined ? n('verein', '%n year', '%n years', member.age) : '-' }}</td>
              <td>{{ member.membershipYears !== null && member.membershipYears !== undefined ? n('verein', '%n year', '%n years', member.membershipYears) : '-' }}</td>
              <td>
                <!-- position plus the status where it is not simply "active" (one column less, so the list fits) -->
                <div class="status-cell">
                <span :class="['role-badge', member.role]">{{ roleLabel(member.role) }}</span>
                <span v-if="member.deceased" class="status-badge deceased">{{ t('verein', 'Deceased') }}</span>
                <span v-if="member.anonymizedAt" class="status-badge anonymized" :title="t('verein', 'Personal data was permanently removed')">{{ t('verein', 'Anonymized') }}</span>
                <span v-else-if="member.isFormer" class="status-badge former">{{ t('verein', 'Former') }}</span>
                <span
                  v-else-if="member.deactivated"
                  class="status-badge deactivated"
                  :title="t('verein', 'No fees, no calendar events and no automatic permissions until the member is activated again')"
                >{{ t('verein', 'Deactivated') }}</span>
                <span v-if="member.foundingMember" class="status-badge founding" :title="t('verein', 'Founding member')">★</span>
                </div>
              </td>
              <td>
                <!-- Edit stays visible, everything else goes into the "…" menu so a row stays one line high -->
                <div class="actions">
                  <NcButton v-if="canManage" @click="startEdit(member)" variant="secondary">
                    {{ t('verein', 'Edit') }}
                  </NcButton>
                  <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: displayName(member) })" :disabled="loading">
                    <NcActionButton
                      v-if="canManage && canManageRoles && member.deactivated"
                      close-after-click
                      @click="activateMember(member.id)"
                    >
                      {{ t('verein', 'Activate') }}
                    </NcActionButton>
                    <NcActionButton
                      v-else-if="canManage && canManageRoles"
                      close-after-click
                      @click="deactivateMember(member.id)"
                    >
                      {{ t('verein', 'Deactivate') }}
                    </NcActionButton>
                    <NcActionButton
                      close-after-click
                      :description="t('verein', 'All data stored about this person in this club, as a JSON file (right of access, Art. 15 GDPR)')"
                      @click="exportMember(member)"
                    >
                      {{ t('verein', 'Personal data export') }}
                    </NcActionButton>
                    <NcActionButton
                      v-if="canManage"
                      close-after-click
                      @click="deleteMember(member.id)"
                    >
                      {{ t('verein', 'Remove from club') }}
                    </NcActionButton>
                    <NcActionButton
                      v-if="canManageRoles && member.isFormer && !member.anonymizedAt"
                      close-after-click
                      :description="t('verein', 'Remove personal data irreversibly (only once the person has left every club or is deceased)')"
                      @click="anonymizeTarget = member"
                    >
                      {{ t('verein', 'Anonymize') }}
                    </NcActionButton>
                  </NcActions>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="filteredMembers.length === 0" class="empty-state">{{ t('verein', 'No members in this category') }}</p>
    </div>

    <AnonymizeDialog
      :member="anonymizeTarget"
      @close="anonymizeTarget = null"
      @done="anonymizeTarget = null; fetchMembers()"
    />
  </div>
</template>

<script>
import { ref, reactive, computed, onMounted, nextTick } from 'vue'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { showSuccess, showError, getFilePickerBuilder } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import { t, n } from '@nextcloud/l10n'
import { formatMoney, formatDate, salutationLabel } from '../format'
import { absoluteUrl } from '../absoluteUrl'
import { currentClub, can } from '../store/club'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import Alert from './Alert.vue'
import ExportButtons from './ExportButtons.vue'
import AnonymizeDialog from './AnonymizeDialog.vue'
import MemberImport from './MemberImport.vue'

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
    NcActionButton,
    NcActions,
    NcButton,
    NcTextField,
    NcSelect,
    NcSelectUsers,
    Alert,
    ExportButtons,
    AnonymizeDialog,
    MemberImport
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
      { id: 'member', label: t('verein', 'Member') },
      { id: 'admin', label: t('verein', 'Board') },
      { id: 'treasurer', label: t('verein', 'Treasurer') }
    ]

    // the stored value stays German (it is printed in letters); only the label is translated
    const salutationOptions = [
      { id: 'Herr', label: t('verein', 'Mr') },
      { id: 'Frau', label: t('verein', 'Ms') },
      { id: 'Divers', label: t('verein', 'Mx') },
      { id: 'Firma', label: t('verein', 'Company') }
    ]

    const formData = reactive(emptyFormData())
    const canManage = computed(() => can('verein.member.manage'))
    const canManageRoles = computed(() => can('verein.role.manage'))

    // fee categories of the current club, for the membership section
    const feeRateOptions = computed(() =>
      (currentClub.value?.feeRates || []).map(r => ({
        id: r.id,
        label: r.name + ' (' + formatMoney(r.amount) + ')' + (r.isDefault ? ' – ' + t('verein', 'default') : '')
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
          subname: u.linkedTo ? t('verein', '{id} – already linked to {name}', { id: u.id, name: u.linkedTo }) : u.id
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
        showError(t('verein', '{user} is already linked to {name}', { user: user.displayName, name: user.linkedTo }))
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
        showSuccess(t('verein', '{name} was added to the club', { name: person.fullName }))
        lookupQuery.value = ''
        lookupResults.value = []
        lookupJoinDate.value = ''
        await fetchMembers()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Person could not be added')))
      } finally {
        loading.value = false
      }
    }

    // Signed mandate PDFs live in the club's team folder in Nextcloud Files
    const pickMandateFile = async () => {
      try {
        const start = currentClub.value?.documentsPath || '/'
        const path = await getFilePickerBuilder(t('verein', 'Choose signed SEPA mandate'))
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
        showError(extractErrorMessage(error, t('verein', 'Error loading the members')))
      } finally {
        loading.value = false
      }
    }

    const categories = computed(() => {
      const active = members.value.filter(m => !m.isFormer).length
      const former = members.value.filter(m => m.isFormer).length
      return [
        { id: 'active', label: t('verein', 'Active'), count: active },
        { id: 'former', label: t('verein', 'Former'), count: former },
        { id: 'all', label: t('verein', 'All'), count: members.value.length }
      ]
    })

    const filteredMembers = computed(() => {
      if (category.value === 'active') return members.value.filter(m => !m.isFormer)
      if (category.value === 'former') return members.value.filter(m => m.isFormer)
      return members.value
    })

    const displayName = (member) => {
      const prefix = member.salutation ? salutationLabel(member.salutation) + ' ' : ''
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
          showSuccess(editingId.value ? t('verein', 'Member updated') : t('verein', 'Member added'))
          cancelEdit()
          await fetchMembers()
        }
      } catch (error) {
        const data = error.response?.data
        alertError.value = data?.message || error.message || t('verein', 'Error saving the member')
        alertErrors.value = data?.errors || []
        if (alertRef.value) alertRef.value.open()
        console.error('Error saving member:', error)
      } finally {
        loading.value = false
      }
    }

    const showForm = ref(false)
    const formSection = ref(null)
    const revealForm = () => {
      showForm.value = true
      nextTick(() => formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
    }

    // The API sends null for empty fields; NcTextField renders nothing at all for a null value
    // (the BIC and mandate reference fields disappeared), so empty fields get the form's default instead.
    const fillForm = (member) => {
      const defaults = emptyFormData()
      Object.assign(formData, defaults, member)
      for (const key of Object.keys(defaults)) {
        if (formData[key] === null || formData[key] === undefined) formData[key] = defaults[key]
      }
    }

    const startAdd = () => {
      cancelEdit()
      revealForm()
    }

    const startEdit = async (member) => {
      editingId.value = member.id
      revealForm()
      fillForm(member)
      setSelectedUserFrom(member)
      userOptions.value = []

      try {
        const response = await api.getMember(member.id)
        const latest = response.data?.data || response.data?.member
        if (latest) {
          fillForm(latest)
          setSelectedUserFrom(latest)
        }
        // Suggest matching Nextcloud accounts by the member's name
        if (!formData.userId) runUserSearch(formData.name)
      } catch (error) {
        console.error('Error loading member details:', error)
        showError(extractErrorMessage(error, t('verein', 'Error loading the member')))
      }
    }

    function cancelEdit() {
      showForm.value = false
      editingId.value = null
      Object.assign(formData, emptyFormData())
      selectedUser.value = null
      userOptions.value = []
    }

    const deleteMember = async (id) => {
      if (!(await confirmAction(t('verein', 'Remove from the club'), t('verein', 'Remove this member from the club? Their fees in this club are deleted as well; the person remains in other clubs.'), { labelConfirm: t('verein', 'Remove'), severity: 'error' }))) return

      loading.value = true
      try {
        await api.delete(`members/${id}`)
        showSuccess(t('verein', 'Member deleted'))
        if (editingId.value === id) cancelEdit()
        await fetchMembers()
      } catch (error) {
        console.error('Error deleting member:', error)
        showError(extractErrorMessage(error, t('verein', 'Error deleting the member')))
      } finally {
        loading.value = false
      }
    }

    const deactivateMember = async (id) => {
      if (!(await confirmAction(t('verein', 'Deactivate member'), t('verein', 'Deactivate this member? They are no longer included in fees and SEPA collections, their birthday and anniversary events are hidden and the permissions derived from their position are suspended. Nothing is deleted; “Activate” restores everything.'), { labelConfirm: t('verein', 'Deactivate'), severity: 'warning' }))) return

      loading.value = true
      try {
        await api.post(`members/${id}/deactivate`)
        showSuccess(t('verein', 'Member deactivated'))
        await fetchMembers()
      } catch (error) {
        console.error('Error deactivating member:', error)
        showError(extractErrorMessage(error, t('verein', 'Error deactivating the member')))
      } finally {
        loading.value = false
      }
    }

    const activateMember = async (id) => {
      loading.value = true
      try {
        await api.post(`members/${id}/activate`)
        showSuccess(t('verein', 'Member activated'))
        await fetchMembers()
      } catch (error) {
        console.error('Error activating member:', error)
        showError(extractErrorMessage(error, t('verein', 'Error activating the member')))
      } finally {
        loading.value = false
      }
    }

    const showImport = ref(false)

    // member row whose anonymize confirmation is open (null = closed)
    const anonymizeTarget = ref(null)

    // Art. 15 GDPR information for a person who cannot use "Meine Daten" themselves
    const exportMember = async (member) => {
      try {
        const response = await api.get(`members/${member.id}/export`, { responseType: 'blob' })
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        link.download = t('verein', 'member-data-{id}.json', { id: member.id })
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      } catch (error) {
        // the error body arrives as a Blob because of responseType
        let message = t('verein', 'Personal data export failed')
        try {
          message = JSON.parse(await error.response.data.text()).message || message
        } catch (e) {
          // keep the generic message
        }
        showError(message)
      }
    }

    const roleLabel = (role) => {
      return roleOptions.find(r => r.id === role)?.label || role
    }

    return {
      t,
      n,
      formatMoney,
      formatDate,
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
      startAdd,
      showForm,
      formSection,
      cancelEdit,
      deleteMember,
      deactivateMember,
      activateMember,
      anonymizeTarget,
      showImport,
      exportMember,
      fetchMembers,
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

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;

  h2 {
    margin: 0;
    font-size: 18px;
    color: var(--color-text);
  }
}

.export-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.member-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  align-items: end;

  /* NcSelect brings a min-width of 260px, wider than a grid cell: it overlapped the next field */
  :deep(.v-select.select) {
    min-width: 0;
    width: 100%;
  }
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

  > .input-field { max-width: 480px; }
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

  > span {
    font-size: 13px;
    color: var(--color-text-secondary);
  }
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

  > :first-child { max-width: 480px; }
}
/* "Adults (€60.00) – default" does not fit into one cell */
@media (min-width: 900px) {
  .fee-rate-select { grid-column: span 2; }
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
      padding: 12px 10px;
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

      td {
        padding: 12px 10px;
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

  &.anonymized {
    background: var(--color-background-darker);
    color: var(--color-text-maxcontrast);
    font-style: italic;
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
  gap: 4px;
  align-items: center;
}

.empty-state {
  text-align: center;
  color: var(--color-text-secondary);
  padding: 40px 20px;
}
</style>
