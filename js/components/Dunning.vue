<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="dunning">
    <h2>{{ t('verein', 'Payment reminders') }}</h2>
    <p class="hint">
      {{ t('verein', 'For unpaid fees past their due date, this creates one letter per person and raises the reminder level: payment reminder → second reminder → final notice. Anyone who received a letter recently is skipped, so the run can safely be repeated. The app sends nothing – you get the letters as a PDF to print or send.') }}
    </p>

    <form class="params" @submit.prevent="preview">
      <label class="field">
        <span>{{ t('verein', 'Overdue by at least (days)') }}</span>
        <input v-model.number="overdueDays" type="number" min="0" max="365" class="form-input" required @input="plan = null" />
      </label>
      <label class="field">
        <span>{{ t('verein', 'Minimum days since the last letter') }}</span>
        <input v-model.number="intervalDays" type="number" min="0" max="365" class="form-input" required @input="plan = null" />
      </label>
      <label class="field">
        <span>{{ t('verein', 'Payment deadline in the letter (days)') }}</span>
        <input v-model.number="deadlineDays" type="number" min="1" max="90" class="form-input" required />
      </label>
      <div class="buttons">
        <NcButton type="submit" variant="secondary" :disabled="busy">{{ t('verein', 'Preview') }}</NcButton>
        <NcButton
          type="button"
          variant="primary"
          :disabled="busy || !plan || !plan.included.length"
          @click="run"
        >
          {{ plan && plan.included.length ? n('verein', 'Create %n letter', 'Create %n letters', plan.included.length) : t('verein', 'Create letters') }}
        </NcButton>
        <NcButton v-if="lastFeeIds.length" type="button" variant="tertiary" :disabled="busy" @click="download(lastFeeIds)">
          {{ t('verein', 'Download the last letters again') }}
        </NcButton>
      </div>
    </form>

    <div v-if="plan" class="result">
      <p class="summary">
        {{ n('verein', '%n letter, total {total}', '%n letters, total {total}', plan.included.length, { total: money(plan.total) }) }}
        <span v-if="plan.skipped.length"> · {{ n('verein', '%n skipped', '%n skipped', plan.skipped.length) }}</span>
      </p>
      <p v-if="!plan.hasAccount" class="warning">
        {{ t('verein', 'No bank account is set for this club (“Club” tab), so the letters will not include bank details.') }}
      </p>
      <p v-if="withoutAddress" class="warning">
        {{ n('verein', '%n person without a complete address – their letter cannot be sent by post.', '%n people without a complete address – their letters cannot be sent by post.', withoutAddress) }}
      </p>

      <details v-if="plan.included.length" open>
        <summary>{{ t('verein', 'Who gets a letter') }}</summary>
        <table>
          <thead><tr><th>{{ t('verein', 'Member') }}</th><th>{{ t('verein', 'Letter') }}</th><th>{{ t('verein', 'Fees') }}</th><th class="num">{{ t('verein', 'Unpaid') }}</th></tr></thead>
          <tbody>
            <tr v-for="e in plan.included" :key="e.memberId">
              <td>{{ e.name }}<span v-if="!e.hasAddress" class="hint"> ({{ t('verein', 'no address') }})</span></td>
              <td>{{ e.levelLabel }}</td>
              <td>{{ e.fees.map(f => f.period || f.description).join(', ') }}</td>
              <td class="num">{{ money(e.total) }}</td>
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
import { ref, computed } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t, n } from '@nextcloud/l10n'
import { formatMoney } from '../format'
import { api } from '../api'
import { confirmAction } from '../confirm'
import { extractErrorMessage } from '../errorMessage'

// same labels as DunningService::levelLabel()
export const DUNNING_LEVELS = {
  1: t('verein', 'Payment reminder'),
  2: t('verein', 'Second reminder'),
  3: t('verein', 'Final notice'),
}

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
        showError(extractErrorMessage(error, t('verein', 'Preview failed')))
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
        // the server names the file in the letters' language and with the local date
        const disposition = response.headers?.['content-disposition'] || ''
        const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
        link.download = name ? decodeURIComponent(name[1]) : 'letters.pdf'
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      } catch (error) {
        let message = t('verein', 'The letters could not be created')
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
      const count = plan.value.included.length
      if (!(await confirmAction(t('verein', 'Create reminder letters'), n('verein', 'Create %n letter? The reminder level of these fees is raised; this cannot be undone automatically.', 'Create %n letters? The reminder level of these fees is raised; this cannot be undone automatically.', count), { labelConfirm: t('verein', 'Create letters'), severity: 'warning' }))) return
      busy.value = true
      let result = null
      try {
        result = (await api.post('dunning', params())).data
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Reminder run failed')))
      } finally {
        busy.value = false
      }
      if (!result) return
      showSuccess(n('verein', '%n letter created', '%n letters created', result.dunned))
      plan.value = null
      emit('done')
      if (result.feeIds && result.feeIds.length) {
        lastFeeIds.value = result.feeIds
        await download(result.feeIds)
      }
    }

    const money = formatMoney

    return { t, n, overdueDays, intervalDays, deadlineDays, plan, busy, lastFeeIds, withoutAddress, preview, run, download, money }
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
