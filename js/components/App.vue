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
          input-label="Verein"
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
        <p v-if="!loaded">Lade Vereine…</p>
        <p v-else-if="loadError" class="verein-error">{{ loadError }}</p>
        <p v-else-if="!clubs.length && !isAdmin && !hasMe">
          Du bist noch keinem Verein zugeordnet. Bitte wende dich an einen Administrator,
          damit er dir in der Vereinsverwaltung eine Rolle zuweist.
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
      { id: 'dashboard', label: 'Dashboard', emoji: '📊', show: can('verein.member.view') },
      { id: 'members', label: 'Mitglieder', emoji: '👥', show: can('verein.member.view') },
      { id: 'finance', label: 'Finanzen', emoji: '💰', show: can('verein.finance.read') },
      { id: 'roles', label: 'Rollen', emoji: '🛡️', show: can('verein.role.manage') },
      { id: 'sepa', label: 'SEPA-Export', emoji: '🏦', show: can('verein.sepa.export') },
      { id: 'clubs', label: 'Verein', emoji: '🏛️', show: can('verein.club.manage') || clubState.isAdmin },
      { id: 'audit', label: 'Protokoll', emoji: '📜', show: can('verein.audit.view') },
      { id: 'me', label: 'Meine Daten', emoji: '👤', show: !!clubState.me?.linked },
      {
        id: 'documents',
        label: 'Dokumente',
        emoji: '📄',
        show: !!currentClub.value,
        href: absoluteUrl('/apps/files/files?dir=' + encodeURIComponent(currentClub.value?.documentsPath || '/'))
      },
      { id: 'calendar', label: 'Termine', emoji: '📅', show: !!currentClub.value, href: absoluteUrl('/apps/calendar/') }
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
        loadError.value = extractErrorMessage(e, 'Die Vereine konnten nicht geladen werden')
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
}
</style>
