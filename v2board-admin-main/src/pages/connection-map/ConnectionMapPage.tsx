import {
  ReloadOutlined,
  InfoCircleOutlined,
  CloseCircleOutlined,
  RightOutlined,
  WarningOutlined,
  DownloadOutlined,
  CopyOutlined,
} from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import {
  Alert,
  Badge,
  Button,
  Card,
  Col,
  Input,
  Modal,
  Progress,
  Radio,
  Row,
  Select,
  Space,
  Switch,
  Table,
  Tabs,
  Tag,
  Tooltip,
  message,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import * as echarts from 'echarts/core'
import { MapChart } from 'echarts/charts'
import { TooltipComponent, VisualMapComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import { useEffect, useMemo, useRef, useState } from 'react'
import { unwrap } from '@/api/request'
import { getConnectionMap } from '@/api/services/stat'
import type { ConnectionMapData, ConnectionMapDetail, ConnectionMapProvince, ConnectionMapRegion } from '@/api/types'
import { useMobile } from '@/hooks/useMobile'
import { AdminLayout } from '@/layouts/AdminLayout'

// 按需注册 ECharts 组件
echarts.use([MapChart, VisualMapComponent, TooltipComponent, CanvasRenderer])

export default function ConnectionMapPage() {
  const isMobile = useMobile()
  const [mapScope, setMapScope] = useState<'china' | 'all'>('china')
  const [activeTab, setActiveTab] = useState<string>('provinces')
  const [selectedProvince, setSelectedProvince] = useState<string | null>(null)

  // 监控大屏自动定时刷新 (0: 关闭, 30: 30s, 60: 60s, 120: 2m)
  const [autoRefreshInterval, setAutoRefreshInterval] = useState<number>(0)

  // 异地并发筛选与下钻明细状态
  const [onlyMultiLocation, setOnlyMultiLocation] = useState<boolean>(false)
  const [drilldownProvince, setDrilldownProvince] = useState<string | null>(null)
  const [drilldownSearch, setDrilldownSearch] = useState<string>('')

  const chartRef = useRef<HTMLDivElement>(null)
  const chartInstanceRef = useRef<echarts.ECharts | null>(null)

  // 获取统计数据 (React Query，20秒原子缓存)
  const query = useQuery({
    queryKey: ['stat', 'connection-map'],
    queryFn: () => unwrap(getConnectionMap()),
    staleTime: 20_000,
  })

  // 自动刷新定时器
  useEffect(() => {
    if (autoRefreshInterval <= 0) return
    const timer = setInterval(() => {
      void query.refetch()
    }, autoRefreshInterval * 1000)
    return () => clearInterval(timer)
  }, [autoRefreshInterval, query])

  const stats: ConnectionMapData = query.data ?? {
    total_online_users: 0,
    total_active_ips: 0,
    covered_provinces: 0,
    overseas_or_lan: 0,
    multi_location_users: 0,
    provinces: [],
    regions: [],
    details: [],
    updated_at: 0,
  }

  // 手动刷新
  const handleRefresh = async () => {
    try {
      await unwrap(getConnectionMap({ refresh: 1 }))
      await query.refetch()
      message.success('数据已更新')
    } catch {
      message.error('刷新失败')
    }
  }

  // 初始化地图
  useEffect(() => {
    let disposed = false

    async function initChart() {
      if (!chartRef.current) return

      try {
        // 动态加载中国地图 GeoJSON，不增加主包体积
        const chinaJsonModule = await import('@/assets/china.json')
        const chinaJson = (chinaJsonModule.default ?? chinaJsonModule) as Parameters<typeof echarts.registerMap>[1]

        if (disposed) return
        echarts.registerMap('china', chinaJson)

        if (chartInstanceRef.current) {
          chartInstanceRef.current.dispose()
        }

        const chart = echarts.init(chartRef.current)
        chartInstanceRef.current = chart

        chart.on('click', (params) => {
          if (params.seriesType === 'map') {
            const name = params.name as string
            setSelectedProvince((prev) => (prev === name ? null : name))
            setActiveTab('provinces')
          }
        })

        // 窗口缩放适配
        const handleResize = () => chart.resize()
        window.addEventListener('resize', handleResize)

        return () => {
          window.removeEventListener('resize', handleResize)
        }
      } catch (err) {
        console.error('Failed to init echarts map:', err)
      }
    }

    void initChart()

    return () => {
      disposed = true
      if (chartInstanceRef.current) {
        chartInstanceRef.current.dispose()
        chartInstanceRef.current = null
      }
    }
  }, [])

  // 数据变更或更新图表配置
  useEffect(() => {
    const chart = chartInstanceRef.current
    if (!chart) return

    const provinceData = (stats.provinces || []).map((p) => ({
      name: p.name,
      value: p.value,
      region: p.region,
      ratio: p.ratio,
    }))

    const maxVal = Math.max(...provinceData.map((d) => d.value), 20)

    chart.setOption(
      {
        tooltip: {
          trigger: 'item',
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e4e7ed',
          borderWidth: 1,
          padding: [8, 12],
          textStyle: { color: '#303133', fontSize: 13 },
          formatter: (params: { name?: string; value?: number; data?: { region?: string; ratio?: number } }) => {
            if (!params.name) return ''
            const count = params.value ?? 0
            const region = params.data?.region ? ` (${params.data.region})` : ''
            const ratio = params.data?.ratio ? ` · 占比 ${params.data.ratio}%` : ''
            return `
              <div style="font-weight: 600; margin-bottom: 4px; color: #1f2d3d;">${params.name}${region}</div>
              <div style="color: #606266; font-size: 12px;">活跃连接数：<b style="color: #f5222d; font-size: 14px;">${count}</b> 人${ratio}</div>
            `
          },
        },
        visualMap: {
          min: 0,
          max: maxVal,
          left: 20,
          bottom: 20,
          show: true,
          text: ['高', '0'],
          calculable: false,
          itemWidth: 12,
          itemHeight: 80,
          inRange: {
            color: ['#00a870', '#85db44', '#ffc53d', '#ff5722'],
          },
          textStyle: {
            color: '#8c8c8c',
            fontSize: 11,
          },
        },
        series: [
          {
            name: '活跃连接',
            type: 'map',
            map: 'china',
            roam: !isMobile,
            scaleLimit: { min: 1, max: 5 },
            zoom: isMobile ? 1.2 : 1.35,
            top: 20,
            bottom: 20,
            center: [105, 36],
            label: {
              show: true,
              fontSize: 9,
              color: '#ffffff',
            },
            itemStyle: {
              borderColor: '#ffffff',
              borderWidth: 0.8,
              areaColor: '#f0f2f5',
            },
            emphasis: {
              label: {
                show: true,
                color: '#1f2d3d',
                fontWeight: 'bold',
              },
              itemStyle: {
                areaColor: '#ff9800',
                borderWidth: 1.2,
                borderColor: '#ffffff',
                shadowBlur: 8,
                shadowColor: 'rgba(0, 0, 0, 0.2)',
              },
            },
            data: provinceData,
          },
        ],
      },
      true,
    )
  }, [stats, isMobile])

  // 筛选后的列表
  const filteredProvinces = useMemo(() => {
    if (!selectedProvince) return stats.provinces || []
    return (stats.provinces || []).filter((p) => p.name === selectedProvince)
  }, [selectedProvince, stats.provinces])

  const filteredDetails = useMemo(() => {
    let list = stats.details || []
    if (selectedProvince) {
      list = list.filter((d) => d.province === selectedProvince)
    }
    if (onlyMultiLocation) {
      list = list.filter((d) => d.is_multi_location)
    }
    return list
  }, [selectedProvince, onlyMultiLocation, stats.details])

  // 省份下钻明细
  const provinceDetails = useMemo(() => {
    if (!drilldownProvince) return []
    return (stats.details || []).filter((d) => d.province === drilldownProvince)
  }, [drilldownProvince, stats.details])

  const filteredProvinceDetails = useMemo(() => {
    if (!drilldownSearch.trim()) return provinceDetails
    const kw = drilldownSearch.trim().toLowerCase()
    return provinceDetails.filter(
      (d) =>
        d.email.toLowerCase().includes(kw) ||
        d.ip.includes(kw) ||
        (d.city && d.city.includes(kw)) ||
        (d.isp && d.isp.includes(kw)) ||
        (d.node && d.node.toLowerCase().includes(kw)),
    )
  }, [provinceDetails, drilldownSearch])

  const provinceMultiCount = useMemo(() => {
    return new Set(provinceDetails.filter((d) => d.is_multi_location).map((d) => d.user_id)).size
  }, [provinceDetails])

  // 导出 CSV 工具
  const handleExportCsv = (dataList: ConnectionMapDetail[], filename: string) => {
    if (!dataList.length) {
      message.warning('当前无数据可导出')
      return
    }
    const headers = ['用户ID', '邮箱', '客户端IP', '省份', '城市', '运营商', '节点', '风控状态', '并发省份']
    const rows = dataList.map((d) => [
      d.user_id,
      `"${d.email.replace(/"/g, '""')}"`,
      d.ip,
      `"${d.province}"`,
      `"${d.city || ''}"`,
      `"${d.isp || ''}"`,
      `"${d.node.replace(/"/g, '""')}"`,
      d.is_multi_location ? '跨省多地并发' : '正常单省',
      `"${(d.concurrent_provinces || []).join(';')}"`,
    ])
    const csvContent = '\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n')
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `${filename}.csv`)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    message.success('已导出 CSV 报表')
  }

  // 省份表格列配置
  const provinceColumns: ColumnsType<ConnectionMapProvince> = [
    {
      title: '排名',
      dataIndex: 'rank',
      key: 'rank',
      width: 50,
      align: 'center',
      render: (rank: number) => {
        let bg = '#f0f0f0'
        let color = '#8c8c8c'
        if (rank === 1) {
          bg = '#f5222d'
          color = '#fff'
        } else if (rank === 2) {
          bg = '#fa8c16'
          color = '#fff'
        } else if (rank === 3) {
          bg = '#fadb14'
          color = '#874d00'
        }
        return (
          <span
            style={{
              display: 'inline-flex',
              width: 22,
              height: 22,
              borderRadius: '50%',
              backgroundColor: bg,
              color,
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 12,
              fontWeight: 'bold',
            }}
          >
            {rank}
          </span>
        )
      },
    },
    {
      title: '省份 / 地区',
      key: 'name',
      render: (_, r) => (
        <span>
          <strong style={{ color: '#1f2d3d' }}>{r.name}</strong>
          {r.region && <span style={{ fontSize: 12, color: '#8c8c8c', marginLeft: 4 }}>({r.region})</span>}
        </span>
      ),
    },
    {
      title: '连接数 (点击查看)',
      dataIndex: 'value',
      key: 'value',
      width: 110,
      align: 'center',
      sorter: (a, b) => a.value - b.value,
      render: (val: number, r: ConnectionMapProvince) => (
        <span
          onClick={(e) => {
            e.stopPropagation()
            setDrilldownProvince(r.name)
            setDrilldownSearch('')
          }}
          style={{
            padding: '2px 8px',
            borderRadius: 12,
            background: '#e6f7ff',
            color: '#1890ff',
            fontWeight: 600,
            fontSize: 12,
            display: 'inline-flex',
            alignItems: 'center',
            gap: 4,
            cursor: 'pointer',
            border: '1px solid #91d5ff',
            transition: 'all 0.2s',
          }}
          title={`点击穿透查看【${r.name}】实时在线用户明细`}
        >
          {val} 人 <RightOutlined style={{ fontSize: 9 }} />
        </span>
      ),
    },
    {
      title: '占比',
      dataIndex: 'ratio',
      key: 'ratio',
      width: 100,
      render: (ratio: number) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <Progress percent={ratio} showInfo={false} strokeColor="#f5222d" size="small" style={{ margin: 0, flex: 1 }} />
          <span style={{ fontSize: 11, fontWeight: 600, color: '#595959', width: 34, textAlign: 'right' }}>
            {ratio}%
          </span>
        </div>
      ),
    },
  ]

  // 大区表格列
  const regionColumns: ColumnsType<ConnectionMapRegion> = [
    {
      title: '#',
      key: 'index',
      width: 50,
      align: 'center',
      render: (_, __, i) => i + 1,
    },
    {
      title: '大区分区',
      dataIndex: 'name',
      key: 'name',
      render: (name: string) => <strong style={{ color: '#262626' }}>{name}</strong>,
    },
    {
      title: '活跃连接数',
      dataIndex: 'value',
      key: 'value',
      width: 110,
      align: 'center',
      render: (val: number) => (
        <span
          style={{
            padding: '2px 8px',
            borderRadius: 10,
            background: '#e6f7ff',
            color: '#1890ff',
            fontWeight: 600,
            fontSize: 12,
          }}
        >
          {val} 人
        </span>
      ),
    },
    {
      title: '占比',
      dataIndex: 'ratio',
      key: 'ratio',
      width: 130,
      render: (ratio: number) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Progress percent={ratio} showInfo={false} strokeColor="#1890ff" size="small" style={{ margin: 0, flex: 1 }} />
          <span style={{ fontSize: 12, fontWeight: 600, color: '#595959', width: 36, textAlign: 'right' }}>
            {ratio}%
          </span>
        </div>
      ),
    },
  ]

  // 明细表格列
  const detailColumns: ColumnsType<ConnectionMapDetail> = [
    {
      title: '客户端 IP',
      dataIndex: 'ip',
      key: 'ip',
      render: (ip: string) => <span style={{ fontFamily: 'monospace', fontWeight: 500 }}>{ip}</span>,
    },
    {
      title: '归属地',
      key: 'location',
      render: (_, r) => (
        <div>
          <div>{r.province}{r.city ? ` ${r.city}` : ''}</div>
          <div style={{ fontSize: 11, color: '#8c8c8c' }}>{r.isp || r.region}</div>
        </div>
      ),
    },
    {
      title: '节点 / 用户',
      key: 'node',
      render: (_, r) => (
        <div>
          <div style={{ fontSize: 12, fontWeight: 500, color: '#262626' }}>{r.node}</div>
          <div style={{ fontSize: 11, color: '#8c8c8c' }}>{r.email}</div>
        </div>
      ),
    },
    {
      title: '风控状态',
      key: 'risk',
      width: 110,
      align: 'center',
      render: (_, r) => {
        if (r.is_multi_location) {
          return (
            <Tooltip
              title={
                <div>
                  <div><b>⚠️ 跨省多地并发异常</b></div>
                  <div>同时在线省份：{r.concurrent_provinces?.join('、')}</div>
                </div>
              }
            >
              <Tag color="error" style={{ cursor: 'pointer' }}>
                <WarningOutlined /> 跨省并发
              </Tag>
            </Tooltip>
          )
        }
        return <Tag color="success">正常单省</Tag>
      },
    },
  ]

  return (
    <AdminLayout title="连接地图">
      <div style={{ padding: '4px 8px 24px' }}>
        {/* 顶部 4 张核心 KPI 指标卡片 */}
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
          <Col xs={12} sm={12} md={6}>
            <Card variant="borderless" style={{ borderRadius: 10, boxShadow: '0 2px 10px rgba(0,0,0,0.03)' }}>
              <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 8 }}>总在线用户数</div>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#f5222d', lineHeight: 1 }}>
                  {stats.total_online_users || 0}
                </span>
                <span style={{ fontSize: 13, color: '#595959' }}>位</span>
              </div>
            </Card>
          </Col>
          <Col xs={12} sm={12} md={6}>
            <Card variant="borderless" style={{ borderRadius: 10, boxShadow: '0 2px 10px rgba(0,0,0,0.03)' }}>
              <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 8 }}>总活跃连接数 (IP)</div>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#1890ff', lineHeight: 1 }}>
                  {stats.total_active_ips || 0}
                </span>
                <span style={{ fontSize: 13, color: '#595959' }}>条</span>
              </div>
            </Card>
          </Col>
          <Col xs={12} sm={12} md={6}>
            <Card variant="borderless" style={{ borderRadius: 10, boxShadow: '0 2px 10px rgba(0,0,0,0.03)' }}>
              <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 8 }}>覆盖中国省份数</div>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#52c41a', lineHeight: 1 }}>
                  {stats.covered_provinces || 0}
                </span>
                <span style={{ fontSize: 13, color: '#595959' }}>个省市</span>
              </div>
            </Card>
          </Col>
          <Col xs={12} sm={12} md={6}>
            <Card variant="borderless" style={{ borderRadius: 10, boxShadow: '0 2px 10px rgba(0,0,0,0.03)' }}>
              <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 8 }}>海外 / 局域网</div>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
                <span style={{ fontSize: 28, fontWeight: 700, color: '#722ed1', lineHeight: 1 }}>
                  {stats.overseas_or_lan || 0}
                </span>
                <span style={{ fontSize: 13, color: '#595959' }}>个</span>
              </div>
            </Card>
          </Col>
        </Row>

        {/* 异常跨省并发风控预警 Banner */}
        {Boolean(stats.multi_location_users && stats.multi_location_users > 0) && (
          <Alert
            type="warning"
            showIcon
            style={{ marginBottom: 16, borderRadius: 8, border: '1px solid #ffe58f' }}
            message={
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                <span>
                  <strong>多地并发风控预警：</strong>检测到当前有 <b style={{ color: '#cf1322', fontSize: 15 }}>{stats.multi_location_users}</b> 位用户正在从多个不同省份并发连接，疑似合租共享账号或凭证泄漏。
                </span>
                <Button
                  type="link"
                  size="small"
                  danger
                  style={{ padding: 0, fontWeight: 500 }}
                  onClick={() => {
                    setActiveTab('details')
                    setOnlyMultiLocation(true)
                  }}
                >
                  立即查看涉事用户明细 →
                </Button>
              </div>
            }
          />
        )}

        {/* 下方主区域：左侧地图 + 右侧列表 */}
        <Row gutter={[16, 16]}>
          <Col xs={24} lg={14}>
            <Card
              variant="borderless"
              style={{ borderRadius: 12, minHeight: isMobile ? 'auto' : 600, boxShadow: '0 4px 16px rgba(0,0,0,0.03)' }}
              styles={{ body: { padding: isMobile ? '12px' : '16px 20px 20px' } }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 10 }}>
                <Radio.Group
                  value={mapScope}
                  onChange={(e) => {
                    const val = e.target.value
                    setMapScope(val)
                    if (val === 'all') {
                      message.info(`海外及局域网连接共 ${stats.overseas_or_lan || 0} 个，可在右侧明细中查看`)
                    }
                  }}
                  buttonStyle="solid"
                >
                  <Radio.Button value="china">中国地区</Radio.Button>
                  <Radio.Button value="all">全部 / 包含海外</Radio.Button>
                </Radio.Group>

                <Space size="middle" wrap>
                  <span style={{ fontSize: 12, color: '#8c8c8c', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <InfoCircleOutlined /> {isMobile ? '提示：点击省份可筛选下方列表' : '提示：滚轮可缩放，点击省份可筛选右侧列表'}
                  </span>
                  <Select
                    size="small"
                    value={autoRefreshInterval}
                    onChange={setAutoRefreshInterval}
                    style={{ width: 110 }}
                    options={[
                      { label: '自动刷新: 关', value: 0 },
                      { label: '每 30 秒', value: 30 },
                      { label: '每 60 秒', value: 60 },
                      { label: '每 2 分钟', value: 120 },
                    ]}
                  />
                  <Button
                    icon={<ReloadOutlined spin={query.isFetching} />}
                    shape="circle"
                    size="small"
                    title="立即刷新"
                    onClick={handleRefresh}
                  />
                </Space>
              </div>

              {selectedProvince && (
                <div style={{ marginBottom: 10, padding: '4px 12px', background: '#fff1f0', borderRadius: 6, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 12, color: '#f5222d' }}>当前筛选省份：</span>
                  <Tag
                    color="red"
                    closable
                    closeIcon={<CloseCircleOutlined />}
                    onClose={() => setSelectedProvince(null)}
                  >
                    {selectedProvince}
                  </Tag>
                </div>
              )}

              {/* 地图容器 */}
              <div style={{ position: 'relative', width: '100%', height: isMobile ? 360 : 520 }}>
                <div ref={chartRef} style={{ width: '100%', height: '100%' }} />
              </div>
            </Card>
          </Col>

          {/* 右侧排名与明细 */}
          <Col xs={24} lg={10}>
            <Card
              variant="borderless"
              style={{ borderRadius: 12, minHeight: isMobile ? 'auto' : 600, boxShadow: '0 4px 16px rgba(0,0,0,0.03)' }}
              styles={{ body: { padding: isMobile ? '12px' : '12px 16px 16px' } }}
            >
              <Tabs
                activeKey={activeTab}
                onChange={setActiveTab}
                items={[
                  {
                    key: 'provinces',
                    label: (
                      <span>
                        省份连接排名
                        <Badge
                          count={filteredProvinces.length}
                          style={{ backgroundColor: '#fff1f0', color: '#f5222d', marginLeft: 6, boxShadow: 'none' }}
                        />
                      </span>
                    ),
                    children: (
                      <Table
                        columns={provinceColumns}
                        dataSource={filteredProvinces}
                        rowKey="name"
                        size="small"
                        pagination={false}
                        scroll={{ y: isMobile ? 320 : 490 }}
                        onRow={(record) => ({
                          onClick: () => setSelectedProvince((prev) => (prev === record.name ? null : record.name)),
                          style: {
                            cursor: 'pointer',
                            backgroundColor: selectedProvince === record.name ? '#fff1f0' : undefined,
                          },
                        })}
                      />
                    ),
                  },
                  {
                    key: 'regions',
                    label: '大区统计',
                    children: (
                      <Table
                        columns={regionColumns}
                        dataSource={stats.regions || []}
                        rowKey="name"
                        size="small"
                        pagination={false}
                        scroll={{ y: isMobile ? 320 : 490 }}
                      />
                    ),
                  },
                  {
                    key: 'details',
                    label: (
                      <span>
                        在线节点明细
                        <Badge
                          count={(stats.details || []).length}
                          style={{ backgroundColor: '#e6f7ff', color: '#1890ff', marginLeft: 6, boxShadow: 'none' }}
                        />
                      </span>
                    ),
                    children: (
                      <div>
                        {/* 筛选与导出工具条 */}
                        <div style={{ marginBottom: 10, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                          <Space size="middle">
                            <span style={{ fontSize: 12, color: '#595959', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                              仅看跨省并发:
                              <Switch
                                size="small"
                                checked={onlyMultiLocation}
                                onChange={setOnlyMultiLocation}
                              />
                            </span>
                            {onlyMultiLocation && (
                              <Tag color="error" closable onClose={() => setOnlyMultiLocation(false)}>
                                跨省异常 ({filteredDetails.length})
                              </Tag>
                            )}
                          </Space>
                          <Button
                            icon={<DownloadOutlined />}
                            size="small"
                            onClick={() => handleExportCsv(filteredDetails, `active_connections_${selectedProvince || 'all'}`)}
                          >
                            导出 CSV
                          </Button>
                        </div>

                        <Table
                          columns={detailColumns}
                          dataSource={filteredDetails}
                          rowKey={(r, i) => `${r.ip}_${i}`}
                          size="small"
                          pagination={false}
                          scroll={{ y: isMobile ? 320 : 450 }}
                        />
                      </div>
                    ),
                  },
                ]}
              />
            </Card>
          </Col>
        </Row>

        {/* 省份实时在线连接下钻穿透弹窗 */}
        <Modal
          title={
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 16, fontWeight: 600 }}>
                【{drilldownProvince}】实时在线连接明细
              </span>
              <Tag color="red">{provinceDetails.length} 条连接</Tag>
            </div>
          }
          open={!!drilldownProvince}
          onCancel={() => setDrilldownProvince(null)}
          footer={null}
          width={840}
          destroyOnClose
        >
          <div style={{ marginTop: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', gap: 8 }}>
              <Space size="middle">
                <span style={{ fontSize: 13, color: '#595959' }}>
                  独立用户数：<b style={{ color: '#262626' }}>{new Set(provinceDetails.map((d) => d.user_id)).size}</b> 位
                </span>
                {provinceMultiCount > 0 && (
                  <Tag color="error">
                    ⚠️ 跨省并发风控：{provinceMultiCount} 位
                  </Tag>
                )}
              </Space>
              <Space>
                <Input.Search
                  placeholder="搜索 邮箱 / IP / 城市 / 节点"
                  allowClear
                  size="small"
                  style={{ width: 210 }}
                  value={drilldownSearch}
                  onChange={(e) => setDrilldownSearch(e.target.value)}
                />
                <Button
                  icon={<DownloadOutlined />}
                  size="small"
                  onClick={() => handleExportCsv(provinceDetails, `province_${drilldownProvince}`)}
                >
                  导出
                </Button>
              </Space>
            </div>

            <Table
              columns={[
                {
                  title: '用户 (UID / 邮箱)',
                  key: 'user',
                  render: (_, r) => (
                    <div>
                      <div style={{ fontWeight: 500, color: '#262626', display: 'flex', alignItems: 'center', gap: 4 }}>
                        <span>{r.email}</span>
                        <Button
                          type="text"
                          icon={<CopyOutlined style={{ fontSize: 11, color: '#8c8c8c' }} />}
                          size="small"
                          style={{ width: 20, height: 20 }}
                          onClick={() => {
                            void navigator.clipboard.writeText(r.email)
                            message.success('邮箱已复制')
                          }}
                          title="复制邮箱"
                        />
                      </div>
                      <div style={{ fontSize: 11, color: '#8c8c8c' }}>UID: {r.user_id}</div>
                    </div>
                  ),
                },
                {
                  title: '客户端 IP / 城市',
                  key: 'ip',
                  render: (_, r) => (
                    <div>
                      <div style={{ fontFamily: 'monospace', fontWeight: 500 }}>{r.ip}</div>
                      <div style={{ fontSize: 11, color: '#595959' }}>
                        {r.city || r.province} {r.isp ? `· ${r.isp}` : ''}
                      </div>
                    </div>
                  ),
                },
                {
                  title: '连接节点',
                  dataIndex: 'node',
                  key: 'node',
                  render: (node: string) => <Tag color="blue">{node}</Tag>,
                },
                {
                  title: '风控状态',
                  key: 'risk',
                  width: 120,
                  align: 'center',
                  render: (_, r) => {
                    if (r.is_multi_location) {
                      return (
                        <Tooltip
                          title={
                            <div>
                              <div><b>⚠️ 跨省多地并发异常！</b></div>
                              <div>该账号同时活跃省份：</div>
                              <div style={{ color: '#ffccc7' }}>{r.concurrent_provinces?.join('、')}</div>
                            </div>
                          }
                        >
                          <Tag color="error" style={{ cursor: 'pointer' }}>
                            <WarningOutlined /> 跨省并发
                          </Tag>
                        </Tooltip>
                      )
                    }
                    return <Tag color="success">正常单省</Tag>
                  },
                },
              ]}
              dataSource={filteredProvinceDetails}
              rowKey={(r, i) => `${r.ip}_${i}`}
              size="small"
              pagination={{ pageSize: 8, showTotal: (t) => `共 ${t} 条` }}
            />
          </div>
        </Modal>
      </div>
    </AdminLayout>
  )
}
