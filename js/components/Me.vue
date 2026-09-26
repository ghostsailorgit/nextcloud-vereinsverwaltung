<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="me-page">
    <h2>{{ t('verein', 'My data') }}</h2>
    <p v-if="loading">{{ t('verein', 'Loading…') }}</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <template v-else-if="data && data.linked">
      <p class="hint">
        {{ t('verein', 'This is the data the club has stored about you (self-disclosure). If something is wrong, please contact the board.') }}
      </p>

      <div class="card">
        <h3>{{ t('verein', 'Personal data') }}</h3>
        <dl>
          <dt>{{ t('verein', 'Name') }}</dt><dd>{{ personName }}</dd>
          <dt>{{ t('verein', 'Birth date') }}</dt><dd>{{ formatDate(data.person.birthDate) }}</dd>
          <dt>{{ t('verein', 'Postal address') }}</dt><dd>{{ address }}</dd>
          <dt>{{ t('verein', 'E-mail') }}</dt><dd>{{ data.person.email || '–' }}</dd>
          <dt>IBAN</dt><dd>{{ data.person.iban || '–' }}</dd>
          <dt>BIC</dt><dd>{{ data.person.bic || '–' }}</dd>
          <dt>{{ t('verein', 'Nextcloud account') }}</dt><dd>{{ data.nextcloudAccount }}</dd>
        </dl>
      </div>

      <div v-for="m in data.memberships" :key="m.club.id" class="card">
        <h3>
          {{ m.club.name }}
          <span :class="['badge', m.isFormer ? 'former' : 'active']">{{ m.isFormer ? t('verein', 'Former') : t('verein', 'Active') }}</span>
        </h3>
        <dl>
          <dt>{{ t('verein', 'Club role') }}</dt><dd>{{ roleLabel(m.role) }}<span v-if="m.foundingMember"> · {{ t('verein', 'Founding member') }} ★</span></dd>
          <dt>{{ t('verein', 'Member since') }}</dt>
          <dd>{{ formatDate(m.joinDate) }}<span v-if="m.membershipYears !== null"> ({{ n('verein', '%n year', '%n years', m.membershipYears) }})</span></dd>
          <dt v-if="m.leaveDate">{{ t('verein', 'Left on') }}</dt><dd v-if="m.leaveDate">{{ formatDate(m.leaveDate) }}</dd>
          <dt>{{ t('verein', 'SEPA mandate') }}</dt>
          <dd>
            <span v-if="m.mandate.date">{{ t('verein', 'Reference {reference}, signed on {date}', { reference: m.mandate.reference, date: formatDate(m.mandate.date) }) }}<span v-if="m.mandate.signedCopyOnFile"> ({{ t('verein', 'copy on file') }})</span></span>
            <span v-else>{{ t('verein', 'no mandate recorded') }}</span>
          </dd>
        </dl>
      </div>

      <div class="card">
        <h3>{{ t('verein', 'Fees') }}</h3>
        <table v-if="data.fees.length">
          <thead><tr><th>{{ t('verein', 'Club') }}</th><th>{{ t('verein', 'Due') }}</th><th>{{ t('verein', 'Amount') }}</th><th>{{ t('verein', 'Status') }}</th><th>{{ t('verein', 'Remark') }}</th></tr></thead>
          <tbody>
            <tr v-for="(f, i) in data.fees" :key="i">
              <td>{{ f.club }}</td>
              <td>{{ formatDate(f.dueDate) }}</td>
              <td>{{ Number(f.amount).toFixed(2) }} €</td>
              <td>{{ statusLabel(f.status) }}</td>
              <td>{{ f.description || '' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="hint">{{ t('verein', 'No fees recorded.') }}</p>
      </div>

      <div class="actions">
        <NcButton variant="secondary" @click="download">{{ t('verein', 'Download my data (JSON)') }}</NcButton>
        <NcButton variant="tertiary" @click="print">{{ t('verein', 'Print') }}</NcButton>
      </div>
    </template>

    <p v-else class="hint">
      {{ t('verein', 'No member is linked to your Nextcloud account yet. The board can set this up in the member form under "Linked Nextcloud account".') }}
    </p>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t, n } from '@nextcloud/l10n'
import { absoluteUrl } from '../absoluteUrl'
import { extractErrorMessage } from '../errorMessage'

export default {
  name: 'Me',
  components: { NcButton },
  setup() {
    const data = ref(null)
    const loading = ref(true)
    const error = ref('')

    onMounted(async () => {
      try {
        const res = await axios.get(absoluteUrl('/apps/verein/me'))
        data.value = res.data
      } catch (e) {
        error.value = extractErrorMessage(e, t('verein', 'The data could not be loaded'))
      } finally {
        loading.value = false
      }
    })

    const personName = computed(() => {
      const p = data.value?.person
      if (!p) return ''
      return [p.salutation, p.firstName, p.name].filter(Boolean).join(' ')
    })
    const address = computed(() => {
      const p = data.value?.person
      if (!p) return '–'
      const line = [p.street, [p.postalCode, p.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
      return line || '–'
    })

    const formatDate = (value) => {
      if (!value) return '–'
      const d = new Date(String(value).replace(' ', 'T'))
      return isNaN(d) ? value : d.toLocaleDateString('de-DE')
    }
    const roleLabel = (role) => ({ member: t('verein', 'Member'), treasurer: t('verein', 'Treasurer'), admin: t('verein', 'Board') }[role] || role)
    const statusLabel = (s) => ({ open: t('verein', 'open'), paid: t('verein', 'paid'), overdue: t('verein', 'overdue'), cancelled: t('verein', 'cancelled') }[s] || s)

    const download = () => {
      window.location.href = absoluteUrl('/apps/verein/me/export')
    }
    const print = () => window.print()

    return { t, n, data, loading, error, personName, address, formatDate, roleLabel, statusLabel, download, print }
  }
}
</script>

<style scoped>
.me-page { padding: 20px; display: flex; flex-direction: column; gap: 16px; max-width: 900px; }
.card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 16px 20px;
}
.card h3 { margin-top: 0; }
dl { display: grid; grid-template-columns: 180px 1fr; gap: 6px 16px; margin: 0; }
dt { color: var(--color-text-maxcontrast); }
dd { margin: 0; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--color-border); }
.hint { color: var(--color-text-maxcontrast); }
.error { color: var(--color-error); }
.actions { display: flex; gap: 8px; }
.badge { font-size: 12px; padding: 2px 8px; border-radius: 8px; margin-left: 8px; }
.badge.active { background: var(--color-success); color: #fff; }
.badge.former { background: var(--color-background-darker); }
@media print { .actions { display: none; } }
</style>
