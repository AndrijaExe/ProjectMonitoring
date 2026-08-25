import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// The API client, the store and the model live above this app, because the phone app needs the
// same ones. See shared/README.md.
const sharedDir = fileURLToPath(new URL('../shared', import.meta.url))

export default defineConfig(({ mode }) => {
  const fileEnv = loadEnv(mode, process.cwd(), '')
  const explicit = process.env.VITE_API_BASE_URL ?? fileEnv.VITE_API_BASE_URL ?? ''
  // Render hands the API's public hostname to the build as API_HOST (see render.yaml),
  // so nobody has to paste a URL after the first deploy.
  const apiHost = process.env.API_HOST ?? fileEnv.API_HOST ?? ''
  const apiBaseUrl = (explicit || (apiHost === '' ? '' : `https://${apiHost}`)).replace(/\/$/, '')

  return {
    plugins: [react()],
    resolve: {
      alias: { '@shared': sharedDir },
    },
    define: {
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(apiBaseUrl),
    },
    server: {
      // Vite refuses to serve files above the project root unless they are listed here.
      fs: { allow: ['.', sharedDir] },
      port: 5173,
      proxy: {
        '/api': 'http://127.0.0.1:8081',
        '/healthz': 'http://127.0.0.1:8081',
        '/readyz': 'http://127.0.0.1:8081',
      },
    },
  }
})
