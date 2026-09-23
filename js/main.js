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

