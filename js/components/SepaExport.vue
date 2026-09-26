<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <div class="sepa-export">
    <h2>{{ t('verein', 'SEPA export – {club}', { club: clubName }) }}</h2>

    <div v-if="accounts.length === 0" class="form-container">
      <p>
        {{ t('verein', 'No bank account is set for this club yet. Account, BIC and creditor ID are maintained in the "Clubs" tab.') }}
      </p>
    </div>

    <div v-else class="form-container">
      <h3>{{ t('verein', 'SEPA direct debit') }}</h3>
      <form @submit.prevent="generateSepa">
        <div class="form-group">
          <NcSelect
            v-model="accountId"
            :options="accounts"
            :reduce="a => a.id"
            :get-option-label="accountLabel"
            :input-label="t('verein', 'Account for the collection')"
            :clearable="false"
          />
        </div>

        <div v-if="selectedAccount" class="creditor-info">
          <p><strong>{{ t('verein', 'Creditor:') }}</strong> {{ clubName }}</p>
          <p><strong>IBAN:</strong> {{ selectedAccount.iban }}</p>
          <p><strong>BIC:</strong> {{ selectedAccount.bic || '–' }}</p>
          <p>
            <strong>{{ t('verein', 'Creditor ID:') }}</strong>
            <span v-if="selectedAccount.creditorId">{{ selectedAccount.creditorId }}</span>
            <span v-else class="missing">{{ t('verein', 'missing – enter it in the "Clubs" tab') }}</span>
          </p>
        </div>

        <div class="form-buttons">
          <NcButton type="button" variant="secondary" @click="preview">{{ t('verein', 'Preview') }}</NcButton>
          <NcButton type="submit" variant="primary">{{ t('verein', 'Download SEPA XML') }}</NcButton>
        </div>
      </form>
    </div>

    <!-- After a download: mark exactly the exported fees as paid -->
    <div v-if="exportedFeeIds.length" class="skipped-warning mark-paid">
      <p>
        {{ n('verein', 'The file contains %n fee. Once you have submitted it to the bank, you can mark exactly this fee as paid.', 'The file contains %n fees. Once you have submitted it to the bank, you can mark exactly these fees as paid.', exportedFeeIds.length) }}
      </p>
      <NcButton variant="primary" :disabled="marking" @click="markExportedPaid">
        {{ n('verein', 'Mark %n fee as paid', 'Mark %n fees as paid', exportedFeeIds.length) }}
      </NcButton>
      <NcButton variant="tertiary" @click="exportedFeeIds = []">{{ t('verein', 'Later') }}</NcButton>
    </div>

    <!-- Preview Section -->
    <div v-if="previewData" class="preview-container">
      <h3>{{ t('verein', 'SEPA export preview') }}</h3>
      <div class="preview-summary">
        <p><strong>{{ t('verein', 'Creditor:') }}</strong> {{ previewData.creditorName }}</p>
        <p><strong>IBAN:</strong> {{ previewData.creditorIban }}</p>
        <p><strong>{{ t('verein', 'Number of transactions:') }}</strong> {{ previewData.transactionCount }}</p>
        <p><strong>{{ t('verein', 'Total amount:') }}</strong> {{ previewData.totalAmount.toFixed(2) }} €</p>
      </div>

      <div v-if="previewData.skipped && previewData.skipped.length" class="skipped-warning">
        <strong>{{ t('verein', 'Not included in the export:') }}</strong>
        <ul>
          <li v-for="(s, idx) in previewData.skipped" :key="idx">
            {{ s.memberName }} – {{ s.amount.toFixed(2) }} €, {{ t('verein', 'due {date}', { date: s.dueDate }) }}
            <em>({{ s.reason }})</em>
          </li>
        </ul>
      </div>

      <h4>{{ t('verein', 'Transactions:') }}</h4>
      <table>
        <thead>
          <tr>
            <th>{{ t('verein', 'Member') }}</th>
            <th>IBAN</th>
            <th>{{ t('verein', 'Mandate') }}</th>
            <th>{{ t('verein', 'Amount') }}</th>
            <th>{{ t('verein', 'Due date') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(txn, idx) in previewData.transactions" :key="idx">
            <td>{{ txn.memberName }}</td>
            <td>{{ txn.iban }}</td>
            <td>{{ txn.mandateReference }} ({{ txn.mandateDate }})</td>
            <td>{{ txn.amount.toFixed(2) }} €</td>
            <td>{{ txn.dueDate }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import { api } from '../api'
import { confirmAction } from '../confirm'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { t, n } from '@nextcloud/l10n'
import { clubState, currentClub } from '../store/club'

export default {
  name: 'SepaExport',
  components: { NcButton, NcSelect },
  data() {
    return {
      accountId: null,
      previewData: null,
      exportedFeeIds: [],
      marking: false
    }
  },
  computed: {
    clubName() {
      return currentClub.value?.name || ''
    },
    accounts() {
      return currentClub.value?.accounts || []
    },
    selectedAccount() {
      return this.accounts.find(a => a.id === this.accountId) || null
    }
  },
  mounted() {
    // Default account comes first (see ClubAccountMapper::findByClub)
    this.accountId = this.accounts[0]?.id ?? null
  },
  methods: {
    t,
    n,
    accountLabel(account) {
      return (account.label ? account.label + ' – ' : '') + account.iban
    },
    requestParams() {
      return { clubId: clubState.currentId, accountId: this.accountId }
    },
    async preview() {
      try {
        const response = await axios.post(
          generateUrl('/apps/verein/sepa/preview'),
          this.requestParams()
        )
        this.previewData = response.data
      } catch (error) {
        console.error('Error loading preview:', error)
        this.previewData = null
        showError(extractErrorMessage(error, t('verein', 'Error loading the preview')))
      }
    },
    async markExportedPaid() {
      if (!(await confirmAction(t('verein', 'Mark as paid'), n('verein', 'Mark %n fee as paid? This should only happen after submitting to the bank.', 'Mark %n fees as paid? This should only happen after submitting to the bank.', this.exportedFeeIds.length), { labelConfirm: t('verein', 'Mark as paid'), severity: 'warning' }))) return
      this.marking = true
      try {
        const res = await api.post('finance/mark-paid', { feeIds: this.exportedFeeIds.join(',') })
        showSuccess(n('verein', '%n fee marked as paid', '%n fees marked as paid', res.data.marked))
        this.exportedFeeIds = []
        this.previewData = null
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Marking failed')))
      } finally {
        this.marking = false
      }
    },
    async generateSepa() {
      try {
        const response = await axios.post(
          generateUrl('/apps/verein/sepa/export'),
          this.requestParams(),
          { responseType: 'blob' }
        )

        // Download file
        const blob = new Blob([response.data], { type: 'application/xml' })
        const url = window.URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `sepa_export_${new Date().toISOString().split('T')[0]}.xml`
        a.click()
        window.URL.revokeObjectURL(url)
        showSuccess(t('verein', 'SEPA XML downloaded'))
        this.exportedFeeIds = (response.headers['x-sepa-fee-ids'] || '').split(',').filter(Boolean).map(Number)
        const skipped = parseInt(response.headers['x-sepa-skipped'] || '0', 10)
        if (skipped > 0) {
          showError(n('verein', 'Attention: %n open payment is missing from the export (no IBAN or no mandate). Details in the preview.', 'Attention: %n open payments are missing from the export (no IBAN or no mandate). Details in the preview.', skipped))
        }
      } catch (error) {
        console.error('Error generating SEPA:', error)
        let message = t('verein', 'Error generating the SEPA file')
        if (error.response?.data instanceof Blob) {
          try {
            const parsed = JSON.parse(await error.response.data.text())
            message = parsed.message || message
          } catch (parseError) {
            // response wasn't JSON, keep the fallback message
          }
        } else {
          message = extractErrorMessage(error, message)
        }
        showError(message)
      }
    }
  }
}
</script>

<style scoped>
.sepa-export {
  padding: 20px;
}

.form-container,
.preview-container {
  background: var(--color-background-hover);
  padding: 20px;
  margin: 20px 0;
  border-radius: 8px;
}

.form-group {
  margin-bottom: 15px;
  max-width: 480px;
}

.creditor-info p {
  margin: 4px 0;
}

.missing {
  color: var(--color-error);
}

.form-buttons {
  margin-top: 15px;
  display: flex;
  gap: 8px;
}

.preview-summary {
  background: var(--color-main-background);
  padding: 15px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.mark-paid { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
.mark-paid p { margin: 0; flex: 1 1 320px; }

.skipped-warning {
  background: var(--color-warning-hover, #fff3cd);
  color: var(--color-main-text);
  border-left: 4px solid var(--color-warning, #e9a800);
  padding: 10px 15px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.preview-summary p {
  margin: 5px 0;
}

table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
  background: var(--color-main-background);
}

th,
td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid var(--color-border);
}

th {
  background-color: var(--color-background-hover);
  font-weight: bold;
}
</style>
