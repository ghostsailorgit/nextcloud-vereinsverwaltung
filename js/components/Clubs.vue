<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="clubs-page">
    <h2>Verein: {{ club ? club.name : '–' }}</h2>

    <!-- Club data -->
    <div v-if="club && canManage" class="card">
      <h3>Vereinsdaten</h3>
      <form class="grid" @submit.prevent="saveClub">
        <NcTextField :model-value="form.name" @update:model-value="form.name = $event" label="Name des Vereins" required />
        <NcTextField :model-value="form.street" @update:model-value="form.street = $event" label="Straße" />
        <NcTextField :model-value="form.postalCode" @update:model-value="form.postalCode = $event" label="PLZ" />
        <NcTextField :model-value="form.city" @update:model-value="form.city = $event" label="Ort" />
        <NcTextField
          :model-value="form.documentsPath"
          @update:model-value="form.documentsPath = $event"
          label="Team-Ordner in Nextcloud Files"
          placeholder="/Mein-Verein"
          helper-text="Pfad des Vereinsordners; hier liegen Dokumente und die unterschriebenen SEPA-Mandate."
        />
        <NcTextField
          :model-value="form.calendarGroups"
          @update:model-value="form.calendarGroups = $event"
          label="Nextcloud-Gruppen für den Vereinskalender"
          placeholder="vorstand, mitglieder"
          helper-text="Kommagetrennt. Diese Gruppen können den Kalender „Vereinstermine“ (Geburtstage, Jubiläen) sehen."
        />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">Speichern</NcButton>
        </div>
      </form>
    </div>
    <p v-else-if="club" class="hint">Du hast keine Berechtigung, die Vereinsdaten zu ändern.</p>

    <!-- Bank accounts -->
    <div v-if="club && canManage" class="card">
      <h3>Bankkonten</h3>
      <p class="hint">
        Ein Verein kann mehrere Konten haben. Bezeichnung, BIC und Gläubiger-ID werden beim SEPA-Export verwendet.
      </p>
      <table v-if="club.accounts.length" class="accounts">
        <thead>
          <tr><th>Bezeichnung</th><th>IBAN</th><th>BIC</th><th>Gläubiger-ID</th><th>Aktionen</th></tr>
        </thead>
        <tbody>
          <tr v-for="a in club.accounts" :key="a.id">
            <td>{{ a.label || '–' }} <span v-if="a.isDefault" class="badge">Standard</span></td>
            <td>{{ a.iban }}</td>
            <td>{{ a.bic || '–' }}</td>
            <td>{{ a.creditorId || '–' }}</td>
            <td class="row-actions">
              <NcButton variant="secondary" @click="editAccount(a)">Bearbeiten</NcButton>
              <NcButton variant="error" @click="removeAccount(a)">Löschen</NcButton>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="hint">Noch kein Konto hinterlegt.</p>

      <h4>{{ accountForm.id ? 'Konto bearbeiten' : 'Konto hinzufügen' }}</h4>
      <form class="grid" @submit.prevent="saveAccount">
        <NcTextField :model-value="accountForm.label" @update:model-value="accountForm.label = $event" label="Bezeichnung" placeholder="z.B. Vereinskonto" />
        <NcTextField :model-value="accountForm.iban" @update:model-value="accountForm.iban = $event" label="IBAN" required />
        <NcTextField :model-value="accountForm.bic" @update:model-value="accountForm.bic = $event" label="BIC" />
        <NcTextField :model-value="accountForm.creditorId" @update:model-value="accountForm.creditorId = $event" label="Gläubiger-ID (SEPA)" placeholder="DE98ZZZ09999999999" />
        <label class="checkbox-field">
          <input v-model="accountForm.isDefault" type="checkbox" />
          <span>Standardkonto</span>
        </label>
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ accountForm.id ? 'Speichern' : 'Hinzufügen' }}</NcButton>
          <NcButton v-if="accountForm.id" type="button" variant="tertiary" @click="resetAccountForm">Abbrechen</NcButton>
        </div>
      </form>
    </div>

    <!-- Fee categories -->
    <div v-if="club && canManage" class="card">
      <h3>Beitragskategorien</h3>
      <p class="hint">
        Jede Kategorie hat einen Jahresbeitrag. Die Standardkategorie gilt für Mitglieder ohne eigene Kategorie
        (Mitgliederformular). 0 € = beitragsfrei (z. B. Ehrenmitglieder). Sie werden im Beitragslauf (Reiter „Finanzen“) verwendet.
      </p>
      <table v-if="club.feeRates && club.feeRates.length" class="accounts">
        <thead><tr><th>Kategorie</th><th>Jahresbeitrag</th><th>Aktionen</th></tr></thead>
        <tbody>
          <tr v-for="r in club.feeRates" :key="r.id">
            <td>{{ r.name }} <span v-if="r.isDefault" class="badge">Standard</span></td>
            <td>{{ Number(r.amount).toFixed(2).replace('.', ',') }} €</td>
            <td class="row-actions">
              <NcButton variant="secondary" @click="editRate(r)">Bearbeiten</NcButton>
              <NcButton variant="error" @click="removeRate(r)">Löschen</NcButton>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="hint">Noch keine Kategorie angelegt.</p>

      <h4>{{ rateForm.id ? 'Kategorie bearbeiten' : 'Kategorie hinzufügen' }}</h4>
      <form class="grid" @submit.prevent="saveRate">
        <NcTextField :model-value="rateForm.name" @update:model-value="rateForm.name = $event" label="Name" placeholder="z. B. Erwachsene" required />
        <NcTextField :model-value="rateForm.amount" @update:model-value="rateForm.amount = $event" label="Jahresbeitrag in €" placeholder="24,00" required />
        <label class="checkbox-field">
          <input v-model="rateForm.isDefault" type="checkbox" />
          <span>Standardkategorie</span>
        </label>
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">{{ rateForm.id ? 'Speichern' : 'Hinzufügen' }}</NcButton>
          <NcButton v-if="rateForm.id" type="button" variant="tertiary" @click="resetRateForm">Abbrechen</NcButton>
        </div>
      </form>
    </div>

    <!-- Automatic rights -->
    <div v-if="club && canManageRoles" class="card">
      <h3>Automatische Rechte</h3>
      <p class="hint">
        Mitglieder mit verknüpftem Nextcloud-Konto (Mitgliederformular) bekommen je nach Vereinsrolle automatisch eine
        App-Rolle für diesen Verein. Die Rechte enden von selbst, sobald jemand austritt oder verstirbt. Leer = keine
        automatischen Rechte. Zusätzlich vergebene Rollen unter „Rollen“ bleiben bestehen.
      </p>
      <form class="grid" @submit.prevent="saveMapping">
        <NcSelect
          v-for="m in mappingRows"
          :key="m.key"
          v-model="mapping[m.key]"
          :options="roleOptions"
          :reduce="r => r.id"
          label="name"
          :input-label="m.label"
          placeholder="keine automatische Rolle"
        />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">Speichern</NcButton>
        </div>
      </form>
    </div>

    <Backups v-if="isAdmin" />

    <!-- Nextcloud administrators: add / remove clubs -->
    <div v-if="isAdmin" class="card">
      <h3>Vereine verwalten (Administrator)</h3>
      <form class="grid" @submit.prevent="createClub">
        <NcTextField :model-value="newClubName" @update:model-value="newClubName = $event" label="Name des neuen Vereins" required />
        <div class="actions">
          <NcButton type="submit" variant="primary" :disabled="busy">Verein anlegen</NcButton>
        </div>
      </form>
      <p v-if="club" class="delete-row">
        <NcButton variant="error" :disabled="busy" @click="deleteClub">„{{ club.name }}“ löschen</NcButton>
        <span class="hint">Nur möglich, wenn der Verein keine Mitglieder mehr hat.</span>
      </p>
    </div>
  </div>
</template>

<script>
import { reactive, ref, computed, watch, onMounted } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Backups from './Backups.vue'
import { api } from '../api'
import { extractErrorMessage } from '../errorMessage'
import { clubState, currentClub, loadClubs, setCurrentClub, can } from '../store/club'

const emptyAccount = () => ({ id: null, label: '', iban: '', bic: '', creditorId: '', isDefault: false })

export default {
  name: 'Clubs',
  components: { NcButton, NcTextField, NcSelect, Backups },
  setup() {
    const busy = ref(false)
    const newClubName = ref('')
    const accountForm = reactive(emptyAccount())
    const form = reactive({ name: '', street: '', postalCode: '', city: '', documentsPath: '', calendarGroups: '' })

    const club = computed(() => currentClub.value)
    const isAdmin = computed(() => clubState.isAdmin)
    const canManage = computed(() => can('verein.club.manage'))
    const canManageRoles = computed(() => can('verein.role.manage'))

    // automatic rights: membership role -> app role
    const roleOptions = ref([])
    const mapping = reactive({ member: null, treasurer: null, admin: null })
    const mappingRows = [
      { key: 'admin', label: 'Vorstand erhält die Rolle' },
      { key: 'treasurer', label: 'Kassierer erhält die Rolle' },
      { key: 'member', label: 'Mitglied erhält die Rolle' }
    ]
    const fillMapping = () => {
      const m = club.value?.roleMapping || {}
      mapping.member = m.member ?? null
      mapping.treasurer = m.treasurer ?? null
      mapping.admin = m.admin ?? null
    }
    onMounted(async () => {
      if (!canManageRoles.value) return
      try {
        const res = await api.get('roles')
        roleOptions.value = Array.isArray(res.data) ? res.data : []
      } catch (e) {
        roleOptions.value = []
      }
    })

    const fillForm = () => {
      const c = club.value
      Object.assign(form, {
        name: c?.name || '',
        street: c?.street || '',
        postalCode: c?.postalCode || '',
        city: c?.city || '',
        documentsPath: c?.documentsPath || '',
        calendarGroups: (c?.calendarGroups || []).join(', ')
      })
    }
    watch(club, () => { fillForm(); fillMapping() }, { immediate: true })

    const run = async (action, okMessage) => {
      busy.value = true
      try {
        await action()
        if (okMessage) showSuccess(okMessage)
        return true
      } catch (error) {
        showError(extractErrorMessage(error, 'Aktion fehlgeschlagen'))
        return false
      } finally {
        busy.value = false
      }
    }

    const saveClub = () => run(async () => {
      await api.updateClub(club.value.id, { ...form })
      await loadClubs()
    }, 'Vereinsdaten gespeichert')

    const saveMapping = () => run(async () => {
      await api.put(`clubs/${club.value.id}/role-mapping`, {
        member: mapping.member ?? '',
        treasurer: mapping.treasurer ?? '',
        admin: mapping.admin ?? ''
      })
      await loadClubs()
    }, 'Automatische Rechte gespeichert')

    const createClub = async () => {
      let createdId = null
      const ok = await run(async () => {
        const res = await api.createClub({ name: newClubName.value })
        createdId = res.data?.data?.id
        await loadClubs()
      }, 'Verein angelegt')
      if (ok) {
        newClubName.value = ''
        if (createdId) setCurrentClub(createdId)
      }
    }

    const deleteClub = async () => {
      if (!confirm(`Verein „${club.value.name}“ wirklich löschen? Bankkonten und Rollenzuweisungen gehen verloren.`)) return
      const ok = await run(async () => {
        await api.deleteClub(club.value.id)
        clubState.currentId = null
        await loadClubs()
      }, 'Verein gelöscht')
      if (!ok) await loadClubs()
    }

    // fee categories
    const rateForm = reactive({ id: null, name: '', amount: '', isDefault: false })
    const resetRateForm = () => Object.assign(rateForm, { id: null, name: '', amount: '', isDefault: false })
    const editRate = (r) => Object.assign(rateForm, { id: r.id, name: r.name, amount: String(r.amount).replace('.', ','), isDefault: r.isDefault })
    const saveRate = async () => {
      const ok = await run(async () => {
        const payload = { name: rateForm.name, amount: rateForm.amount, isDefault: rateForm.isDefault }
        if (rateForm.id) {
          await api.put(`clubs/${club.value.id}/fee-rates/${rateForm.id}`, payload)
        } else {
          await api.post(`clubs/${club.value.id}/fee-rates`, payload)
        }
        await loadClubs()
      }, 'Beitragskategorie gespeichert')
      if (ok) resetRateForm()
    }
    const removeRate = async (r) => {
      if (!confirm(`Kategorie „${r.name}“ wirklich löschen?`)) return
      await run(async () => {
        await api.delete(`clubs/${club.value.id}/fee-rates/${r.id}`)
        await loadClubs()
      }, 'Beitragskategorie gelöscht')
    }

    const resetAccountForm = () => Object.assign(accountForm, emptyAccount())
    const editAccount = (a) => Object.assign(accountForm, emptyAccount(), a)

    const saveAccount = async () => {
      const ok = await run(async () => {
        const payload = { ...accountForm }
        if (accountForm.id) {
          await api.updateClubAccount(club.value.id, accountForm.id, payload)
        } else {
          await api.createClubAccount(club.value.id, payload)
        }
        await loadClubs()
      }, 'Konto gespeichert')
      if (ok) resetAccountForm()
    }

    const removeAccount = async (a) => {
      if (!confirm(`Konto ${a.iban} wirklich löschen?`)) return
      await run(async () => {
        await api.deleteClubAccount(club.value.id, a.id)
        await loadClubs()
      }, 'Konto gelöscht')
    }

    return {
      rateForm, saveRate, editRate, removeRate, resetRateForm,
      busy, club, isAdmin, canManage, canManageRoles, roleOptions, mapping, mappingRows, saveMapping, form, accountForm, newClubName,
      saveClub, createClub, deleteClub, saveAccount, editAccount, removeAccount, resetAccountForm
    }
  }
}
</script>

<style scoped>
.clubs-page { padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.card {
  background: var(--color-main-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  padding: 20px;
}
.card h3 { margin-top: 0; }
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 12px;
  align-items: start;
}
.actions { grid-column: 1 / -1; display: flex; gap: 8px; }
.hint { color: var(--color-text-maxcontrast); }
.accounts { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
.accounts th, .accounts td { padding: 8px; text-align: left; border-bottom: 1px solid var(--color-border); }
.row-actions { display: flex; gap: 8px; }
.badge {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  border-radius: 8px;
  padding: 1px 8px;
  font-size: 12px;
  margin-left: 6px;
}
.checkbox-field { display: flex; gap: 8px; align-items: center; }
.delete-row { display: flex; gap: 12px; align-items: center; margin-top: 16px; }
</style>
