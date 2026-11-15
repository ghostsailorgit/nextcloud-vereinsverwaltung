<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <div class="card backups">
    <h3>{{ isAdmin ? t('verein', 'Backups (administrator)') : t('verein', 'Backups') }}</h3>
    <p class="hint">
      {{ t('verein', 'All club data (all clubs, members, fees, roles) is backed up automatically every day. Backups older than {days} days are deleted automatically. They are stored in Nextcloud\'s app data folder, not in Files.', { days: retentionDays }) }}
    </p>
    <p class="buttons">
      <NcButton variant="primary" :disabled="busy" @click="create">{{ t('verein', 'Back up now') }}</NcButton>
      <NcButton variant="secondary" :disabled="busy" @click="$refs.file.click()">{{ t('verein', 'Restore from a file…') }}</NcButton>
      <input ref="file" type="file" accept=".gz,application/gzip" class="hidden-file" @change="restoreFile" />
    </p>
    <p class="hint">{{ t('verein', 'Restoring replaces the data of all clubs with the backup. The current state is backed up automatically first, so a restore can be undone.') }}</p>
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
          <td class="actions">
            <a :href="downloadUrl(b.name)">{{ t('verein', 'Download') }}</a>
            <NcButton variant="tertiary" :disabled="busy" @click="restoreStored(b)">{{ t('verein', 'Restore…') }}</NcButton>
          </td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { formatDateTime, formatNumber } from '../format'
import NcButton from '@nextcloud/vue/components/NcButton'
import { api } from '../api'
import { absoluteUrl } from '../absoluteUrl'
import { extractErrorMessage } from '../errorMessage'
import { confirmAction } from '../confirm'
import { clubState } from '../store/club'

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

    // the tables people know; the rest (clubs, roles, log, …) is replaced too, the dialog says "all clubs"
    const labels = {
      verein_members: t('verein', 'Members'),
      verein_fees: t('verein', 'Fees'),
      verein_groups: t('verein', 'Groups'),
    }
    // one line of text: the dialog shows its text as a single paragraph, line breaks get lost
    const describe = (summary) => summary.tables
      .filter(row => labels[row.table] && row.backup !== null)
      .map(row => t('verein', '{what}: {now} now, {backup} in the backup', { what: labels[row.table], now: row.now, backup: row.backup }))
      .join('; ')

    // asks with what would change, then restores and reloads the page (every view shows other data afterwards)
    const restoreWith = async (preview, run) => {
      busy.value = true
      try {
        const summary = (await preview()).data
        const text = t('verein', 'Replace the data of all clubs with the backup of {date} (app version {version})? Everything entered since then is lost; the current state is backed up first.', { date: summary.created ? formatDateTime(Date.parse(summary.created) / 1000) : '?', version: summary.appVersion || '?' }) + ' ' + describe(summary) + '.'
        if (!(await confirmAction(t('verein', 'Restore backup'), text, { labelConfirm: t('verein', 'Restore'), severity: 'error' }))) return
        const result = (await run()).data
        showSuccess(t('verein', 'Backup restored. The previous state was saved as {name}.', { name: result.safetyBackup }))
        setTimeout(() => window.location.reload(), 1500)
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'The backup could not be restored')))
      } finally {
        busy.value = false
      }
    }
    const restoreStored = (backup) => restoreWith(
      () => api.post(`backups/${encodeURIComponent(backup.name)}/preview`, {}),
      () => api.post(`backups/${encodeURIComponent(backup.name)}/restore`, {}),
    )
    const restoreFile = (event) => {
      const file = event.target.files?.[0]
      event.target.value = '' // the same file can be chosen again
      if (!file) return
      const form = () => {
        const data = new FormData()
        data.append('backup', file)
        return data
      }
      restoreWith(() => api.post('backup-upload/preview', form()), () => api.post('backup-upload/restore', form()))
    }

    const formatTime = (ts) => formatDateTime(ts)
    const formatSize = (bytes) => bytes >= 1048576
      ? formatNumber(bytes / 1048576, 1) + ' MB'
      : formatNumber(Math.max(1, Math.round(bytes / 1024))) + ' KB'
    const downloadUrl = (name) => absoluteUrl('/apps/verein/backups/' + encodeURIComponent(name))

    onMounted(load)
    return { t, isAdmin: computed(() => clubState.isAdmin), backups, retentionDays, loaded, busy, create, restoreStored, restoreFile, formatTime, formatSize, downloadUrl }
  }
}
</script>

<style scoped>
.hint { color: var(--color-text-maxcontrast); }
.buttons { display: flex; flex-wrap: wrap; gap: 8px; }
.hidden-file { display: none; }
.actions { display: flex; align-items: center; gap: 8px; }
.scroll { max-height: calc(36px * 6); overflow-y: auto; } /* header + 5 rows, then scroll */
.list { border-collapse: collapse; width: 100%; }
/* the app-wide rule "#app-content-vue table td { padding: 12px !important }" needs the same weight here */
#app-content-vue .backups .list th, #app-content-vue .backups .list td { text-align: left; padding: 0 16px 0 0 !important; height: 36px !important; line-height: 22px; box-sizing: border-box; }
.list thead th { position: sticky; top: 0; background: var(--color-main-background); }
</style>
