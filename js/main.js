/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { createApp } from 'vue'
import App from './components/App.vue'

// Nextcloud Theme Integration
import './theme.scss'

// Required for showSuccess/showError toasts (used throughout the app) to
// render styled instead of as bare unstyled text
import '@nextcloud/dialogs/style.css'

// Chart.js is now lazy-loaded with Statistics component for better initial load

createApp(App)
  .mount('#app')

