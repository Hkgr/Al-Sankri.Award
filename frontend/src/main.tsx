import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './assets/brand/fonts/fonts.css'
import './styles/brand-tokens.css'
import './styles/base.css'
import './components/layout/layout.css'
import App from './App.tsx'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
