import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  base: '/assets/admin-new/',
  plugins: [vue()],
  build: {
    outDir: '../public/assets/admin-new',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) {
            if (id.includes('element-plus')) {
              return 'element-plus';
            }
            if (id.includes('echarts')) {
              return 'echarts';
            }
            if (id.includes('vue') || id.includes('pinia')) {
              return 'vue-vendor';
            }
            return 'vendor';
          }
        }
      }
    }
  }
})
