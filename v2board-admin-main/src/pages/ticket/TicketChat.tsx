import { SolutionOutlined, UserOutlined, PictureOutlined, LeftOutlined, CheckCircleOutlined } from '@ant-design/icons'
import { Button, ConfigProvider, Divider, Tooltip, Image, Input, Upload, message, Spin, Empty, Modal } from 'antd'
import type { Locale } from 'antd/es/locale'
import enUS from 'antd/locale/en_US'
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { usePlans } from '@/api/queries'
import { useTicketManageStore } from '@/stores/ticketManage'
import { formatTime } from '@/utils/format'
import { uploadImage } from '@/utils/imageUploadHelper'
import { TrafficLogModal } from '../user/TrafficLogModal'
import { UserDrawer } from '../user/UserDrawer'

const parseMessageContent = (text: string) => {
  if (!text) return []
  const regex = /!\[(.*?)\]\((.*?)\)/g
  const parts = []
  let lastIndex = 0
  let match
  while ((match = regex.exec(text)) !== null) {
    const textBefore = text.substring(lastIndex, match.index)
    if (textBefore) parts.push({ type: 'text', content: textBefore })
    parts.push({ type: 'image', url: match[2], alt: match[1] })
    lastIndex = match.index + match[0].length
  }
  if (lastIndex < text.length) {
    parts.push({ type: 'text', content: text.substring(lastIndex) })
  }
  return parts
}

const styles = { content: 'content___DW5w1', input: 'input___1j_ND', tag: 'tag___12_9H', ctrl: 'ctrl___UqDJ7' }
const EN_US: Locale = (enUS as Locale & { default?: Locale }).default ?? enUS
const LEGACY_EN_US: Locale = { ...EN_US, Table: { ...EN_US.Table, emptyText: 'No Data' }, Empty: { description: 'No Data' } }

export function TicketChat({ ticketId, onBack }: { ticketId: string | number; onBack?: () => void }) {
  const ticket = useTicketManageStore((s) => s.ticket)
  const chatRef = useRef<HTMLDivElement>(null)
  const [replyText, setReplyText] = useState('')
  const chatCount = useRef(0)
  const mounted = useRef(false)
  const [loading, setLoading] = useState(false)
  usePlans()

  useEffect(() => {
    if (!ticketId) return
    const store = useTicketManageStore.getState()
    setLoading(true)
    store.fetchById(ticketId).finally(() => setLoading(false))
    let timer: ReturnType<typeof setTimeout>
    const check = () => {
      timer = setTimeout(() => {
        void useTicketManageStore.getState().fetchById(ticketId)
        check()
      }, 5000)
    }
    check()
    return () => clearTimeout(timer)
  }, [ticketId])

  useLayoutEffect(() => {
    const scroll = () => chatRef.current?.scrollTo(0, chatRef.current.scrollHeight)
    if (!mounted.current) {
      mounted.current = true
      scroll()
      return
    }
    const count = ticket?.message?.length ?? 0
    if (chatCount.current === count) return
    chatCount.current = count
    scroll()
  })

  const reply = () => {
    if (!replyText.trim() || !ticketId) return
    void useTicketManageStore.getState().reply(ticketId, replyText, () => {
      setReplyText('')
    })
  }

  const handleClose = () => {
    if (!ticket?.id) return
    Modal.confirm({
      title: '确认关闭工单',
      content: '确定要关闭该工单吗？关闭后用户将无法继续追加回复。',
      okText: '确认关闭',
      cancelText: '取消',
      okType: 'danger',
      onOk: async () => {
        await useTicketManageStore.getState().close(ticket.id as number)
        message.success('工单已成功关闭')
      }
    })
  }

  if (!ticketId) {
    return (
      <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Empty description="请在左侧选择一个工单进行查看与回复" />
      </div>
    )
  }

  return (
    <ConfigProvider locale={LEGACY_EN_US}>
      <div style={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
        <div className="block-content-full bg-gray-lighter p-3" style={{ flexShrink: 0, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8, borderBottom: '1px solid #f0f0f0' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            {onBack && (
              <Button 
                type="link" 
                icon={<LeftOutlined />} 
                onClick={onBack}
                style={{ padding: '0 8px 0 0', height: 'auto', fontWeight: 'bold' }}
              >
                返回
              </Button>
            )}
            <span style={{ fontWeight: 'bold', fontSize: 15 }}>#{ticket?.id || ticketId} {ticket?.subject || '加载中...'}</span>
            {ticket?.status === 1 ? (
              <span style={{ background: '#f5f5f5', color: '#8c8c8c', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>已关闭</span>
            ) : (
              <span style={{ background: '#e6f7ff', color: '#1890ff', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>进行中</span>
            )}
          </div>
          <div className={styles.ctrl} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            {ticket?.status === 0 && (
              <Button size="small" danger onClick={handleClose}>
                关闭工单
              </Button>
            )}
            {ticket?.user_id ? (
              <>
                <UserDrawer userId={ticket.user_id}>
                  <Tooltip title="查看用户信息" placement="top">
                    <Button size="small" icon={<UserOutlined />} />
                  </Tooltip>
                </UserDrawer>
                <TrafficLogModal userId={ticket.user_id} key={ticket.user_id}>
                  <Tooltip title="查看流量记录" placement="top">
                    <Button size="small" icon={<SolutionOutlined />} />
                  </Tooltip>
                </TrafficLogModal>
              </>
            ) : null}
          </div>
        </div>
        <div
          className={`bg-white js-chat-messages block-content block-content-full text-wrap-break-word overflow-y-auto ${styles.content}`}
          ref={chatRef}
          style={{ flexGrow: 1, padding: 16 }}
        >
          {loading && (!ticket?.message || ticket.message.length === 0) ? (
            <div style={{ textAlign: 'center', marginTop: 20 }}><Spin /></div>
          ) : (
            ticket?.message?.map((item: any) =>
              item.is_me ? (
                <div key={item.id}>
                  <div className="font-size-sm text-muted my-2 text-right">{formatTime(item.created_at)}</div>
                  <div className="text-right ml-4">
                    <div className="d-inline-block bg-gray-lighter px-3 py-2 mb-2 mw-100 rounded text-left">
                      {parseMessageContent(item.message).map((part, pIdx) =>
                        part.type === 'text' ? (
                          <span key={pIdx} style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>{part.content}</span>
                        ) : (
                          <div key={pIdx} style={{ marginTop: 4, marginBottom: 4 }}>
                            <Image src={part.url} alt={part.alt} style={{ maxWidth: '100%', maxHeight: 200, objectFit: 'contain', borderRadius: 4 }} />
                          </div>
                        )
                      )}
                    </div>
                  </div>
                </div>
              ) : (
                <div key={item.id}>
                  <div className="font-size-sm text-muted my-2">{formatTime(item.created_at)}</div>
                  <div className="mr-4">
                    <div className="d-inline-block bg-success-lighter px-3 py-2 mb-2 mw-100 rounded text-left">
                      {parseMessageContent(item.message).map((part, pIdx) =>
                        part.type === 'text' ? (
                          <span key={pIdx} style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>{part.content}</span>
                        ) : (
                          <div key={pIdx} style={{ marginTop: 4, marginBottom: 4 }}>
                            <Image src={part.url} alt={part.alt} style={{ maxWidth: '100%', maxHeight: 200, objectFit: 'contain', borderRadius: 4 }} />
                          </div>
                        )
                      )}
                    </div>
                  </div>
                </div>
              )
            )
          )}
        </div>
        <div className={`js-chat-form block-content p-3 bg-body-dark ${styles.input}`} style={{ flexShrink: 0 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
            <Upload
              showUploadList={false}
              customRequest={async ({ file, onSuccess, onError }) => {
                try {
                  message.loading({ content: '上传中...', key: 'uploadImage' })
                  const res = await uploadImage(file as any)
                  if (res && res.markdown) {
                    setReplyText(prev => (prev ? prev + '\n' : '') + res.markdown)
                    message.success({ content: '图片上传成功', key: 'uploadImage' })
                  }
                  onSuccess?.(res)
                } catch (e: any) {
                  message.error({ content: e.message || '图片上传失败', key: 'uploadImage' })
                  onError?.(e as any)
                }
              }}
            >
              <Button icon={<PictureOutlined />} type="default" />
            </Upload>
            <Input.TextArea
              value={replyText}
              autoSize={{ minRows: 4, maxRows: 8 }}
              placeholder="输入回复内容，支持粘贴图片..."
              style={{ flexGrow: 1 }}
              onChange={(e) => setReplyText(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !e.shiftKey && !useTicketManageStore.getState().replyLoading) {
                  e.preventDefault()
                  reply()
                }
              }}
              onPaste={async (e) => {
                const items = e.clipboardData?.items
                if (!items) return
                for (let i = 0; i < items.length; i++) {
                  if (items[i].type.indexOf('image') !== -1) {
                    const file = items[i].getAsFile()
                    if (!file) continue
                    try {
                      message.loading({ content: '上传中...', key: 'uploadImage' })
                      const res = await uploadImage(file)
                      if (res && res.markdown) {
                        setReplyText(prev => (prev ? prev + '\n' : '') + res.markdown)
                        message.success({ content: '图片上传成功', key: 'uploadImage' })
                      }
                    } catch (err: any) {
                      message.error({ content: err.message || '图片上传失败', key: 'uploadImage' })
                    }
                  }
                }
              }}
            />
            <Button type="primary" loading={useTicketManageStore.getState().replyLoading} onClick={reply}>
              发送
            </Button>
          </div>
        </div>
      </div>
    </ConfigProvider>
  )
}
