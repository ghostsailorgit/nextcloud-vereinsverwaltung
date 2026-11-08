<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="groups-page">
    <div class="card">
      <div class="section-header">
        <h2>{{ t('verein', 'Groups') }}</h2>
        <NcButton v-if="canManage && !showForm" variant="primary" @click="startAdd">{{ t('verein', 'Add group') }}</NcButton>
      </div>
      <p class="hint">{{ t('verein', 'Groups inside the club, e.g. training groups. A member can be in none, one or several. Joining and leaving are recorded for each stay, also when somebody joins again.') }}</p>

      <form v-if="showForm" class="group-form" @submit.prevent="saveGroup">
        <NcTextField :model-value="form.name" @update:model-value="form.name = $event" :label="t('verein', 'Name')" maxlength="100" required />
        <NcTextField :model-value="form.description" @update:model-value="form.description = $event" :label="t('verein', 'Description (optional)')" maxlength="255" />
        <div class="buttons">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ form.id ? t('verein', 'Save') : t('verein', 'Add') }}</NcButton>
          <NcButton type="button" variant="tertiary" @click="showForm = false">{{ t('verein', 'Cancel') }}</NcButton>
        </div>
      </form>

      <div v-if="groups.length" class="table-scroll">
        <table>
          <thead><tr><th>{{ t('verein', 'Group') }}</th><th>{{ t('verein', 'Description') }}</th><th class="num">{{ t('verein', 'Current members') }}</th><th>{{ t('verein', 'Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="g in groups" :key="g.id" :class="{ selected: selected && selected.id === g.id }">
              <td>{{ g.name }}</td>
              <td>{{ g.description || '–' }}</td>
              <td class="num">{{ g.currentMembers }}</td>
              <td>
                <div class="row-actions">
                  <NcButton variant="secondary" @click="open(g)">{{ t('verein', 'Open') }}</NcButton>
                  <NcActions v-if="canManage" force-menu :aria-label="t('verein', 'More actions for {name}', { name: g.name })">
                    <NcActionButton close-after-click @click="startEdit(g)">{{ t('verein', 'Edit') }}</NcActionButton>
                    <NcActionButton close-after-click @click="removeGroup(g)">{{ t('verein', 'Delete') }}</NcActionButton>
                  </NcActions>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else-if="loaded" class="hint">{{ t('verein', 'No group yet.') }}</p>
    </div>

    <div v-if="selected" class="card">
      <h3>{{ selected.name }}</h3>

      <form v-if="canManage" class="add-member" @submit.prevent="addMembers">
        <NcSelect
          v-model="adding.members"
          :options="memberOptions"
          :input-label="t('verein', 'Add members')"
          :placeholder="t('verein', 'Select…')"
          label="label"
          multiple
          :close-on-select="false"
        />
        <label class="date-field">
          <span>{{ t('verein', 'Date joined') }}</span>
          <input v-model="adding.joinedOn" type="date" class="form-input" required />
        </label>
        <NcButton type="submit" variant="primary" :disabled="busy || !adding.members.length">{{ t('verein', 'Add') }}</NcButton>
      </form>

      <h4>{{ t('verein', 'Current members ({count})', { count: current.length }) }}</h4>
      <div v-if="current.length" class="table-scroll">
        <table>
          <thead><tr><th>{{ t('verein', 'Member') }}</th><th>{{ t('verein', 'Date joined') }}</th><th v-if="editing.id && editingCurrent">{{ t('verein', 'Date left') }}</th><th v-if="canManage">{{ t('verein', 'Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in current" :key="p.id">
              <td>{{ p.memberName }}</td>
              <template v-if="editing.id === p.id">
                <td><input v-model="editing.joinedOn" type="date" class="form-input" required /></td>
                <td><input v-model="editing.leftOn" type="date" class="form-input" /></td>
                <td><div class="row-actions"><NcButton variant="primary" :disabled="busy" @click="savePeriod">{{ t('verein', 'Save') }}</NcButton><NcButton variant="tertiary" @click="editing.id = null">{{ t('verein', 'Cancel') }}</NcButton></div></td>
              </template>
              <template v-else>
                <td>{{ formatDate(p.joinedOn) }}</td>
                <td v-if="editing.id && editingCurrent"></td>
                <td v-if="canManage">
                  <div class="row-actions">
                    <NcButton variant="secondary" @click="startEnd(p)">{{ t('verein', 'End') }}</NcButton>
                    <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: p.memberName })">
                      <NcActionButton close-after-click @click="startEditPeriod(p)">{{ t('verein', 'Edit') }}</NcActionButton>
                      <NcActionButton close-after-click @click="removePeriod(p)">{{ t('verein', 'Delete') }}</NcActionButton>
                    </NcActions>
                  </div>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="hint">{{ t('verein', 'Nobody is in this group at the moment.') }}</p>

      <h4>{{ t('verein', 'Earlier periods ({count})', { count: earlier.length }) }}</h4>
      <div v-if="earlier.length" class="table-scroll">
        <table>
          <thead><tr><th>{{ t('verein', 'Member') }}</th><th>{{ t('verein', 'Date joined') }}</th><th>{{ t('verein', 'Date left') }}</th><th v-if="canManage">{{ t('verein', 'Actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in earlier" :key="p.id">
              <td>{{ p.memberName }} <span v-if="p.memberFormer" class="hint">({{ t('verein', 'left the club') }})</span></td>
              <template v-if="editing.id === p.id">
                <td><input v-model="editing.joinedOn" type="date" class="form-input" required /></td>
                <td><input v-model="editing.leftOn" type="date" class="form-input" /></td>
                <td><div class="row-actions"><NcButton variant="primary" :disabled="busy" @click="savePeriod">{{ t('verein', 'Save') }}</NcButton><NcButton variant="tertiary" @click="editing.id = null">{{ t('verein', 'Cancel') }}</NcButton></div></td>
              </template>
              <template v-else>
                <td>{{ formatDate(p.joinedOn) }}</td>
                <td>{{ p.leftOn ? formatDate(p.leftOn) : '–' }}</td>
                <td v-if="canManage">
                  <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: p.memberName })">
                    <NcActionButton close-after-click @click="startEditPeriod(p)">{{ t('verein', 'Edit') }}</NcActionButton>
                    <NcActionButton close-after-click @click="removePeriod(p)">{{ t('verein', 'Delete') }}</NcActionButton>
                  </NcActions>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="hint">{{ t('verein', 'No earlier periods.') }}</p>
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, onMounted } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t, n } from '@nextcloud/l10n'
import { api } from '../api'
import { can } from '../store/club'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'
import { formatDate } from '../format'

const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default {
  name: 'Groups',
  components: { NcActionButton, NcActions, NcButton, NcSelect, NcTextField },
  setup() {
    const canManage = computed(() => can('verein.member.manage'))
    const groups = ref([])
    const loaded = ref(false)
    const busy = ref(false)
    const showForm = ref(false)
    const form = reactive({ id: null, name: '', description: '' })
    const selected = ref(null)
    const periods = ref([])
    const members = ref([])
    const adding = reactive({ members: [], joinedOn: today() })
    const editing = reactive({ id: null, joinedOn: '', leftOn: '' })

    // an open period of somebody who left the club belongs to the past, even without a date left
    const isCurrent = (p) => !p.leftOn && !p.memberFormer
    const current = computed(() => periods.value.filter(isCurrent))
    const earlier = computed(() => periods.value.filter(p => !isCurrent(p)))
    const editingCurrent = computed(() => current.value.some(p => p.id === editing.id))

    const memberOptions = computed(() => {
      const inGroup = new Set(current.value.map(p => p.memberId))
      return members.value
        .filter(m => !m.isFormer && !inGroup.has(m.id))
        .map(m => ({ id: m.id, label: m.fullName }))
        .sort((a, b) => a.label.localeCompare(b.label))
    })

    const call = async (action) => {
      busy.value = true
      try {
        await action()
        return true
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Action failed')))
        return false
      } finally {
        busy.value = false
      }
    }

    const loadGroups = async () => {
      const res = await api.get('groups')
      groups.value = res.data.data
      loaded.value = true
    }
    const loadPeriods = async () => {
      const res = await api.get(`groups/${selected.value.id}/members`)
      periods.value = res.data.data
    }

    const open = (group) => call(async () => {
      selected.value = group
      editing.id = null
      await loadPeriods()
    })

    const startAdd = () => {
      Object.assign(form, { id: null, name: '', description: '' })
      showForm.value = true
    }
    const startEdit = (group) => {
      Object.assign(form, { id: group.id, name: group.name, description: group.description || '' })
      showForm.value = true
    }
    const saveGroup = () => call(async () => {
      const payload = { name: form.name, description: form.description }
      if (form.id) await api.put(`groups/${form.id}`, payload)
      else await api.post('groups', payload)
      showForm.value = false
      await loadGroups()
      if (selected.value && selected.value.id === form.id) selected.value = groups.value.find(g => g.id === form.id) || null
    })
    const removeGroup = async (group) => {
      if (!(await confirmAction(t('verein', 'Delete group'), t('verein', 'Delete “{name}” with all recorded periods of its members?', { name: group.name }), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      await call(async () => {
        await api.delete(`groups/${group.id}`)
        if (selected.value && selected.value.id === group.id) selected.value = null
        await loadGroups()
      })
    }

    const refresh = async () => {
      await Promise.all([loadPeriods(), loadGroups()])
    }

    const addMembers = async () => {
      const list = [...adding.members]
      const done = await call(async () => {
        for (const member of list) {
          await api.post(`groups/${selected.value.id}/members`, { memberId: member.id, joinedOn: adding.joinedOn })
          adding.members = adding.members.filter(m => m.id !== member.id)
        }
      })
      await call(refresh)
      if (done) showSuccess(n('verein', '%n member added', '%n members added', list.length))
    }

    const startEditPeriod = (p) => Object.assign(editing, { id: p.id, joinedOn: p.joinedOn, leftOn: p.leftOn || '' })
    const startEnd = (p) => Object.assign(editing, { id: p.id, joinedOn: p.joinedOn, leftOn: today() })
    const savePeriod = () => call(async () => {
      await api.put(`group-members/${editing.id}`, { joinedOn: editing.joinedOn, leftOn: editing.leftOn })
      editing.id = null
      await refresh()
    })
    const removePeriod = async (p) => {
      if (!(await confirmAction(t('verein', 'Delete period'), t('verein', 'Delete this period of {name}? Use this only for entries made by mistake.', { name: p.memberName }), { labelConfirm: t('verein', 'Delete'), severity: 'error' }))) return
      await call(async () => {
        await api.delete(`group-members/${p.id}`)
        await refresh()
      })
    }

    onMounted(async () => {
      await call(loadGroups)
      if (canManage.value) {
        await call(async () => {
          members.value = (await api.getMembers()).data.members
        })
      }
    })

    return {
      t, formatDate, canManage, groups, loaded, busy, showForm, form, selected, adding, editing, current, earlier, editingCurrent, memberOptions,
      open, startAdd, startEdit, saveGroup, removeGroup, addMembers, startEditPeriod, startEnd, savePeriod, removePeriod,
    }
  },
}
</script>

<style scoped>
.card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}
.section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.hint { color: var(--color-text-maxcontrast); }
.group-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 12px 0; align-items: end; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; }
.add-member { display: grid; grid-template-columns: minmax(220px, 2fr) minmax(160px, 1fr) auto; gap: 12px; align-items: end; margin-bottom: 12px; }
.date-field { display: flex; flex-direction: column; gap: 4px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); }
.num { text-align: right; }
tr.selected { background: var(--color-background-hover); }
.row-actions { display: flex; align-items: center; gap: 4px; }
@media (max-width: 600px) {
  .add-member { grid-template-columns: 1fr; }
}
</style>
