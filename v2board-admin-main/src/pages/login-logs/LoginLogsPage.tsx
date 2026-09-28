import React, { useState, useEffect } from 'react';
import {
  Card,
  Form,
  Input,
  Select,
  Button,
  Checkbox,
  Table,
  Tag,
  Tooltip,
  Modal,
  message,
  Space,
  Typography
} from 'antd';
import { SearchOutlined, ReloadOutlined, ApiOutlined, DesktopOutlined } from '@ant-design/icons';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import dayjs from 'dayjs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { UserDetailModal } from '../security-audit/SecurityAuditPage';


const { Text } = Typography;

const showColumnOptions = [
  { label: 'ID', value: 'id' },
  { label: '邮箱 / 账号', value: 'email' },
  { label: 'IP', value: 'ip' },
  { label: '归属地', value: 'location' },
  { label: '时间', value: 'time' },
  { label: '类型', value: 'type' },
  { label: '客户端 UA', value: 'ua' },
];

export default function LoginLogsPage() {
  const [form] = Form.useForm();

  // 列表数据
  const [list, setList] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [current, setCurrent] = useState(1);
  const [pageSize, setPageSize] = useState(20);

  // 动态列
  const [showColumns, setShowColumns] = useState(['id', 'email', 'ip', 'location', 'time', 'type']);

  // IP 关联分析
  const [ipModalVisible, setIpModalVisible] = useState(false);
  const [ipList, setIpList] = useState<any[]>([]);
  const [ipLoading, setIpLoading] = useState(false);
  const [userDetailVisible, setUserDetailVisible] = useState(false);
  const [activeUserId, setActiveUserId] = useState<number | null>(null);


  // 设备关联分析
  const [deviceModalVisible, setDeviceModalVisible] = useState(false);
  const [deviceList, setDeviceList] = useState<any[]>([]);
  const [deviceLoading, setDeviceLoading] = useState(false);

  const showUserDetail = (userId: number) => { setActiveUserId(userId); setUserDetailVisible(true); };
  const fetchList = async (page = current, size = pageSize) => {
    setLoading(true);
    try {
      const values = form.getFieldsValue();
      const res: any = await get(adminPath('/system/getLoginLog'), {
        current: page,
        page_size: size,
        ...values,
      });
      if (res && res.data) {
        setList(res.data);
        setTotal(res.total || 0);
      }
    } catch (error: any) {
      // 错误通常已被拦截
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchList();
  }, []);

  const handleSearch = () => {
    setCurrent(1);
    fetchList(1, pageSize);
  };

  const handleReset = () => {
    form.resetFields();
    setCurrent(1);
    fetchList(1, pageSize);
  };

  const handleTableChange = (pagination: any) => {
    setCurrent(pagination.current);
    setPageSize(pagination.pageSize);
    fetchList(pagination.current, pagination.pageSize);
  };

  const fetchIpAssociation = async () => {
    setIpLoading(true);
    try {
      const res: any = await get(adminPath('/stat/getLoginIpAssociationAnalysis'));
      setIpList(res?.data || []);
    } catch (error: any) {
      message.error(error.message || '获取关联分析数据失败');
    } finally {
      setIpLoading(false);
    }
  };

  const openIpAssociationModal = () => {
    setIpModalVisible(true);
    fetchIpAssociation();
  };

  const fetchDeviceAssociation = async () => {
    setDeviceLoading(true);
    try {
      const res: any = await get(adminPath('/stat/getLoginDeviceAssociationAnalysis'));
      setDeviceList(res?.data || []);
    } catch (error: any) {
      message.error(error.message || '获取设备关联分析数据失败');
    } finally {
      setDeviceLoading(false);
    }
  };

  const openDeviceAssociationModal = () => {
    setDeviceModalVisible(true);
    fetchDeviceAssociation();
  };

  const banAssociatedIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/banIp'), { ip });
      message.success('IP 封禁成功');
      fetchIpAssociation();
    } catch (error: any) {
      message.error(error.message || '操作失败');
    }
  };

  const unbanAssociatedIp = async (ip: string) => {
    try {
      await post(adminPath('/stat/removeBanIp'), { ip });
      message.success('IP 已解封');
      fetchIpAssociation();
    } catch (error: any) {
      message.error(error.message || '操作失败');
    }
  };

  const formatTime = (timestamp: number) => {
    if (!timestamp) return '-';
    return dayjs(timestamp * 1000).format('YYYY-MM-DD HH:mm:ss');
  };



  // 组装主表格列
  const columns = [
    showColumns.includes('id') && {
      title: 'ID',
      dataIndex: 'id',
      align: 'center' as const,
      width: 100,
    },
    showColumns.includes('email') && {
      title: '邮箱 / 账号',
      dataIndex: 'email',
      render: (text: string, record: any) => (
        <Space>
          <a
            onClick={() => record.user_id ? showUserDetail(record.user_id) : null}
            style={{ textDecoration: 'underline' }}
          >
            {text || '未知'}
          </a>
          {record.user_id && <Tag color="default">UID:{record.user_id}</Tag>}
        </Space>
      ),
    },
    showColumns.includes('ip') && {
      title: 'IP 地址',
      dataIndex: 'ip',
      align: 'center' as const,
      width: 160,
      render: (text: string) => <Text code>{text}</Text>,
    },
    showColumns.includes('location') && {
      title: '归属地',
      dataIndex: 'location',
      ellipsis: true,
      render: (text: string) => <Tooltip title={text}><span style={{maxWidth: 150, display: 'inline-block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap'}}>{text || '-'}</span></Tooltip>,
    },
    showColumns.includes('time') && {
      title: '时间',
      dataIndex: 'created_at',
      align: 'center' as const,
      width: 180,
      render: (val: number) => formatTime(val),
    },
    showColumns.includes('type') && {
      title: '类型',
      dataIndex: 'type',
      align: 'center' as const,
      width: 150,
      render: (text: string) => (
        <Tag color={text && text.includes('成功') ? 'success' : 'error'}>
          {text}
        </Tag>
      ),
    },
    showColumns.includes('ua') && {
      title: '客户端 UA',
      dataIndex: 'ua',
      render: (text: string) => (
        <Tooltip title={text} placement="topLeft">
          <div style={{ maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {text}
          </div>
        </Tooltip>
      ),
    },
  ].filter(Boolean) as any[];

  return (
    <AdminLayout title="登录记录">
      <Card title="📝 用户登录记录" className="box-card">
      <Form form={form} layout="inline" style={{ marginBottom: 16 }}>
        <Form.Item name="email">
          <Input placeholder="邮箱" style={{ width: 200 }} allowClear onPressEnter={handleSearch} />
        </Form.Item>
        <Form.Item name="ip">
          <Input placeholder="IP 地址" style={{ width: 200 }} allowClear onPressEnter={handleSearch} />
        </Form.Item>
        <Form.Item name="type">
          <Select placeholder="登录状态" style={{ width: 150 }} allowClear onChange={handleSearch}>
            <Select.Option value="成功">成功</Select.Option>
            <Select.Option value="失败">失败</Select.Option>
          </Select>
        </Form.Item>
        <Form.Item>
          <Space>
            <Button type="primary" icon={<SearchOutlined />} onClick={handleSearch}>
              查询
            </Button>
            <Button icon={<ReloadOutlined />} onClick={handleReset}>
              重置
            </Button>
            <Button danger icon={<ApiOutlined />} onClick={openIpAssociationModal} style={{ marginLeft: 8 }}>
              IP 关联分析
            </Button>
            <Button danger icon={<DesktopOutlined />} onClick={openDeviceAssociationModal}>
              异常设备雷达
            </Button>
          </Space>
        </Form.Item>
      </Form>

      <div style={{ marginBottom: 16 }}>
        <span style={{ fontSize: 14, marginRight: 16 }}>显示表项:</span>
        <Checkbox.Group
          options={showColumnOptions}
          value={showColumns}
          onChange={(checkedValues) => setShowColumns(checkedValues as string[])}
        />
      </div>

      <Table
        rowKey="id"
        columns={columns}
        dataSource={list}
        loading={loading}
        bordered
        pagination={{
          current,
          pageSize,
          total,
          showSizeChanger: true,
          showQuickJumper: true,
          showTotal: (total) => `共 ${total} 条`,
          pageSizeOptions: ['10', '20', '50', '100'],
        }}
        onChange={handleTableChange}
      />

      <Modal
        title="多账号共用 IP 关联分析雷达 (登录记录)"
        open={ipModalVisible}
        onCancel={() => setIpModalVisible(false)}
        footer={
          <Button onClick={() => setIpModalVisible(false)}>关闭</Button>
        }
        width={900}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: 'rgba(0, 0, 0, 0.45)', marginBottom: 15, lineHeight: 1.5 }}>
          分析系统内所有的用户登录历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同登录过</strong>的 IP 地址。
        </div>
        <Table
          rowKey="ip"
          dataSource={ipList}
          loading={ipLoading}
          size="small"
          scroll={{ y: 450 }}
          pagination={false}
          columns={[
            {
              title: '共用 IP',
              dataIndex: 'ip',
              width: 240,
              render: (text, record: any) => (
                <div>
                  <Text code strong>{text}</Text>
                  {record.location && (
                    <div style={{ fontSize: 11, color: 'rgba(0, 0, 0, 0.45)', marginTop: 2 }}>
                      {record.location}
                    </div>
                  )}
                </div>
              )
            },
            {
              title: '关联账号数',
              width: 160,
              render: (_, record: any) => (
                <span style={{ fontSize: 13 }}>
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
              render: (_, record: any) => (
                <Space wrap size={[0, 6]}>
                  {record.associated_users?.map((u: any) => (
                    <Tag
                      key={u.email}
                      color={u.in_honeypot === 1 ? 'warning' : 'success'}
                    >
                      {u.email} {u.id ? `(${u.id})` : ''}
                    </Tag>
                  ))}
                </Space>
              )
            },
            {
              title: '总频次',
              dataIndex: 'total_pulls',
              align: 'center',
              width: 80,
            },
            {
              title: '最近登录',
              dataIndex: 'latest_time',
              width: 150,
              render: (val) => (
                <span style={{ fontSize: 12, color: 'rgba(0, 0, 0, 0.45)' }}>
                  {formatTime(val)}
                </span>
              )
            },
            {
              title: '操作',
              align: 'right',
              width: 110,
              fixed: 'right',
              render: (_, record: any) => (
                record.is_banned === 0 ? (
                  <Button
                    danger
                    size="small"
                    onClick={() => banAssociatedIp(record.ip)}
                  >
                    封禁 IP
                  </Button>
                ) : (
                  <Button
                    type="default"
                    size="small"
                    onClick={() => unbanAssociatedIp(record.ip)}
                  >
                    已封锁
                  </Button>
                )
              )
            }
          ]}
        />
      </Modal>

      <Modal
        title="异常设备关联分析雷达 (物理机防作弊)"
        open={deviceModalVisible}
        onCancel={() => setDeviceModalVisible(false)}
        footer={
          <Button onClick={() => setDeviceModalVisible(false)}>关闭</Button>
        }
        width={950}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: 'rgba(0, 0, 0, 0.45)', marginBottom: 15, lineHeight: 1.5 }}>
          分析登录记录，提取底层物理设备特征（设备 ID），抓出<strong>同一台物理设备关联了 2 个及以上不同账号，或高频使用了 2 个及以上不同 IP 地址</strong>的内鬼工作室！
        </div>
        <Table
          rowKey="device_id"
          dataSource={deviceList}
          loading={deviceLoading}
          size="small"
          scroll={{ y: 450 }}
          pagination={false}
          columns={[
            {
              title: '设备 ID (硬件特征)',
              dataIndex: 'device_id',
              width: 210,
              render: (text) => <Text code strong style={{ color: '#ff4d4f' }}>{text}</Text>
            },
            {
              title: '关联账号数',
              width: 110,
              render: (_, record: any) => (
                <span style={{ fontSize: 13 }}>
                  <strong style={{ color: record.associated_accounts_count >= 2 ? '#ff4d4f' : 'inherit' }}>
                    {record.associated_accounts_count}
                  </strong> 账号
                  {record.honeypot_accounts_count > 0 && (
                    <span style={{ color: '#faad14', fontSize: 12, marginLeft: 4 }}>
                      ({record.honeypot_accounts_count} 蜜罐)
                    </span>
                  )}
                </span>
              )
            },
            {
              title: '共用 IP 数',
              width: 90,
              render: (_, record: any) => (
                <span style={{ fontSize: 13 }}>
                  <strong style={{ color: record.associated_ips_count >= 2 ? '#ff4d4f' : 'inherit' }}>
                    {record.associated_ips_count}
                  </strong> IP
                </span>
              )
            },
            {
              title: '共用账号列表',
              render: (_, record: any) => (
                <Space wrap size={[0, 6]}>
                  {record.associated_users?.map((u: any) => (
                    <Tag
                      key={u.id}
                      color={u.in_honeypot === 1 ? 'warning' : 'success'}
                      onClick={() => u.id ? showUserDetail(u.id) : null}
                      style={{ cursor: 'pointer' }}
                    >
                      {u.email}
                    </Tag>
                  ))}
                </Space>
              )
            },
            {
              title: '登录 IP 列表',
              render: (_, record: any) => {
                const ips = record.associated_ips || [];
                const showIps = ips.slice(0, 5);
                const moreCount = ips.length - 5;
                return (
                  <Space wrap size={[0, 4]}>
                    {showIps.map((ip: string, i: number) => (
                      <Tag key={i} color="default">{ip}</Tag>
                    ))}
                    {moreCount > 0 && (
                      <Tag color="default">+{moreCount}</Tag>
                    )}
                  </Space>
                );
              }
            }
          ]}
        />
      </Modal>
    </Card>
    
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



