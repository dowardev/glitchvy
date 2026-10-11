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
      // Per-format output filename. Rollup's `entryFileNames` would override
      // this, so we do NOT set `rollupOptions.output.entryFileNames` — only
      // the chunk filename (cjs collects internal deps into one chunk).
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
      // Bundle every type into a single `index.d.ts` (with imports rewritten
      // to relative paths) so consumers see one public surface.
      include: ['src/**/*.ts'],
      exclude: ['**/*.spec.ts', '**/*.test.ts', 'tests/**'],
      // Bundling with the public entry as the root so `src/index.ts` exports
      // become `dist/index.d.ts`.
      bundle: true,
      entryRoot: 'src',
    }),
  ],
});
