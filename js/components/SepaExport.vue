<template>
  <div class="sepa-export">
    <h2>SEPA-Export – {{ clubName }}</h2>

    <div v-if="accounts.length === 0" class="form-container">
      <p>
        Für diesen Verein ist noch kein Bankkonto hinterlegt. Konto, BIC und Gläubiger-ID
        werden im Reiter „Vereine“ gepflegt.
      </p>
    </div>

    <div v-else class="form-container">
      <h3>SEPA-Lastschrift</h3>
      <form @submit.prevent="generateSepa">
        <div class="form-group">
          <NcSelect
            v-model="accountId"
            :options="accounts"
            :reduce="a => a.id"
            :get-option-label="accountLabel"
            input-label="Konto für den Einzug"
            :clearable="false"
          />
        </div>

        <div v-if="selectedAccount" class="creditor-info">
          <p><strong>Gläubiger:</strong> {{ clubName }}</p>
          <p><strong>IBAN:</strong> {{ selectedAccount.iban }}</p>
          <p><strong>BIC:</strong> {{ selectedAccount.bic || '–' }}</p>
          <p>
            <strong>Gläubiger-ID:</strong>
            <span v-if="selectedAccount.creditorId">{{ selectedAccount.creditorId }}</span>
            <span v-else class="missing">fehlt – im Reiter „Vereine“ eintragen</span>
          </p>
        </div>

        <div class="form-buttons">
          <NcButton type="button" variant="secondary" @click="preview">Vorschau</NcButton>
          <NcButton type="submit" variant="primary">SEPA-XML herunterladen</NcButton>
        </div>
      </form>
    </div>

    <!-- Preview Section -->
    <div v-if="previewData" class="preview-container">
      <h3>Vorschau SEPA-Export</h3>
      <div class="preview-summary">
        <p><strong>Gläubiger:</strong> {{ previewData.creditorName }}</p>
        <p><strong>IBAN:</strong> {{ previewData.creditorIban }}</p>
        <p><strong>Anzahl Transaktionen:</strong> {{ previewData.transactionCount }}</p>
        <p><strong>Gesamtbetrag:</strong> {{ previewData.totalAmount.toFixed(2) }} €</p>
      </div>

      <div v-if="previewData.skipped && previewData.skipped.length" class="skipped-warning">
        <strong>Nicht im Export enthalten:</strong>
        <ul>
          <li v-for="(s, idx) in previewData.skipped" :key="idx">
            {{ s.memberName }} – {{ s.amount.toFixed(2) }} €, fällig {{ s.dueDate }}
            <em>({{ s.reason }})</em>
          </li>
        </ul>
      </div>

      <h4>Transaktionen:</h4>
      <table>
        <thead>
          <tr>
            <th>Mitglied</th>
            <th>IBAN</th>
            <th>Mandat</th>
            <th>Betrag</th>
            <th>Fälligkeitsdatum</th>
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { clubState, currentClub } from '../store/club'

export default {
  name: 'SepaExport',
  components: { NcButton, NcSelect },
  data() {
    return {
      accountId: null,
      previewData: null
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
        showError(extractErrorMessage(error, 'Fehler beim Laden der Vorschau'))
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
        showSuccess('SEPA-XML heruntergeladen')
        const skipped = parseInt(response.headers['x-sepa-skipped'] || '0', 10)
        if (skipped > 0) {
          showError(`Achtung: ${skipped} offene Zahlung(en) fehlen im Export (keine IBAN oder kein Mandat). Details in der Vorschau.`)
        }
      } catch (error) {
        console.error('Error generating SEPA:', error)
        let message = 'Fehler beim Generieren der SEPA-Datei'
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
