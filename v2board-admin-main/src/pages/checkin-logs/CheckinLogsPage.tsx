import React, { useState, useEffect, useCallback } from 'react';
import {
  Card,
  Input,
  Button,
  Table,
  Tag,
  Space,
  Row,
  Col,
  Statistic,
  DatePicker,
  Typography,
} from 'antd';
import {
  SearchOutlined,
  ReloadOutlined,
  CalendarOutlined,
  GiftOutlined,
  BarChartOutlined,
  ClockCircleOutlined,
  CloseCircleOutlined,
} from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { get } from '@/api/request';
import { adminPath } from '@/app/settings';
import { AdminLayout } from '@/layouts/AdminLayout';

const { Text } = Typography;

interface CheckinItem {
  id: number;
  user_id: number;
  email: string;
  telegram_id: number | null;
  plan_id: number;
  plan_name: string;
  traffic: number;
  traffic_formatted: string;
  checkin_date: string;
  month: string;
  created_at: number;
  created_at_formatted: string;
  time_formatted: string;
}

interface Statistics {
  today_date: string;
  today_user_count: number;
  today_traffic: number;
  today_traffic_formatted: string;
  yesterday_user_count: number;
  yesterday_traffic_formatted: string;
  month_user_count: number;
  month_traffic: number;
  month_traffic_formatted: string;
  total_count: number;
  total_traffic: number;
  total_traffic_formatted: string;
}

export default function CheckinLogsPage() {
  const [loading, setLoading] = useState(false);
  const [list, setList] = useState<CheckinItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);

  // 筛选条件
  const [emailFilter, setEmailFilter] = useState('');
  const [userIdFilter, setUserIdFilter] = useState('');
  const [dateFilter, setDateFilter] = useState<Dayjs | null>(null);
  const [activeFilteredUser, setActiveFilteredUser] = useState<{ id: number; email: string } | null>(null);

  // 统计数据
  const [statistics, setStatistics] = useState<Statistics>({
    today_date: '',
    today_user_count: 0,
    today_traffic: 0,
    today_traffic_formatted: '0 B',
    yesterday_user_count: 0,
    yesterday_traffic_formatted: '0 B',
    month_user_count: 0,
    month_traffic: 0,
    month_traffic_formatted: '0 B',
    total_count: 0,
    total_traffic: 0,
    total_traffic_formatted: '0 B',
  });

  const fetchData = useCallback(
    async (targetPage = page, targetPageSize = pageSize) => {
      setLoading(true);
      try {
        const params: Record<string, any> = {
          current: targetPage,
          pageSize: targetPageSize,
        };
        if (emailFilter.trim()) params.email = emailFilter.trim();
        if (userIdFilter.trim()) params.user_id = userIdFilter.trim();
        if (dateFilter) params.date = dateFilter.format('YYYY-MM-DD');

        const res: any = await get(adminPath('/checkin/fetch'), params);
        if (res && res.data) {
          setList(res.data);
          setTotal(res.total || 0);
          if (res.statistics) {
            setStatistics(res.statistics);
          }
        }
      } catch (err) {
        console.error('获取每日签到记录失败:', err);
      } finally {
        setLoading(false);
      }
    },
    [page, pageSize, emailFilter, userIdFilter, dateFilter]
  );

  useEffect(() => {
    fetchData(1, pageSize);
  }, [fetchData, pageSize]);

  const handleSearch = () => {
    setPage(1);
    fetchData(1, pageSize);
  };

  const handleReset = () => {
    setEmailFilter('');
    setUserIdFilter('');
    setDateFilter(null);
    setActiveFilteredUser(null);
    setPage(1);
    setLoading(true);
    get(adminPath('/checkin/fetch'), { current: 1, pageSize })
      .then((res: any) => {
        if (res && res.data) {
          setList(res.data);
          setTotal(res.total || 0);
          if (res.statistics) setStatistics(res.statistics);
        }
      })
      .finally(() => setLoading(false));
  };

  const handleQuickDate = (daysOffset: number) => {
    const target = dayjs().add(daysOffset, 'day');
    setDateFilter(target);
    setPage(1);
  };

  const filterByAccount = (userId: number, email: string) => {
    setUserIdFilter(String(userId));
    setEmailFilter('');
    setActiveFilteredUser({ id: userId, email });
    setPage(1);
  };

  const columns = [
    {
      title: 'ID',
      dataIndex: 'id',
      width: 75,
      align: 'center' as const,
    },
    {
      title: '签到时间 (精确到秒)',
      key: 'created_at',
      width: 190,
      render: (_: any, record: CheckinItem) => (
        <Space size={6}>
          <ClockCircleOutlined style={{ color: '#8c8c8c' }} />
          <Text style={{ fontFamily: 'monospace', fontWeight: 600 }}>
            {record.created_at_formatted}
          </Text>
        </Space>
      ),
    },
    {
      title: '用户账号 / UID',
      key: 'user',
      minWidth: 220,
      render: (_: any, record: CheckinItem) => (
        <Space size={6} wrap>
          <Text
            strong
            style={{
              color: '#1677ff',
              cursor: 'pointer',
              textDecoration: 'underline dashed',
            }}
            onClick={() => filterByAccount(record.user_id, record.email)}
            title="点击仅查看该用户的签到记录"
          >
            {record.email}
          </Text>
          <Tag color="default" style={{ fontSize: 11 }}>
            UID: {record.user_id}
          </Tag>
          {record.telegram_id && (
            <Tag color="cyan" style={{ fontSize: 11 }}>
              TG已绑
            </Tag>
          )}
        </Space>
      ),
    },
    {
      title: '当时套餐',
      dataIndex: 'plan_name',
      width: 160,
      render: (val: string) => <Tag color="blue">{val || '有效套餐'}</Tag>,
    },
    {
      title: '获得流量奖励',
      key: 'traffic',
      width: 150,
      align: 'center' as const,
      render: (_: any, record: CheckinItem) => (
        <Tag color="success" style={{ fontWeight: 'bold', fontSize: 12, padding: '2px 8px' }}>
          +{record.traffic_formatted}
        </Tag>
      ),
    },
    {
      title: '归属日期',
      dataIndex: 'checkin_date',
      width: 120,
      align: 'center' as const,
      render: (val: string) => <Text type="secondary">{val}</Text>,
    },
    {
      title: '操作',
      key: 'action',
      width: 110,
      align: 'center' as const,
      render: (_: any, record: CheckinItem) => (
        <Button
          type="link"
          size="small"
          onClick={() => filterByAccount(record.user_id, record.email)}
        >
          TA的签到
        </Button>
      ),
    },
  ];

  return (
    <AdminLayout>
      <div style={{ padding: '4px 0 24px' }}>
        {/* 顶部统计卡片 */}
        <Row gutter={[16, 16]} style={{ marginBottom: 16 }}>
          <Col xs={24} sm={12} md={6}>
            <Card variant="outlined" style={{ borderRadius: 12 }}>
              <Statistic
                title="今日签到人数"
                value={statistics.today_user_count || 0}
                suffix="人"
                valueStyle={{ color: '#1677ff', fontWeight: 800 }}
                prefix={<CalendarOutlined />}
              />
              <div style={{ fontSize: 12, color: '#8c8c8c', marginTop: 8 }}>
                较昨日: {statistics.yesterday_user_count || 0} 人
              </div>
            </Card>
          </Col>

          <Col xs={24} sm={12} md={6}>
            <Card variant="outlined" style={{ borderRadius: 12 }}>
              <Statistic
                title="今日赠送流量"
                value={statistics.today_traffic_formatted || '0 B'}
                valueStyle={{ color: '#52c41a', fontWeight: 800 }}
                prefix={<GiftOutlined />}
              />
              <div style={{ fontSize: 12, color: '#8c8c8c', marginTop: 8 }}>
                昨日赠送: {statistics.yesterday_traffic_formatted || '0 B'}
              </div>
            </Card>
          </Col>

          <Col xs={24} sm={12} md={6}>
            <Card variant="outlined" style={{ borderRadius: 12 }}>
              <Statistic
                title="本月签到人次"
                value={statistics.month_user_count || 0}
                suffix="次"
                valueStyle={{ color: '#faad14', fontWeight: 800 }}
                prefix={<BarChartOutlined />}
              />
              <div style={{ fontSize: 12, color: '#8c8c8c', marginTop: 8 }}>
                本月累计送: {statistics.month_traffic_formatted || '0 B'}
              </div>
            </Card>
          </Col>

          <Col xs={24} sm={12} md={6}>
            <Card variant="outlined" style={{ borderRadius: 12 }}>
              <Statistic
                title="历史累计赠送"
                value={statistics.total_traffic_formatted || '0 B'}
                valueStyle={{ color: '#722ed1', fontWeight: 800 }}
                prefix={<GiftOutlined />}
              />
              <div style={{ fontSize: 12, color: '#8c8c8c', marginTop: 8 }}>
                历史累计打卡: {statistics.total_count || 0} 人次
              </div>
            </Card>
          </Col>
        </Row>

        {/* 主数据卡片 */}
        <Card
          title={
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <CalendarOutlined style={{ color: '#1677ff' }} />
              <span>每日打卡签到记录明细</span>
              <Tag color="default">共 {total} 条</Tag>
            </div>
          }
          extra={
            <Button icon={<ReloadOutlined />} onClick={() => fetchData(page, pageSize)}>
              刷新
            </Button>
          }
          style={{ borderRadius: 12 }}
        >
          {/* 筛选栏 */}
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, marginBottom: 16 }}>
            <Input
              placeholder="搜索用户邮箱"
              value={emailFilter}
              onChange={(e) => setEmailFilter(e.target.value)}
              onPressEnter={handleSearch}
              allowClear
              style={{ width: 200 }}
            />
            <Input
              placeholder="UID"
              value={userIdFilter}
              onChange={(e) => setUserIdFilter(e.target.value)}
              onPressEnter={handleSearch}
              allowClear
              style={{ width: 110 }}
            />
            <DatePicker
              placeholder="选择签到日期"
              value={dateFilter}
              onChange={(val) => setDateFilter(val)}
              format="YYYY-MM-DD"
              style={{ width: 160 }}
            />
            <Space>
              <Button type="primary" icon={<SearchOutlined />} onClick={handleSearch}>
                查询
              </Button>
              <Button icon={<ReloadOutlined />} onClick={handleReset}>
                重置
              </Button>
              <Button
                type={dateFilter?.format('YYYY-MM-DD') === dayjs().format('YYYY-MM-DD') ? 'primary' : 'default'}
                onClick={() => handleQuickDate(0)}
              >
                今日
              </Button>
              <Button
                type={dateFilter?.format('YYYY-MM-DD') === dayjs().subtract(1, 'day').format('YYYY-MM-DD') ? 'primary' : 'default'}
                onClick={() => handleQuickDate(-1)}
              >
                昨日
              </Button>
            </Space>
          </div>

          {/* 单账号筛选提示横幅 */}
          {activeFilteredUser && (
            <div
              style={{
                padding: '8px 14px',
                marginBottom: 16,
                backgroundColor: '#e6f4ff',
                border: '1px solid #91caff',
                borderRadius: 8,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
              }}
            >
              <Text>
                🔍 正在查看用户 <strong>{activeFilteredUser.email} (UID: {activeFilteredUser.id})</strong> 的全部签到记录
              </Text>
              <Button
                type="link"
                size="small"
                icon={<CloseCircleOutlined />}
                onClick={handleReset}
              >
                清除筛选
              </Button>
            </div>
          )}

          {/* 表格 */}
          <Table
            rowKey="id"
            loading={loading}
            dataSource={list}
            columns={columns}
            pagination={{
              current: page,
              pageSize: pageSize,
              total: total,
              showSizeChanger: true,
              pageSizeOptions: ['10', '20', '50', '100'],
              showTotal: (t) => `共 ${t} 条记录`,
              onChange: (p, ps) => {
                setPage(p);
                setPageSize(ps);
                fetchData(p, ps);
              },
            }}
            scroll={{ x: 900 }}
          />
        </Card>
      </div>
    </AdminLayout>
  );
}
