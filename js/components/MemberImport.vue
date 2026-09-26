<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="member-import">
    <h2>Mitglieder aus CSV importieren</h2>
    <p class="hint">
      Erste Zeile = Spaltenüberschriften. Erkannt werden u. a. Anrede, Vorname, Name (oder Nachname), Straße, PLZ, Ort,
      E-Mail, IBAN, BIC, Geburtsdatum, Eintritt, Austritt, Funktion, Beitragskategorie, Mandatsreferenz, Mandatsdatum,
      Gründungsmitglied, Verstorben – also auch der eigene Mitglieder-Export. Trennzeichen Semikolon, Komma oder Tab;
      Datum als TT.MM.JJJJ oder JJJJ-MM-TT. Zuerst wird nur geprüft, nichts gespeichert.
    </p>

    <div class="pick">
      <input ref="fileInput" type="file" accept=".csv,text/csv,text/plain" @change="onFile" />
      <NcButton variant="secondary" :disabled="busy || !csv" @click="preview">Erneut prüfen</NcButton>
    </div>

    <div v-if="plan" class="result">
      <p class="summary">
        <strong>{{ plan.counts.ok }}</strong> können importiert werden
        <span v-if="plan.counts.duplicate"> · {{ plan.counts.duplicate }} schon vorhanden</span>
        <span v-if="plan.counts.error"> · {{ plan.counts.error }} fehlerhaft</span>
      </p>
      <p class="hint">
        Erkannte Spalten: {{ Object.keys(plan.columns).join(', ') }}
        <span v-if="plan.ignoredColumns.length"><br>Nicht übernommen: {{ plan.ignoredColumns.join(', ') }}</span>
      </p>

      <div class="buttons">
        <NcButton variant="primary" :disabled="busy || !importable.length" @click="runImport">
          {{ importable.length }} Mitglieder importieren
        </NcButton>
        <NcButton variant="tertiary" :disabled="busy" @click="reset">Abbrechen</NcButton>
        <span v-if="progress" class="progress">{{ progress }}</span>
      </div>

      <table class="rows">
        <thead><tr><th>Zeile</th><th>Name</th><th>Ergebnis</th><th>Hinweise</th></tr></thead>
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
import { api } from '../api'
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
        showError('Die Datei ist zu groß (höchstens 2 MB)')
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
        showError(extractErrorMessage(error, 'Die Datei konnte nicht geprüft werden'))
      } finally {
        busy.value = false
      }
    }

    const runImport = async () => {
      const lines = importable.value
      if (!lines.length || !confirm(`${lines.length} Mitglieder anlegen?`)) return
      busy.value = true
      let created = 0
      let problems = 0
      try {
        for (let i = 0; i < lines.length; i += CHUNK) {
          progress.value = `${Math.min(i + CHUNK, lines.length)} von ${lines.length} …`
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
        showError(extractErrorMessage(error, 'Import abgebrochen'))
      } finally {
        busy.value = false
        progress.value = ''
      }
      if (created) showSuccess(`${created} Mitglieder importiert`)
      if (problems) showWarning(`${problems} Zeilen nicht importiert – siehe Liste`)
      if (created) emit('done')
    }

    const statusLabel = (r) => {
      const o = outcome.value[r.line]
      if (o === 'created') return '✓ importiert'
      if (o) return '✗ ' + o.replace(/^failed: /, 'Fehler: ').replace(/^skipped: /, 'übersprungen: ')
      return { ok: 'bereit', error: 'fehlerhaft', duplicate: 'schon vorhanden' }[r.status] || r.status
    }

    const reset = () => {
      plan.value = null
      csv.value = ''
      outcome.value = {}
      if (fileInput.value) fileInput.value.value = ''
    }

    return { fileInput, csv, plan, busy, progress, importable, onFile, preview, runImport, statusLabel, reset }
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
