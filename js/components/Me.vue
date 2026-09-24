<template>
  <div class="me-page">
    <h2>Meine Daten</h2>
    <p v-if="loading">Lade…</p>
    <p v-else-if="error" class="error">{{ error }}</p>

    <template v-else-if="data && data.linked">
      <p class="hint">
        Das sind die Daten, die der Verein über dich gespeichert hat (Selbstauskunft). Stimmt etwas nicht,
        wende dich bitte an den Vorstand.
      </p>

      <div class="card">
        <h3>Persönliche Daten</h3>
        <dl>
          <dt>Name</dt><dd>{{ personName }}</dd>
          <dt>Geburtsdatum</dt><dd>{{ formatDate(data.person.birthDate) }}</dd>
          <dt>Anschrift</dt><dd>{{ address }}</dd>
          <dt>E-Mail</dt><dd>{{ data.person.email || '–' }}</dd>
          <dt>IBAN</dt><dd>{{ data.person.iban || '–' }}</dd>
          <dt>BIC</dt><dd>{{ data.person.bic || '–' }}</dd>
          <dt>Nextcloud-Konto</dt><dd>{{ data.nextcloudAccount }}</dd>
        </dl>
      </div>

      <div v-for="m in data.memberships" :key="m.club.id" class="card">
        <h3>
          {{ m.club.name }}
          <span :class="['badge', m.isFormer ? 'former' : 'active']">{{ m.isFormer ? 'Ehemalig' : 'Aktiv' }}</span>
        </h3>
        <dl>
          <dt>Funktion</dt><dd>{{ roleLabel(m.role) }}<span v-if="m.foundingMember"> · Gründungsmitglied ★</span></dd>
          <dt>Mitglied seit</dt>
          <dd>{{ formatDate(m.joinDate) }}<span v-if="m.membershipYears !== null"> ({{ m.membershipYears }} {{ m.membershipYears === 1 ? 'Jahr' : 'Jahre' }})</span></dd>
          <dt v-if="m.leaveDate">Ausgetreten am</dt><dd v-if="m.leaveDate">{{ formatDate(m.leaveDate) }}</dd>
          <dt>SEPA-Mandat</dt>
          <dd>
            <span v-if="m.mandate.date">Referenz {{ m.mandate.reference }}, unterschrieben am {{ formatDate(m.mandate.date) }}<span v-if="m.mandate.signedCopyOnFile"> (Kopie liegt vor)</span></span>
            <span v-else>kein Mandat erfasst</span>
          </dd>
        </dl>
      </div>

      <div class="card">
        <h3>Beiträge</h3>
        <table v-if="data.fees.length">
          <thead><tr><th>Verein</th><th>Fällig</th><th>Betrag</th><th>Status</th><th>Bemerkung</th></tr></thead>
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
        <p v-else class="hint">Keine Beiträge erfasst.</p>
      </div>

      <div class="actions">
        <NcButton variant="secondary" @click="download">Meine Daten herunterladen (JSON)</NcButton>
        <NcButton variant="tertiary" @click="print">Drucken</NcButton>
      </div>
    </template>

    <p v-else class="hint">
      Mit deinem Nextcloud-Konto ist noch kein Mitglied verknüpft. Der Vorstand kann das im Mitgliederformular
      unter „Verknüpftes Nextcloud-Konto“ einrichten.
    </p>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import NcButton from '@nextcloud/vue/components/NcButton'
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
        error.value = extractErrorMessage(e, 'Die Daten konnten nicht geladen werden')
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
    const roleLabel = (role) => ({ member: 'Mitglied', treasurer: 'Kassierer', admin: 'Vorstand' }[role] || role)
    const statusLabel = (s) => ({ open: 'offen', paid: 'bezahlt', overdue: 'überfällig', cancelled: 'storniert' }[s] || s)

    const download = () => {
      window.location.href = absoluteUrl('/apps/verein/me/export')
    }
    const print = () => window.print()

    return { data, loading, error, personName, address, formatDate, roleLabel, statusLabel, download, print }
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
