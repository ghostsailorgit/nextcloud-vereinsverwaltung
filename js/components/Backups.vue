<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="card backups">
    <h3>Sicherungen (Administrator)</h3>
    <p class="hint">
      Alle Vereinsdaten (alle Vereine, Mitglieder, Beiträge, Rollen) werden täglich automatisch gesichert.
      Sicherungen älter als {{ retentionDays }} Tage werden automatisch gelöscht.
      Sie liegen im App-Datenordner von Nextcloud, nicht in den Dateien.
    </p>
    <p>
      <NcButton variant="primary" :disabled="busy" @click="create">Jetzt sichern</NcButton>
    </p>
    <p v-if="loaded && !backups.length" class="hint">Noch keine Sicherung vorhanden.</p>
    <div v-if="backups.length" class="scroll">
    <table class="list">
      <thead>
        <tr><th>Zeitpunkt</th><th>Größe</th><th /></tr>
      </thead>
      <tbody>
        <tr v-for="b in backups" :key="b.name">
          <td>{{ formatTime(b.created) }}</td>
          <td>{{ formatSize(b.size) }}</td>
          <td><a :href="downloadUrl(b.name)">Herunterladen</a></td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { api } from '../api'
import { absoluteUrl } from '../absoluteUrl'
import { extractErrorMessage } from '../errorMessage'

export default {
  name: 'Backups',
  components: { NcButton },
  setup() {
    const backups = ref([])
    const retentionDays = ref(30)
    const loaded = ref(false)
    const busy = ref(false)

    const load = async () => {
      try {
        const response = await api.get('backups')
        backups.value = response.data.backups || []
        retentionDays.value = response.data.retentionDays || 30
      } catch (error) {
        showError(extractErrorMessage(error, 'Sicherungen konnten nicht geladen werden'))
      } finally {
        loaded.value = true
      }
    }

    const create = async () => {
      busy.value = true
      try {
        await api.post('backups', {})
        showSuccess('Sicherung erstellt')
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, 'Sicherung fehlgeschlagen'))
      } finally {
        busy.value = false
      }
    }

    const formatTime = (ts) => new Date(ts * 1000).toLocaleString('de-DE')
    const formatSize = (bytes) => bytes >= 1048576
      ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'
      : Math.max(1, Math.round(bytes / 1024)) + ' KB'
    const downloadUrl = (name) => absoluteUrl('/apps/verein/backups/' + encodeURIComponent(name))

    onMounted(load)
    return { backups, retentionDays, loaded, busy, create, formatTime, formatSize, downloadUrl }
  }
}
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
.scroll { max-height: calc(36px * 6); overflow-y: auto; } /* header + 5 rows, then scroll */
.list { border-collapse: collapse; width: 100%; }
/* the app-wide rule "#app-content-vue table td { padding: 12px !important }" needs the same weight here */
#app-content-vue .backups .list th, #app-content-vue .backups .list td { text-align: left; padding: 0 16px 0 0 !important; height: 36px !important; line-height: 22px; box-sizing: border-box; }
.list thead th { position: sticky; top: 0; background: var(--color-main-background); }
</style>
