<!--
  - SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
  - SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
  - SPDX-License-Identifier: AGPL-3.0-only
-->
<template>
  <NcContent app-name="verein">
    <NcAppNavigation id="app-navigation-vue">
      <div v-if="clubs.length" class="verein-club-switcher">
        <NcSelect
          v-if="clubs.length > 1"
          :model-value="currentClubId"
          :options="clubs"
          :reduce="c => c.id"
          label="name"
          :input-label="t('verein', 'Club')"
          :clearable="false"
          @update:model-value="onClubChange"
        />
        <div v-else class="verein-club-name">{{ clubs[0].name }}</div>
      </div>

      <NcAppNavigationList>
        <NcAppNavigationItem
          v-for="tab in visibleTabs"
          :key="tab.id"
          :name="tab.label"
          :active="!tab.href && activeTab === tab.id"
          @click="onTabClick(tab)"
        >
          <template #icon>
            <span class="verein-nav-icon">{{ tab.emoji }}</span>
          </template>
        </NcAppNavigationItem>
      </NcAppNavigationList>
    </NcAppNavigation>

    <NcAppContent id="app-content-vue">
      <div class="verein-container">
        <p v-if="!loaded">{{ t('verein', 'Loading clubs…') }}</p>
        <p v-else-if="loadError" class="verein-error">{{ loadError }}</p>
        <p v-else-if="!clubs.length && !isAdmin && !hasMe">
          {{ t('verein', 'You are not assigned to any club yet. Please ask an administrator to give you a role in a club.') }}
        </p>
        <component
          :is="currentComponent"
          v-else-if="currentComponent"
          :key="activeTab + '-' + currentClubId"
          @navigate="(tab) => { activeTab = tab }"
        />
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script>
import { ref, computed, defineAsyncComponent, onMounted, watch } from 'vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { absoluteUrl } from '../absoluteUrl'
import { extractErrorMessage } from '../errorMessage'
import { t } from '@nextcloud/l10n'
import { clubState, currentClub, loadClubs, loadMe, setCurrentClub, can } from '../store/club'
import Members from './Members.vue'
import Finance from './Finance.vue'
// Lazy-load Statistics (includes Chart.js ~500KB) for better initial load
const Statistics = defineAsyncComponent(() => import('./Statistics.vue'))
import Roles from './Roles.vue'
import SepaExport from './SepaExport.vue'
import Clubs from './Clubs.vue'
import Me from './Me.vue'
import AuditLog from './AuditLog.vue'

export default {
  name: 'App',
  components: {
    NcContent,
    NcAppNavigation,
    NcAppNavigationList,
    NcAppNavigationItem,
    NcAppContent,
    NcSelect,
    Members,
    Finance,
    Statistics,
    Roles,
    SepaExport,
    Clubs,
    Me,
    AuditLog
  },
  setup() {
    const activeTab = ref('dashboard')
    const loadError = ref('')

    // 'Dokumente'/'Termine' deliberately deep-link into the official Files/Calendar
    // apps instead of a custom in-app view (Files + Group folders + OCR handle
    // document management; Calendar app handles events) - see project decision.
    // Each tab is only offered when the user holds the permission in the current club.
    const allTabs = computed(() => [
      { id: 'dashboard', label: t('verein', 'Dashboard'), emoji: '📊', show: can('verein.member.view') },
      { id: 'members', label: t('verein', 'Members'), emoji: '👥', show: can('verein.member.view') },
      { id: 'finance', label: t('verein', 'Finances'), emoji: '💰', show: can('verein.finance.read') },
      { id: 'roles', label: t('verein', 'Roles'), emoji: '🛡️', show: can('verein.role.manage') },
      { id: 'sepa', label: t('verein', 'SEPA export'), emoji: '🏦', show: can('verein.sepa.export') },
      { id: 'clubs', label: t('verein', 'Club'), emoji: '🏛️', show: can('verein.club.manage') || clubState.isAdmin },
      { id: 'audit', label: t('verein', 'Audit log'), emoji: '📜', show: can('verein.audit.view') },
      { id: 'me', label: t('verein', 'My data'), emoji: '👤', show: !!clubState.me?.linked },
      {
        id: 'documents',
        label: t('verein', 'Documents'),
        emoji: '📄',
        show: !!currentClub.value,
        href: absoluteUrl('/apps/files/files?dir=' + encodeURIComponent(currentClub.value?.documentsPath || '/'))
      },
      { id: 'calendar', label: t('verein', 'Events'), emoji: '📅', show: !!currentClub.value, href: absoluteUrl('/apps/calendar/') }
    ])

    const visibleTabs = computed(() => allTabs.value.filter(t => t.show))

    const componentMap = {
      dashboard: 'Statistics',
      members: 'Members',
      finance: 'Finance',
      roles: 'Roles',
      sepa: 'SepaExport',
      clubs: 'Clubs',
      audit: 'AuditLog',
      me: 'Me'
    }

    // If the current tab isn't available (e.g. after switching to a club where
    // the user has fewer permissions), fall back to the first one that is
    const ensureVisibleTab = () => {
      const stillVisible = visibleTabs.value.some(t => !t.href && t.id === activeTab.value)
      if (!stillVisible) {
        activeTab.value = visibleTabs.value.find(t => !t.href)?.id ?? 'dashboard'
      }
    }

    const currentComponent = computed(() => {
      return visibleTabs.value.some(t => t.id === activeTab.value) ? componentMap[activeTab.value] : null
    })

    onMounted(async () => {
      try {
        await Promise.all([loadClubs(), loadMe()])
        ensureVisibleTab()
      } catch (e) {
        loadError.value = extractErrorMessage(e, t('verein', 'The clubs could not be loaded'))
        clubState.loaded = true
      }
    })

    watch(() => clubState.currentId, ensureVisibleTab)

    const onTabClick = (tab) => {
      if (tab.href) {
        window.location.href = tab.href
        return
      }
      activeTab.value = tab.id
    }

    return {
      t,
      activeTab,
      loadError,
      visibleTabs,
      currentComponent,
      onTabClick,
      onClubChange: setCurrentClub,
      clubs: computed(() => clubState.clubs),
      currentClubId: computed(() => clubState.currentId),
      loaded: computed(() => clubState.loaded),
      isAdmin: computed(() => clubState.isAdmin),
      hasMe: computed(() => !!clubState.me?.linked)
    }
  }
}
</script>

<style scoped lang="scss">
.verein-nav-icon {
  font-size: 18px;
  line-height: 1;
}

.verein-club-switcher {
  padding: 8px 12px;
}

.verein-club-name {
  font-weight: 600;
  padding: 4px 0;
}

.verein-error {
  color: var(--color-error);
}

.verein-container {
  padding: 2rem;
  width: 100%;

  @media (max-width: 600px) {
    padding: 12px 8px;
  }
}
</style>

<style lang="scss">
/* One heading scale for all tabs: Nextcloud's defaults for h2/h3 inside app content are display sizes (26-28px),
   which some tabs used and others overrode with 18px. Components can still set their own (scoped rules win). */
.verein-container {
  h2 { font-size: 22px; line-height: 1.3; margin: 0 0 16px; }
  h3 { font-size: 18px; line-height: 1.3; margin: 0 0 12px; }
  h4 { font-size: 16px; line-height: 1.3; margin: 16px 0 8px; }

  /* Nextcloud's core CSS sets white-space: nowrap on every table, so no cell wrapped and wide tables pushed
     the whole page sideways. Cells wrap again; what must stay on one line says so itself. */
  table { white-space: normal; }

  /* every table sits in one of these, so a table that is still too wide (phones) scrolls on its own */
  .table-scroll { overflow-x: auto; max-width: 100%; }

  /* long lists (members, fees): the actions column stays in view while the table scrolls sideways */
  /* rows set their color as --row-tint; the sticky cell lays the same tint over an opaque background,
     otherwise a semi-transparent row color showed twice (or the scrolled content shone through) */
  .sticky-actions {
    tbody tr { background: var(--row-tint, transparent); }
    /* one line per row; the list scrolls sideways instead (headings may wrap: "Member since" is wider than "21 years") */
    td { white-space: nowrap; }
    th:last-child,
    td:last-child {
      position: sticky;
      right: 0;
      box-shadow: -6px 0 6px -6px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
    }
    td:last-child {
      background: linear-gradient(var(--row-tint, transparent), var(--row-tint, transparent)), var(--color-main-background);
    }
    thead th:last-child { background: var(--color-background-hover); }
  }
}
</style>
