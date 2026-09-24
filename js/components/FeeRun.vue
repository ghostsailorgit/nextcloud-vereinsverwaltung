<template>
  <div class="fee-run">
    <h2>Beitragslauf</h2>
    <p class="hint">
      Erzeugt für alle aktiven Mitglieder den Jahresbeitrag nach ihrer Beitragskategorie (Reiter „Verein“).
      Wer für das Jahr schon einen Beitrag hat, wird übersprungen, ein Lauf lässt sich also gefahrlos wiederholen.
      Zuerst die Vorschau ansehen.
    </p>

    <form class="params" @submit.prevent="preview">
      <label class="field">
        <span>Beitragsjahr</span>
        <input v-model.number="year" type="number" min="2000" max="2100" class="form-input" required />
      </label>
      <label class="field">
        <span>Fällig am</span>
        <input v-model="dueDate" type="date" class="form-input" required />
      </label>
      <NcTextField
        :model-value="description"
        @update:model-value="description = $event"
        label="Bemerkung (optional)"
        :placeholder="'Mitgliedsbeitrag ' + year"
      />
      <div class="buttons">
        <NcButton type="submit" variant="secondary" :disabled="busy">Vorschau</NcButton>
        <NcButton
          type="button"
          variant="primary"
          :disabled="busy || !plan || !plan.included.length"
          @click="run"
        >
          {{ plan ? plan.included.length + ' Beiträge erzeugen' : 'Beiträge erzeugen' }}
        </NcButton>
        <NcButton type="button" variant="tertiary" :disabled="busy" @click="flagOverdue">
          Überfällige markieren
        </NcButton>
      </div>
    </form>

    <div v-if="plan" class="result">
      <p class="summary">
        <strong>{{ plan.included.length }}</strong> Beiträge über insgesamt
        <strong>{{ formatMoney(plan.total) }}</strong> für {{ plan.year }}, fällig am {{ formatDate(plan.dueDate) }}
        <span v-if="plan.skipped.length"> · {{ plan.skipped.length }} übersprungen</span>
      </p>

      <details v-if="plan.included.length" open>
        <summary>Wer bekommt einen Beitrag</summary>
        <table>
          <thead><tr><th>Mitglied</th><th>Kategorie</th><th class="num">Betrag</th></tr></thead>
          <tbody>
            <tr v-for="e in plan.included" :key="e.memberId">
              <td>{{ e.name }}</td><td>{{ e.category }}</td><td class="num">{{ formatMoney(e.amount) }}</td>
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
import { ref } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { api } from '../api'
import { extractErrorMessage } from '../errorMessage'

export default {
  name: 'FeeRun',
  components: { NcButton, NcTextField },
  emits: ['done'],
  setup(props, { emit }) {
    const year = ref(new Date().getFullYear())
    const dueDate = ref(`${new Date().getFullYear()}-03-31`)
    const description = ref('')
    const plan = ref(null)
    const busy = ref(false)

    const params = () => ({ year: year.value, dueDate: dueDate.value, description: description.value })

    const call = async (action) => {
      busy.value = true
      try {
        return await action()
      } catch (error) {
        showError(extractErrorMessage(error, 'Aktion fehlgeschlagen'))
        return null
      } finally {
        busy.value = false
      }
    }

    const preview = async () => {
      const res = await call(() => api.post('fee-run/preview', params()))
      if (res) plan.value = res.data
    }

    const run = async () => {
      if (!plan.value) return
      const n = plan.value.included.length
      if (!confirm(`${n} Beiträge für ${year.value} jetzt erzeugen?`)) return
      const res = await call(() => api.post('fee-run', params()))
      if (res) {
        showSuccess(`${res.data.created} Beiträge erzeugt`)
        plan.value = null
        emit('done')
      }
    }

    const flagOverdue = async () => {
      const res = await call(() => api.post('finance/flag-overdue', {}))
      if (res) {
        showSuccess(res.data.flagged + ' Beiträge als überfällig markiert')
        emit('done')
      }
    }

    const formatMoney = (v) => Number(v).toFixed(2).replace('.', ',') + ' €'
    const formatDate = (v) => {
      const d = new Date(v)
      return isNaN(d) ? v : d.toLocaleDateString('de-DE')
    }

    return { year, dueDate, description, plan, busy, preview, run, flagOverdue, formatMoney, formatDate }
  }
}
</script>

<style scoped>
.fee-run {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  margin-bottom: 20px;
}
.fee-run h2 { margin-top: 0; }
.hint { color: var(--color-text-maxcontrast); }
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
