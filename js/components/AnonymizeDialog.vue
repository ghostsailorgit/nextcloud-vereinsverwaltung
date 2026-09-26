<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <NcDialog
    :open="!!member"
    name="Person unwiderruflich anonymisieren"
    size="normal"
    @update:open="(v) => { if (!v) close() }"
  >
    <div v-if="member" class="anonymize-dialog">
      <p class="warning">
        <strong>{{ fullName }}</strong> wird unwiderruflich anonymisiert. In der App lässt sich das
        <strong>nicht rückgängig</strong> machen; nur das Zurückspielen einer älteren Sicherung brächte die Daten
        zurück, und damit gingen alle späteren Änderungen verloren.
      </p>
      <p>Ersetzt bzw. entfernt werden:</p>
      <ul>
        <li>Name, Anrede, Anschrift, E-Mail und Geburtsdatum</li>
        <li>IBAN, BIC und die Verknüpfung mit einem Nextcloud-Konto</li>
        <li>die personenbezogenen Angaben in älteren Einträgen des Änderungsprotokolls</li>
      </ul>
      <p>
        Erhalten bleiben die Beiträge und die SEPA-Historie (Aufbewahrungspflicht der Buchhaltung) sowie Ein- und
        Austrittsdaten. Das geht nur, wenn die Person in <strong>allen</strong> Vereinen ausgetreten oder verstorben ist.
      </p>
      <NcTextField
        :model-value="typed"
        label="Zum Bestätigen den vollständigen Namen eintippen"
        :placeholder="fullName"
        autocomplete="off"
        @update:model-value="typed = $event"
      />
    </div>
    <template #actions>
      <NcButton variant="tertiary" :disabled="busy" @click="close">Abbrechen</NcButton>
      <NcButton variant="error" :disabled="!confirmed || busy" @click="anonymize">
        Unwiderruflich anonymisieren
      </NcButton>
    </template>
  </NcDialog>
</template>

<script>
import { ref, computed, watch } from 'vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { api } from '../api'
import { extractErrorMessage } from '../errorMessage'

export default {
  name: 'AnonymizeDialog',
  components: { NcDialog, NcButton, NcTextField },
  props: {
    // the member row to anonymize; null = dialog closed
    member: { type: Object, default: null }
  },
  emits: ['close', 'done'],
  setup(props, { emit }) {
    const typed = ref('')
    const busy = ref(false)
    const fullName = computed(() => [props.member?.firstName, props.member?.name].filter(Boolean).join(' '))
    // exact name, only surrounding blanks and repeated inner blanks are forgiven
    const norm = (s) => s.trim().replace(/\s+/g, ' ')
    const confirmed = computed(() => !!props.member && norm(typed.value) === norm(fullName.value))

    watch(() => props.member, () => { typed.value = '' })

    const close = () => {
      if (!busy.value) emit('close')
    }

    const anonymize = async () => {
      if (!confirmed.value) return
      busy.value = true
      try {
        await api.post(`members/${props.member.id}/anonymize`, {})
        showSuccess(`${fullName.value} wurde anonymisiert`)
        emit('done')
      } catch (error) {
        showError(extractErrorMessage(error, 'Anonymisieren fehlgeschlagen'))
      } finally {
        busy.value = false
      }
    }

    return { typed, busy, fullName, confirmed, close, anonymize }
  }
}
</script>

<style scoped>
.anonymize-dialog { padding: 0 4px 8px; }
.warning { color: var(--color-error-text, var(--color-error)); }
ul { margin: 4px 0 12px 20px; list-style: disc; }
</style>
