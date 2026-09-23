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
    </div>
  </div>
</template>

<script>
import { api } from '../api'
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
      saved: false
    }
  },
  async mounted() {
    try {
      const res = await api.getAppSettings()
      this.documentsPath = res.data?.data?.documents_path || '/Verein'
    } catch (e) {
      console.error('Error loading app settings', e)
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
      } finally {
        this.saving = false
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
