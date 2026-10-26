import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './assets/brand/fonts/fonts.css'
import './styles/brand-tokens.css'
import './styles/base.css'
import './components/layout/layout.css'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthContext'
import { RouterProvider } from './lib/router'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <RouterProvider>
      <AuthProvider>
        <App />
      </AuthProvider>
    </RouterProvider>
  </StrictMode>,
)
