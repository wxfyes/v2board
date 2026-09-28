import { Badge, Divider, Input, Radio, List, Row, Col, Card, Tag } from 'antd'
import { useEffect, useRef, useState } from 'react'
import type { Ticket } from '@/api/types'
import { JsLink } from '@/components/JsLink'
import { Loading } from '@/components/Loading'
import { AdminLayout } from '@/layouts/AdminLayout'
import { useTicketManageStore } from '@/stores/ticketManage'
import { formatTime } from '@/utils/format'
import { TicketChat } from './TicketChat'
import { usePlans } from '@/api/queries'

const LEVEL_TEXT = ['低', '中', '高']
const LEVEL_COLOR = ['default', 'warning', 'error']
const FIRST_PAGE = { pageSize: 10, current: 1 }

export default function TicketPage() {
  const model = useTicketManageStore()
  const { tickets, fetchLoading, pagination, filter } = model
  const searchTimer = useRef<ReturnType<typeof setTimeout>>(undefined)
  const [activeTicketId, setActiveTicketId] = useState<number | undefined>(undefined)
  usePlans() // ensure plans are loaded for user tags

  useEffect(() => {
    void useTicketManageStore.getState().fetch()
  }, [])

  return (
    <AdminLayout title="工单管理">
      <Loading loading={fetchLoading && tickets.length === 0}>
        <Row gutter={16} style={{ height: 'calc(100vh - 120px)', minHeight: 600 }}>
          <Col span={6} style={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
            <Card 
              title="工单列表" 
              bodyStyle={{ padding: 0, flexGrow: 1, overflowY: 'auto' }}
              style={{ height: '100%', display: 'flex', flexDirection: 'column' }}
              extra={
                <Radio.Group
                  size="small"
                  value={filter.status}
                  onChange={(e) => model.filterBy(FIRST_PAGE, { status: e.target.value })}
                >
                  <Radio.Button value={0}>已开启</Radio.Button>
                  <Radio.Button value={1}>已关闭</Radio.Button>
                </Radio.Group>
              }
            >
              <div className="p-3" style={{ borderBottom: '1px solid #f0f0f0' }}>
                <Input
                  placeholder="搜索工单标题、内容或用户邮箱..."
                  defaultValue={typeof filter.email === 'string' ? filter.email : undefined}
                  onChange={(e) => {
                    const email = e.target.value
                    clearTimeout(searchTimer.current)
                    searchTimer.current = setTimeout(() => model.filterBy(FIRST_PAGE, { email }), 300)
                  }}
                />
              </div>
              <List
                itemLayout="horizontal"
                dataSource={tickets}
                style={{ padding: 0 }}
                pagination={{
                  ...pagination,
                  size: 'small',
                  onChange: (page, pageSize) => model.filterBy({ current: page, pageSize }, filter),
                  style: { padding: '0 16px' }
                }}
                renderItem={(ticket) => (
                  <List.Item 
                    style={{ 
                      padding: '12px 16px', 
                      cursor: 'pointer', 
                      backgroundColor: activeTicketId === ticket.id ? '#e6f7ff' : '#fff',
                      borderLeft: activeTicketId === ticket.id ? '3px solid #1890ff' : '3px solid transparent',
                      transition: 'all 0.3s'
                    }}
                    onClick={() => setActiveTicketId(ticket.id)}
                  >
                    <List.Item.Meta
                      title={
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                          <span style={{ fontWeight: 'bold' }}>#{ticket.id} {ticket.subject}</span>
                          {ticket.status === 1 ? <Tag>已关闭</Tag> : <Badge status={ticket.reply_status ? 'processing' : 'error'} text={ticket.reply_status ? '已回复' : '待回复'} />}
                        </div>
                      }
                      description={
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
                          <span style={{ fontSize: '12px', color: '#999' }}>{formatTime(ticket.created_at)}</span>
                          <Tag color={LEVEL_COLOR[ticket.level] || 'default'} style={{ margin: 0 }}>
                            {LEVEL_TEXT[ticket.level] || '未知'}优先级
                          </Tag>
                        </div>
                      }
                    />
                  </List.Item>
                )}
              />
            </Card>
          </Col>
          <Col span={18} style={{ height: '100%' }}>
            <Card 
              bodyStyle={{ padding: 0, height: '100%' }}
              style={{ height: '100%' }}
            >
              <TicketChat ticketId={activeTicketId as any} />
            </Card>
          </Col>
        </Row>
      </Loading>
    </AdminLayout>
  )
}
