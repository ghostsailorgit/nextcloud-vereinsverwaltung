<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <div class="roles-page">
    <h2>{{ t('verein', 'Role management') }}</h2>

    <div v-if="isAdmin" class="controls">
      <NcButton @click="openCreate" variant="primary">➕ {{ t('verein', 'New role') }}</NcButton>
    </div>
    <p v-else class="hint">
      {{ t('verein', 'The roles and their permissions apply to all clubs and are maintained by Nextcloud administrators. Here you can assign them to accounts for {club}.', { club: clubName }) }}
    </p>

    <div v-if="showForm" class="modal-overlay">
      <div class="modal">
      <h3>{{ editingRole ? t('verein', 'Edit role') : t('verein', 'New role') }}</h3>
      <form @submit.prevent="saveRole">
        <NcTextField
          id="name"
          :model-value="form.name"
          @update:model-value="form.name = $event"
          :label="t('verein', 'Name')"
          required
        />

        <NcTextField
          id="description"
          :model-value="form.description"
          @update:model-value="form.description = $event"
          :label="t('verein', 'Description')"
        />

        <label class="permissions-label">{{ t('verein', 'Permissions') }}</label>
        <div class="permissions-list">
          <div v-if="permissionsList.length === 0">{{ t('verein', 'Loading permissions…') }}</div>
          <NcCheckboxRadioSwitch
            v-for="perm in permissionsList"
            :key="(perm.key || perm)"
            type="checkbox"
            :value="(perm.key || perm)"
            v-model="form.permissions"
          >
            {{ (perm.label || perm.name || perm) }}
          </NcCheckboxRadioSwitch>
        </div>

        <div class="form-actions">
          <NcButton type="submit" variant="primary">{{ t('verein', 'Save') }}</NcButton>
          <NcButton type="button" variant="tertiary" @click="closeForm">{{ t('verein', 'Cancel') }}</NcButton>
        </div>
      </form>
    </div>
  </div>

    <div class="table-card">
      <table>
        <colgroup>
          <col style="width: 15%">
          <col style="width: 20%">
          <col style="width: 45%">
          <col style="width: 20%">
        </colgroup>
        <thead>
          <tr>
            <th>{{ t('verein', 'Name') }}</th>
            <th>{{ t('verein', 'Description') }}</th>
            <th>{{ t('verein', 'Permissions') }}</th>
            <th>{{ t('verein', 'Actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="role in roles" :key="role.id">
            <td>{{ role.name }}</td>
            <td>{{ role.description || '-' }}</td>
            <td class="permissions"><small>{{ (role.permissions || []).join(', ') }}</small></td>
            <td class="actions">
              <template v-if="isAdmin">
              <NcButton @click="editRole(role)" variant="secondary" :aria-label="t('verein', 'Edit role')">✏️</NcButton>
              <NcButton @click="deleteRole(role.id)" variant="error" :aria-label="t('verein', 'Delete role')">🗑️</NcButton>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Assign Role To User -->
    <div class="form-card">
      <h3>{{ t('verein', 'Assign a role – {club}', { club: clubName }) }}</h3>
      <div class="assign-row">
        <NcSelectUsers
          v-model="assign.selectedUser"
          :options="assign.searchResults"
          :input-label="t('verein', 'Nextcloud account')"
          :placeholder="t('verein', 'Enter a name or account name')"
          @search="onAssignQueryInput"
        />

        <NcSelect
          v-model="assign.roleId"
          :options="roles"
          :reduce="r => r.id"
          label="name"
          :input-label="t('verein', 'Role')"
          :placeholder="t('verein', 'Select role…')"
        />

        <div class="form-actions">
          <NcButton variant="primary" @click="assignRoleToUser">{{ t('verein', 'Assign') }}</NcButton>
        </div>
      </div>
    </div>

    <!-- Who holds a role in this club -->
    <div class="table-card">
      <h3>{{ t('verein', 'Assigned roles – {club}', { club: clubName }) }}</h3>
      <table class="assignments">
        <thead>
          <tr><th>{{ t('verein', 'Account') }}</th><th>{{ t('verein', 'Roles') }}</th><th>{{ t('verein', 'Actions') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in assignments" :key="a.userId">
            <td>{{ a.displayName }} <small>({{ a.userId }})</small></td>
            <td>
              {{ a.roles.join(', ') }}
              <span v-if="a.automaticRoles && a.automaticRoles.length" class="auto" :title="t('verein', 'derived from the membership (“Club” tab)')">
                <template v-if="a.roles.length"> · </template>{{ a.automaticRoles.join(', ') }} ({{ t('verein', 'automatic') }})
              </span>
            </td>
            <td>
              <NcButton v-if="a.roles.length" variant="error" @click="removeAssignments(a)">{{ t('verein', 'Revoke assigned roles') }}</NcButton>
              <small v-else>{{ t('verein', 'ends with the membership') }}</small>
            </td>
          </tr>
          <tr v-if="assignments.length === 0">
            <td colspan="3">{{ t('verein', 'No roles have been assigned in this club yet.') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import { confirmAction } from '../confirm'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { t } from '@nextcloud/l10n'
import { clubState, currentClub } from '../store/club'

export default {
  name: 'Roles',
  components: { NcButton, NcTextField, NcSelect, NcSelectUsers, NcCheckboxRadioSwitch },
  computed: {
    isAdmin() { return clubState.isAdmin },
    clubName() { return currentClub.value?.name || '' }
  },
  data() {
    return {
      roles: [],
      assignments: [],
      permissionsList: [],
      showForm: false,
      editingRole: null,
      form: {
        name: '',
        description: '',
        permissions: []
      },
      // assign role form
      assign: {
        // the picked Nextcloud account ({ id, user, displayName, ... }), not a club Member
        selectedUser: null,
        roleId: null,
        // remote Nextcloud-account search results (NcSelectUsersModel[])
        searchResults: [],
        // debounce timer
        searchTimer: null
      }
    }
  },
  mounted() {
    this.loadRoles()
    this.loadPermissions()
    this.loadAssignments()
  },
  methods: {
    t,
    async loadRoles() {
      try {
        const res = await axios.get(generateUrl('/apps/verein/roles'))
        this.roles = res.data || []
      } catch (e) {
        console.error('Error loading roles', e)
        showError(extractErrorMessage(e, t('verein', 'Error loading the roles')))
      }
    },
    async loadAssignments() {
      try {
        const res = await axios.get(generateUrl('/apps/verein/roles/assignments'), { params: { clubId: clubState.currentId } })
        this.assignments = Array.isArray(res.data) ? res.data : []
      } catch (e) {
        console.error('Error loading assignments', e)
        this.assignments = []
      }
    },
    async removeAssignments(entry) {
      if (!(await confirmAction(t('verein', 'Revoke roles'), t('verein', 'Revoke all roles of {name} in this club?', { name: entry.displayName }), { labelConfirm: t('verein', 'Revoke'), severity: 'warning' }))) return
      try {
        await axios.delete(generateUrl('/apps/verein/roles/users'), { params: { userId: entry.userId, clubId: clubState.currentId } })
        showSuccess(t('verein', 'Roles revoked'))
        this.loadAssignments()
      } catch (e) {
        console.error('Error removing roles', e)
        showError(extractErrorMessage(e, t('verein', 'Error revoking the roles')))
      }
    },
    async loadPermissions() {
      try {
        const res = await axios.get(generateUrl('/apps/verein/permissions'))
        const data = res.data || {}
        // API returns { permissions: [...] }
        this.permissionsList = data.permissions || []
      } catch (e) {
        console.error('Error loading permissions', e)
        showError(extractErrorMessage(e, t('verein', 'Error loading the permissions')))
      }
    },
    async searchUsers(query) {
      try {
        if (!query || query.trim() === '') {
          this.assign.searchResults = []
          return
        }
        const res = await axios.get(generateUrl('/apps/verein/roles/search-users'), { params: { query } })
        this.assign.searchResults = Array.isArray(res.data) ? res.data : []
      } catch (e) {
        console.error('Error searching users', e)
        this.assign.searchResults = []
        showError(extractErrorMessage(e, t('verein', 'Error searching accounts')))
      }
    },
    onAssignQueryInput(query) {
      // debounce remote calls
      if (this.assign.searchTimer) clearTimeout(this.assign.searchTimer)
      this.assign.searchTimer = setTimeout(() => {
        this.searchUsers(query)
      }, 300)
    },
    openCreate() {
      this.editingRole = null
      this.form = { name: '', description: '', permissions: [] }
      this.showForm = true
    },
    closeForm() {
      this.showForm = false
      this.editingRole = null
    },
    async saveRole() {
      try {
        const payload = {
          name: this.form.name,
          description: this.form.description,
          permissions: Array.isArray(this.form.permissions) ? this.form.permissions : []
        }

        if (this.editingRole) {
          await axios.put(generateUrl(`/apps/verein/roles/${this.editingRole.id}`), payload)
          showSuccess(t('verein', 'Role updated'))
        } else {
          await axios.post(generateUrl('/apps/verein/roles'), payload)
          showSuccess(t('verein', 'Role created'))
        }

        this.loadRoles()
        this.closeForm()
      } catch (e) {
        console.error('Error saving role', e)
        showError(extractErrorMessage(e, t('verein', 'Error saving the role')))
      }
    },
    editRole(role) {
      this.editingRole = role
      this.form = {
        name: role.name,
        description: role.description || '',
        permissions: role.permissions || []
      }
      this.showForm = true
    },
    async assignRoleToUser() {
      const userId = this.assign.selectedUser?.user
      if (!userId || !this.assign.roleId) {
        showError(t('verein', 'Account and role are required'))
        return
      }
      try {
        const payload = {
          userId,
          roleId: this.assign.roleId,
          clubId: clubState.currentId
        }
        await axios.post(generateUrl('/apps/verein/roles/users'), payload)
        showSuccess(t('verein', 'Role assigned'))
        // clear selection but keep search results
        this.assign.selectedUser = null
        this.assign.roleId = null
        this.loadAssignments()
      } catch (e) {
        console.error('Error assigning role', e)
        showError(extractErrorMessage(e, t('verein', 'Error assigning the role')))
      }
    },
    async deleteRole(id) {
      if (!(await confirmAction(t('verein', 'Delete role'), t('verein', 'Delete this role?'), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      try {
        await axios.delete(generateUrl(`/apps/verein/roles/${id}`))
        showSuccess(t('verein', 'Role deleted'))
        this.loadRoles()
      } catch (e) {
        console.error('Error deleting role', e)
        showError(extractErrorMessage(e, t('verein', 'Error deleting the role')))
      }
    }
  }
}
</script>

<style scoped>
.roles-page { padding: 20px }
.auto { color: var(--color-text-maxcontrast); font-style: italic }
.hint { color: var(--color-text-maxcontrast); margin-bottom: 12px }
.controls { margin-bottom: 12px }
.form-card, .table-card { background: var(--color-main-background); border: 1px solid var(--color-border); padding: 16px; border-radius: 6px; margin-bottom: 16px }
.form-actions { display:flex; gap:8px }
.assign-row { display: grid; gap: 12px; max-width: 480px }
.permissions-label { display: block; margin-top: 8px; margin-bottom: 4px; font-weight: bold }

/* table-layout: fixed + a <colgroup> pinning all 4 column widths (an
   "auto" column doesn't reliably get squeezed by its neighbors in fixed
   layout), otherwise the long comma-separated permissions string doesn't
   wrap inside its own cell and visually bleeds into/under the Aktionen
   column's buttons */
.table-card { overflow-x: auto }
.table-card table { width: 100%; table-layout: fixed; border-collapse: collapse }
.table-card th, .table-card td { padding: 8px; text-align: left; vertical-align: top }
/* Nextcloud core CSS sets white-space: nowrap on <small> with higher
   specificity than this scoped rule - without the override here,
   word-break/overflow-wrap are moot (nowrap suppresses wrapping outright)
   and the text paints straight over the Aktionen column instead */
.permissions { word-break: break-word; overflow-wrap: break-word }
.permissions small { white-space: normal !important }
.actions { display: flex; gap: 8px; flex-wrap: wrap }

/* modal */
.modal-overlay { position: fixed; inset: 0; display:flex; align-items:center; justify-content:center; background: rgba(0,0,0,0.35); z-index: 1200 }
.modal { background: var(--color-main-background); border-radius:8px; padding:18px; width: 720px; max-width: calc(100% - 32px); box-shadow: 0 10px 30px rgba(0,0,0,0.25) }
</style>
