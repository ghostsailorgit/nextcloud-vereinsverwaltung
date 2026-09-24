<template>
  <div class="roles-page">
    <h2>Rollenverwaltung</h2>

    <div v-if="isAdmin" class="controls">
      <NcButton @click="openCreate" variant="primary">➕ Neue Rolle</NcButton>
    </div>
    <p v-else class="hint">
      Die Rollen und ihre Berechtigungen gelten für alle Vereine und werden von Nextcloud-Administratoren gepflegt.
      Hier kannst du sie Benutzern für <strong>{{ clubName }}</strong> zuweisen.
    </p>

    <div v-if="showForm" class="modal-overlay">
      <div class="modal">
      <h3>{{ editingRole ? 'Rolle bearbeiten' : 'Neue Rolle' }}</h3>
      <form @submit.prevent="saveRole">
        <NcTextField
          id="name"
          :model-value="form.name"
          @update:model-value="form.name = $event"
          label="Name"
          required
        />

        <NcTextField
          id="description"
          :model-value="form.description"
          @update:model-value="form.description = $event"
          label="Beschreibung"
        />

        <label class="permissions-label">Berechtigungen</label>
        <div class="permissions-list">
          <div v-if="permissionsList.length === 0">Lade Berechtigungen...</div>
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
          <NcButton type="submit" variant="primary">Speichern</NcButton>
          <NcButton type="button" variant="tertiary" @click="closeForm">Abbrechen</NcButton>
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
            <th>Name</th>
            <th>Beschreibung</th>
            <th>Berechtigungen</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="role in roles" :key="role.id">
            <td>{{ role.name }}</td>
            <td>{{ role.description || '-' }}</td>
            <td class="permissions"><small>{{ (role.permissions || []).join(', ') }}</small></td>
            <td class="actions">
              <template v-if="isAdmin">
              <NcButton @click="editRole(role)" variant="secondary" aria-label="Rolle bearbeiten">✏️</NcButton>
              <NcButton @click="deleteRole(role.id)" variant="error" aria-label="Rolle löschen">🗑️</NcButton>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Assign Role To User -->
    <div class="form-card">
      <h3>Rolle einem Benutzer zuweisen – {{ clubName }}</h3>
      <div class="assign-row">
        <NcSelectUsers
          v-model="assign.selectedUser"
          :options="assign.searchResults"
          input-label="Benutzer (Nextcloud-Konto)"
          placeholder="Name oder Benutzername eingeben"
          @search="onAssignQueryInput"
        />

        <NcSelect
          v-model="assign.roleId"
          :options="roles"
          :reduce="r => r.id"
          label="name"
          input-label="Rolle"
          placeholder="-- Rolle wählen --"
        />

        <div class="form-actions">
          <NcButton variant="primary" @click="assignRoleToUser">Zuweisen</NcButton>
        </div>
      </div>
    </div>

    <!-- Who holds a role in this club -->
    <div class="table-card">
      <h3>Zugewiesene Rollen – {{ clubName }}</h3>
      <table class="assignments">
        <thead>
          <tr><th>Benutzer</th><th>Rollen</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in assignments" :key="a.userId">
            <td>{{ a.displayName }} <small>({{ a.userId }})</small></td>
            <td>{{ a.roles.join(', ') }}</td>
            <td>
              <NcButton variant="error" @click="removeAssignments(a)">Alle Rollen entziehen</NcButton>
            </td>
          </tr>
          <tr v-if="assignments.length === 0">
            <td colspan="3">In diesem Verein sind noch keine Rollen vergeben.</td>
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
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
      permissionTemplates: [],
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
    async loadRoles() {
      try {
        const res = await axios.get(generateUrl('/apps/verein/roles'))
        this.roles = res.data || []
      } catch (e) {
        console.error('Error loading roles', e)
        showError(extractErrorMessage(e, 'Fehler beim Laden der Rollen'))
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
      if (!confirm('Alle Rollen von ' + entry.displayName + ' in diesem Verein entziehen?')) return
      try {
        await axios.delete(generateUrl('/apps/verein/roles/users'), { params: { userId: entry.userId, clubId: clubState.currentId } })
        showSuccess('Rollen entzogen')
        this.loadAssignments()
      } catch (e) {
        console.error('Error removing roles', e)
        showError(extractErrorMessage(e, 'Fehler beim Entziehen der Rollen'))
      }
    },
    async loadPermissions() {
      try {
        const res = await axios.get(generateUrl('/apps/verein/permissions'))
        const data = res.data || {}
        // API returns { permissions: [...], templates: [...] }
        this.permissionsList = data.permissions || []
        this.permissionTemplates = data.templates || []
      } catch (e) {
        console.error('Error loading permissions', e)
        showError(extractErrorMessage(e, 'Fehler beim Laden der Berechtigungen'))
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
        showError(extractErrorMessage(e, 'Fehler bei der Benutzersuche'))
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
          showSuccess('Rolle aktualisiert')
        } else {
          await axios.post(generateUrl('/apps/verein/roles'), payload)
          showSuccess('Rolle angelegt')
        }

        this.loadRoles()
        this.closeForm()
      } catch (e) {
        console.error('Error saving role', e)
        showError(extractErrorMessage(e, 'Fehler beim Speichern der Rolle'))
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
        showError('Benutzer und Rolle erforderlich')
        return
      }
      try {
        const payload = {
          userId,
          roleId: this.assign.roleId,
          clubId: clubState.currentId
        }
        await axios.post(generateUrl('/apps/verein/roles/users'), payload)
        showSuccess('Rolle zugewiesen')
        // clear selection but keep search results
        this.assign.selectedUser = null
        this.assign.roleId = null
        this.loadAssignments()
      } catch (e) {
        console.error('Error assigning role', e)
        showError(extractErrorMessage(e, 'Fehler beim Zuweisen der Rolle'))
      }
    },
    async deleteRole(id) {
      if (!confirm('Rolle wirklich löschen?')) return
      try {
        await axios.delete(generateUrl(`/apps/verein/roles/${id}`))
        showSuccess('Rolle gelöscht')
        this.loadRoles()
      } catch (e) {
        console.error('Error deleting role', e)
        showError(extractErrorMessage(e, 'Fehler beim Löschen der Rolle'))
      }
    }
  }
}
</script>

<style scoped>
.roles-page { padding: 20px }
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
