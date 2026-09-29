import { Card, Table, message, Modal, Input, Button, Space, Tag, Tooltip, Typography } from 'antd';
import { SearchOutlined, ReloadOutlined, TrophyOutlined, LinkOutlined, MonitorOutlined } from '@ant-design/icons';
import { useEffect, useState, useCallback } from 'react';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import { AdminLayout } from '@/layouts/AdminLayout';
import { UserDetailModal } from '../security-audit/SecurityAuditPage';
import { useMobile } from '@/hooks/useMobile';

const { Text } = Typography;

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
    fetchLogs();
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
      title: '拉取 IP 列表',
      key: 'associated_ips',
      render: (_: any, record: any) => {
        const ips = record.associated_ips || [];
        const showIps = ips.slice(0, 5);
        const more = ips.length > 5 ? ips.length - 5 : 0;
        return (
          <Space size={[0, 4]} wrap>
            {showIps.map((ip: string, idx: number) => (
              <Tag key={idx} color="default">{ip}</Tag>
            ))}
            {more > 0 && <Tag color="default">+{more}</Tag>}
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

          <Table
            dataSource={data}
            columns={columns}
            loading={loading}
            rowKey={(record, index) => record.id || index}
            scroll={{ x: 950 }}
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
        </Card>
      </div>

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


