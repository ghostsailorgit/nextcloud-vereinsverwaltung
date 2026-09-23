<template>
  <div class="settings-page">
    <h2>Einstellungen</h2>

    <div class="settings-grid">
      <div class="card">
        <h3>Rollen & Berechtigungen</h3>
        <p>Verwalte Rollen und die zugehörigen Berechtigungen.</p>
        <NcButton variant="secondary" @click="$emit('navigate', 'roles')">Zu Rollen</NcButton>
      </div>

      <div class="card">
        <h3>SEPA / Exporte</h3>
        <p>Export-Optionen verwalten und SEPA-Export erstellen.</p>
        <NcButton variant="secondary" @click="$emit('navigate', 'sepa')">Zu SEPA</NcButton>
      </div>

      <div class="card">
        <h3>Dokumente</h3>
        <p>Ordner in den Dateien, auf den der "Dokumente"-Reiter verlinkt.</p>
        <div class="documents-path-form">
          <NcTextField
            class="path-field"
            :model-value="documentsPath"
            @update:model-value="documentsPath = $event"
            label="Ordnerpfad"
            placeholder="/Verein"
          />
          <NcButton variant="primary" :disabled="saving" @click="saveDocumentsPath">Speichern</NcButton>
        </div>
        <p v-if="saved" class="hint">Gespeichert.</p>
      </div>

      <div class="card">
        <h3>Geburtstags- &amp; Jubiläums-Erinnerungen</h3>
        <p>
          Trägt Geburtstage und Eintritts-Jubiläen automatisch als
          jährlich wiederkehrende Termine in den Kalender des angegebenen
          Nextcloud-Benutzers ein (Kalender "Vereinstermine"). Wird ein
          Mitglied als ausgetreten oder verstorben markiert, werden dessen
          Erinnerungen automatisch entfernt. Leer lassen, um die Funktion
          zu deaktivieren.
        </p>
        <div class="documents-path-form">
          <NcTextField
            class="path-field"
            :model-value="calendarOwnerUser"
            @update:model-value="calendarOwnerUser = $event"
            label="Nextcloud-Benutzername"
            placeholder="z.B. admin"
          />
          <NcButton variant="primary" :disabled="savingCalendar" @click="saveCalendarOwner">Speichern</NcButton>
        </div>
        <p v-if="savedCalendar" class="hint">Gespeichert.</p>
      </div>
    </div>
  </div>
</template>

<script>
import { api } from '../api'
import { showError } from '@nextcloud/dialogs'
import { extractErrorMessage } from '../errorMessage'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
  name: 'Settings',
  components: { NcButton, NcTextField },
  emits: ['navigate'],
  data() {
    return {
      documentsPath: '',
      saving: false,
      saved: false,
      calendarOwnerUser: '',
      savingCalendar: false,
      savedCalendar: false
    }
  },
  async mounted() {
    try {
      const res = await api.getAppSettings()
      this.documentsPath = res.data?.data?.documents_path || '/Verein'
      this.calendarOwnerUser = res.data?.data?.calendar_owner_user || ''
    } catch (e) {
      console.error('Error loading app settings', e)
      showError(extractErrorMessage(e, 'Fehler beim Laden der Einstellungen'))
    }
  },
  methods: {
    async saveDocumentsPath() {
      this.saving = true
      this.saved = false
      try {
        await api.setDocumentsPath(this.documentsPath)
        this.saved = true
      } catch (e) {
        console.error('Error saving documents path', e)
        showError(extractErrorMessage(e, 'Fehler beim Speichern des Ordnerpfads'))
      } finally {
        this.saving = false
      }
    },
    async saveCalendarOwner() {
      this.savingCalendar = true
      this.savedCalendar = false
      try {
        await api.setCalendarOwner(this.calendarOwnerUser)
        this.savedCalendar = true
      } catch (e) {
        console.error('Error saving calendar owner', e)
        showError(extractErrorMessage(e, 'Fehler beim Speichern der Kalender-Einstellung'))
      } finally {
        this.savingCalendar = false
      }
    }
  }
}
</script>

<style scoped>
.settings-page { padding: 20px }
.settings-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:16px }
.card { background: var(--color-main-background); border:1px solid var(--color-border); padding:16px; border-radius:8px }
.documents-path-form { display:flex; gap:8px; margin-top:12px; align-items: flex-end }
.documents-path-form .path-field { flex: 1 }
.hint { color: var(--color-success); font-size: 12px; margin-top: 8px }
</style>
