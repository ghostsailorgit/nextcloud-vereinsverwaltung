<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="card custom-fields">
    <div class="section-header">
      <h3>{{ t('verein', 'Own fields') }}</h3>
      <NcButton v-if="!showForm" variant="primary" @click="startAdd">{{ t('verein', 'Add field') }}</NcButton>
    </div>
    <p class="hint">{{ t('verein', 'Fields your club needs in addition, e.g. license, pass number or instrument. They appear in the member form, can be shown in the member list and are part of the CSV export and import (the column heading is the name of the field). Members see their values under “My data”.') }}</p>

    <form v-if="showForm" class="field-form" @submit.prevent="save">
      <NcTextField :model-value="form.label" @update:model-value="form.label = $event" :label="t('verein', 'Name of the field')" maxlength="100" required />
      <NcSelect v-model="form.type" :options="typeOptions" :reduce="o => o.id" label="label" :input-label="t('verein', 'Type of field')" :clearable="false" />
      <NcSelect v-model="form.sectionId" :options="sectionOptions" :reduce="o => o.id" label="label" :input-label="t('verein', 'Section')" :clearable="false" />
      <label v-if="form.type === 'select' || form.type === 'multiselect'" class="choices">
        <span>{{ t('verein', 'Choices (one per line)') }}</span>
        <textarea v-model="form.options" rows="4" class="form-input" />
      </label>
      <label v-if="form.type !== 'checkbox'" class="checkbox-field">
        <input v-model="form.required" type="checkbox" />
        <span>{{ t('verein', 'Required') }}</span>
      </label>
      <label class="checkbox-field">
        <input v-model="form.showInList" type="checkbox" />
        <span>{{ t('verein', 'Show in the member list') }}</span>
      </label>
      <div class="buttons">
        <NcButton type="submit" variant="primary" :disabled="busy">{{ form.id ? t('verein', 'Save') : t('verein', 'Add') }}</NcButton>
        <NcButton type="button" variant="tertiary" @click="showForm = false">{{ t('verein', 'Cancel') }}</NcButton>
      </div>
    </form>

    <div v-if="fields.length" class="table-scroll">
      <table>
        <thead><tr><th>{{ t('verein', 'Name') }}</th><th>{{ t('verein', 'Type of field') }}</th><th>{{ t('verein', 'Section') }}</th><th>{{ t('verein', 'Required') }}</th><th>{{ t('verein', 'In the list') }}</th><th>{{ t('verein', 'Actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="f in fields" :key="f.id">
            <td>{{ f.label }}<small v-if="f.type === 'select' || f.type === 'multiselect'" class="hint"><br>{{ f.options.join(', ') }}</small></td>
            <td>{{ typeLabel(f.type) }}</td>
            <td>{{ sectionName(f.sectionId) }}</td>
            <td>{{ f.required ? t('verein', 'Yes') : t('verein', 'No') }}</td>
            <td>{{ f.showInList ? t('verein', 'Yes') : t('verein', 'No') }}</td>
            <td>
              <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: f.label })">
                <NcActionButton close-after-click @click="startEdit(f)">{{ t('verein', 'Edit') }}</NcActionButton>
                <NcActionButton close-after-click @click="remove(f)">{{ t('verein', 'Delete') }}</NcActionButton>
              </NcActions>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else-if="loaded" class="hint">{{ t('verein', 'No own fields yet.') }}</p>

    <div class="section-header sections-header">
      <h4>{{ t('verein', 'Sections and who may see them') }}</h4>
      <NcButton v-if="!showSectionForm" @click="startAddSection">{{ t('verein', 'Add section') }}</NcButton>
    </div>
    <p class="hint">{{ t('verein', 'Sections group the own fields in the member form, e.g. “Bank details”, “Club internal” or “Police certificate”. Fields in a section are only seen by the roles chosen there and only changed by the roles allowed to edit it (who also needs the permission to manage members). Fields without a section are seen by everyone who sees members. Whoever may manage the club always sees every section.') }}</p>

    <form v-if="showSectionForm" class="field-form section-form" @submit.prevent="saveSection">
      <NcTextField :model-value="sectionForm.name" @update:model-value="sectionForm.name = $event" :label="t('verein', 'Name of the section')" maxlength="100" required />
      <NcSelect
        v-model="sectionForm.viewRoles"
        :options="roles"
        :reduce="o => o.id"
        label="name"
        multiple
        :close-on-select="false"
        :input-label="t('verein', 'Roles that may see it')"
        :placeholder="t('verein', 'Select…')"
      />
      <NcSelect
        v-model="sectionForm.editRoles"
        :options="roles"
        :reduce="o => o.id"
        label="name"
        multiple
        :close-on-select="false"
        :input-label="t('verein', 'Roles that may change it')"
        :placeholder="t('verein', 'Select…')"
      />
      <div class="buttons">
        <NcButton type="submit" variant="primary" :disabled="busy">{{ sectionForm.id ? t('verein', 'Save') : t('verein', 'Add') }}</NcButton>
        <NcButton type="button" variant="tertiary" @click="showSectionForm = false">{{ t('verein', 'Cancel') }}</NcButton>
      </div>
    </form>

    <div v-if="sections.length" class="table-scroll">
      <table>
        <thead><tr><th>{{ t('verein', 'Name') }}</th><th>{{ t('verein', 'May see it') }}</th><th>{{ t('verein', 'May change it') }}</th><th>{{ t('verein', 'Fields') }}</th><th>{{ t('verein', 'Actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in sections" :key="s.id">
            <td>{{ s.name }}</td>
            <td>{{ roleNames(s.viewRoles) }}</td>
            <td>{{ roleNames(s.editRoles) }}</td>
            <td>{{ fields.filter(f => f.sectionId === s.id).length }}</td>
            <td>
              <NcActions force-menu :aria-label="t('verein', 'More actions for {name}', { name: s.name })">
                <NcActionButton close-after-click @click="startEditSection(s)">{{ t('verein', 'Edit') }}</NcActionButton>
                <NcActionButton close-after-click @click="removeSection(s)">{{ t('verein', 'Delete') }}</NcActionButton>
              </NcActions>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-else-if="loaded" class="hint">{{ t('verein', 'No sections yet.') }}</p>
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
import { t } from '@nextcloud/l10n'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'
import { fieldTypeOptions, fieldTypeLabel } from '../customFields'

export default {
  name: 'CustomFields',
  components: { NcActionButton, NcActions, NcButton, NcSelect, NcTextField },
  setup() {
    const fields = ref([])
    const sections = ref([])
    const roles = ref([])
    const loaded = ref(false)
    const busy = ref(false)
    const showForm = ref(false)
    const showSectionForm = ref(false)
    // sectionId 0 = no section (a select cannot hold null as a choice)
    const empty = () => ({ id: null, label: '', type: 'text', sectionId: 0, options: '', required: false, showInList: false })
    const form = reactive(empty())
    const emptySection = () => ({ id: null, name: '', viewRoles: [], editRoles: [] })
    const sectionForm = reactive(emptySection())
    const typeOptions = fieldTypeOptions()
    const sectionOptions = computed(() => [{ id: 0, label: t('verein', 'No section (everyone who sees members)') }]
      .concat(sections.value.map(s => ({ id: s.id, label: s.name }))))

    const sectionName = (id) => sections.value.find(s => s.id === id)?.name || '–'
    const roleNames = (ids) => {
      const names = (ids || []).map(id => roles.value.find(r => r.id === id)?.name).filter(Boolean)
      return names.length ? names.join(', ') : t('verein', 'Only club managers')
    }

    const load = async () => {
      try {
        const [f, s] = await Promise.all([api.get('custom-fields'), api.get('custom-sections')])
        fields.value = f.data.data
        sections.value = s.data.data.sections
        roles.value = s.data.data.roles
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The fields could not be loaded')))
      } finally {
        loaded.value = true
      }
    }
    const startAdd = () => {
      Object.assign(form, empty())
      showForm.value = true
    }
    const startEdit = (f) => {
      Object.assign(form, { id: f.id, label: f.label, type: f.type, sectionId: f.sectionId || 0, options: f.options.join('\n'), required: f.required, showInList: f.showInList })
      showForm.value = true
    }
    const save = async () => {
      busy.value = true
      try {
        // form fields: booleans and the list of choices as plain text
        const payload = { label: form.label, type: form.type, sectionId: String(form.sectionId || ''), options: form.options, required: form.required ? '1' : '0', showInList: form.showInList ? '1' : '0' }
        if (form.id) await api.put(`custom-fields/${form.id}`, payload)
        else await api.post('custom-fields', payload)
        showSuccess(t('verein', 'Field saved'))
        showForm.value = false
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The field could not be saved')))
      } finally {
        busy.value = false
      }
    }
    const remove = async (f) => {
      if (!(await confirmAction(t('verein', 'Delete field'), t('verein', 'Delete the field “{name}”? The values of all members in this field are deleted too. This cannot be undone.', { name: f.label }), { labelConfirm: t('verein', 'Delete field'), severity: 'error' }))) return
      busy.value = true
      try {
        await api.delete(`custom-fields/${f.id}`)
        showSuccess(t('verein', 'Field deleted'))
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The field could not be deleted')))
      } finally {
        busy.value = false
      }
    }

    const startAddSection = () => {
      Object.assign(sectionForm, emptySection())
      showSectionForm.value = true
    }
    const startEditSection = (s) => {
      Object.assign(sectionForm, { id: s.id, name: s.name, viewRoles: [...s.viewRoles], editRoles: [...s.editRoles] })
      showSectionForm.value = true
    }
    const saveSection = async () => {
      busy.value = true
      try {
        // the role lists as JSON: the API sends form fields
        const payload = { name: sectionForm.name, viewRoles: JSON.stringify(sectionForm.viewRoles), editRoles: JSON.stringify(sectionForm.editRoles) }
        if (sectionForm.id) await api.put(`custom-sections/${sectionForm.id}`, payload)
        else await api.post('custom-sections', payload)
        showSuccess(t('verein', 'Section saved'))
        showSectionForm.value = false
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The section could not be saved')))
      } finally {
        busy.value = false
      }
    }
    const removeSection = async (s) => {
      if (!(await confirmAction(t('verein', 'Delete section'), t('verein', 'Delete the section “{name}”?', { name: s.name }), { labelConfirm: t('verein', 'Delete section'), severity: 'error' }))) return
      busy.value = true
      try {
        await api.delete(`custom-sections/${s.id}`)
        showSuccess(t('verein', 'Section deleted'))
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The section could not be deleted')))
      } finally {
        busy.value = false
      }
    }

    onMounted(load)
    return {
      t, fields, sections, roles, loaded, busy, showForm, showSectionForm, form, sectionForm, typeOptions, sectionOptions,
      typeLabel: fieldTypeLabel, sectionName, roleNames, startAdd, startEdit, save, remove, startAddSection, startEditSection, saveSection, removeSection,
    }
  },
}
</script>

<style scoped>
.section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.sections-header { margin-top: 20px; }
.hint { color: var(--color-text-maxcontrast); }
.field-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 12px 0; align-items: end; }
.field-form :deep(.v-select.select) { min-width: 0; width: 100%; margin-bottom: 0; }
/* the role selects get the room: their labels and chosen roles are longer than a name */
.section-form { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
.choices { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--color-text-secondary); }
.choices textarea { width: 100%; height: auto; padding: 8px; }
.checkbox-field { display: flex; align-items: center; gap: 8px; padding-bottom: 8px; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; }
</style>
