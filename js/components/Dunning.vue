<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="dunning">
    <h2>Mahnwesen</h2>
    <p class="hint">
      Erstellt für offene Beiträge, deren Fälligkeit verstrichen ist, ein Schreiben je Person und erhöht die Mahnstufe:
      Zahlungserinnerung → 1. Mahnung → 2. und letzte Mahnung. Wer vor kurzem schon ein Schreiben bekommen hat, wird
      übersprungen, ein Lauf lässt sich also gefahrlos wiederholen. Die App verschickt nichts – die Schreiben kommen als
      PDF zum Drucken oder Versenden.
    </p>

    <form class="params" @submit.prevent="preview">
      <label class="field">
        <span>Fällig seit mindestens (Tage)</span>
        <input v-model.number="overdueDays" type="number" min="0" max="365" class="form-input" required @input="plan = null" />
      </label>
      <label class="field">
        <span>Abstand zum letzten Schreiben (Tage)</span>
        <input v-model.number="intervalDays" type="number" min="0" max="365" class="form-input" required @input="plan = null" />
      </label>
      <label class="field">
        <span>Zahlungsfrist im Schreiben (Tage)</span>
        <input v-model.number="deadlineDays" type="number" min="1" max="90" class="form-input" required />
      </label>
      <div class="buttons">
        <NcButton type="submit" variant="secondary" :disabled="busy">Vorschau</NcButton>
        <NcButton
          type="button"
          variant="primary"
          :disabled="busy || !plan || !plan.included.length"
          @click="run"
        >
          {{ plan && plan.included.length ? plan.included.length + ' Schreiben erstellen' : 'Schreiben erstellen' }}
        </NcButton>
        <NcButton v-if="lastFeeIds.length" type="button" variant="tertiary" :disabled="busy" @click="download(lastFeeIds)">
          Letzte Schreiben erneut herunterladen
        </NcButton>
      </div>
    </form>

    <div v-if="plan" class="result">
      <p class="summary">
        <strong>{{ plan.included.length }}</strong> Schreiben über zusammen <strong>{{ money(plan.total) }}</strong>
        <span v-if="plan.skipped.length"> · {{ plan.skipped.length }} übersprungen</span>
      </p>
      <p v-if="!plan.hasAccount" class="warning">
        Für diesen Verein ist kein Bankkonto hinterlegt (Reiter „Verein“). Die Schreiben nennen dann keine Bankverbindung.
      </p>
      <p v-if="withoutAddress" class="warning">
        {{ withoutAddress }} Person(en) ohne vollständige Anschrift – deren Schreiben lassen sich nicht per Post versenden.
      </p>

      <details v-if="plan.included.length" open>
        <summary>Wer ein Schreiben bekommt</summary>
        <table>
          <thead><tr><th>Mitglied</th><th>Schreiben</th><th>Beiträge</th><th class="num">Offen</th></tr></thead>
          <tbody>
            <tr v-for="e in plan.included" :key="e.memberId">
              <td>{{ e.name }}<span v-if="!e.hasAddress" class="hint"> (ohne Anschrift)</span></td>
              <td>{{ e.levelLabel }}</td>
              <td>{{ e.fees.map(f => f.period || f.description).join(', ') }}</td>
              <td class="num">{{ money(e.total) }}</td>
            </tr>
          </tbody>
        </table>
      </details>

      <details v-if="plan.skipped.length">
        <summary>Übersprungen ({{ plan.skipped.length }})</summary>
        <table>
          <thead><tr><th>Mitglied</th><th>Grund</th></tr></thead>
          <tbody>
            <tr v-for="s in plan.skipped" :key="s.memberId"><td>{{ s.name }}</td><td>{{ s.reason }}</td></tr>
          </tbody>
        </table>
      </details>
    </div>
  </div>
</template>

<script>
import { ref, computed } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { api } from '../api'
import { extractErrorMessage } from '../errorMessage'

// same labels as DunningService::LEVELS
export const DUNNING_LEVELS = { 1: 'Zahlungserinnerung', 2: '1. Mahnung', 3: '2. und letzte Mahnung' }

export default {
  name: 'Dunning',
  components: { NcButton },
  emits: ['done'],
  setup(props, { emit }) {
    const overdueDays = ref(14)
    const intervalDays = ref(14)
    const deadlineDays = ref(14)
    const plan = ref(null)
    const busy = ref(false)
    const lastFeeIds = ref([])

    const params = () => ({ overdueDays: overdueDays.value, intervalDays: intervalDays.value })
    const withoutAddress = computed(() => plan.value ? plan.value.included.filter(e => !e.hasAddress).length : 0)

    const preview = async () => {
      busy.value = true
      try {
        plan.value = (await api.post('dunning/preview', params())).data
      } catch (error) {
        showError(extractErrorMessage(error, 'Vorschau fehlgeschlagen'))
      } finally {
        busy.value = false
      }
    }

    const download = async (feeIds) => {
      busy.value = true
      try {
        const response = await api.get('dunning/letters', {
          params: { feeIds: feeIds.join(','), deadlineDays: deadlineDays.value },
          responseType: 'blob'
        })
        const url = URL.createObjectURL(response.data)
        const link = document.createElement('a')
        link.href = url
        const d = new Date() // local date, not toISOString() (UTC: the day before shortly after midnight)
        link.download = `mahnschreiben_${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}.pdf`
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      } catch (error) {
        let message = 'Die Schreiben konnten nicht erzeugt werden'
        try {
          message = JSON.parse(await error.response.data.text()).message || message
        } catch (e) {
          // keep the generic message
        }
        showError(message)
      } finally {
        busy.value = false
      }
    }

    const run = async () => {
      if (!plan.value) return
      const n = plan.value.included.length
      if (!confirm(`${n} Schreiben erstellen? Die Mahnstufe der betroffenen Beiträge wird erhöht; das lässt sich nicht automatisch zurücknehmen.`)) return
      busy.value = true
      let result = null
      try {
        result = (await api.post('dunning', params())).data
      } catch (error) {
        showError(extractErrorMessage(error, 'Mahnlauf fehlgeschlagen'))
      } finally {
        busy.value = false
      }
      if (!result) return
      showSuccess(`${result.dunned} Schreiben erstellt`)
      plan.value = null
      emit('done')
      if (result.feeIds && result.feeIds.length) {
        lastFeeIds.value = result.feeIds
        await download(result.feeIds)
      }
    }

    const money = (v) => Number(v).toFixed(2).replace('.', ',') + ' €'

    return { overdueDays, intervalDays, deadlineDays, plan, busy, lastFeeIds, withoutAddress, preview, run, download, money }
  }
}
</script>

<style scoped>
.dunning {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}
.dunning h2 { margin-top: 0; }
.hint { color: var(--color-text-maxcontrast); }
.warning { color: var(--color-error-text, var(--color-error)); }
.params { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end; }
.field { display: flex; flex-direction: column; gap: 4px; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
.result { margin-top: 16px; }
.summary { font-size: 15px; }
details { margin-top: 8px; }
summary { cursor: pointer; font-weight: 600; margin-bottom: 6px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); }
.num { text-align: right; }
</style>
