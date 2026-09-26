<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <div class="export-buttons" :class="{ inline: inline }">
    <NcButton
      :disabled="busyCsv"
      @click="handleCsv"
      variant="secondary"
      :aria-label="t('verein', 'Export {what} as CSV', { what: labelBase })"
      :title="t('verein', 'Download {what} as CSV', { what: labelBase })"
    >
      {{ busyCsv ? t('verein', 'Exporting…') : '📊 ' + t('verein', 'CSV export') }}
    </NcButton>
    <NcButton
      :disabled="busyPdf"
      @click="handlePdf"
      variant="secondary"
      :aria-label="t('verein', 'Export {what} as PDF', { what: labelBase })"
      :title="t('verein', 'Download {what} as PDF', { what: labelBase })"
    >
      {{ busyPdf ? t('verein', 'Exporting…') : '📄 ' + t('verein', 'PDF export') }}
    </NcButton>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t } from '@nextcloud/l10n'
import { clubState } from '../store/club'

export default {
  name: 'ExportButtons',
  components: { NcButton },
  props: {
    resource: { type: String, required: true }, // 'members' | 'fees'
    inline: { type: Boolean, default: false }
  },
  data() {
    return {
      busyCsv: false,
      busyPdf: false
    }
  },
  computed: {
    labelBase() {
      return this.resource === 'members' ? t('verein', 'Members') : t('verein', 'Fees')
    }
  },
  methods: {
    t,
    toastSuccess(msg) { showSuccess(msg); this.$emit('success', msg) },
    toastError(msg) { showError(msg); this.$emit('error', msg) },
    // Both requests use responseType:'blob', so an error body (JSON) also
    // arrives as a Blob instead of being auto-parsed by axios - unwrap it
    // to surface the backend's real message instead of a generic one.
    async extractBlobErrorMessage(e, fallback) {
      const raw = e.response?.data instanceof Blob
        ? await e.response.data.text()
        : e.message
      if (!raw) return fallback
      try {
        const parsed = JSON.parse(raw)
        return parsed.message || parsed.error || fallback
      } catch (parseError) {
        return raw
      }
    },
    async handleCsv() {
      if (this.busyCsv) return
      this.busyCsv = true
      try {
        const endpoint = generateUrl(`/apps/verein/export/${this.resource}/csv`)
        const response = await axios.get(endpoint, { responseType: 'blob', params: { clubId: clubState.currentId } })
        const ct = (response.headers && response.headers['content-type']) || response.data?.type || ''
        if (!ct.includes('text/csv') && !ct.includes('application/csv')) {
          const text = await new Response(response.data).text()
          throw new Error(text || t('verein', 'CSV export failed'))
        }
        this.downloadFile(response.data, `${this.resource}.csv`, 'text/csv')
        this.toastSuccess(t('verein', '{what} exported as CSV', { what: this.labelBase }))
      } catch (e) {
        console.error('CSV export failed', e)
        this.toastError(await this.extractBlobErrorMessage(e, t('verein', 'Error during the CSV export')))
      } finally {
        this.busyCsv = false
      }
    },
    async handlePdf() {
      if (this.busyPdf) return
      this.busyPdf = true
      try {
        const endpoint = generateUrl(`/apps/verein/export/${this.resource}/pdf`)
        const response = await axios.get(endpoint, { responseType: 'blob', params: { clubId: clubState.currentId } })
        const ct = (response.headers && response.headers['content-type']) || response.data?.type || ''
        if (!ct.includes('application/pdf')) {
          const text = await new Response(response.data).text()
          throw new Error(text || t('verein', 'PDF export failed'))
        }
        this.downloadFile(response.data, `${this.resource}.pdf`, 'application/pdf')
        this.toastSuccess(t('verein', '{what} exported as PDF', { what: this.labelBase }))
      } catch (e) {
        console.error('PDF export failed', e)
        this.toastError(await this.extractBlobErrorMessage(e, t('verein', 'Error during the PDF export')))
      } finally {
        this.busyPdf = false
      }
    },
    downloadFile(blob, filename, mimeType) {
      const url = window.URL.createObjectURL(new Blob([blob], { type: mimeType }))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', filename)
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    }
  }
}
</script>

<style scoped>
.export-buttons {
  display: flex;
  gap: 8px;
}
.export-buttons.inline {
  display: inline-flex;
}
</style>