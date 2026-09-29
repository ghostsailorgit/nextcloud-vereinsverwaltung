/**
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'
import { visualizer } from 'rollup-plugin-visualizer'
import terser from '@rollup/plugin-terser'

export default defineConfig({
  plugins: [
    vue(),
    visualizer({
      filename: 'bundle-stats.html',
      open: false,
      gzipSize: true
    })
  ],
  define: {
    'process.env.NODE_ENV': '"production"'
  },
  build: {
    outDir: 'js/dist',
    lib: {
      entry: path.resolve(__dirname, 'js/main.js'),
      name: 'VereinApp',
      formats: ['es'],
      cssFileName: 'style',
      fileName: (format) => `nextcloud-verein.mjs`
    },
    rollupOptions: {
      external: [],
      output: {
        globals: {},
        chunkFileNames: 'chunks/[name]-[hash].mjs'
      },
      plugins: [
        terser({
          compress: {
            drop_console: true,
            drop_debugger: true,
            passes: 3,
            pure_funcs: ['console.log', 'console.info', 'console.debug']
            // no unsafe* options: they may change what the code does (comparisons, float math) for a few KB
          },
          mangle: {
            properties: false
          },
          format: {
            comments: false
          }
        })
      ]
    },
    minify: false  // Use rollup-plugin-terser instead
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './js')
    }
  }
})
