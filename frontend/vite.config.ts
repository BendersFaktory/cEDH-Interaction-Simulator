/// <reference types="vitest" />
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    // Évite le CORS en dev : /api est relayé vers `symfony serve` (ou php -S) sur le port 8000.
    proxy: { '/api': 'http://localhost:8000' },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**'],
      exclude: ['src/**/*.test.ts', 'src/**/__fixtures__/**'],
      reporter: ['text', 'text-summary'],
      // Seuil = taux de lignes mesuré le 2026-10-24 (60,59 %) arrondi vers le bas.
      thresholds: { lines: 60 },
    },
  },
})
