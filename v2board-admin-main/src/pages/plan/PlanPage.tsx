import {
  CaretDownOutlined,
  DeleteOutlined,
  EditOutlined,
  PlusOutlined,
  QuestionCircleOutlined,
  UserOutlined,
} from '@ant-design/icons'
import { arrayMove } from '@dnd-kit/sortable'
import { useQueryClient } from '@tanstack/react-query'
import { Button, Dropdown, Switch, Tag, Tooltip, Card, Empty, Space, type TableColumnsType } from 'antd'
import { useMemo, useState, type AnchorHTMLAttributes } from 'react'
import { queryKeys, usePlans, useRefetch, useServerGroups } from '@/api/queries'
import { dropPlan, sortPlans, updatePlan } from '@/api/services/plan'
import type { Plan } from '@/api/types'
import { confirmDelete } from '@/components/confirmDelete'
import { JsLink } from '@/components/JsLink'
import { TableBlock } from '@/components/TableBlock'
import { DragHandle } from '@/components/table/DragHandle'
import { V2Table } from '@/components/table/V2Table'
import { AdminLayout } from '@/layouts/AdminLayout'
import { PERIOD_KEYS } from '@/utils/constants'
import { useMobile } from '@/hooks/useMobile'
import { PlanDrawer } from './PlanDrawer'

/** 与原版 plan/fetch 一致：价格由分换算成元（null 保持 null） */
function toYuan(plan: Plan): Plan {
  const next = { ...plan }
  for (const key of PERIOD_KEYS) if (next[key] !== null) next[key] = (next[key] as number) / 100
  return next
}

const price = (value: number | null) => (value !== null ? value.toFixed(2) : '-')

const PRICE_COLUMNS: Array<[keyof Plan, string]> = [
  ['month_price', '月付'],
  ['quarter_price', '季付'],
  ['half_year_price', '半年付'],
  ['year_price', '年付'],
  ['two_year_price', '两年付'],
  ['three_year_price', '三年付'],
  ['onetime_price', '一次性'],
  ['reset_price', '重置包'],
]

// 原版为不带 href 的 <a>（颜色继承菜单项）；onClick 由 PlanDrawer 通过 cloneElement 传入
function EditLabel(props: AnchorHTMLAttributes<HTMLAnchorElement>) {
  return (
    <a {...props}>
      <EditOutlined /> 编辑
    </a>
  )
}

// 订阅管理（原版模块 ih8c + model plan）
export default function PlanPage() {
  const queryClient = useQueryClient()
  const { data: rawPlans = [], isFetching } = usePlans()
  const plans = useMemo(() => rawPlans.map(toYuan), [rawPlans])
  const { data: groups = [] } = useServerGroups()
  const refetch = useRefetch(queryKeys.plans)
  const [sorting, setSorting] = useState(false)
  // 右键菜单对应的行（原版 this.record）
  const [contextRecord, setContextRecord] = useState<Plan | undefined>()

  const update = async (id: number, key: 'show' | 'renew', value: 0 | 1) => {
    const res = await updatePlan(id, key, value)
    if (res.code === 200) void refetch()
  }

  // 有意修正：原版订阅删除没有确认，这里与优惠券、知识库等页面统一
  const drop = (id: number | undefined) =>
    confirmDelete(async () => {
      if (id === undefined) return
      const res = await dropPlan(id)
      if (res.code === 200) void refetch()
    })

  const isMobile = useMobile()

  // 与原版一致：先在本地调整顺序，再提交全部 id，最后重新拉取
  const sort = async (fromIndex: number, toIndex: number) => {
    const next = arrayMove(rawPlans, fromIndex, toIndex)
    queryClient.setQueryData(queryKeys.plans, next)
    setSorting(true)
    const res = await sortPlans(next.map((plan) => plan.id))
    setSorting(false)
    if (res.code === 200) void refetch()
  }

  const columns: TableColumnsType<Plan> = useMemo(() => [
    !isMobile && { title: '排序', dataIndex: 'sort', key: 'sort', render: () => <DragHandle /> },
    {
      title: '销售',
      dataIndex: 'show',
      key: 'show',
      width: isMobile ? 65 : undefined,
      render: (show: number, record: Plan) => (
        <Switch size="small" checked={Boolean(show)} onClick={() => void update(record.id, 'show', show ? 0 : 1)} />
      ),
    },
    {
      title: (
        <span>
          续费{' '}
          <Tooltip placement="top" title="在订阅停止销售时，已购用户是否可以续费">
            <QuestionCircleOutlined />
          </Tooltip>
        </span>
      ),
      dataIndex: 'renew',
      key: 'renew',
      width: isMobile ? 65 : undefined,
      render: (renew: number, record: Plan) => (
        <Switch size="small" checked={Boolean(renew)} onClick={() => void update(record.id, 'renew', renew ? 0 : 1)} />
      ),
    },
    { title: '名称', dataIndex: 'name', key: 'name' },
    !isMobile && {
      title: '统计',
      dataIndex: 'count',
      key: 'count',
      render: (count: number) => (
        <>
          <UserOutlined style={{ cursor: 'move' }} /> {count}
        </>
      ),
    },
    { title: '流量', dataIndex: 'transfer_enable', key: 'transfer_enable', render: (value: number) => <>{value} GB</> },
    !isMobile && {
      title: '设备数限制',
      dataIndex: 'device_limit',
      key: 'device_limit',
      render: (value: number | null) => (value !== null ? value : '-'),
    },
    ...(isMobile ? [{
      title: '价格',
      key: 'price_summary',
      render: (_: unknown, r: Plan) => {
        const parts: string[] = []
        if (r.month_price !== null && typeof r.month_price === 'number') parts.push(`月:¥${r.month_price.toFixed(2)}`)
        if (r.year_price !== null && typeof r.year_price === 'number') parts.push(`年:¥${r.year_price.toFixed(2)}`)
        if (r.onetime_price !== null && typeof r.onetime_price === 'number') parts.push(`单:¥${r.onetime_price.toFixed(2)}`)
        if (parts.length === 0) {
          for (const [k, name] of PRICE_COLUMNS) {
            if (r[k] !== null && typeof r[k] === 'number') {
              parts.push(`${name}:¥${(r[k] as number).toFixed(2)}`)
              break
            }
          }
        }
        return <span style={{ fontSize: 12 }}>{parts.join(' / ') || '-'}</span>
      }
    }] : PRICE_COLUMNS.map(([key, title]) => ({ title, dataIndex: key, key, render: price }))),
    {
      title: '权限组',
      dataIndex: 'group_id',
      key: 'group_id',
      render: (groupId: number | null) =>
        groups
          .filter((group) => group.id === Number.parseInt(String(groupId), 10))
          .map((group) => <Tag key={group.id}>{group.name}</Tag>),
    },
    {
      title: '操作',
      dataIndex: 'action',
      key: 'action',
      fixed: 'right',
      align: 'right',
      render: (_: unknown, record: Plan) => (
        <Dropdown
          trigger={['click']}
          menu={{
            items: [
              {
                key: 'edit',
                label: (
                  <PlanDrawer record={record} key={record.id}>
                    <EditLabel />
                  </PlanDrawer>
                ),
              },
              {
                key: 'drop',
                // 删除的红色：皮肤里取 --v2b-danger-text（见 styles/skins），legacy 下没有定义，取原值
                style: { color: 'var(--v2b-danger-text, #ff4d4f)' },
                onClick: () => drop(record.id),
                label: (
                  <>
                    <DeleteOutlined /> 删除
                  </>
                ),
              },
            ],
          }}
        >
          <JsLink>
            操作 <CaretDownOutlined />
          </JsLink>
        </Dropdown>
      ),
    },
  ].filter(Boolean) as TableColumnsType<Plan>, [isMobile, groups])

  return (
    <AdminLayout title="订阅管理">
      <TableBlock
        loading={isFetching || sorting}
        toolbar={
          <PlanDrawer>
            <Button icon={<PlusOutlined />}>添加订阅</Button>
          </PlanDrawer>
        }
      >
        {isMobile ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10, padding: '10px 4px' }}>
            {plans.map((record) => {
              const parts: string[] = []
              if (record.month_price !== null && typeof record.month_price === 'number') parts.push(`月付: ¥${record.month_price.toFixed(2)}`)
              if (record.quarter_price !== null && typeof record.quarter_price === 'number') parts.push(`季付: ¥${record.quarter_price.toFixed(2)}`)
              if (record.year_price !== null && typeof record.year_price === 'number') parts.push(`年付: ¥${record.year_price.toFixed(2)}`)
              if (record.onetime_price !== null && typeof record.onetime_price === 'number') parts.push(`一次性: ¥${record.onetime_price.toFixed(2)}`)
              if (parts.length === 0) {
                for (const [k, name] of PRICE_COLUMNS) {
                  if (record[k] !== null && typeof record[k] === 'number') {
                    parts.push(`${name}: ¥${(record[k] as number).toFixed(2)}`)
                  }
                }
              }

              const groupName = groups.find((g) => g.id === Number.parseInt(String(record.group_id), 10))?.name;

              return (
                <Card
                  key={record.id}
                  size="small"
                  style={{ borderRadius: 8, boxShadow: '0 1px 3px rgba(0,0,0,0.05)', border: '1px solid #f0f0f0', background: '#fafafa' }}
                  bodyStyle={{ padding: '12px 14px' }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                    <span style={{ fontWeight: 600, fontSize: 15, color: '#333' }}>{record.name}</span>
                    <Space size={10}>
                      <span style={{ fontSize: 12 }}>
                        销售 <Switch size="small" checked={Boolean(record.show)} onClick={() => void update(record.id, 'show', record.show ? 0 : 1)} />
                      </span>
                      <span style={{ fontSize: 12 }}>
                        续费 <Switch size="small" checked={Boolean(record.renew)} onClick={() => void update(record.id, 'renew', record.renew ? 0 : 1)} />
                      </span>
                    </Space>
                  </div>

                  <div style={{ fontSize: 12, color: '#666', marginBottom: 8, display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 6 }}>
                    <span>流量: <strong>{record.transfer_enable} GB</strong></span>
                    <span>设备数: {record.device_limit !== null ? `${record.device_limit} 台` : '无限制'}</span>
                    {groupName && <Tag color="blue" style={{ margin: 0 }}>权限: {groupName}</Tag>}
                  </div>

                  {parts.length > 0 && (
                    <div style={{ fontSize: 12, color: '#1677ff', background: '#f0f5ff', padding: '6px 10px', borderRadius: 4, marginBottom: 10, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                      {parts.map((p, idx) => (
                        <span key={idx}>{p}</span>
                      ))}
                    </div>
                  )}

                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, borderTop: '1px dashed #e8e8e8', paddingTop: 8 }}>
                    <PlanDrawer record={record} key={record.id}>
                      <Button size="small" icon={<EditOutlined />}>编辑</Button>
                    </PlanDrawer>
                    <Button
                      size="small"
                      danger
                      icon={<DeleteOutlined />}
                      onClick={() => drop(record.id)}
                    >
                      删除
                    </Button>
                  </div>
                </Card>
              );
            })}
            {plans.length === 0 && (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无订阅套餐" />
            )}
          </div>
        ) : (
          <V2Table<Plan>
            dataSource={plans}
            columns={columns}
            pagination={false}
            scroll={{ x: 1300 }}
            onDragSort={(from, to) => void sort(from, to)}
            onRowContextMenu={setContextRecord}
            contextMenu={
              <ul className="ant-dropdown-menu ant-dropdown-menu-light ant-dropdown-menu-root ant-dropdown-menu-vertical">
                <li className="ant-dropdown-menu-item">
                  <PlanDrawer record={contextRecord} key={contextRecord?.id}>
                    <EditLabel />
                  </PlanDrawer>
                </li>
                <li className="ant-dropdown-menu-item" onClick={() => drop(contextRecord?.id)}>
                  <a style={{ color: 'var(--v2b-danger-text, #ff4d4f)' }}>
                    <DeleteOutlined /> 删除
                  </a>
                </li>
              </ul>
            }
          />
        )}
      </TableBlock>
    </AdminLayout>
  )
}
