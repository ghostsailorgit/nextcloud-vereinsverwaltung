<template>
  <div class="settings-page">
    <h2>Einstellungen</h2>

    <div class="settings-grid">
      <div class="card">
        <h3>Rollen & Berechtigungen</h3>
        <p>Verwalte Rollen und die zugehörigen Berechtigungen.</p>
        <button class="button" @click="$emit('navigate', 'roles')">Zu Rollen</button>
      </div>

      <div class="card">
        <h3>SEPA / Exporte</h3>
        <p>Export-Optionen verwalten und SEPA-Export erstellen.</p>
        <button class="button" @click="$emit('navigate', 'sepa')">Zu SEPA</button>
      </div>

      <div class="card">
        <h3>Dokumente</h3>
        <p>Ordner in den Dateien, auf den der "Dokumente"-Reiter verlinkt.</p>
        <div class="documents-path-form">
          <input v-model="documentsPath" placeholder="/Verein" />
          <button class="button" :disabled="saving" @click="saveDocumentsPath">Speichern</button>
        </div>
        <p v-if="saved" class="hint">Gespeichert.</p>
      </div>
    </div>
  </div>
</template>

<script>
import { api } from '../api'

export default {
  name: 'Settings',
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
.button { margin-top:12px; display:inline-block }
.documents-path-form { display:flex; gap:8px; margin-top:12px }
.documents-path-form input { flex:1; padding:8px; border:1px solid var(--color-border); border-radius:4px }
.hint { color: var(--color-success); font-size: 12px; margin-top: 8px }
</style>
