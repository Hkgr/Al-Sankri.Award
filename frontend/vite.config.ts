import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // The Laravel backend in development (php artisan serve).
  const backend = loadEnv(mode, process.cwd(), '').VITE_BACKEND_URL || 'http://127.0.0.1:8000'

  // Only backend paths are proxied, so the browser talks to one origin and
  // the session and XSRF cookies work as in production. The Host header is
  // kept (changeOrigin: false) so Laravel builds links for this dev origin.
  const toBackend = { target: backend, changeOrigin: false }

  return {
    plugins: [react()],
    server: {
      proxy: {
        '/api/': toBackend,
        '/sanctum/': toBackend,
        '/auth/google/': toBackend,
        '/email/verify/': toBackend,
      },
    },
  }
})
