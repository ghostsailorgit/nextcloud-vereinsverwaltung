<template>
  <NcContent app-name="verein">
    <NcAppNavigation id="app-navigation-vue">
      <NcAppNavigationList>
        <NcAppNavigationItem
          v-for="tab in tabs"
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
        <component
          :is="currentComponent"
          :key="activeTab"
          @navigate="(tab) => { activeTab = tab }"
        />
      </div>
    </NcAppContent>
  </NcContent>
</template>

<script>
import { ref, reactive, computed, defineAsyncComponent, onMounted } from 'vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import { absoluteUrl } from '../absoluteUrl'
import { api } from '../api'
import Members from './Members.vue'
import Finance from './Finance.vue'
// Lazy-load Statistics (includes Chart.js ~500KB) for better initial load
const Statistics = defineAsyncComponent(() => import('./Statistics.vue'))
import Roles from './Roles.vue'
import SepaExport from './SepaExport.vue'
import Settings from './Settings.vue'

export default {
  name: 'App',
  components: {
    NcContent,
    NcAppNavigation,
    NcAppNavigationList,
    NcAppNavigationItem,
    NcAppContent,
    Members,
    Finance,
    Statistics,
    Roles,
    SepaExport,
    Settings
  },
  setup() {
    const activeTab = ref('dashboard')

    // 'Dokumente'/'Termine' deliberately deep-link into the official Files/Calendar
    // apps instead of a custom in-app view (Files + Group folders + OCR handle
    // document management; Calendar app handles events) - see project decision.
    const tabs = reactive([
      { id: 'dashboard', label: 'Dashboard', emoji: '📊' },
      { id: 'members', label: 'Mitglieder', emoji: '👥' },
      { id: 'finance', label: 'Finanzen', emoji: '💰' },
      { id: 'roles', label: 'Rollen', emoji: '🛡️' },
      { id: 'sepa', label: 'SEPA-Export', emoji: '🏦' },
      { id: 'documents', label: 'Dokumente', emoji: '📄', href: absoluteUrl('/apps/files/files?dir=' + encodeURIComponent('/Verein')) },
      { id: 'calendar', label: 'Termine', emoji: '📅', href: absoluteUrl('/apps/calendar/') },
      { id: 'settings', label: 'Einstellungen', emoji: '⚙️' }
    ])

    onMounted(async () => {
      try {
        const res = await api.getAppSettings()
        const path = res.data?.data?.documents_path
        if (path) {
          const documentsTab = tabs.find(t => t.id === 'documents')
          if (documentsTab) documentsTab.href = absoluteUrl('/apps/files/files?dir=' + encodeURIComponent(path))
        }
      } catch (e) {
        // keep the default documents href on error
      }
    })

    const componentMap = {
      dashboard: 'Statistics',
      members: 'Members',
      finance: 'Finance',
      roles: 'Roles',
      sepa: 'SepaExport',
      settings: 'Settings'
    }

    const currentComponent = computed(() => {
      return componentMap[activeTab.value]
    })

    const onTabClick = (tab) => {
      if (tab.href) {
        window.location.href = tab.href
        return
      }
      activeTab.value = tab.id
    }

    return {
      activeTab,
      tabs,
      currentComponent,
      onTabClick
    }
  }
}
</script>

<style scoped lang="scss">
.verein-nav-icon {
  font-size: 18px;
  line-height: 1;
}

.verein-container {
  padding: 2rem;
  width: 100%;
}
</style>
