import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './assets/brand/fonts/fonts.css'
import './styles/brand-tokens.css'
import './styles/base.css'
import './components/layout/layout.css'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthContext'
import { LocaleProvider } from './i18n/LocaleContext'
import './i18n/i18n.css'
import { RouterProvider } from './lib/router'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <RouterProvider>
      <LocaleProvider>
        <AuthProvider>
          <App />
        </AuthProvider>
      </LocaleProvider>
    </RouterProvider>
  </StrictMode>,
)
