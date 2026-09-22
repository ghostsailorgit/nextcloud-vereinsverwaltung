<template>
  <nav class="verein-navigation">
    <ul class="nav-menu">
      <li>
        <router-link to="/members" :class="{ active: isActive('members') }">
          <span class="icon">👥</span>
          <span>Mitglieder</span>
        </router-link>
      </li>
      <li>
        <router-link to="/fees" :class="{ active: isActive('fees') }">
          <span class="icon">💰</span>
          <span>Beiträge</span>
        </router-link>
      </li>
      <li>
        <router-link to="/sepa" :class="{ active: isActive('sepa') }">
          <span class="icon">📄</span>
          <span>SEPA-Export</span>
        </router-link>
      </li>
      <li>
        <a :href="documentsUrl">
          <span class="icon">📁</span>
          <span>Dokumente</span>
        </a>
      </li>
      <li>
        <a :href="calendarUrl">
          <span class="icon">📅</span>
          <span>Termine</span>
        </a>
      </li>
      <li v-if="showRolesLink">
        <router-link to="/roles" :class="{ active: isActive('roles') }">
          <span class="icon">🛡️</span>
          <span>Rollen</span>
        </router-link>
      </li>
      <li v-if="showSettingsLink">
        <router-link to="/settings" :class="{ active: isActive('settings') }">
          <span class="icon">⚙️</span>
          <span>Einstellungen</span>
        </router-link>
      </li>
    </ul>
  </nav>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
  name: 'Navigation',
  data() {
    return {
      showSettingsLink: false,
      showRolesLink: false,
      documentsUrl: generateUrl('/apps/files/files?dir=' + encodeURIComponent('/Verein')),
      calendarUrl: generateUrl('/apps/calendar/')
    }
  },
  methods: {
    isActive(routeName) {
      return this.$route.path.includes(routeName)
    }
  },
  async mounted() {
    try {
      // try to load permissions; this endpoint is protected by RequirePermission('verein.role.manage')
      const res = await axios.get(generateUrl('/apps/verein/api/permissions'))
      // if call succeeds, user has management permission
      this.showSettingsLink = true
      this.showRolesLink = true
      // optionally keep the permissions in-memory for other components (not used here)
      this._permissionsResponse = res.data
    } catch (e) {
      // no permission or error -> hide the settings and roles links
      this.showSettingsLink = false
      this.showRolesLink = false
    }

    try {
      // the documents folder path is admin-configurable (Einstellungen), default '/Verein'
      const settingsRes = await axios.get(generateUrl('/apps/verein/api/settings'))
      const path = settingsRes.data?.data?.documents_path
      if (path) {
        this.documentsUrl = generateUrl('/apps/files/files?dir=' + encodeURIComponent(path))
      }
    } catch (e) {
      // keep the default documentsUrl on error
    }
  }
}
</script>

<style scoped>
.verein-navigation {
  background-color: #f5f5f5;
  border-bottom: 1px solid #ddd;
  margin-bottom: 20px;
}

.nav-menu {
  display: flex;
  list-style: none;
  margin: 0;
  padding: 0;
}

.nav-menu li {
  margin: 0;
}

.nav-menu a {
  display: flex;
  align-items: center;
  padding: 15px 20px;
  text-decoration: none;
  color: #333;
  transition: background-color 0.2s;
}

.nav-menu a:hover {
  background-color: #e0e0e0;
}

.nav-menu a.active {
  background-color: #0082c9;
  color: white;
}

.icon {
  margin-right: 8px;
  font-size: 1.2em;
}
</style>
