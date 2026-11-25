import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [vue()],
    server: {
      host: '127.0.0.1',
      // In development the site calls /api on its own origin and Vite forwards it to
      // the local API (php artisan serve), so no CORS setup is needed locally.
      proxy: {
        '/api': { target: env.VITE_API_PROXY || 'http://localhost:8000', changeOrigin: true },
      },
    },
  }
})
