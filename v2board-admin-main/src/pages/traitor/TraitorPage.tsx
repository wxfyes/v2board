import { CheckOutlined, MessageOutlined, EnvironmentOutlined } from '@ant-design/icons'
import { Card, Button, Input, Row, Col, Alert, message, Tag, Popover, Space } from 'antd'
import { useEffect, useState, useMemo } from 'react'
import { get, post } from '@/api/request'
import { adminPath } from '@/app/settings'
import { AdminLayout } from '@/layouts/AdminLayout'
import { useMobile } from '@/hooks/useMobile'

interface TraitorData {
  emails?: string
  ips?: string
  match_count?: number
  matched_emails?: string[]
}

export default function TraitorPage() {
  const isMobile = useMobile()
  const [loading, setLoading] = useState(false)
  const [emails, setEmails] = useState('')
  const [ips, setIps] = useState('')
  const [matchCount, setMatchCount] = useState(0)
  const [matchedEmails, setMatchedEmails] = useState<string[]>([])

  const emailCount = useMemo(() => {
    if (!emails) return 0
    return emails.split('\n').filter((line) => line.trim() !== '').length
  }, [emails])

  const ipCount = useMemo(() => {
    if (!ips) return 0
    return ips.split('\n').filter((line) => line.trim() !== '').length
  }, [ips])

  const fetchConfig = async () => {
    try {
      const res = await get<TraitorData>(adminPath('/traitor/fetch'))
      if (res && res.data) {
        setEmails(res.data.emails || '')
        setIps(res.data.ips || '')
        setMatchCount(res.data.match_count || 0)
        setMatchedEmails(res.data.matched_emails || [])
      }
    } catch (err: any) {
      message.error(err?.message || '获取配置失败')
    }
  }

  const saveConfig = async () => {
    setLoading(true)
    try {
      // json: true to send as JSON payload
      await post(adminPath('/traitor/save'), { emails, ips }, true)
      message.success('保存成功')
      fetchConfig() // 刷新格式化后内容
    } catch (err: any) {
      message.error(err?.message || '保存失败')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void fetchConfig()
  }, [])

  return (
    <AdminLayout title="内鬼名单">
      <div className="block border-bottom" style={{ padding: isMobile ? '12px 8px' : '24px' }}>
        <Card
          bordered={false}
          title={
            <Space wrap size={[6, 6]}>
              <span style={{ fontSize: isMobile ? '15px' : '16px', fontWeight: 600 }}>风控配置</span>
              <Tag color="error">拦截</Tag>
              {matchCount > 0 ? (
                <Popover
                  title="命中拦截的账号"
                  content={
                    <div style={{ maxHeight: '200px', overflowY: 'auto' }}>
                      {matchedEmails.map((email) => (
                        <div key={email} style={{ marginBottom: '5px' }}>
                          <Tag color="error">{email}</Tag>
                        </div>
                      ))}
                    </div>
                  }
                  trigger="hover"
                >
                  <Tag color="warning" style={{ cursor: 'pointer' }}>
                    已拦截 {matchCount} 个账号
                  </Tag>
                </Popover>
              ) : (
                (emails || ips) && <Tag color="success">未匹配到已注册账号</Tag>
              )}
            </Space>
          }
          extra={
            <Button type="primary" size={isMobile ? 'small' : 'middle'} icon={<CheckOutlined />} loading={loading} onClick={saveConfig}>
              保存
            </Button>
          }
        >
          <Alert
            message="拦截说明"
            description="系统会在用户注册、登录以及拉取订阅时自动拦截：只要用户邮箱或请求 IP/网段在此列表中，会自动转入蜜罐下发诱饵节点，全局静默。"
            type="warning"
            showIcon
            style={{ marginBottom: isMobile ? '12px' : '20px' }}
          />

          <Row gutter={isMobile ? [12, 12] : [20, 20]}>
            <Col xs={24} md={12}>
              <Card
                type="inner"
                bodyStyle={{ padding: isMobile ? '12px' : '24px' }}
                title={
                  <Space>
                    <MessageOutlined />
                    <span>邮箱黑名单列表</span>
                    <Tag>{emailCount} 个</Tag>
                  </Space>
                }
              >
                <div style={{ fontSize: '12px', color: '#888', marginBottom: '8px' }}>
                  一行一个邮箱，自动转小写并去重。
                </div>
                <Input.TextArea
                  value={emails}
                  onChange={(e) => setEmails(e.target.value)}
                  rows={isMobile ? 8 : 15}
                  placeholder="example1@gmail.com&#10;example2@gmail.com"
                />
              </Card>
            </Col>

            <Col xs={24} md={12}>
              <Card
                type="inner"
                bodyStyle={{ padding: isMobile ? '12px' : '24px' }}
                title={
                  <Space>
                    <EnvironmentOutlined />
                    <span>IP 黑名单列表</span>
                    <Tag>{ipCount} 个</Tag>
                  </Space>
                }
              >
                <div style={{ fontSize: '12px', color: '#888', marginBottom: '8px' }}>
                  一行一个 IP 地址或 CIDR 网段，支持 IPv4/IPv6 单 IP 及网段（如 211.145.0.0/16 或 2400:dd0d:2000::/64）。
                </div>
                <Input.TextArea
                  value={ips}
                  onChange={(e) => setIps(e.target.value)}
                  rows={isMobile ? 8 : 15}
                  placeholder="1.1.1.1&#10;211.145.0.0/16&#10;2400:dd0d:2000::/64"
                />
              </Card>
            </Col>
          </Row>
        </Card>
      </div>
    </AdminLayout>
  )
}
