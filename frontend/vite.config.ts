import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig(({ mode }) => {
  const fileEnv = loadEnv(mode, process.cwd(), '')
  const explicit = process.env.VITE_API_BASE_URL ?? fileEnv.VITE_API_BASE_URL ?? ''
  // Render hands the API's public hostname to the build as API_HOST (see render.yaml),
  // so nobody has to paste a URL after the first deploy.
  const apiHost = process.env.API_HOST ?? fileEnv.API_HOST ?? ''
  const apiBaseUrl = (explicit || (apiHost === '' ? '' : `https://${apiHost}`)).replace(/\/$/, '')

  return {
    plugins: [react()],
    define: {
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(apiBaseUrl),
    },
    server: {
      port: 5173,
      proxy: {
        '/api': 'http://127.0.0.1:8081',
        '/healthz': 'http://127.0.0.1:8081',
        '/readyz': 'http://127.0.0.1:8081',
      },
    },
  }
})
