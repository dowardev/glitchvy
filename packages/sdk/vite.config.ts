// SPDX-FileCopyrightText: 2026 DowarDev
// SPDX-License-Identifier: AGPL-3.0-only

import { defineConfig } from 'vite';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import dts from 'vite-plugin-dts';

const __dirname = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  build: {
    sourcemap: false,
    target: 'es2020',
    lib: {
      entry: resolve(__dirname, 'src/index.ts'),
      name: 'glitchvy',
      fileName: (format) => (format === 'es' ? 'index.js' : 'index.cjs'),
      formats: ['es', 'cjs'],
    },
    rollupOptions: {
      output: {
        exports: 'named',
        chunkFileNames: '[name].js',
      },
    },
    emptyOutDir: true,
  },
  plugins: [
    dts({
      insertTypesEntry: true,
      outDir: resolve(__dirname, 'dist'),
      include: ['src/**/*.ts'],
    }),
  ],
});
