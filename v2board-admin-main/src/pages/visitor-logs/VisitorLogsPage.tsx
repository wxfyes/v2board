import React, { useState, useEffect } from 'react';
import {
  Card,
  Form,
  Input,
  Select,
  Button,
  Table,
  Tag,
  Tooltip,
  Modal,
  message,
  Space,
  Typography,
  Pagination,
  Empty,
  Popconfirm
} from 'antd';
import {
  SearchOutlined,
  ReloadOutlined,
  DeleteOutlined,
  CopyOutlined,
  StopOutlined,
  CheckCircleOutlined
} from '@ant-design/icons';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import dayjs from 'dayjs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useMobile } from '@/hooks/useMobile';
import copy from 'copy-to-clipboard';

const { Text } = Typography;

// 彩色/经典浏览器与操作系统 SVG 图标组件
const BrowserIcon: React.FC<{ name?: string }> = ({ name = '' }) => {
  const n = name.toLowerCase();
  if (n.includes('chrome')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <circle cx="16" cy="16" r="14" fill="#fff" />
        <path d="M16 2A14 14 0 0 0 3.8 9l6.5 11.3A7 7 0 0 1 16 9h13.9A14 14 0 0 0 16 2z" fill="#EA4335" />
        <path d="M29.9 9H16a7 7 0 0 0-6.1 3.5L3.8 23A14 14 0 0 0 16 30a14 14 0 0 0 12.1-7l-6-10.4A7 7 0 0 1 29.9 9z" fill="#FBBC05" />
        <path d="M16 30a14 14 0 0 0 12.1-7l-6.5-11.3a7 7 0 0 1-5.6 11.3v-6a7 7 0 0 1-6.1-3.5L3.8 23A14 14 0 0 0 16 30z" fill="#34A853" />
        <circle cx="16" cy="16" r="5.5" fill="#4285F4" />
      </svg>
    );
  }
  if (n.includes('edge')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <path d="M28.5 18.5C28.2 24.5 23 29.5 16.5 29.5 8.5 29.5 2 23 2 15S8.5 2 16.5 2c3.5 0 6.5 1.5 8.5 4-3.5 0-7 2-8.5 5.5-1.5 3.5 0 7 3.5 8.5 3.5 1.5 7.5.5 8.5-1.5z" fill="#0078D7" />
        <path d="M16.5 29.5c6.5 0 11.7-5 12-11 0-3-2-5.5-5.5-5.5-4 0-7 3-7 7 0 5.5 4 9.5 9.5 9.5z" fill="#00BCF2" />
        <path d="M14 19c-3.5 0-6-2.5-6-6 0-4.5 3.5-8 8.5-8 3.5 0 6 2 7 4.5-2.5-1-5.5-1-8 1.5-2 2-2.5 5-1.5 8z" fill="#25B96E" />
      </svg>
    );
  }
  if (n.includes('safari')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <circle cx="16" cy="16" r="14" fill="#006CFF" />
        <circle cx="16" cy="16" r="12" fill="#fff" />
        <circle cx="16" cy="16" r="11" fill="#006CFF" />
        <polygon points="16,6 18.5,13.5 26,16 18.5,18.5 16,26 13.5,18.5 6,16 13.5,13.5" fill="#FF2638" />
        <polygon points="16,16 18.5,13.5 26,16" fill="#fff" />
        <polygon points="16,16 13.5,18.5 6,16" fill="#fff" />
      </svg>
    );
  }
  if (n.includes('firefox')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <circle cx="16" cy="16" r="14" fill="#FF7139" />
        <circle cx="15" cy="15" r="9" fill="#802090" />
        <path d="M26 12c-1.5-3-4-5-7-5 2 1.5 3 4 2 6-1 2-3 3-5 2s-3-3-2-5c-3 1-5 4-5 8 0 6 5 10 11 10s10-4 10-10c0-2-.5-4-1.5-6z" fill="#FF9400" />
      </svg>
    );
  }
  if (n.includes('wechat') || n.includes('micromessenger')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <circle cx="12" cy="12" r="10" fill="#07C160" />
        <circle cx="21" cy="20" r="8" fill="#09B056" />
        <circle cx="9" cy="10" r="1.5" fill="#fff" />
        <circle cx="15" cy="10" r="1.5" fill="#fff" />
        <circle cx="19" cy="19" r="1.2" fill="#fff" />
        <circle cx="24" cy="19" r="1.2" fill="#fff" />
      </svg>
    );
  }
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" style={{ verticalAlign: 'middle', marginRight: 6 }}>
      <circle cx="12" cy="12" r="10" fill="none" stroke="#888" strokeWidth="2" />
      <ellipse cx="12" cy="12" rx="4" ry="10" fill="none" stroke="#888" strokeWidth="1.5" />
      <line x1="2" y1="12" x2="22" y2="12" stroke="#888" strokeWidth="1.5" />
    </svg>
  );
};

const OsIcon: React.FC<{ name?: string }> = ({ name = '' }) => {
  const n = name.toLowerCase();
  if (n.includes('win')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <path d="M2 5.5l11-1.5v11H2V5.5zm13-1.8L29 2v13H15V3.7zM2 17h11v11l-11-1.5V17zm13 0h14v13l-14-2V17z" fill="#0078D7" />
      </svg>
    );
  }
  if (n.includes('android')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <path d="M9 13v10c0 .6.4 1 1 1h2v4a1.5 1.5 0 0 0 3 0v-4h2v4a1.5 1.5 0 0 0 3 0v-4h2c.6 0 1-.4 1-1V13H9z" fill="#78C257" />
        <path d="M10.5 11h11a5.5 5.5 0 0 0-11 0z" fill="#78C257" />
        <line x1="12" y1="5" x2="13.5" y2="7.5" stroke="#78C257" strokeWidth="1.5" strokeLinecap="round" />
        <line x1="20" y1="5" x2="18.5" y2="7.5" stroke="#78C257" strokeWidth="1.5" strokeLinecap="round" />
        <circle cx="13" cy="9.5" r="1" fill="#fff" />
        <circle cx="19" cy="9.5" r="1" fill="#fff" />
        <rect x="6" y="13" width="2" height="7" rx="1" fill="#78C257" />
        <rect x="24" y="13" width="2" height="7" rx="1" fill="#78C257" />
      </svg>
    );
  }
  if (n.includes('mac') || n.includes('ios') || n.includes('apple')) {
    const isDark = n.includes('ios');
    return (
      <svg width="18" height="18" viewBox="0 0 170 170" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <path
          d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.69-3.04-7.67-7.81-11.96-14.34-7.5-11.39-13.3-24.62-17.4-39.69-4.1-15.07-6.15-28.77-6.15-41.11 0-14.35 3.42-26.47 10.26-36.35 6.84-9.88 15.63-14.93 26.36-15.15 4.36 0 9.28 1.15 14.75 3.44 5.48 2.29 9.39 3.51 11.75 3.65 2.12 0 6.15-1.33 12.08-3.98 5.94-2.65 11.25-3.86 15.93-3.65 11.83.64 21.32 4.96 28.46 12.96-10.45 6.34-15.54 15.08-15.28 26.23.27 8.92 3.66 16.34 10.18 22.28 6.52 5.94 14.19 9.3 23 10.08-2.12 6.52-4.77 13.04-7.96 19.57zM119.22 31.84c0-7.39 2.65-14.18 7.96-20.37 5.3-6.19 11.85-10.02 19.64-11.47.27 1.09.41 2.18.41 3.26 0 7.39-2.73 14.38-8.2 20.97-5.46 6.59-12.06 10.37-19.81 11.33v-3.72z"
          fill={isDark ? '#000' : '#495057'}
        />
      </svg>
    );
  }
  if (n.includes('linux')) {
    return (
      <svg width="18" height="18" viewBox="0 0 32 32" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <ellipse cx="16" cy="18" rx="8" ry="10" fill="#333" />
        <ellipse cx="16" cy="19" rx="5" ry="7" fill="#fff" />
        <circle cx="16" cy="9" r="6" fill="#333" />
        <circle cx="14" cy="8.5" r="1" fill="#fff" />
        <circle cx="18" cy="8.5" r="1" fill="#fff" />
        <polygon points="16,10 14,13 18,13" fill="#FFA500" />
      </svg>
    );
  }
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" style={{ verticalAlign: 'middle', marginRight: 6 }}>
      <rect x="3" y="3" width="18" height="18" rx="3" fill="#6c757d" />
      <text x="12" y="16" fontSize="11" fill="#fff" textAnchor="middle" fontWeight="bold">OS</text>
    </svg>
  );
};

const DeviceIcon: React.FC<{ type?: string }> = ({ type = 'Desktop' }) => {
  const t = type.toLowerCase();
  if (t === 'mobile') {
    return (
      <svg width="18" height="18" viewBox="0 0 24 24" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <rect x="6" y="2" width="12" height="20" rx="3" fill="#1890ff" />
        <rect x="8" y="5" width="8" height="12" rx="1" fill="#fff" />
        <circle cx="12" cy="19" r="1" fill="#fff" />
      </svg>
    );
  }
  if (t === 'tablet') {
    return (
      <svg width="18" height="18" viewBox="0 0 24 24" style={{ verticalAlign: 'middle', marginRight: 6 }}>
        <rect x="4" y="2" width="16" height="20" rx="2" fill="#13c2c2" />
        <rect x="6" y="4" width="12" height="14" rx="1" fill="#fff" />
        <circle cx="12" cy="19.5" r="0.8" fill="#fff" />
      </svg>
    );
  }
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" style={{ verticalAlign: 'middle', marginRight: 6 }}>
      <rect x="3" y="3" width="18" height="12" rx="2" fill="#1890ff" />
      <rect x="5" y="5" width="14" height="8" rx="1" fill="#fff" />
      <path d="M9 18h6v2H9z" fill="#1890ff" />
      <line x1="12" y1="15" x2="12" y2="18" stroke="#1890ff" strokeWidth="2" />
    </svg>
  );
};

export default function VisitorLogsPage() {
  const isMobile = useMobile();
  const [form] = Form.useForm();
  const [list, setList] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(20);

  const fetchLogs = async (p = page, size = pageSize, extraFilter?: any) => {
    setLoading(true);
    try {
      const values = extraFilter || form.getFieldsValue();
      const res: any = await get(adminPath('/system/getVisitorLog'), {
        current: p,
        page_size: size,
        ...values
      });
      if (res && res.data) {
        setList(res.data || []);
        setTotal(res.total || 0);
      }
    } catch (e: any) {
      message.error(e?.message || '获取访客记录失败');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs(1, pageSize);
  }, []);

  const handleSearch = () => {
    setPage(1);
    fetchLogs(1, pageSize);
  };

  const handleReset = () => {
    form.resetFields();
    setPage(1);
    fetchLogs(1, pageSize, {});
  };

  const handleClearAll = async () => {
    try {
      await post(adminPath('/system/clearVisitorLog'));
      message.success('访客记录已清空');
      fetchLogs(1, pageSize, {});
    } catch (e: any) {
      message.error(e?.message || '清空失败');
    }
  };

  const handleBanIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/banIp'), { ip });
      message.success(`已封禁 IP: ${ip}`);
      fetchLogs(page, pageSize);
    } catch (e: any) {
      message.error(e?.message || '操作失败');
    }
  };

  const handleUnbanIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/removeBanIp'), { ip });
      message.success(`已解封 IP: ${ip}`);
      fetchLogs(page, pageSize);
    } catch (e: any) {
      message.error(e?.message || '操作失败');
    }
  };

  const copyIp = (ip: string) => {
    copy(ip);
    message.success(`已复制 IP: ${ip}`);
  };

  const formatTime = (timestamp: number) => {
    if (!timestamp) return '-';
    return dayjs(timestamp * 1000).format('YYYY-MM-DD HH:mm:ss');
  };

  // 100% 还原图一的表格列
  const columns: any[] = [
    {
      title: 'IP',
      dataIndex: 'ip',
      key: 'ip',
      width: 170,
      render: (ip: string, record: any) => (
        <span
          style={{
            color: '#2b8a3e',
            fontWeight: 500,
            cursor: 'pointer',
            fontSize: 14,
            textDecoration: 'none'
          }}
          onClick={() => copyIp(ip)}
          title="点击复制 IP"
        >
          {ip}
        </span>
      )
    },
    {
      title: '访问次数',
      dataIndex: 'visit_count',
      key: 'visit_count',
      width: 100,
      align: 'left',
      render: (count: number) => (
        <span style={{ fontSize: 14, color: '#333', fontWeight: 500 }}>
          {count || 1}
        </span>
      )
    },
    {
      title: '位置',
      key: 'location',
      width: 150,
      render: (_: any, record: any) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13 }}>
          <span style={{ fontSize: 16 }}>{record.flag || '🇨🇳'}</span>
          <span>{record.city || record.province || record.country || '未知'}</span>
        </div>
      )
    },
    {
      title: '浏览器',
      dataIndex: 'browser',
      key: 'browser',
      width: 140,
      render: (browser: string) => (
        <div style={{ display: 'flex', alignItems: 'center', fontSize: 13 }}>
          <BrowserIcon name={browser} />
          <span>{browser || 'Chrome'}</span>
        </div>
      )
    },
    {
      title: '操作系统',
      dataIndex: 'os',
      key: 'os',
      width: 140,
      render: (os: string) => (
        <div style={{ display: 'flex', alignItems: 'center', fontSize: 13 }}>
          <OsIcon name={os} />
          <span>{os || 'Windows'}</span>
        </div>
      )
    },
    {
      title: '设备',
      dataIndex: 'device',
      key: 'device',
      width: 130,
      render: (device: string) => (
        <div style={{ display: 'flex', alignItems: 'center', fontSize: 13 }}>
          <DeviceIcon type={device} />
          <span>{device || 'Desktop'}</span>
        </div>
      )
    },
    {
      title: '最近访问',
      dataIndex: 'updated_at',
      key: 'updated_at',
      width: 170,
      render: (time: number, record: any) => (
        <Tooltip title={record.last_url ? `最后访问: ${record.last_url}` : '无路径记录'}>
          <span style={{ fontSize: 12, color: '#888' }}>{formatTime(time)}</span>
        </Tooltip>
      )
    },
    {
      title: '操作',
      key: 'action',
      width: 120,
      align: 'center',
      render: (_: any, record: any) => (
        <Space size={4}>
          {record.is_banned === 1 ? (
            <Button
              size="small"
              onClick={() => handleUnbanIp(record.ip)}
              style={{ color: '#52c41a', borderColor: '#52c41a' }}
            >
              已封禁
            </Button>
          ) : (
            <Button
              size="small"
              danger
              onClick={() => handleBanIp(record.ip)}
            >
              封禁
            </Button>
          )}
        </Space>
      )
    }
  ];

  return (
    <AdminLayout title="访客记录">
      <div style={{ padding: isMobile ? '12px 8px' : '24px' }}>
        <Card
          title={
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
              <span>🌐 网站访客访问记录</span>
              <span style={{ fontSize: 12, color: '#999', fontWeight: 'normal' }}>
                自动聚合独立 IP 访问环境与频次 · 定期自动清理
              </span>
            </div>
          }
          className="box-card"
          bodyStyle={{ padding: isMobile ? 12 : 24 }}
        >
          {/* 搜索过滤栏 */}
          <Form form={form} layout={isMobile ? 'vertical' : 'inline'} style={{ marginBottom: 16 }}>
            <div style={{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', gap: 8, marginBottom: 8, width: isMobile ? '100%' : 'auto' }}>
              <Form.Item name="ip" style={{ margin: 0, flex: 1 }}>
                <Input placeholder="IP 地址" style={{ width: isMobile ? '100%' : 180 }} allowClear onPressEnter={handleSearch} />
              </Form.Item>
              <Form.Item name="location" style={{ margin: 0, flex: 1 }}>
                <Input placeholder="地区 / 城市" style={{ width: isMobile ? '100%' : 150 }} allowClear onPressEnter={handleSearch} />
              </Form.Item>
              <Form.Item name="device" style={{ margin: 0, width: isMobile ? '100%' : 130 }}>
                <Select placeholder="设备类型" allowClear onChange={handleSearch}>
                  <Select.Option value="Desktop">Desktop</Select.Option>
                  <Select.Option value="Mobile">Mobile</Select.Option>
                  <Select.Option value="Tablet">Tablet</Select.Option>
                </Select>
              </Form.Item>
            </div>

            <Form.Item style={{ margin: 0 }}>
              <Space wrap size={[6, 6]}>
                <Button type="primary" icon={<SearchOutlined />} onClick={handleSearch}>
                  查询
                </Button>
                <Button icon={<ReloadOutlined />} onClick={handleReset}>
                  重置
                </Button>
                <Popconfirm
                  title="清空日志警告"
                  description="确认要清空全部访客访问日志吗？此操作无法恢复！"
                  onConfirm={handleClearAll}
                  okText="确认清空"
                  cancelText="取消"
                  okButtonProps={{ danger: true }}
                >
                  <Button danger icon={<DeleteOutlined />}>
                    清空日志
                  </Button>
                </Popconfirm>
              </Space>
            </Form.Item>
          </Form>

          {/* 移动端卡片流 */}
          {isMobile ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {list.map((item) => (
                <Card
                  key={item.id}
                  size="small"
                  style={{
                    borderRadius: 8,
                    boxShadow: '0 1px 3px rgba(0,0,0,0.05)',
                    border: '1px solid #f0f0f0',
                    background: '#fafafa'
                  }}
                  bodyStyle={{ padding: '12px 14px' }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                    <span
                      style={{ color: '#2b8a3e', fontWeight: 600, fontSize: 15, cursor: 'pointer' }}
                      onClick={() => copyIp(item.ip)}
                    >
                      {item.ip}
                    </span>
                    <Tag color="blue">{item.visit_count || 1} 次访问</Tag>
                  </div>

                  <div style={{ fontSize: 13, color: '#555', marginBottom: 4, display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span>{item.flag || '🇨🇳'}</span>
                    <span>{item.city || item.province || item.country || '未知'}</span>
                  </div>

                  <div style={{ fontSize: 12, color: '#777', display: 'flex', gap: 12, marginBottom: 6, flexWrap: 'wrap' }}>
                    <div style={{ display: 'flex', alignItems: 'center' }}>
                      <BrowserIcon name={item.browser} />
                      <span>{item.browser || 'Chrome'}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center' }}>
                      <OsIcon name={item.os} />
                      <span>{item.os || 'Windows'}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center' }}>
                      <DeviceIcon type={item.device} />
                      <span>{item.device || 'Desktop'}</span>
                    </div>
                  </div>

                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#999', paddingTop: 6, borderTop: '1px solid #f0f0f0' }}>
                    <span>{formatTime(item.updated_at)}</span>
                    <Space size={6}>
                      {item.is_banned === 1 ? (
                        <Button size="small" onClick={() => handleUnbanIp(item.ip)}>已封锁</Button>
                      ) : (
                        <Button size="small" danger onClick={() => handleBanIp(item.ip)}>封禁IP</Button>
                      )}
                    </Space>
                  </div>
                </Card>
              ))}
              {list.length === 0 && !loading && (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无访客记录" />
              )}
              <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}>
                <Pagination
                  simple
                  current={page}
                  pageSize={pageSize}
                  total={total}
                  onChange={(p) => {
                    setPage(p);
                    fetchLogs(p, pageSize);
                  }}
                />
              </div>
            </div>
          ) : (
            <Table
              rowKey="id"
              columns={columns}
              dataSource={list}
              loading={loading}
              bordered={false}
              pagination={{
                current: page,
                pageSize: pageSize,
                total: total,
                showSizeChanger: true,
                showQuickJumper: true,
                showTotal: (t) => `共 ${t} 个独立访客 IP`,
                pageSizeOptions: ['10', '20', '50', '100'],
                onChange: (p, size) => {
                  setPage(p);
                  setPageSize(size);
                  fetchLogs(p, size);
                }
              }}
            />
          )}
        </Card>
      </div>
    </AdminLayout>
  );
}
