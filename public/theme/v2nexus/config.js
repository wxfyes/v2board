/**
 * V2Nexus-Theme 全局运行时配置
 * 
 * 【双模运行机制说明】：
 * 1. 嵌入式部署（当前默认：作为 v2board 插件主题）：
 *    系统会自动优先读取 v2board 后台管理界面的配置 (window.settings)，无需手动修改本文件。
 * 2. 动静分离 / 独立部署（前端托管在 Cloudflare Pages / Vercel / 独立 CDN）：
 *    此时没有后端的 Blade 模板注入，前端将全量读取本文件中的配置。
 *    支持直接在服务器面板双击修改本文件，按 F5 刷新即时生效，无需重新编译打包！
 */
window.NEXUS_CONFIG = {
  // 基础站点信息 (留空则使用默认配置)
  siteName: 'V2Nexus',
  siteSubtitle: '全球高速网络互联平台',
  siteLogo: '', // 留空使用自适应矢量 Logo 或默认站点徽标

  // 后端 API 接口配置 (前后端分离部署时，将 backendOrigin 指向您的 v2board 后端域名)
  backendOrigin: '', // 例如: 'https://api.yourdomain.com'，嵌入式部署留空即可
  apiBaseUrl: '/api/v1',
  currency: '¥',
  defaultTheme: 'light', // 默认色彩模式: 'light' | 'dark' | 'auto'

  // 前台品牌落地页开关: 1 为开启 (展示全功能品牌落地页)，0 为关闭 (直接进入登录/控制台)
  enableLandingPage: 0,

  // 快捷登录独立通道开关 (1 为开启，0 为关闭)
  enableTelegramLogin: 0, // 开启需后端已配置 Telegram Bot
  enableGoogleLogin: 1,
  enableGithubLogin: 1,

  // 封端防盗链模式: 0 为关闭复制/扫码 (封端防盗链防护模式)，1 为开启 (允许复制与二维码扫码)
  allowCopySubscribe: 0,

  // 客户端导入弹窗展示模式: 'all' (全平台通用) | 'shadowrocket_only' (小火箭独占模式) | 'mobile_only' (仅移动端)
  clientImportMode: 'all',

  // 专属邀请短链根域名 (例如 'example.com'，自动生成 https://[邀请码].example.com，留空使用标准注册链接)
  customInviteDomain: '',

  // 官方自研客户端版本与说明 (填入后在仪表盘客户端卡片中展示)
  clientVersion: 'v2.5.0',
  clientChangelog: '全新双核网络分流加速引擎，支持自适应节点智能探测',

  // 官方客户端各平台下载链接 (留空则不展示该平台卡片)
  clientLinks: {
    windows: '',
    macos: '',
    ios: '',
    android: '',
    linux: '',
    openwrt: '',
    tv: '',
  },

  // ImgBB 图床 API Key (工单支持图片上传功能，从 api.imgbb.com 免费申请，留空则关闭工单图片上传)
  imgbbApiKey: '',

  // 发卡账号 / 虚拟商品独立商城开关: 1 为开启，0 为关闭 (仅展示订阅套餐)
  shopCardEnable: 1,
  // 每日签到福利总开关: 1 为开启，0 为关闭
  checkinEnable: 1,
  checkinRatePercent: 0,

  // 提前开启新周期总开关: 1 为开启 (当流量耗尽且剩余天数大于30天时展示按钮)，0 为关闭
  enableNewPeriod: 1,

  // 自定义页脚或统计/在线客服 HTML 代码
  customHtml: '',
};
