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
      <label v-if="form.type === 'select'" class="choices">
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
        <thead><tr><th>{{ t('verein', 'Name') }}</th><th>{{ t('verein', 'Type of field') }}</th><th>{{ t('verein', 'Required') }}</th><th>{{ t('verein', 'In the list') }}</th><th>{{ t('verein', 'Actions') }}</th></tr></thead>
        <tbody>
          <tr v-for="f in fields" :key="f.id">
            <td>{{ f.label }}<small v-if="f.type === 'select'" class="hint"><br>{{ f.options.join(', ') }}</small></td>
            <td>{{ typeLabel(f.type) }}</td>
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
  </div>
</template>

<script>
import { ref, reactive, onMounted } from 'vue'
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
    const loaded = ref(false)
    const busy = ref(false)
    const showForm = ref(false)
    const empty = () => ({ id: null, label: '', type: 'text', options: '', required: false, showInList: false })
    const form = reactive(empty())
    const typeOptions = fieldTypeOptions()

    const load = async () => {
      try {
        fields.value = (await api.get('custom-fields')).data.data
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
      Object.assign(form, { id: f.id, label: f.label, type: f.type, options: f.options.join('\n'), required: f.required, showInList: f.showInList })
      showForm.value = true
    }
    const save = async () => {
      busy.value = true
      try {
        // form fields: booleans and the list of choices as plain text
        const payload = { label: form.label, type: form.type, options: form.options, required: form.required ? '1' : '0', showInList: form.showInList ? '1' : '0' }
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

    onMounted(load)
    return { t, fields, loaded, busy, showForm, form, typeOptions, typeLabel: fieldTypeLabel, startAdd, startEdit, save, remove }
  },
}
</script>

<style scoped>
.section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.hint { color: var(--color-text-maxcontrast); }
.field-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 12px 0; align-items: end; }
.field-form :deep(.v-select.select) { min-width: 0; width: 100%; margin-bottom: 0; }
.choices { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--color-text-secondary); }
.choices textarea { width: 100%; height: auto; padding: 8px; }
.checkbox-field { display: flex; align-items: center; gap: 8px; padding-bottom: 8px; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; }
</style>
