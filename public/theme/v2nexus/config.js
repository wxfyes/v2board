/**
 * V2Nexus-Theme 全局运行时配置
 * 自动向下兼容 v2board 原生 window.settings，亦支持独立前端部署
 */
window.NEXUS_CONFIG = {
  siteName: '天阙',
  siteSubtitle: '全球高速网络互联平台',
  apiBaseUrl: '/api/v1',
  backendOrigin: 'https://go.tianquege.top',
  currency: '¥',
  defaultTheme: 'dark', // 'dark' | 'light' | 'auto'
  landingText: {
    title: '探索全球网络无限可能',
    subtitle: '高速稳定、安全私密，助力业务数字化转型',
  },
  clientVersion: 'v2.5.0',
  clientChangelog: '全新双核网络分流加速引擎，支持自适应节点智能探测',
  clientLinks: {
    windows: 'https://github.com',
    macos: 'https://github.com',
    ios: 'https://apps.apple.com',
    android: 'https://github.com',
    linux: 'https://github.com',
    openwrt: 'https://github.com',
    downloadPage: '',
  },
};
