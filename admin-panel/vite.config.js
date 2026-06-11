import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  base: '/assets/admin-new/',
  plugins: [vue()],
  build: {
    outDir: '../public/assets/admin-new',
    emptyOutDir: true
  }
})
