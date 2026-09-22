<template>
  <div class="export-buttons" :class="{ inline: inline }">
    <NcButton
      :disabled="busyCsv"
      @click="handleCsv"
      variant="secondary"
      :aria-label="`${labelBase} als CSV exportieren`"
      :title="`${labelBase} als CSV herunterladen`"
    >
      {{ busyCsv ? 'Export läuft…' : '📊 CSV Export' }}
    </NcButton>
    <NcButton
      :disabled="busyPdf"
      @click="handlePdf"
      variant="secondary"
      :aria-label="`${labelBase} als PDF exportieren`"
      :title="`${labelBase} als PDF herunterladen`"
    >
      {{ busyPdf ? 'Export läuft…' : '📄 PDF Export' }}
    </NcButton>
  </div>
</template>

<script>
import axios from '@nextcloud/axios'
import { absoluteUrl as generateUrl } from '../absoluteUrl'
import * as notify from '../notify'
import NcButton from '@nextcloud/vue/components/NcButton'

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
      return this.resource === 'members' ? 'Mitglieder' : 'Beiträge'
    }
  },
  methods: {
    toastSuccess(msg) { notify.success(msg); this.$emit('success', msg) },
    toastError(msg) { notify.error(msg); this.$emit('error', msg) },
    async handleCsv() {
      if (this.busyCsv) return
      this.busyCsv = true
      try {
        const endpoint = generateUrl(`/apps/verein/export/${this.resource}/csv`)
        const response = await axios.get(endpoint, { responseType: 'blob' })
        const ct = (response.headers && response.headers['content-type']) || response.data?.type || ''
        if (!ct.includes('text/csv') && !ct.includes('application/csv')) {
          const text = await new Response(response.data).text()
          throw new Error(text || 'CSV-Export fehlgeschlagen')
        }
        this.downloadFile(response.data, `${this.resource}.csv`, 'text/csv')
        this.toastSuccess(`${this.labelBase} als CSV exportiert`)
      } catch (e) {
        console.error('CSV export failed', e)
        this.toastError('Fehler beim CSV-Export')
      } finally {
        this.busyCsv = false
      }
    },
    async handlePdf() {
      if (this.busyPdf) return
      this.busyPdf = true
      try {
        const endpoint = generateUrl(`/apps/verein/export/${this.resource}/pdf`)
        const response = await axios.get(endpoint, { responseType: 'blob' })
        const ct = (response.headers && response.headers['content-type']) || response.data?.type || ''
        if (!ct.includes('application/pdf')) {
          const text = await new Response(response.data).text()
          throw new Error(text || 'PDF-Export fehlgeschlagen')
        }
        this.downloadFile(response.data, `${this.resource}.pdf`, 'application/pdf')
        this.toastSuccess(`${this.labelBase} als PDF exportiert`)
      } catch (e) {
        console.error('PDF export failed', e)
        this.toastError('Fehler beim PDF-Export')
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