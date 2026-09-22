<template>
  <div id="verein-app" class="verein-app">
    <!-- Tabs Navigation -->
    <nav id="verein-navigation" class="verein-tabs" role="navigation" aria-label="Hauptnavigation" tabindex="-1">
      <div class="verein-tabs-container" role="tablist" aria-label="Hauptnavigation">
        <component
          :is="tab.href ? 'a' : 'button'"
          v-for="tab in tabs"
          :key="tab.id"
          :href="tab.href || null"
          :class="['verein-tab', { active: !tab.href && activeTab === tab.id }]"
          :id="'verein-tab-' + tab.id"
          :aria-current="!tab.href && activeTab === tab.id ? 'page' : false"
          @click="!tab.href && (activeTab = tab.id)"
          role="tab"
          aria-controls="app-content"
          :aria-selected="!tab.href && activeTab === tab.id"
          :tabindex="!tab.href && activeTab === tab.id ? 0 : -1"
          @keydown="onKeyDown($event, tab)"
        >
          <span :class="['verein-tab-icon', 'icon-' + tab.icon]"></span>
          <span class="verein-tab-label">{{ tab.label }}</span>
        </component>
      </div>
    </nav>

    <!-- Tab Content -->
    <main class="verein-content-wrapper">
      <div id="verein-tab-panel" role="region" :aria-labelledby="'verein-tab-' + activeTab">
      <div class="verein-container">
        <component
          :is="currentComponent"
          :key="activeTab"
          @navigate="(tab) => { activeTab = tab }"
        />
      </div>
      </div>
    </main>

    <!-- Notifications -->
    <div v-if="notification" :class="['verein-notification', notification.type]" role="alert">
      {{ notification.message }}
    </div>
  </div>
</template>

<script>
import { ref, reactive, computed, defineAsyncComponent, onMounted } from 'vue'
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
    Members,
    Finance,
    Statistics,
    Roles,
    SepaExport,
    Settings
  },
  setup() {
    const activeTab = ref('dashboard')
    const notification = ref(null)

    // 'Dokumente'/'Termine' deliberately deep-link into the official Files/Calendar
    // apps instead of a custom in-app view (Files + Group folders + OCR handle
    // document management; Calendar app handles events) - see project decision.
    const tabs = reactive([
      { id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
      { id: 'members', label: 'Mitglieder', icon: 'users' },
      { id: 'finance', label: 'Finanzen', icon: 'finance' },
      { id: 'roles', label: 'Rollen', icon: 'roles' },
      { id: 'sepa', label: 'SEPA-Export', icon: 'sepa' },
      { id: 'documents', label: 'Dokumente', icon: 'documents', href: absoluteUrl('/apps/files/files?dir=' + encodeURIComponent('/Verein')) },
      { id: 'calendar', label: 'Termine', icon: 'calendar', href: absoluteUrl('/apps/calendar/') },
      { id: 'settings', label: 'Einstellungen', icon: 'settings' }
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

    const showNotification = (message, type = 'success') => {
      notification.value = { message, type }
      setTimeout(() => {
        notification.value = null
      }, 3000)
    }

    const currentComponent = computed(() => {
      return componentMap[activeTab.value]
    })

    const onKeyDown = (event, tab) => {
      const index = tabs.findIndex(t => t.id === tab.id)
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        const next = (index + 1) % tabs.length
        if (!tabs[next].href) activeTab.value = tabs[next].id
        setTimeout(() => {
          const nodes = document.querySelectorAll('.verein-tab')
          if (nodes[next]) nodes[next].focus()
        }, 0)
        event.preventDefault()
      } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        const prev = (index - 1 + tabs.length) % tabs.length
        if (!tabs[prev].href) activeTab.value = tabs[prev].id
        setTimeout(() => {
          const nodes = document.querySelectorAll('.verein-tab')
          if (nodes[prev]) nodes[prev].focus()
        }, 0)
        event.preventDefault()
      } else if ((event.key === 'Enter' || event.key === ' ') && !tab.href) {
        activeTab.value = tab.id
        event.preventDefault()
      }
    }

    return {
      activeTab,
      tabs,
      notification,
      showNotification,
      currentComponent
      ,onKeyDown
    }
  }
}
</script>

<style scoped lang="scss">
// Responsive Breakpoints
$breakpoint-tablet: 768px;
$breakpoint-desktop: 1024px;
$max-container-width: 1200px; // retained for fallback but not enforced for full-width layout

::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: var(--color-background);
}

::-webkit-scrollbar-thumb {
  background: var(--color-border);
  border-radius: 4px;

  &:hover {
    background: var(--color-text-secondary);
  }
}

.verein-app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: transparent;
  color: var(--color-text);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif;
}

.verein-tabs {
  background: rgba(255, 255, 255, 0.95);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--color-border);
  // !important: same Nextcloud-core-CSS specificity issue as elsewhere in
  // this file - without it "position" gets reset (observed as both "static"
  // and "fixed" depending on cache state), breaking the sticky tab bar
  position: sticky !important;
  top: var(--header-height, 50px);
  z-index: 2100 !important; /* place tabs above header area so they remain clickable */
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.verein-tabs-container {
  // !important: Nextcloud's own core CSS (now loaded, see PageController)
  // has higher-specificity rules (e.g. "#content a") that otherwise win
  // over this scoped class for the <a>-based Dokumente/Termine tabs
  display: flex !important;
  gap: 0;
  /* allow the tab bar to use the full available width inside Nextcloud's content area
     but keep a small horizontal padding so it doesn't touch browser edges */
  max-width: none;
  margin: 0 20px;
  width: calc(100% - 40px);
  padding: 0;
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;

  @media (max-width: $breakpoint-tablet) {
    margin: 0;
    width: 100%;
    overflow-x: auto;
    scroll-behavior: smooth;
  }
}

.verein-tab {
  // !important on the box-model/layout properties: same Nextcloud-core-CSS
  // specificity issue as .verein-tabs-container above, but here it's
  // specifically core's own "a" element styling beating our class for the
  // Dokumente/Termine tabs (which render as <a>, not <button>)
  flex: 0 0 auto;
  padding: 14px 16px !important;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--color-text-secondary);
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  display: flex !important;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  transition: all 0.2s ease;
  -webkit-user-select: none;
  user-select: none;
  text-decoration: none !important;

  @media (max-width: $breakpoint-tablet) {
    padding: 12px 12px;
    font-size: 13px;
    gap: 6px;
  }

  @media (max-width: 480px) {
    padding: 10px 8px;
    font-size: 12px;
    gap: 4px;

    .verein-tab-label {
      display: none;
    }
  }

  .verein-tab-icon {
    font-size: 16px;
    display: inline-flex;
    align-items: center;

    @media (max-width: $breakpoint-tablet) {
      font-size: 15px;
    }
  }

  &:hover {
    color: var(--color-text);
    background: var(--color-background-hover);
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: -2px;
  }

  &.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
    background: var(--color-background-hover);
  }
}

.verein-content-wrapper {
  flex: 1;
  display: flex;
  width: 100%;
  background: transparent;
  padding: 2rem 0;
}

.verein-container {
  /* expand to use the available content width inside Nextcloud */
  width: 100%;
  max-width: 100%;
  margin: 0 auto;
  padding: 0 1.5rem;
  display: flex;
  flex-direction: column;

  @media (min-width: 1400px) {
    padding: 0 3rem;
  }

  @media (max-width: $breakpoint-tablet) {
    padding: 0 1rem;
  }

  @media (max-width: 480px) {
    padding: 0 0.75rem;
  }
}

.verein-notification {
  position: fixed;
  bottom: 20px;
  right: 20px;
  padding: 12px 16px;
  border-radius: 4px;
  font-size: 14px;
  z-index: 1000;
  animation: slideIn 0.3s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  max-width: calc(100% - 40px);

  @media (max-width: 480px) {
    bottom: 16px;
    right: 16px;
    left: 16px;
    max-width: none;
  }

  &.success {
    background: var(--color-success, #388e3c);
    color: white;
  }

  &.error {
    background: var(--color-error, #d32f2f);
    color: white;
  }

  &.warning {
    background: var(--color-warning, #f57f17);
    color: white;
  }

  &.info {
    background: var(--color-info, #1976d2);
    color: white;
  }
}

@keyframes slideIn {
  from {
    transform: translateX(20px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

// Dark Mode Support - Nextcloud CSS Variables
@media (prefers-color-scheme: dark) {
  .verein-app {
    background: var(--color-main-background, #222);
    color: var(--color-text, #fff);
  }

  .verein-tabs {
    background: var(--color-main-background, #222);
  }

  .verein-tab {
    &:hover {
      background: var(--color-background-hover, rgba(255, 255, 255, 0.05));
    }

    &.active {
      background: var(--color-background-hover, rgba(255, 255, 255, 0.05));
    }
  }
}
</style>

