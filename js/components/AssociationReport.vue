<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <section class="association-report">
    <h2>{{ t('verein', 'Membership report for the sports federation') }}</h2>
    <p class="hint">{{ t('verein', 'Members by birth year and gender on the reference date, as a table and as an XML file for upload to the state sports federation. Without a stored gender the title is used.') }}</p>

    <form class="params" @submit.prevent="load">
      <label class="field">
        <span>{{ t('verein', 'Reference date') }}</span>
        <input v-model="date" type="date" class="form-input" required />
      </label>
      <NcTextField :model-value="number" @update:model-value="number = $event" :label="t('verein', 'Club number')" maxlength="20" />
      <NcTextField :model-value="contact" @update:model-value="contact = $event" :label="t('verein', 'Contact person')" maxlength="100" />
      <NcTextField :model-value="sportCode" @update:model-value="sportCode = $event" :label="t('verein', 'Federation code (optional)')" :helper-text="t('verein', 'Code of the sport or federation. Leave empty to report totals only.')" maxlength="6" />
      <div class="buttons">
        <NcButton type="submit" variant="secondary" :disabled="busy">{{ t('verein', 'Show') }}</NcButton>
        <NcButton type="button" variant="primary" :disabled="busy || !number.trim()" @click="download">{{ t('verein', 'Download XML file') }}</NcButton>
      </div>
    </form>

    <div v-if="report">
      <p>{{ n('verein', '%n member counted', '%n members counted', report.total) }}</p>
      <p v-if="report.genderFromTitle" class="hint">{{ n('verein', 'For %n member the gender was taken from the title.', 'For %n members the gender was taken from the title.', report.genderFromTitle) }}</p>
      <p v-if="report.genderUnknown" class="hint">{{ n('verein', '%n member counts as “not specified”.', '%n members count as “not specified”.', report.genderUnknown) }}</p>
      <details v-if="report.missingBirthDate.length" open>
        <summary>{{ t('verein', 'Not counted because the date of birth is missing ({count})', { count: report.missingBirthDate.length }) }}</summary>
        <p>{{ report.missingBirthDate.join(', ') }}</p>
      </details>
      <div v-if="report.rows.length" class="table-scroll">
        <table>
          <thead><tr><th>{{ t('verein', 'Birth year') }}</th><th class="num">{{ t('verein', 'Male') }}</th><th class="num">{{ t('verein', 'Female') }}</th><th class="num">{{ t('verein', 'Non-binary') }}</th><th class="num">{{ t('verein', 'Not specified') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in report.rows" :key="row.year">
              <td>{{ row.year }}</td><td class="num">{{ row.M }}</td><td class="num">{{ row.W }}</td><td class="num">{{ row.D }}</td><td class="num">{{ row.O }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<script>
import { ref, watch } from 'vue'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t, n } from '@nextcloud/l10n'
import { api } from '../api'
import { extractErrorMessage } from '../errorMessage'
import { clubState } from '../store/club'

// a convenience per viewer: the report is due once a year
const storageKey = () => `verein-association-report-${clubState.currentId}`
const remembered = () => {
  try {
    return JSON.parse(localStorage.getItem(storageKey()) || '{}')
  } catch (e) {
    return {}
  }
}

export default {
  name: 'AssociationReport',
  components: { NcButton, NcTextField },
  setup() {
    const saved = remembered()
    const date = ref(`${new Date().getFullYear()}-01-01`)
    const number = ref(saved.number || '')
    const contact = ref(saved.contact || '')
    const sportCode = ref(saved.sportCode || '')
    const report = ref(null)
    const busy = ref(false)

    watch([number, contact, sportCode], () => {
      try {
        localStorage.setItem(storageKey(), JSON.stringify({ number: number.value, contact: contact.value, sportCode: sportCode.value }))
      } catch (e) {
        // only a convenience
      }
    })

    const call = async (action) => {
      busy.value = true
      try {
        await action()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Action failed')))
      } finally {
        busy.value = false
      }
    }

    const load = () => call(async () => {
      report.value = (await api.get('association-report', { params: { date: date.value } })).data.data
    })

    const download = () => call(async () => {
      const response = await api.post('association-report/xml', { date: date.value, number: number.value, contact: contact.value, sportCode: sportCode.value }, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = `bestandserhebung-${date.value.substring(0, 4)}.xml`
      link.click()
      URL.revokeObjectURL(url)
    })

    return { t, n, date, number, contact, sportCode, report, busy, load, download }
  },
}
</script>

<style scoped>
.association-report {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px 24px;
  margin-top: 20px;
}
.hint { color: var(--color-text-maxcontrast); }
.params { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: start; margin: 12px 0; }
.field { display: flex; flex-direction: column; gap: 4px; }
.buttons { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 8px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); }
.num { text-align: right; }
</style>
