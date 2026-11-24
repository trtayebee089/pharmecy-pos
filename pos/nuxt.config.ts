import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  compatibilityDate: '2026-11-01',
  // The POS is an authenticated back-office app: SPA mode, deployed as static files.
  ssr: false,
  devtools: { enabled: false },
  modules: ['@pinia/nuxt', '@vueuse/nuxt'],
  css: ['~/assets/css/main.css'],
  imports: { dirs: ['services'] },
  vite: {
    plugins: [tailwindcss()],
  },
  runtimeConfig: {
    public: {
      apiBase: 'https://api.redpharmabd.com/api/pos/v1',
      // Development-only mock layer (app/mocks). Overridden by NUXT_PUBLIC_USE_MOCKS.
      useMocks: false,
      // While mocks are on: API domains already served live (see useApi). Overridden by NUXT_PUBLIC_LIVE_DOMAINS.
      liveDomains: '',
      appName: 'Red Pharma POS',
    },
  },
  app: {
    head: {
      title: 'Red Pharma POS',
      htmlAttrs: { lang: 'en' },
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#0b655e' },
      ],
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap',
        },
      ],
    },
  },
  typescript: { strict: true },
})
