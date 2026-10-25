/// <reference types="vitest" />

import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { defineConfig, loadEnv } from 'vite'

const __dirname = dirname(fileURLToPath(import.meta.url))

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [
      vue()
    ],
    resolve: {
      alias: {
        '@': resolve(__dirname, './src'),
      },
    },
    server: {
      // Proxy the whole /api tree to the Laravel backend so the SPA can
      // call the SaaS API without CORS in dev. The dev server port itself
      // comes from `ionic s -p=N` (handled by the Ionic CLI's built-in
      // vue-vite handler), not from this config.
      proxy: {
        '/api': {
          target: env.VITE_GLITCHVY_PROXY_TARGET || 'http://localhost:8000',
          changeOrigin: true,
          secure: false,
        },
      },
    },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: ['./tests/setup.ts'],
    },
  }
})
