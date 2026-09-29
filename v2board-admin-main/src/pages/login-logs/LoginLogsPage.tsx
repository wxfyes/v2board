import React, { useState, useEffect, useCallback } from 'react';
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
  Typography,
  Pagination,
  Empty,
  Popover
} from 'antd';
import { SearchOutlined, ReloadOutlined, ApiOutlined, DesktopOutlined, CloseCircleOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import dayjs from 'dayjs';
import { AdminLayout } from '@/layouts/AdminLayout';
import { UserDetailModal } from '../security-audit/SecurityAuditPage';
import { useMobile } from '@/hooks/useMobile';

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

// 单账号订阅拉取 IP 记录快捷浮窗
function UserSubscribeLogsModal({
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

  const fetchUserSubscribes = useCallback(async (p = 1) => {
    if (!user) return;
    setLoading(true);
    try {
      const res: any = await get(adminPath('/system/getSubscribeLog'), {
        user_id: user.id,
        current: p,
        page_size: pageSize
      });
      if (res && res.data) {
        setLogs(res.data.data || res.data || []);
        setTotal(res.data.total || res.total || 0);
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
      fetchUserSubscribes(1);
    }
  }, [visible, user, fetchUserSubscribes]);

  const formatTime = (timestamp: number) => {
    if (!timestamp) return '-';
    return dayjs(timestamp * 1000).format('YYYY-MM-DD HH:mm:ss');
  };

  return (
    <Modal
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          <span>🛡️ 账号订阅拉取 IP 记录</span>
          <Tag color="blue">{user?.email || '未知用户'}</Tag>
          <Tag color="default">UID: {user?.id}</Tag>
        </div>
      }
      open={visible}
      onCancel={onCancel}
      width={isMobile ? '95%' : 780}
      footer={[
        <Button
          key="goto"
          onClick={() => {
            onCancel();
            navigate(`/subscribe-logs?user_id=${user?.id}`);
          }}
        >
          前往订阅雷达大厅 &gt;
        </Button>,
        <Button key="close" type="primary" onClick={onCancel}>
          关闭
        </Button>
      ]}
      destroyOnClose
    >
      <div style={{ marginBottom: 12, fontSize: 13, color: '#666' }}>
        查看该账号的历史客户端拉取 IP、归属地及识别类型（共 <strong>{total}</strong> 条记录）：
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
                <Tag color="processing" style={{ margin: 0 }}>
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
            <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无该账号的订阅拉取记录" />
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
              title: '拉取 IP',
              dataIndex: 'ip',
              render: (ip: string, r: any) => (
                <div>
                  <code>{ip}</code>
                  {r.location && <div style={{ fontSize: 11, color: '#888' }}>{r.location}</div>}
                </div>
              )
            },
            {
              title: '客户端',
              dataIndex: 'type',
              width: 100,
              render: (t: string) => <Tag>{t || '-'}</Tag>
            },
            {
              title: '拉取时间',
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
            fetchUserSubscribes(p);
          }}
        />
      </div>
    </Modal>
  );
}

export default function LoginLogsPage() {
  const isMobile = useMobile();
  const [form] = Form.useForm();

  // 列表数据
  const [list, setList] = useState<any[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [current, setCurrent] = useState(1);
  const [pageSize, setPageSize] = useState(20);

  // 当前激活的单账号筛选标记
  const [activeFilteredUser, setActiveFilteredUser] = useState<{ id?: number | string; email?: string } | null>(null);

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

  // 快捷订阅拉取记录弹窗
  const [subModalVisible, setSubModalVisible] = useState(false);
  const [targetUser, setTargetUser] = useState<{ id: number; email: string } | null>(null);

  const openUserSubModal = (user: { id: number; email: string }) => {
    setTargetUser(user);
    setSubModalVisible(true);
  };

  const showUserDetail = (userId: number) => { setActiveUserId(userId); setUserDetailVisible(true); };

  const fetchList = async (page = current, size = pageSize, customValues?: any) => {
    setLoading(true);
    try {
      const values = customValues || form.getFieldsValue();
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
    // 检查是否有预设参数（如 user_id 或 email）
    let initialUserId = '';
    let initialEmail = '';
    const hash = window.location.hash;
    const qIndex = hash.indexOf('?');
    if (qIndex !== -1) {
      const sp = new URLSearchParams(hash.slice(qIndex));
      initialUserId = sp.get('user_id') || '';
      initialEmail = sp.get('email') || '';
    } else {
      const sp = new URLSearchParams(window.location.search);
      initialUserId = sp.get('user_id') || '';
      initialEmail = sp.get('email') || '';
    }

    if (initialUserId || initialEmail) {
      const initVals: any = {};
      if (initialEmail) initVals.email = initialEmail;
      if (initialUserId) initVals.user_id = initialUserId;
      form.setFieldsValue(initVals);
      setActiveFilteredUser({ id: initialUserId, email: initialEmail });
      fetchList(1, pageSize, initVals);
    } else {
      fetchList();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleSearch = () => {
    const vals = form.getFieldsValue();
    if (vals.email || vals.user_id) {
      setActiveFilteredUser({ id: vals.user_id, email: vals.email });
    } else {
      setActiveFilteredUser(null);
    }
    setCurrent(1);
    fetchList(1, pageSize);
  };

  const handleReset = () => {
    form.resetFields();
    setActiveFilteredUser(null);
    setCurrent(1);
    fetchList(1, pageSize, {});
  };

  const filterByAccount = (email: string, userId?: number) => {
    form.setFieldsValue({ email: email || '', user_id: userId ? String(userId) : '' });
    setActiveFilteredUser({ id: userId, email });
    setCurrent(1);
    fetchList(1, pageSize, { email: email || '', user_id: userId ? String(userId) : '' });
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
      width: 90,
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
      width: 120,
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
    {
      title: '快捷操作',
      key: 'actions',
      width: 220,
      align: 'center' as const,
      render: (_: any, record: any) => (
        <Space size={4}>
          <Button
            size="small"
            type={activeFilteredUser?.email === record.email ? "primary" : "link"}
            onClick={() => filterByAccount(record.email, record.user_id)}
          >
            {activeFilteredUser?.email === record.email ? '筛选中' : '全部登录'}
          </Button>
          {record.user_id ? (
            <Button
              size="small"
              type="link"
              onClick={() => openUserSubModal({ id: record.user_id, email: record.email })}
            >
              拉取记录
            </Button>
          ) : null}
          {record.user_id ? (
            <Button
              size="small"
              type="link"
              onClick={() => showUserDetail(record.user_id)}
            >
              档案
            </Button>
          ) : null}
        </Space>
      )
    }
  ].filter(Boolean) as any[];

  return (
    <AdminLayout title="登录记录">
      <div style={{ padding: isMobile ? '12px 8px' : '24px' }}>
      <Card title="📝 用户登录记录" className="box-card" bodyStyle={{ padding: isMobile ? 12 : 24 }}>
        <Form form={form} layout={isMobile ? 'vertical' : 'inline'} style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', gap: 8, marginBottom: 8, width: isMobile ? '100%' : 'auto' }}>
            <Form.Item name="email" style={{ margin: 0, flex: 1 }}>
              <Input placeholder="邮箱" style={{ width: isMobile ? '100%' : 180 }} allowClear onPressEnter={handleSearch} />
            </Form.Item>
            <Form.Item name="ip" style={{ margin: 0, flex: 1 }}>
              <Input placeholder="IP 地址" style={{ width: isMobile ? '100%' : 180 }} allowClear onPressEnter={handleSearch} />
            </Form.Item>
            <Form.Item name="type" style={{ margin: 0, width: isMobile ? '100%' : 140 }}>
              <Select placeholder="登录状态" style={{ width: '100%' }} allowClear onChange={handleSearch}>
                <Select.Option value="成功">成功</Select.Option>
                <Select.Option value="失败">失败</Select.Option>
              </Select>
            </Form.Item>
          </div>
          <Form.Item style={{ margin: 0 }}>
            <Space wrap size={[6, 6]}>
              <Button type="primary" size={isMobile ? 'small' : 'middle'} icon={<SearchOutlined />} onClick={handleSearch}>
                查询
              </Button>
              <Button size={isMobile ? 'small' : 'middle'} icon={<ReloadOutlined />} onClick={handleReset}>
                重置
              </Button>
              <Button danger size={isMobile ? 'small' : 'middle'} icon={<ApiOutlined />} onClick={openIpAssociationModal}>
                IP 关联
              </Button>
              <Button danger size={isMobile ? 'small' : 'middle'} icon={<DesktopOutlined />} onClick={openDeviceAssociationModal}>
                设备雷达
              </Button>
            </Space>
          </Form.Item>
        </Form>

        {/* 正在筛选单账号登录记录的醒目提示 Banner */}
        {activeFilteredUser && (
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
              🔍 正在查看账号 <strong>{activeFilteredUser.email || `UID:${activeFilteredUser.id}`}</strong> 的全部登录记录 (共 {total} 条)
            </span>
            <Button type="primary" size="small" icon={<CloseCircleOutlined />} onClick={handleReset}>
              清除筛选 / 查看全部
            </Button>
          </div>
        )}

        {!isMobile && (
          <div style={{ marginBottom: 16 }}>
            <span style={{ fontSize: 13, marginRight: 8, color: '#888' }}>显示表项:</span>
            <Checkbox.Group
              options={showColumnOptions}
              value={showColumns}
              onChange={(checkedValues) => setShowColumns(checkedValues as string[])}
            />
          </div>
        )}

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
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, minWidth: 0, flex: 1, marginRight: 8 }}>
                    <a
                      onClick={() => item.user_id ? showUserDetail(item.user_id) : null}
                      style={{ fontWeight: 600, fontSize: 14, color: '#1677ff', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                    >
                      {item.email || '未知用户'}
                    </a>
                    {item.user_id && <Tag color="default" style={{ margin: 0, fontSize: 11, flexShrink: 0 }}>UID:{item.user_id}</Tag>}
                  </div>
                  <Tag color={item.type && item.type.includes('成功') ? 'success' : 'error'} style={{ margin: 0, fontWeight: 500, flexShrink: 0 }}>
                    {item.type || '未知'}
                  </Tag>
                </div>

                <div style={{ fontSize: 12, color: '#555', marginBottom: 4, display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                  <span>IP: <Text code style={{ fontSize: 12 }}>{item.ip}</Text></span>
                  <span style={{ color: '#888' }}>{item.location || '-'}</span>
                </div>

                <div style={{ fontSize: 12, color: '#888', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span>时间: {formatTime(item.created_at)}</span>
                  <span style={{ fontSize: 11, color: '#bbb' }}>#{item.id}</span>
                </div>

                {item.ua && (
                  <div style={{ fontSize: 11, color: '#999', marginTop: 6, paddingTop: 6, borderTop: '1px dashed #e8e8e8', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    设备: {item.ua}
                  </div>
                )}

                {/* 快捷操作区 */}
                <div style={{ display: 'flex', gap: 6, marginTop: 10, paddingTop: 8, borderTop: '1px solid #f0f0f0', justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                  <Button
                    size="small"
                    type={activeFilteredUser?.email === item.email ? "primary" : "default"}
                    onClick={() => filterByAccount(item.email, item.user_id)}
                  >
                    {activeFilteredUser?.email === item.email ? '筛选中' : 'TA的全部登录'}
                  </Button>
                  {item.user_id ? (
                    <Button
                      size="small"
                      onClick={() => openUserSubModal({ id: item.user_id, email: item.email })}
                    >
                      TA的拉取记录
                    </Button>
                  ) : null}
                  {item.user_id ? (
                    <Button
                      size="small"
                      onClick={() => showUserDetail(item.user_id)}
                    >
                      用户档案
                    </Button>
                  ) : null}
                </div>
              </Card>
            ))}

            {list.length === 0 && !loading && (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无登录记录" />
            )}

            <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}>
              <Pagination
                simple
                current={current}
                pageSize={pageSize}
                total={total}
                onChange={(page) => {
                  setCurrent(page);
                  fetchList(page, pageSize);
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
        )}

      {/* 单账号订阅拉取记录快捷浮窗 */}
      {subModalVisible && targetUser && (
        <UserSubscribeLogsModal
          visible={subModalVisible}
          user={targetUser}
          onCancel={() => {
            setSubModalVisible(false);
            setTargetUser(null);
          }}
        />
      )}

      <Modal
        title="多账号共用 IP 关联分析雷达 (登录记录)"
        open={ipModalVisible}
        onCancel={() => setIpModalVisible(false)}
        footer={
          <Button onClick={() => setIpModalVisible(false)}>关闭</Button>
        }
        width={isMobile ? '95%' : 900}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: 'rgba(0, 0, 0, 0.45)', marginBottom: 15, lineHeight: 1.5 }}>
          分析系统内所有的用户登录历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同登录过</strong>的 IP 地址。
        </div>
        <Table
          dataSource={ipList}
          columns={[
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
              dataIndex: 'total_logins',
              key: 'total_logins',
              width: 80,
              align: 'center' as const
            },
            {
              title: '最近登录',
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
                  return <Button danger size="small" onClick={() => banAssociatedIp(record.ip)}>封禁 IP</Button>;
                }
                return <Button size="small" onClick={() => unbanAssociatedIp(record.ip)}>已封锁</Button>;
              }
            }
          ]}
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
        footer={
          <Button onClick={() => setDeviceModalVisible(false)}>关闭</Button>
        }
        width={isMobile ? '95%' : 950}
        destroyOnClose
      >
        <div style={{ fontSize: 13, color: 'rgba(0, 0, 0, 0.45)', marginBottom: 15, lineHeight: 1.5 }}>
          分析登录记录，提取底层物理设备特征（设备 ID），抓出<strong>同一台物理设备登录了 2 个及以上不同账号，或高频使用了 2 个及以上不同 IP 地址</strong>的账号群！
        </div>
        <Table
          dataSource={deviceList}
          columns={[
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
              title: '登录 IP 列表 (含归属地)',
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
          ]}
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
      </Card>
      </div>
    </AdminLayout>
  );
}
