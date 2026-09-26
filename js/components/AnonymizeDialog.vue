<!--
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
  <NcDialog
    :open="!!member"
    :name="t('verein', 'Anonymize person irreversibly')"
    size="normal"
    @update:open="(v) => { if (!v) close() }"
  >
    <div v-if="member" class="anonymize-dialog">
      <p class="warning">
        <strong>{{ fullName }}</strong> {{ t('verein', 'will be anonymized irreversibly. This cannot be undone in the app; only restoring an older backup would bring the data back, and all later changes would be lost.') }}
      </p>
      <p>{{ t('verein', 'Replaced or removed:') }}</p>
      <ul>
        <li>{{ t('verein', 'Name, salutation, address, e-mail and date of birth') }}</li>
        <li>{{ t('verein', 'IBAN, BIC and the link to a Nextcloud account') }}</li>
        <li>{{ t('verein', 'the personal data in older entries of the change log') }}</li>
      </ul>
      <p>
        {{ t('verein', 'Fees and the SEPA history are kept (bookkeeping retention), as well as join and leave dates. This is only possible once the person has left all clubs or is deceased.') }}
      </p>
      <NcTextField
        :model-value="typed"
        :label="t('verein', 'Type the full name to confirm')"
        :placeholder="fullName"
        autocomplete="off"
        @update:model-value="typed = $event"
      />
    </div>
    <template #actions>
      <NcButton variant="tertiary" :disabled="busy" @click="close">{{ t('verein', 'Cancel') }}</NcButton>
      <NcButton variant="error" :disabled="!confirmed || busy" @click="anonymize">
        {{ t('verein', 'Anonymize irreversibly') }}
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
import { t } from '@nextcloud/l10n'
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
        showSuccess(t('verein', '{name} was anonymized', { name: fullName.value }))
        emit('done')
      } catch (error) {
        showError(extractErrorMessage(error, t('verein', 'Anonymization failed')))
      } finally {
        busy.value = false
      }
    }

    return { t, typed, busy, fullName, confirmed, close, anonymize }
  }
}
</script>

<style scoped>
.anonymize-dialog { padding: 0 4px 8px; }
.warning { color: var(--color-error-text, var(--color-error)); }
ul { margin: 4px 0 12px 20px; list-style: disc; }
</style>
