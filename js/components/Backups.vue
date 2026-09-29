<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="card backups">
    <h3>{{ t('verein', 'Backups (administrator)') }}</h3>
    <p class="hint">
      {{ t('verein', 'All club data (all clubs, members, fees, roles) is backed up automatically every day. Backups older than {days} days are deleted automatically. They are stored in Nextcloud\'s app data folder, not in Files.', { days: retentionDays }) }}
    </p>
    <p>
      <NcButton variant="primary" :disabled="busy" @click="create">{{ t('verein', 'Back up now') }}</NcButton>
    </p>
    <p v-if="loaded && !backups.length" class="hint">{{ t('verein', 'No backup yet.') }}</p>
    <div v-if="backups.length" class="scroll">
    <table class="list">
      <thead>
        <tr><th>{{ t('verein', 'Time') }}</th><th>{{ t('verein', 'Size') }}</th><th /></tr>
      </thead>
      <tbody>
        <tr v-for="b in backups" :key="b.name">
          <td>{{ formatTime(b.created) }}</td>
          <td>{{ formatSize(b.size) }}</td>
          <td><a :href="downloadUrl(b.name)">{{ t('verein', 'Download') }}</a></td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { formatDateTime, formatNumber } from '../format'
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
        showError(extractErrorMessage(error, t('verein', 'Backups could not be loaded')))
      } finally {
        loaded.value = true
      }
    }

    const create = async () => {
      busy.value = true
      try {
        await api.post('backups', {})
        showSuccess(t('verein', 'Backup created'))
        await load()
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Backup failed')))
      } finally {
        busy.value = false
      }
    }

    const formatTime = (ts) => formatDateTime(ts)
    const formatSize = (bytes) => bytes >= 1048576
      ? formatNumber(bytes / 1048576, 1) + ' MB'
      : formatNumber(Math.max(1, Math.round(bytes / 1024))) + ' KB'
    const downloadUrl = (name) => absoluteUrl('/apps/verein/backups/' + encodeURIComponent(name))

    onMounted(load)
    return { t, backups, retentionDays, loaded, busy, create, formatTime, formatSize, downloadUrl }
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
