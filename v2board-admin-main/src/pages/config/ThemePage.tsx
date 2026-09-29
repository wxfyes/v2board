import { useEffect, useState } from 'react'
import { message, Card, Button, Tag, Space, Alert } from 'antd'
import { CheckOutlined, SettingOutlined, SwapOutlined } from '@ant-design/icons'
import { saveConfig } from '@/api/services/config'
import { AdminLayout } from '@/layouts/AdminLayout'
import { useThemeManageStore } from '@/stores/themeManage'
import { useConfigManageStore } from '@/stores/configManage'
import { settings } from '@/app/settings'
import { ThemeConfigModal } from './ThemeConfigModal'
import { useMobile } from '@/hooks/useMobile'

const CARD_BACKGROUND =
  'linear-gradient(135deg, rgba(255,255,255,0.92) 0%, rgba(240,244,250,0.95) 100%), url(https://images.unsplash.com/photo-1567095761054-7a02e69e5c43?ixlib=rb-1.2.1&auto=format&fit=crop&w=1374&q=80)'

async function activeTheme(name: string) {
  const res = await saveConfig({ frontend_theme: name })
  if (res.code !== 200) return
  void useThemeManageStore.getState().getThemes()
  void useConfigManageStore.getState().fetch()
}

export default function ThemePage() {
  const isMobile = useMobile()
  const { themes, active } = useThemeManageStore()
  const adminTheme = useConfigManageStore((s) => s.frontend?.admin_theme) || 'react'
  const [switching, setSwitching] = useState(false)

  useEffect(() => {
    void useThemeManageStore.getState().getThemes()
    void useConfigManageStore.getState().fetch()
  }, [])

  return (
    <AdminLayout title="主题配置" loading={Object.keys(themes).length <= 0}>
      <div style={{ padding: isMobile ? '12px 10px' : '20px 24px', maxWidth: 1200, margin: '0 auto' }}>
        <Alert
          type="warning"
          showIcon
          message={
            <span style={{ fontSize: 13 }}>
              如果你采用前后分离的方式部署V2board，那么主题配置将不会生效。{' '}
              <a
                href="https://docs.v2board.com/use/advanced.html#%E5%89%8D%E7%AB%AF%E5%88%86%E7%A6%BB"
                target="_blank"
                rel="noreferrer"
                style={{ fontWeight: 600 }}
              >
                了解前后分离
              </a>
            </span>
          }
          style={{ marginBottom: 20, borderRadius: 8 }}
        />

        <div style={{ marginBottom: 24 }}>
          <h2 style={{ fontSize: isMobile ? 18 : 20, fontWeight: 700, marginBottom: 14, color: '#1f2937' }}>
            🖥️ 后台前端主题配置
          </h2>
          <div style={{ display: 'grid', gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, 1fr)', gap: 14 }}>
            {/* React 全新版 */}
            <Card
              bordered={false}
              style={{
                borderRadius: 12,
                boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                border: adminTheme === 'react' ? '2px solid #1677ff' : '1px solid #e5e7eb',
                backgroundImage: CARD_BACKGROUND,
                backgroundSize: 'cover',
                overflow: 'hidden'
              }}
              bodyStyle={{ padding: isMobile ? '16px' : '20px' }}
            >
              <div style={{ display: 'flex', flexDirection: 'column', height: '100%', justifyContent: 'space-between' }}>
                <div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#111827' }}>
                      React (全新版)
                    </h3>
                    {adminTheme === 'react' && <Tag color="processing" icon={<CheckOutlined />}>当前激活</Tag>}
                  </div>
                  <p style={{ color: '#4b5563', fontSize: 13, margin: '0 0 16px 0', lineHeight: 1.5 }}>
                    Ant Design 架构，响应式卡片流与现代化管理面板
                  </p>
                </div>
                <div>
                  <Button
                    type={adminTheme === 'react' ? 'default' : 'primary'}
                    icon={<SwapOutlined />}
                    block={isMobile}
                    disabled={adminTheme === 'react' || switching}
                    onClick={() => {
                      setSwitching(true)
                      message.loading('正在切换至 React 后台...', 2)
                      document.cookie = 'admin_theme=react; path=/; max-age=31536000'
                      saveConfig({ admin_theme: 'react' }).finally(() => {
                        window.location.href = `${window.location.origin}/${settings.secure_path}?theme=react`
                      })
                    }}
                  >
                    {adminTheme === 'react' ? '当前使用中' : '切换至此主题'}
                  </Button>
                </div>
              </div>
            </Card>

            {/* Vue 旧原版 */}
            <Card
              bordered={false}
              style={{
                borderRadius: 12,
                boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                border: adminTheme === 'vue' ? '2px solid #1677ff' : '1px solid #e5e7eb',
                backgroundImage: CARD_BACKGROUND,
                backgroundSize: 'cover',
                overflow: 'hidden'
              }}
              bodyStyle={{ padding: isMobile ? '16px' : '20px' }}
            >
              <div style={{ display: 'flex', flexDirection: 'column', height: '100%', justifyContent: 'space-between' }}>
                <div>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                    <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#111827' }}>
                      Vue (旧原版)
                    </h3>
                    {adminTheme === 'vue' && <Tag color="processing" icon={<CheckOutlined />}>当前激活</Tag>}
                  </div>
                  <p style={{ color: '#4b5563', fontSize: 13, margin: '0 0 16px 0', lineHeight: 1.5 }}>
                    原始经典 V2Board Vue 3 + Element Plus 管理后台
                  </p>
                </div>
                <div>
                  <Button
                    type={adminTheme === 'vue' ? 'default' : 'primary'}
                    icon={<SwapOutlined />}
                    block={isMobile}
                    disabled={adminTheme === 'vue' || switching}
                    onClick={() => {
                      setSwitching(true)
                      message.loading('正在切换至 Vue 后台...', 2)
                      document.cookie = 'admin_theme=vue; path=/; max-age=31536000'
                      saveConfig({ admin_theme: 'vue' }).finally(() => {
                        window.location.href = `${window.location.origin}/${settings.secure_path}?theme=vue`
                      })
                    }}
                  >
                    {adminTheme === 'vue' ? '当前使用中' : '切换至此主题'}
                  </Button>
                </div>
              </div>
            </Card>
          </div>
        </div>

        <div>
          <h2 style={{ fontSize: isMobile ? 18 : 20, fontWeight: 700, marginBottom: 14, color: '#1f2937' }}>
            🎨 用户前端主题配置
          </h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {Object.keys(themes).map((key) => {
              const theme = themes[key]
              const isCurrent = active === key
              return (
                <Card
                  key={key}
                  bordered={false}
                  style={{
                    borderRadius: 12,
                    boxShadow: '0 2px 6px rgba(0,0,0,0.05)',
                    border: isCurrent ? '2px solid #52c41a' : '1px solid #e5e7eb',
                    backgroundImage: CARD_BACKGROUND,
                    backgroundSize: 'cover',
                    overflow: 'hidden'
                  }}
                  bodyStyle={{ padding: isMobile ? '14px' : '18px 20px' }}
                >
                  <div
                    style={{
                      display: 'flex',
                      flexDirection: isMobile ? 'column' : 'row',
                      justifyContent: 'space-between',
                      alignItems: isMobile ? 'stretch' : 'center',
                      gap: 12
                    }}
                  >
                    <div>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                        <h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: '#111827' }}>
                          {theme.name || key}
                        </h3>
                        {isCurrent && <Tag color="success">当前主题</Tag>}
                      </div>
                      <p style={{ color: '#4b5563', fontSize: 13, margin: 0, lineHeight: 1.4 }}>
                        {theme.description || '无主题描述'}
                      </p>
                    </div>

                    <div style={{ display: 'flex', gap: 8, justifyContent: isMobile ? 'stretch' : 'flex-end', flexWrap: 'wrap' }}>
                      <Button
                        type={isCurrent ? 'default' : 'primary'}
                        disabled={isCurrent}
                        block={isMobile}
                        style={{ flex: isMobile ? 1 : undefined }}
                        onClick={() => void activeTheme(key)}
                      >
                        {isCurrent ? '当前使用中' : '激活主题'}
                      </Button>
                      <ThemeConfigModal keyName={key} themeName={theme.name} configs={theme.configs}>
                        <Button icon={<SettingOutlined />} block={isMobile} style={{ flex: isMobile ? 1 : undefined }}>
                          主题设置
                        </Button>
                      </ThemeConfigModal>
                    </div>
                  </div>
                </Card>
              )
            })}
          </div>
        </div>
      </div>
    </AdminLayout>
  )
}
