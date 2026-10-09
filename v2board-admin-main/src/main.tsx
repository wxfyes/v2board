import dayjs from 'dayjs'
import 'dayjs/locale/zh-cn'
import { createRoot } from 'react-dom/client'
import './styles/index.scss'
import { App } from './app/App'
import { useThemeStore } from './stores/theme'
import { applyInitialAppearance } from './utils/darkMode'

dayjs.locale('zh-cn')
// 首屏渲染前应用界面预设、主题色与暗黑模式，避免闪烁
document.documentElement.dataset.v2bColor = useThemeStore.getState().theme.color
// darkreader 下载失败时照常渲染（亮色）
await applyInitialAppearance().catch(() => undefined)

// 捕获发版后由于分包哈希变更导致的动态分包 404 错误，自动重载最新版
window.addEventListener('vite:preloadError', (event) => {
  event.preventDefault()
  window.location.reload()
})
window.addEventListener('unhandledrejection', (event) => {
  const msg = String(event?.reason?.message || event?.reason || '')
  if (msg.includes('Failed to fetch dynamically imported module') || msg.includes('Importing a module script failed')) {
    event.preventDefault()
    window.location.reload()
  }
})

createRoot(document.getElementById('app') || document.getElementById('root')!).render(<App />)
