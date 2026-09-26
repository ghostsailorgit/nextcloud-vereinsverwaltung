<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="member-import">
    <h2>{{ t('verein', 'Import members from CSV') }}</h2>
    <p class="hint">
      {{ t('verein', 'First line = column headings. Recognised are among others Salutation, First name, Name (or Last name), Street, Postal code, City, E-mail, IBAN, BIC, Birth date, Join date, Leave date, Role, Fee rate, Mandate reference, Mandate date, Founding member, Deceased - so the app\'s own member export as well. Separator semicolon, comma or tab; dates as DD.MM.YYYY or YYYY-MM-DD. First everything is only checked, nothing is saved.') }}
    </p>

    <div class="pick">
      <input ref="fileInput" type="file" accept=".csv,text/csv,text/plain" @change="onFile" />
      <NcButton variant="secondary" :disabled="busy || !csv" @click="preview">{{ t('verein', 'Check again') }}</NcButton>
    </div>

    <div v-if="plan" class="result">
      <p class="summary">
        <strong>{{ plan.counts.ok }}</strong> {{ n('verein', 'can be imported', 'can be imported', plan.counts.ok) }}
        <span v-if="plan.counts.duplicate"> · {{ n('verein', '%n already exists', '%n already exist', plan.counts.duplicate) }}</span>
        <span v-if="plan.counts.error"> · {{ n('verein', '%n with errors', '%n with errors', plan.counts.error) }}</span>
      </p>
      <p class="hint">
        {{ t('verein', 'Recognised columns: {list}', { list: Object.keys(plan.columns).join(', ') }) }}
        <span v-if="plan.ignoredColumns.length"><br>{{ t('verein', 'Not imported: {list}', { list: plan.ignoredColumns.join(', ') }) }}</span>
      </p>

      <div class="buttons">
        <NcButton variant="primary" :disabled="busy || !importable.length" @click="runImport">
          {{ n('verein', 'Import %n member', 'Import %n members', importable.length) }}
        </NcButton>
        <NcButton variant="tertiary" :disabled="busy" @click="reset">{{ t('verein', 'Cancel') }}</NcButton>
        <span v-if="progress" class="progress">{{ progress }}</span>
      </div>

      <table class="rows">
        <thead><tr><th>{{ t('verein', 'Line') }}</th><th>{{ t('verein', 'Name') }}</th><th>{{ t('verein', 'Result') }}</th><th>{{ t('verein', 'Notes') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in plan.rows" :key="r.line" :class="r.status">
            <td>{{ r.line }}</td>
            <td>{{ r.name }}</td>
            <td>{{ statusLabel(r) }}</td>
            <td>
              <div v-for="(m, i) in r.messages" :key="'m' + i">{{ m }}</div>
              <div v-for="(w, i) in r.warnings" :key="'w' + i" class="warn">{{ w }}</div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import { ref, computed } from 'vue'
import { showSuccess, showError, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t, n } from '@nextcloud/l10n'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'

const CHUNK = 20

export default {
  name: 'MemberImport',
  components: { NcButton },
  emits: ['done'],
  setup(props, { emit }) {
    const fileInput = ref(null)
    const csv = ref('')
    const plan = ref(null)
    const busy = ref(false)
    const progress = ref('')
    // result per line after the import: 'created' | 'failed' | 'skipped'
    const outcome = ref({})

    const importable = computed(() => plan.value ? plan.value.rows.filter(r => r.status === 'ok' && !outcome.value[r.line]).map(r => r.line) : [])

    // Spreadsheets on Windows often save CSV as Windows-1252; everything is sent to the server as UTF-8.
    const decode = (buffer) => {
      try {
        return new TextDecoder('utf-8', { fatal: true }).decode(buffer)
      } catch (e) {
        return new TextDecoder('windows-1252').decode(buffer)
      }
    }

    const onFile = async (event) => {
      const file = event.target.files && event.target.files[0]
      if (!file) return
      if (file.size > 2 * 1024 * 1024) {
        showError(t('verein', 'The file is too large (at most 2 MB)'))
        return
      }
      csv.value = decode(await file.arrayBuffer())
      outcome.value = {}
      await preview()
    }

    const preview = async () => {
      busy.value = true
      try {
        plan.value = (await api.post('members/import/preview', { csv: csv.value })).data
      } catch (error) {
        plan.value = null
        showError(extractErrorMessage(error, t('verein', 'The file could not be checked')))
      } finally {
        busy.value = false
      }
    }

    const runImport = async () => {
      const lines = importable.value
      if (!lines.length || !(await confirmAction(t('verein', 'Import members'), n('verein', 'Create %n member?', 'Create %n members?', lines.length), { labelConfirm: t('verein', 'Import'), severity: 'warning' }))) return
      busy.value = true
      let created = 0
      let problems = 0
      try {
        for (let i = 0; i < lines.length; i += CHUNK) {
          progress.value = t('verein', '{done} of {total} …', { done: Math.min(i + CHUNK, lines.length), total: lines.length })
          const res = (await api.post('members/import', { csv: csv.value, lines: lines.slice(i, i + CHUNK).join(',') })).data
          const next = { ...outcome.value }
          res.created.forEach(r => { next[r.line] = 'created' })
          res.failed.forEach(r => { next[r.line] = 'failed: ' + r.reason })
          res.skipped.forEach(r => { next[r.line] = 'skipped: ' + r.reason })
          outcome.value = next
          created += res.created.length
          problems += res.failed.length + res.skipped.length
        }
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Import aborted')))
      } finally {
        busy.value = false
        progress.value = ''
      }
      if (created) showSuccess(n('verein', '%n member imported', '%n members imported', created))
      if (problems) showWarning(n('verein', '%n line not imported - see the list', '%n lines not imported - see the list', problems))
      if (created) emit('done')
    }

    const statusLabel = (r) => {
      const o = outcome.value[r.line]
      if (o === 'created') return '✓ ' + t('verein', 'imported')
      if (o) return '✗ ' + o.replace(/^failed: /, t('verein', 'Error: ')).replace(/^skipped: /, t('verein', 'skipped: '))
      return { ok: t('verein', 'ready'), error: t('verein', 'faulty'), duplicate: t('verein', 'already exists') }[r.status] || r.status
    }

    const reset = () => {
      plan.value = null
      csv.value = ''
      outcome.value = {}
      if (fileInput.value) fileInput.value.value = ''
    }

    return { t, n, fileInput, csv, plan, busy, progress, importable, onFile, preview, runImport, statusLabel, reset }
  }
}
</script>

<style scoped>
.member-import {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}
.member-import h2 { margin-top: 0; }
.hint { color: var(--color-text-maxcontrast); }
.pick { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.result { margin-top: 16px; }
.summary { font-size: 15px; }
.buttons { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 8px 0 12px; }
.progress { color: var(--color-text-maxcontrast); }
.rows { width: 100%; border-collapse: collapse; }
.rows th, .rows td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); vertical-align: top; }
.rows tr.error td, .rows tr.duplicate td { color: var(--color-text-maxcontrast); }
.warn { color: var(--color-warning-text, var(--color-warning)); }
</style>
