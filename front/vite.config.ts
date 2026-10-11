// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 5174, // different from the consumer app's vite default
    proxy: {
      // Proxy the API to the Laravel backend during local dev. The base URL
      // is read from `VITE_GLITCHVY_API_URL` if set, otherwise defaults here.
      '/api/glitchvy': {
        target: process.env.VITE_GLITCHVY_PROXY_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false, // per spec, no source maps
    target: 'es2020',
  },
});
