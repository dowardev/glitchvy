/// <reference types="vitest" />

import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { defineConfig, loadEnv } from 'vite'

const __dirname = dirname(fileURLToPath(import.meta.url))

// Content Security Policy for production builds. The admin SPA used to
// ship without one, which made any XSS able to run inline script. The
// policy is injected at build time (not as a static <meta> in index.html)
// so the dev server keeps working: Vite's HMR needs inline scripts and
// websockets, a production bundle does not.
//
// `connect-src` assumes the SPA is served from the same origin as the
// API (the default: the Vite dev server proxies /api, and in production
// the SPA is served by the same host). A deployment that calls the API
// from another origin adds it here, e.g.
// VITE_GLITCHVY_CSP_CONNECT_SRC="'self' https://errors.dowar.xyz".
function contentSecurityPolicy(connectSrc: string) {
  const policy = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self' data:",
    `connect-src ${connectSrc}`,
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ].join('; ')

  return {
    name: 'glitchvy-csp',
    apply: 'build' as const,
    transformIndexHtml(html: string) {
      return {
        html,
        tags: [
          {
            tag: 'meta',
            attrs: {
              'http-equiv': 'Content-Security-Policy',
              content: policy,
            },
            injectTo: 'head-prepend' as const,
          },
        ],
      }
    },
  }
}

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [
      vue(),
      contentSecurityPolicy(env.VITE_GLITCHVY_CSP_CONNECT_SRC || "'self'"),
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
