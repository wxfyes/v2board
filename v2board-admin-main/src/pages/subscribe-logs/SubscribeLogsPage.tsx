import { Card, Table, message, Modal, Input, Button, Space, Tag, Tooltip, Typography, Pagination, Empty, Popover } from 'antd';
import { SearchOutlined, ReloadOutlined, TrophyOutlined, LinkOutlined, MonitorOutlined, CloseCircleOutlined } from '@ant-design/icons';
import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import { AdminLayout } from '@/layouts/AdminLayout';
import { UserDetailModal } from '../security-audit/SecurityAuditPage';
import { useMobile } from '@/hooks/useMobile';

const { Text } = Typography;

// 单账号登录 IP 记录快捷浮窗
function UserLoginLogsModal({
  visible,
  user,
  onCancel
}: {
  visible: boolean;
  user: { id: number; email: string } | null;
  onCancel: () => void;
}) {
  const isMobile = useMobile();
  const navigate = useNavigate();
  const [logs, setLogs] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const pageSize = 10;

  const fetchUserLogins = useCallback(async (p = 1) => {
    if (!user) return;
    setLoading(true);
    try {
      const res: any = await get(adminPath('/system/getLoginLog'), {
        user_id: user.id,
        email: user.email,
        current: p,
        page_size: pageSize
      });
      if (res && res.data) {
        setLogs(res.data);
        setTotal(res.total || 0);
      }
    } catch (e) {
      // handled
    } finally {
      setLoading(false);
    }
  }, [user]);

  useEffect(() => {
    if (visible && user) {
      setPage(1);
      fetchUserLogins(1);
    }
  }, [visible, user, fetchUserLogins]);

  const formatTime = (timestamp: number) => {
    if (!timestamp) return '-';
    const d = new Date(timestamp * 1000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
  };

  return (
    <Modal
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span>📋 账号登录 IP 记录</span>
          <Tag color="blue">{user?.email || '未知用户'}</Tag>
          <Tag color="default">UID: {user?.id}</Tag>
        </div>
      }
      open={visible}
      onCancel={onCancel}
      width={isMobile ? '95%' : 750}
      footer={[
        <Button
          key="goto"
          onClick={() => {
            onCancel();
            navigate(`/login-logs?user_id=${user?.id}&email=${encodeURIComponent(user?.email || '')}`);
          }}
        >
          前往登录记录大厅 &gt;
        </Button>,
        <Button key="close" type="primary" onClick={onCancel}>
          关闭
        </Button>
      ]}
      destroyOnClose
    >
      <div style={{ marginBottom: 12, fontSize: 13, color: '#666' }}>
        查看该账号的历史登录 IP、归属地、终端及登录状态（共 <strong>{total}</strong> 条记录）：
      </div>

      {isMobile ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, maxHeight: 420, overflowY: 'auto' }}>
          {logs.map((log: any, idx: number) => (
            <div
              key={log.id || idx}
              style={{
                background: '#fafafa',
                border: '1px solid #f0f0f0',
                borderRadius: 6,
                padding: '10px 12px',
                fontSize: 12
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                <span style={{ fontWeight: 600, color: '#333' }}>
                  <code style={{ background: '#eee', padding: '2px 5px', borderRadius: 4 }}>{log.ip}</code>
                  {log.location && <span style={{ marginLeft: 6, color: '#888', fontWeight: 'normal' }}>{log.location}</span>}
                </span>
                <Tag color={log.type && log.type.includes('成功') ? 'success' : 'error'} style={{ margin: 0 }}>
                  {log.type || '未知'}
                </Tag>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', color: '#999', fontSize: 11 }}>
                <span>{formatTime(log.created_at)}</span>
              </div>
              {log.ua && (
                <div style={{ color: '#aaa', fontSize: 10, marginTop: 4, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  UA: {log.ua}
                </div>
              )}
            </div>
          ))}
          {logs.length === 0 && !loading && (
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无该账号的登录记录" />
          )}
        </div>
      ) : (
        <Table
          dataSource={logs}
          rowKey={(r, i) => r.id || i}
          loading={loading}
          pagination={false}
          size="small"
          scroll={{ y: 360 }}
          columns={[
            {
              title: '登录 IP',
              dataIndex: 'ip',
              render: (ip: string, r: any) => (
                <div>
                  <code>{ip}</code>
                  {r.location && <div style={{ fontSize: 11, color: '#888' }}>{r.location}</div>}
                </div>
              )
            },
            {
              title: '状态',
              dataIndex: 'type',
              width: 90,
              render: (t: string) => <Tag color={t && t.includes('成功') ? 'success' : 'error'}>{t || '-'}</Tag>
            },
            {
              title: '登录时间',
              dataIndex: 'created_at',
              width: 170,
              render: (t: number) => formatTime(t)
            },
            {
              title: '客户端 UA',
              dataIndex: 'ua',
              ellipsis: true,
              render: (ua: string) => <Tooltip title={ua}><span>{ua}</span></Tooltip>
            }
          ]}
        />
      )}

      <div style={{ display: 'flex', justifyContent: 'center', marginTop: 12 }}>
        <Pagination
          simple
          current={page}
          pageSize={pageSize}
          total={total}
          onChange={(p) => {
            setPage(p);
            fetchUserLogins(p);
          }}
        />
      </div>
    </Modal>
  );
}

export default function SubscribeLogsPage() {
  const isMobile = useMobile();
  const [loading, setLoading] = useState(false);
  const [data, setData] = useState<any[]>([]);
  const [total, setTotal] = useState(0);

  const [query, setQuery] = useState({
    current: 1,
    page_size: 20,
    user_id: '',
    ip: '',
    ua: ''
  });

  // 单账号登录 IP 记录弹窗状态
  const [loginModalVisible, setLoginModalVisible] = useState(false);
  const [targetUser, setTargetUser] = useState<{ id: number; email: string } | null>(null);

  const openUserLoginModal = (user: { id: number; email: string }) => {
    setTargetUser(user);
    setLoginModalVisible(true);
  };

  const fetchLogs = useCallback(async (params = query) => {
    setLoading(true);
    try {
      const res = await get<any>(adminPath('/system/getSubscribeLog'), params);
      if (res && res.data) {
        setData(res.data.data || res.data || []);
        setTotal(res.data.total || res.total || 0);
      }
    } catch (err: any) {
      // API error handled globally or via request instance
    } finally {
      setLoading(false);
    }
  }, [query]);

  const getTopUsers = async () => {
    setLoading(true);
    try {
      const res = await get<any>(adminPath('/system/getTopSubscribeUsers'));
      if (res && res.data) {
        setData(res.data.data || res.data || []);
        setTotal(res.data.total || res.total || 0);
      }
    } catch (err: any) {
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // 检查是否有 URL 预填充参数
    let initialUserId = '';
    const hash = window.location.hash;
    const qIndex = hash.indexOf('?');
    if (qIndex !== -1) {
      const sp = new URLSearchParams(hash.slice(qIndex));
      initialUserId = sp.get('user_id') || '';
    } else {
      initialUserId = new URLSearchParams(window.location.search).get('user_id') || '';
    }

    if (initialUserId) {
      const initQuery = { ...query, user_id: initialUserId };
      setQuery(initQuery);
      fetchLogs(initQuery);
    } else {
      fetchLogs();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleFilter = () => {
    const newQuery = { ...query, current: 1 };
    setQuery(newQuery);
    fetchLogs(newQuery);
  };

  const resetFilter = () => {
    const newQuery = { current: 1, page_size: 20, user_id: '', ip: '', ua: '' };
    setQuery(newQuery);
    fetchLogs(newQuery);
  };

  const filterByUser = (userId: string | number) => {
    const newQuery = { ...query, user_id: String(userId), current: 1 };
    setQuery(newQuery);
    fetchLogs(newQuery);
  };

  const handleTableChange = (pagination: any) => {
    const newQuery = { ...query, current: pagination.current, page_size: pagination.pageSize };
    setQuery(newQuery);
    fetchLogs(newQuery);
  };

  const showUserDetail = (userId: number) => { setActiveUserId(userId); setUserDetailVisible(true); };

  const formatTime = (timestamp: number) => {
    if (!timestamp) return '-';
    const d = new Date(timestamp * 1000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
  };

  const columns = [
    {
      title: 'User ID',
      dataIndex: 'user_id',
      key: 'user_id',
      width: 100,
      align: 'center' as const,
      render: (val: number) => (
        <a onClick={() => showUserDetail(val)}>#{val}</a>
      )
    },
    {
      title: '邮箱 / 账号',
      dataIndex: 'email',
      key: 'email',
      render: (val: string, record: any) => (
        <a onClick={() => showUserDetail(record.user_id)}>{val || '未知用户'}</a>
      )
    },
    {
      title: '识别客户端',
      dataIndex: 'type',
      key: 'type',
      width: 120,
      align: 'center' as const,
      render: (val: string) => <Tag>{val || '未知'}</Tag>
    },
    {
      title: 'IP 地址',
      dataIndex: 'ip',
      key: 'ip',
      width: 160,
      align: 'center' as const,
      render: (val: string) => <div style={{ wordWrap: 'break-word', wordBreak: 'break-all' }}>{val}</div>
    },
    {
      title: '归属地',
      dataIndex: 'location',
      key: 'location',
      ellipsis: true,
      render: (val: string) => <Tooltip title={val}><span style={{maxWidth: 150, display: 'inline-block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{val || '-'}</span></Tooltip>
    },
    {
      title: '拉取时间',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 180,
      align: 'center' as const,
      render: (val: number) => formatTime(val)
    },
    {
      title: '今日频次',
      dataIndex: 'today_count',
      key: 'today_count',
      width: 100,
      align: 'center' as const,
      render: (val: number, record: any) => {
        const count = val || 0;
        let color = 'success';
        if (count > 10) color = 'error';
        else if (count > 5) color = 'warning';
        return (
          <Tooltip title="点击查看该用户所有拉取记录">
            <Tag color={color} style={{ cursor: 'pointer' }} onClick={() => filterByUser(record.user_id)}>
              {count} 次
            </Tag>
          </Tooltip>
        );
      }
    },
    {
      title: '原始 User-Agent',
      dataIndex: 'ua',
      key: 'ua',
      ellipsis: {
        showTitle: false,
      },
      render: (val: string) => (
        <Tooltip placement="topLeft" title={val}>
          <span>{val}</span>
        </Tooltip>
      )
    },
    {
      title: '快捷操作',
      key: 'actions',
      width: 220,
      align: 'center' as const,
      render: (_: any, record: any) => (
        <Space size={4}>
          <Button
            size="small"
            type={query.user_id === String(record.user_id) ? "primary" : "link"}
            onClick={() => filterByUser(record.user_id)}
          >
            {query.user_id === String(record.user_id) ? '筛选中' : '全部拉取'}
          </Button>
          <Button
            size="small"
            type="link"
            onClick={() => openUserLoginModal({ id: record.user_id, email: record.email })}
          >
            登录IP
          </Button>
          <Button
            size="small"
            type="link"
            onClick={() => showUserDetail(record.user_id)}
          >
            档案
          </Button>
        </Space>
      )
    }
  ];

  // IP Association
  const [ipModalVisible, setIpModalVisible] = useState(false);
  const [ipList, setIpList] = useState<any[]>([]);
  const [ipLoading, setIpLoading] = useState(false);
  const [userDetailVisible, setUserDetailVisible] = useState(false);
  const [activeUserId, setActiveUserId] = useState<number | null>(null);

  const fetchIpAssociation = async () => {
    setIpLoading(true);
    try {
      const res = await get<any>(adminPath('/stat/getIpAssociationAnalysis'));
      setIpList(res?.data || []);
    } catch (err: any) {
      message.error(err.message || '获取关联分析数据失败');
    } finally {
      setIpLoading(false);
    }
  };

  const openIpModal = () => {
    setIpModalVisible(true);
    fetchIpAssociation();
  };

  const banIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/banIp'), { ip });
      message.success('IP 封禁成功');
      fetchIpAssociation();
    } catch (err: any) {
      message.error(err.message || '操作失败');
    }
  };

  const unbanIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/removeBanIp'), { ip });
      message.success('IP 已解封');
      fetchIpAssociation();
    } catch (err: any) {
      message.error(err.message || '操作失败');
    }
  };

  const ipColumns = [
    {
      title: '共用 IP',
      dataIndex: 'ip',
      key: 'ip',
      render: (val: string, record: any) => (
        <div>
          <code style={{ padding: "4px 8px", background: "#f0f0f0", color: "#333", borderRadius: "4px", border: "1px solid #d9d9d9", wordBreak: "break-all" }}>{val}</code>
          {record.location && <div style={{ fontSize: 11, color: '#8c8c8c', marginTop: 4 }}>{record.location}</div>}
        </div>
      )
    },
    {
      title: '关联账号数',
      key: 'associated_accounts_count',
      width: 140,
      render: (_: any, record: any) => (
        <span>
          <strong>{record.associated_accounts_count}</strong> 个账号
          {record.honeypot_accounts_count > 0 && (
            <span style={{ color: '#faad14', fontSize: 12, marginLeft: 4 }}>
              ({record.honeypot_accounts_count} 蜜罐)
            </span>
          )}
        </span>
      )
    },
    {
      title: '共用账号列表',
      key: 'associated_users',
      render: (_: any, record: any) => (
        <Space size={[0, 4]} wrap>
          {(record.associated_users || []).map((u: any) => (
            <Tag key={u.id} color={u.in_honeypot === 1 ? 'warning' : 'success'}>
              {u.email} ({u.id})
            </Tag>
          ))}
        </Space>
      )
    },
    {
      title: '总频次',
      dataIndex: 'total_pulls',
      key: 'total_pulls',
      width: 80,
      align: 'center' as const
    },
    {
      title: '最近拉取',
      dataIndex: 'latest_time',
      key: 'latest_time',
      width: 150,
      render: (val: number) => <span style={{ fontSize: 12, color: '#8c8c8c' }}>{formatTime(val)}</span>
    },
    {
      title: '操作',
      key: 'action',
      width: 110,
      align: 'right' as const,
      render: (_: any, record: any) => {
        if (record.is_banned === 0) {
          return <Button danger size="small" onClick={() => banIp(record.ip)}>封禁 IP</Button>;
        }
        return <Button size="small" onClick={() => unbanIp(record.ip)}>已封锁</Button>;
      }
    }
  ];

  // Device Association
  const [deviceModalVisible, setDeviceModalVisible] = useState(false);
  const [deviceList, setDeviceList] = useState<any[]>([]);
  const [deviceLoading, setDeviceLoading] = useState(false);

  const fetchDeviceAssociation = async () => {
    setDeviceLoading(true);
    try {
      const res = await get<any>(adminPath('/stat/getDeviceAssociationAnalysis'));
      setDeviceList(res?.data || []);
    } catch (err: any) {
      message.error(err.message || '获取设备关联分析数据失败');
    } finally {
      setDeviceLoading(false);
    }
  };

  const openDeviceModal = () => {
    setDeviceModalVisible(true);
    fetchDeviceAssociation();
  };

  const deviceColumns = [
    {
      title: '设备 ID (硬件特征)',
      dataIndex: 'device_id',
      key: 'device_id',
      render: (val: string) => <code style={{ padding: "4px 8px", background: "#f0f0f0", color: "#cf1322", borderRadius: "4px", border: "1px solid #d9d9d9", wordBreak: "break-all" }}>{val}</code>
    },
    {
      title: '关联账号数',
      key: 'associated_accounts_count',
      width: 110,
      render: (_: any, record: any) => (
        <span>
          <strong style={{ color: record.associated_accounts_count >= 2 ? '#ff4d4f' : 'inherit' }}>
            {record.associated_accounts_count}
          </strong> 账号
          {record.honeypot_accounts_count > 0 && (
            <span style={{ color: '#faad14', fontSize: 12, marginLeft: 4 }}>
              ({record.honeypot_accounts_count}蜜罐)
            </span>
          )}
        </span>
      )
    },
    {
      title: '共用 IP 数',
      key: 'associated_ips_count',
      width: 90,
      render: (_: any, record: any) => (
        <span>
          <strong style={{ color: record.associated_ips_count >= 2 ? '#ff4d4f' : 'inherit' }}>
            {record.associated_ips_count}
          </strong> IP
        </span>
      )
    },
    {
      title: '共用账号列表',
      key: 'associated_users',
      render: (_: any, record: any) => (
        <Space size={[0, 4]} wrap>
          {(record.associated_users || []).map((u: any) => (
            <Tag
              key={u.id}
              color={u.in_honeypot === 1 ? 'warning' : 'success'}
              style={{ cursor: 'pointer' }}
              onClick={() => showUserDetail(u.id)}
            >
              {u.email}
            </Tag>
          ))}
        </Space>
      )
    },
    {
      title: '拉取 IP 列表 (含归属地)',
      key: 'associated_ips',
      render: (_: any, record: any) => {
        const getIpStr = (item: any) => (typeof item === 'string' ? item : item?.ip || '');
        const getIpLoc = (item: any) => (typeof item === 'object' && item?.location && item.location !== '未知' ? item.location : '');
        const getIpLocShort = (item: any) => {
          const loc = getIpLoc(item);
          if (!loc) return '';
          const parts = loc.split('-').filter((p: string) => p !== '中国' && p !== 'CN');
          return parts.slice(0, 2).join('·') || loc;
        };

        const ips = record.associated_ips || [];
        const showIps = ips.slice(0, 5);
        const more = ips.length > 5 ? ips.length - 5 : 0;
        return (
          <Space size={[0, 4]} wrap>
            {showIps.map((item: any, idx: number) => {
              const ip = getIpStr(item);
              const loc = getIpLoc(item);
              const shortLoc = getIpLocShort(item);
              return (
                <Tooltip key={idx} title={loc || '归属地未知'}>
                  <Tag color="default" style={{ cursor: 'help', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <span>{ip}</span>
                    {loc && (
                      <span style={{ color: '#52c41a', fontSize: 11, backgroundColor: 'rgba(82, 196, 26, 0.1)', padding: '0 4px', borderRadius: 2 }}>
                        {shortLoc}
                      </span>
                    )}
                  </Tag>
                </Tooltip>
              );
            })}
            {more > 0 && (
              <Popover
                title={`该设备关联的全部 IP (${ips.length} 个)`}
                content={
                  <div style={{ maxHeight: 280, overflowY: 'auto', minWidth: 260 }}>
                    {ips.map((item: any, idx: number) => {
                      const ip = getIpStr(item);
                      const loc = getIpLoc(item);
                      return (
                        <div key={idx} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '4px 0', borderBottom: '1px solid #f0f0f0', fontSize: 12 }}>
                          <code style={{ color: '#1890ff' }}>{ip}</code>
                          <span style={{ color: '#52c41a' }}>{loc || '未知'}</span>
                        </div>
                      );
                    })}
                  </div>
                }
              >
                <Tag color="default" style={{ cursor: 'pointer' }}>+{more}</Tag>
              </Popover>
            )}
          </Space>
        );
      }
    }
  ];

  return (
    <AdminLayout title="🛡️ 订阅拉取雷达 (无限制监控)">
      <div style={{ padding: isMobile ? '12px 8px' : '24px' }}>
        <Card bordered={false} bodyStyle={{ padding: isMobile ? 12 : 24 }}>
          <div style={{ marginBottom: 16 }}>
            <div style={{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', gap: 8, marginBottom: 8 }}>
              <Input
                placeholder="User ID"
                value={query.user_id}
                onChange={(e) => setQuery({ ...query, user_id: e.target.value })}
                onPressEnter={handleFilter}
                allowClear
                style={{ width: isMobile ? '100%' : 150 }}
              />
              <Input
                placeholder="IP 地址"
                value={query.ip}
                onChange={(e) => setQuery({ ...query, ip: e.target.value })}
                onPressEnter={handleFilter}
                allowClear
                style={{ width: isMobile ? '100%' : 200 }}
              />
              <Input
                placeholder="User-Agent 关键词"
                value={query.ua}
                onChange={(e) => setQuery({ ...query, ua: e.target.value })}
                onPressEnter={handleFilter}
                allowClear
                style={{ width: isMobile ? '100%' : 250 }}
              />
            </div>
            <Space wrap size={[6, 6]}>
              <Button type="primary" size={isMobile ? 'small' : 'middle'} icon={<SearchOutlined />} onClick={handleFilter}>
                查询
              </Button>
              <Button size={isMobile ? 'small' : 'middle'} icon={<ReloadOutlined />} onClick={resetFilter}>
                重置
              </Button>
              <Button type="primary" size={isMobile ? 'small' : 'middle'} style={{ backgroundColor: '#faad14', borderColor: '#faad14' }} icon={<TrophyOutlined />} onClick={getTopUsers}>
                今日排行
              </Button>
              <Button type="primary" size={isMobile ? 'small' : 'middle'} danger icon={<LinkOutlined />} onClick={openIpModal}>
                IP 关联
              </Button>
              <Button type="primary" size={isMobile ? 'small' : 'middle'} danger icon={<MonitorOutlined />} onClick={openDeviceModal}>
                设备雷达
              </Button>
            </Space>
          </div>

          {/* 正在筛选单账号拉取记录的醒目提示 Banner */}
          {query.user_id && (
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                background: '#e6f7ff',
                border: '1px solid #91d5ff',
                padding: '8px 12px',
                borderRadius: 6,
                marginBottom: 14,
                color: '#0050b3',
                fontSize: 13,
                flexWrap: 'wrap',
                gap: 6
              }}
            >
              <span>
                🔍 正在查看账号 <strong>UID: {query.user_id}</strong> 的全部拉取记录 (共 {total} 条)
              </span>
              <Button type="primary" size="small" icon={<CloseCircleOutlined />} onClick={resetFilter}>
                清除筛选 / 查看全部
              </Button>
            </div>
          )}

          {isMobile ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {data.map((item, index) => {
                const count = item.today_count || 0;
                let countColor = 'success';
                if (count > 10) countColor = 'error';
                else if (count > 5) countColor = 'warning';
                return (
                  <Card
                    key={item.id || index}
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
                      <div style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0, flex: 1, marginRight: 8 }}>
                        <a
                          onClick={() => showUserDetail(item.user_id)}
                          style={{ fontWeight: 600, fontSize: 14, color: '#1677ff', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                        >
                          {item.email || '未知用户'}
                        </a>
                        <Tag color="default" style={{ margin: 0, fontSize: 11, flexShrink: 0 }}>UID:{item.user_id}</Tag>
                      </div>
                      <Tag color={countColor} style={{ margin: 0, fontWeight: 500, flexShrink: 0, cursor: 'pointer' }} onClick={() => filterByUser(item.user_id)}>
                        今日 {count} 次
                      </Tag>
                    </div>

                    <div style={{ fontSize: 12, color: '#555', marginBottom: 4, display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                      <span>客户端: <Tag style={{ margin: 0, fontSize: 11 }}>{item.type || '未知'}</Tag></span>
                      <span style={{ color: '#888' }}>{formatTime(item.created_at)}</span>
                    </div>

                    <div style={{ fontSize: 12, color: '#666', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span>IP: <code style={{ padding: '2px 4px', background: '#f0f0f0', borderRadius: 4, fontSize: 11 }}>{item.ip}</code></span>
                      <span style={{ color: '#888' }}>{item.location || '-'}</span>
                    </div>

                    {item.ua && (
                      <div style={{ fontSize: 11, color: '#999', marginTop: 6, paddingTop: 6, borderTop: '1px dashed #e8e8e8', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        UA: {item.ua}
                      </div>
                    )}

                    {/* 快捷操作区：直接查看该账号的所有拉取 IP、所有登录 IP 与用户档案 */}
                    <div style={{ display: 'flex', gap: 6, marginTop: 10, paddingTop: 8, borderTop: '1px solid #f0f0f0', justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                      <Button
                        size="small"
                        type={query.user_id === String(item.user_id) ? "primary" : "default"}
                        onClick={() => filterByUser(item.user_id)}
                      >
                        {query.user_id === String(item.user_id) ? '筛选中' : 'TA的全部拉取'}
                      </Button>
                      <Button
                        size="small"
                        onClick={() => openUserLoginModal({ id: item.user_id, email: item.email })}
                      >
                        TA的登录IP
                      </Button>
                      <Button
                        size="small"
                        onClick={() => showUserDetail(item.user_id)}
                      >
                        用户档案
                      </Button>
                    </div>
                  </Card>
                );
              })}

              {data.length === 0 && !loading && (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无订阅拉取记录" />
              )}

              <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}>
                <Pagination
                  simple
                  current={query.current}
                  pageSize={query.page_size}
                  total={total}
                  onChange={(page, pageSize) => {
                    handleTableChange({ current: page, pageSize });
                  }}
                />
              </div>
            </div>
          ) : (
            <Table
              dataSource={data}
              columns={columns}
              loading={loading}
              rowKey={(record, index) => record.id || index}
              pagination={{
                current: query.current,
                pageSize: query.page_size,
                total: total,
                showSizeChanger: true,
                pageSizeOptions: ['10', '20', '50', '100'],
                showTotal: (t) => `共 ${t} 条`
              }}
              onChange={handleTableChange}
              bordered
            />
          )}
        </Card>
      </div>

      {/* 单账号历史登录 IP 快捷浮窗 */}
      {loginModalVisible && targetUser && (
        <UserLoginLogsModal
          visible={loginModalVisible}
          user={targetUser}
          onCancel={() => {
            setLoginModalVisible(false);
            setTargetUser(null);
          }}
        />
      )}

      <Modal
        title="多账号共用 IP 关联分析雷达 (订阅拉取)"
        open={ipModalVisible}
        onCancel={() => setIpModalVisible(false)}
        footer={[
          <Button key="close" onClick={() => setIpModalVisible(false)}>关闭</Button>
        ]}
        width={isMobile ? '95%' : 900}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 15, lineHeight: 1.5 }}>
          分析所有用户的客户端拉取历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同使用过</strong>的 IP 地址。
        </div>
        <Table
          dataSource={ipList}
          columns={ipColumns}
          loading={ipLoading}
          rowKey="ip"
          pagination={false}
          size="small"
          scroll={{ x: 650, y: 450 }}
        />
      </Modal>

      <Modal
        title="异常设备关联分析雷达 (物理机防作弊)"
        open={deviceModalVisible}
        onCancel={() => setDeviceModalVisible(false)}
        footer={[
          <Button key="close" onClick={() => setDeviceModalVisible(false)}>关闭</Button>
        ]}
        width={isMobile ? '95%' : 950}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: '#8c8c8c', marginBottom: 15, lineHeight: 1.5 }}>
          分析订阅拉取记录，提取底层物理设备特征（设备 ID），抓出<strong>同一台物理设备关联了 2 个及以上不同账号，或高频使用了 2 个及以上不同 IP 地址</strong>的内鬼工作室！
        </div>
        <Table
          dataSource={deviceList}
          columns={deviceColumns}
          loading={deviceLoading}
          rowKey="device_id"
          pagination={false}
          size="small"
          scroll={{ x: 750, y: 450 }}
        />
      </Modal>
    
      {userDetailVisible && activeUserId && (
        <UserDetailModal 
          visible={userDetailVisible}
          userId={activeUserId}
          onCancel={() => { setUserDetailVisible(false); setActiveUserId(null); }}
        />
      )}
    </AdminLayout>
  );
}
