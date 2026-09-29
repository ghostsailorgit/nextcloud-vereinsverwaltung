<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="fee-run">
    <h2>{{ t('verein', 'Annual fee run') }}</h2>
    <p class="hint">
      {{ t('verein', 'Creates the annual fee for all active members according to their fee category (“Club” tab). Members who already have a fee for the year are skipped, so the run can safely be repeated. Check the preview first.') }}
    </p>

    <form class="params" @submit.prevent="preview">
      <label class="field">
        <span>{{ t('verein', 'Fee year') }}</span>
        <input v-model.number="year" type="number" min="2000" max="2100" class="form-input" required />
      </label>
      <label class="field">
        <span>{{ t('verein', 'Due on') }}</span>
        <input v-model="dueDate" type="date" class="form-input" required />
      </label>
      <NcTextField
        :model-value="description"
        @update:model-value="description = $event"
        :label="t('verein', 'Comment (optional)')"
        :placeholder="t('verein', 'Membership fee {year}', { year })"
      />
      <NcCheckboxRadioSwitch
        class="prorata"
        type="checkbox"
        :model-value="prorata"
        @update:model-value="prorata = $event; plan = null"
      >
        {{ t('verein', 'Pro rata for members who joined during the fee year (from the month they joined)') }}
      </NcCheckboxRadioSwitch>
      <div class="buttons">
        <NcButton type="submit" variant="secondary" :disabled="busy">{{ t('verein', 'Preview') }}</NcButton>
        <NcButton
          type="button"
          variant="primary"
          :disabled="busy || !plan || !plan.included.length"
          @click="run"
        >
          {{ plan ? n('verein', 'Create %n fee', 'Create %n fees', plan.included.length) : t('verein', 'Create fees') }}
        </NcButton>
        <NcButton type="button" variant="tertiary" :disabled="busy" @click="flagOverdue">
          {{ t('verein', 'Flag overdue fees') }}
        </NcButton>
      </div>
    </form>

    <div v-if="plan" class="result">
      <p class="summary">
        {{ n('verein', '%n fee totaling {total} for {year}, due on {date}', '%n fees totaling {total} for {year}, due on {date}', plan.included.length, { total: formatMoney(plan.total), year: plan.year, date: formatDate(plan.dueDate) }) }}
        <span v-if="plan.skipped.length"> · {{ n('verein', '%n skipped', '%n skipped', plan.skipped.length) }}</span>
      </p>

      <details v-if="plan.included.length" open>
        <summary>{{ t('verein', 'Who gets a fee') }}</summary>
        <table>
          <thead><tr><th>{{ t('verein', 'Member') }}</th><th>{{ t('verein', 'Fee category') }}</th><th class="num">{{ t('verein', 'Amount') }}</th></tr></thead>
          <tbody>
            <tr v-for="e in plan.included" :key="e.memberId">
              <td>{{ e.name }}</td>
              <td>
                {{ e.category }}
                <span v-if="e.months" class="prorata-note">
                  · {{ t('verein', 'pro rata {months}/12 of {amount}', { months: e.months, amount: formatMoney(e.fullAmount) }) }}
                </span>
              </td>
              <td class="num">{{ formatMoney(e.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </details>

      <details v-if="plan.skipped.length">
        <summary>{{ t('verein', 'Skipped ({count})', { count: plan.skipped.length }) }}</summary>
        <table>
          <thead><tr><th>{{ t('verein', 'Member') }}</th><th>{{ t('verein', 'Reason') }}</th></tr></thead>
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { t, n } from '@nextcloud/l10n'
import { formatMoney, formatDate } from '../format'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'

export default {
  name: 'FeeRun',
  components: { NcButton, NcTextField, NcCheckboxRadioSwitch },
  emits: ['done'],
  setup(props, { emit }) {
    const year = ref(new Date().getFullYear())
    const dueDate = ref(`${new Date().getFullYear()}-03-31`)
    const description = ref('')
    const prorata = ref(false)
    const plan = ref(null)
    const busy = ref(false)

    const params = () => ({
      year: year.value,
      dueDate: dueDate.value,
      description: description.value,
      prorata: prorata.value ? 1 : 0
    })

    const call = async (action) => {
      busy.value = true
      try {
        return await action()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Action failed')))
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
      const count = plan.value.included.length
      if (!(await confirmAction(t('verein', 'Create fees'), n('verein', 'Create %n fee for {year} now?', 'Create %n fees for {year} now?', count, { year: year.value }), { labelConfirm: t('verein', 'Create fees'), severity: 'warning' }))) return
      const res = await call(() => api.post('fee-run', params()))
      if (res) {
        showSuccess(n('verein', '%n fee created', '%n fees created', res.data.created))
        plan.value = null
        emit('done')
      }
    }

    const flagOverdue = async () => {
      const res = await call(() => api.post('finance/flag-overdue', {}))
      if (res) {
        showSuccess(n('verein', '%n fee marked as overdue', '%n fees marked as overdue', res.data.flagged))
        emit('done')
      }
    }


    return { t, n, year, dueDate, description, prorata, plan, busy, preview, run, flagOverdue, formatMoney, formatDate }
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
.prorata { grid-column: 1 / -1; }
.prorata-note { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.buttons { grid-column: 1 / -1; display: flex; gap: 8px; flex-wrap: wrap; }
.result { margin-top: 16px; }
.summary { font-size: 15px; }
details { margin-top: 8px; }
summary { cursor: pointer; font-weight: 600; margin-bottom: 6px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); }
.num { text-align: right; }
</style>
