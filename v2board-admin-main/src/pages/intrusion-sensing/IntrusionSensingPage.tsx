import React, { useState, useEffect } from 'react';
import {
  Card, Row, Col, Table, Tag, Button, Input, Select, Modal,
  message, Space, Drawer, Popconfirm, Statistic
} from 'antd';
import {
  SafetyCertificateOutlined, AlertOutlined, StopOutlined,
  SearchOutlined, ReloadOutlined, DeleteOutlined,
  LeftOutlined, RightOutlined, LockOutlined, UnlockOutlined, PlusOutlined
} from '@ant-design/icons';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import { AdminLayout } from '@/layouts/AdminLayout';

const { Option } = Select;

interface SecurityLogItem {
  id: number;
  ip: string;
  location: string;
  method: string;
  url: string;
  user_agent: string;
  status_code: number;
  rule_name: string;
  duration: number;
  created_at: number;
  is_banned?: boolean;
}

interface BlacklistItem {
  id: number;
  ip: string;
  reason: string;
  operator: string;
  created_at: number;
}

export default function IntrusionSensingPage() {
  const [loading, setLoading] = useState(false);
  const [list, setList] = useState<SecurityLogItem[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(15);

  // 筛选参数
  const [searchIp, setSearchIp] = useState('');
  const [statusCode, setStatusCode] = useState<number | undefined>(undefined);
  const [keyword, setKeyword] = useState('');

  // 统计数据
  const [stats, setStats] = useState({
    today_count: 0,
    total_blocked: 0,
    blacklist_count: 0,
  });

  // 详情弹窗
  const [detailVisible, setDetailVisible] = useState(false);
  const [currentIndex, setCurrentIndex] = useState(0);
  const currentDetail = list[currentIndex] || ({} as SecurityLogItem);

  // 黑名单抽屉
  const [blacklistVisible, setBlacklistVisible] = useState(false);
  const [blacklist, setBlacklist] = useState<BlacklistItem[]>([]);
  const [manualIp, setManualIp] = useState('');
  const [manualReason, setManualReason] = useState('');

  const formatTime = (ts: number) => {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  };

  const fetchData = async (p = page, ps = pageSize) => {
    setLoading(true);
    try {
      const res: any = await get(adminPath('/security/logs'), {
        page: p,
        page_size: ps,
        ip: searchIp,
        status_code: statusCode,
        keyword: keyword,
      });
      const data = res?.data?.data || res?.data || [];
      const totalCount = res?.data?.total || res?.total || 0;
      setList(data);
      setTotal(totalCount);

      // 加载大盘统计
      const statsRes: any = await get(adminPath('/security/stats'));
      if (statsRes?.data) {
        setStats(statsRes.data);
      }
    } catch (e: any) {
      message.error(e?.message || '获取入侵感知数据失败');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData(1, pageSize);
  }, []);

  const handleSearch = () => {
    setPage(1);
    fetchData(1, pageSize);
  };

  const handleReset = () => {
    setSearchIp('');
    setStatusCode(undefined);
    setKeyword('');
    setPage(1);
    fetchData(1, pageSize);
  };

  const openDetail = (index: number) => {
    setCurrentIndex(index);
    setDetailVisible(true);
  };

  const handleBanIp = async (ip: string) => {
    try {
      const res: any = await post(adminPath('/security/ban'), {
        ip,
        reason: '入侵感知后台一键封禁',
      });
      if (res?.code && res.code !== 200) {
        return;
      }
      message.success(`IP 【${ip}】 已成功封禁！`);
      fetchData();
      if (blacklistVisible) fetchBlacklist();
    } catch (e: any) {
      message.error(e?.message || '封禁操作失败');
    }
  };

  const openBlacklist = () => {
    setBlacklistVisible(true);
    fetchBlacklist();
  };

  const fetchBlacklist = async () => {
    try {
      const res: any = await get(adminPath('/security/blacklist'));
      setBlacklist(res?.data || []);
    } catch (e: any) {
      message.error('获取黑名单失败');
    }
  };

  const handleManualBan = async () => {
    if (!manualIp.trim()) {
      message.warning('请输入有效的 IP 地址');
      return;
    }
    try {
      const res: any = await post(adminPath('/security/ban'), {
        ip: manualIp.trim(),
        reason: manualReason.trim() || '后台手动添加',
      });
      if (res?.code && res.code !== 200) {
        return;
      }
      message.success('封禁成功');
      setManualIp('');
      setManualReason('');
      fetchBlacklist();
      fetchData();
    } catch (e: any) {
      message.error(e?.message || '添加失败');
    }
  };

  const handleUnbanIp = async (ip: string) => {
    try {
      const res: any = await post(adminPath('/security/unban'), { ip });
      if (res?.code && res.code !== 200) {
        return;
      }
      message.success(`IP 【${ip}】 已成功解除封禁`);
      fetchBlacklist();
      fetchData();
    } catch (e: any) {
      message.error('解封失败');
    }
  };

  const handleDropLogs = async () => {
    try {
      await post(adminPath('/security/dropLogs'));
      message.success('已清空 7 天前的入侵感知日志');
      fetchData(1, pageSize);
    } catch (e: any) {
      message.error('清空日志失败');
    }
  };

  const columns = [
    {
      title: '发生时间',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 175,
      render: (ts: number) => <span>{formatTime(ts)}</span>,
    },
    {
      title: '威胁源 IP / 归属地',
      key: 'ip_loc',
      render: (_: any, record: SecurityLogItem) => (
        <div>
          <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#1e293b' }}>
            {record.ip}
          </span>
          <br />
          <Tag color="cyan" style={{ marginTop: 2 }}>{record.location || '未知归属'}</Tag>
        </div>
      ),
    },
    {
      title: '事件类型',
      dataIndex: 'rule_name',
      key: 'rule_name',
      width: 170,
      render: (name: string) => (
        <Tag color={name && name.includes('越权') ? 'error' : 'warning'}>
          {name || '异常探测'}
        </Tag>
      ),
    },
    {
      title: '攻击请求 URL',
      dataIndex: 'url',
      key: 'url',
      ellipsis: true,
      render: (url: string, record: SecurityLogItem) => (
        <span>
          <Tag color="blue" style={{ marginRight: 6 }}>{record.method}</Tag>
          <span style={{ fontFamily: 'monospace', fontSize: 13 }}>{url}</span>
        </span>
      ),
    },
    {
      title: '状态码',
      dataIndex: 'status_code',
      key: 'status_code',
      width: 90,
      align: 'center' as const,
      render: (code: number) => (
        <Tag color={code === 403 ? 'red' : (code === 404 ? 'orange' : 'purple')}>
          {code}
        </Tag>
      ),
    },
    {
      title: '耗时',
      dataIndex: 'duration',
      key: 'duration',
      width: 80,
      align: 'center' as const,
      render: (ms: number) => <span style={{ color: '#64748b', fontSize: 12 }}>{ms || 1}ms</span>,
    },
    {
      title: '操作',
      key: 'action',
      width: 200,
      align: 'center' as const,
      render: (_: any, record: SecurityLogItem, index: number) => (
        <Space>
          <Button size="small" type="primary" ghost onClick={() => openDetail(index)}>
            详情
          </Button>
          {!record.is_banned ? (
            <Popconfirm
              title="确定将此 IP 永久加入黑名单吗？"
              onConfirm={() => handleBanIp(record.ip)}
              okText="封禁"
              cancelText="取消"
              okButtonProps={{ danger: true }}
            >
              <Button size="small" danger>一键封禁</Button>
            </Popconfirm>
          ) : (
            <Tag color="default">已在黑名单</Tag>
          )}
        </Space>
      ),
    },
  ];

  return (
    <AdminLayout>
      <div style={{ padding: '4px 0' }}>
        {/* 指标大盘 */}
        <Row gutter={16} style={{ marginBottom: 16 }}>
          <Col xs={24} sm={8}>
            <Card style={{ borderRadius: 8, border: '1px solid #fecaca', background: '#fef2f2' }}>
              <Statistic
                title={<span style={{ color: '#991b1b', fontWeight: 500 }}>🚨 今日防御拦截</span>}
                value={stats.today_count}
                suffix="次"
                valueStyle={{ color: '#dc2626', fontWeight: 'bold' }}
                prefix={<AlertOutlined />}
              />
            </Card>
          </Col>
          <Col xs={24} sm={8}>
            <Card style={{ borderRadius: 8, border: '1px solid #bfdbfe', background: '#eff6ff' }}>
              <Statistic
                title={<span style={{ color: '#1e40af', fontWeight: 500 }}>🛡️ 累计恶意阻断</span>}
                value={stats.total_blocked}
                suffix="次"
                valueStyle={{ color: '#2563eb', fontWeight: 'bold' }}
                prefix={<SafetyCertificateOutlined />}
              />
            </Card>
          </Col>
          <Col xs={24} sm={8}>
            <Card style={{ borderRadius: 8, border: '1px solid #fed7aa', background: '#fff7ed' }}>
              <Statistic
                title={<span style={{ color: '#9a3412', fontWeight: 500 }}>🚫 永久封禁黑名单</span>}
                value={stats.blacklist_count}
                suffix="个"
                valueStyle={{ color: '#ea580c', fontWeight: 'bold' }}
                prefix={<StopOutlined />}
              />
            </Card>
          </Col>
        </Row>

        {/* 主卡片 */}
        <Card
          title={
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ fontSize: 17, fontWeight: 700 }}>🛡️ 入侵感知防御系统</span>
              <Tag color="red">实时威胁捕获</Tag>
            </div>
          }
          extra={
            <Space>
              <Button danger icon={<LockOutlined />} onClick={openBlacklist}>
                黑名单库 ({stats.blacklist_count})
              </Button>
              <Popconfirm title="确定清理 7 天前旧日志？" onConfirm={handleDropLogs}>
                <Button icon={<DeleteOutlined />}>清空旧日志</Button>
              </Popconfirm>
              <Button type="primary" icon={<ReloadOutlined />} onClick={() => fetchData()}>
                刷新
              </Button>
            </Space>
          }
          style={{ borderRadius: 8 }}
        >
          {/* 筛选栏 */}
          <Space wrap style={{ marginBottom: 16 }}>
            <Input
              placeholder="搜索攻击者 IP"
              value={searchIp}
              onChange={(e) => setSearchIp(e.target.value)}
              onPressEnter={handleSearch}
              style={{ width: 170 }}
              allowClear
            />
            <Select
              placeholder="状态码"
              value={statusCode}
              onChange={setStatusCode}
              style={{ width: 130 }}
              allowClear
            >
              <Option value={403}>403 Forbidden</Option>
              <Option value={404}>404 Not Found</Option>
              <Option value={500}>500 Error</Option>
            </Select>
            <Input
              placeholder="搜索关键词 / URL / 规则"
              value={keyword}
              onChange={(e) => setKeyword(e.target.value)}
              onPressEnter={handleSearch}
              style={{ width: 220 }}
              allowClear
            />
            <Button type="primary" icon={<SearchOutlined />} onClick={handleSearch}>查询</Button>
            <Button onClick={handleReset}>重置</Button>
          </Space>

          {/* 表格 */}
          <Table
            rowKey="id"
            loading={loading}
            dataSource={list}
            columns={columns}
            pagination={{
              current: page,
              pageSize,
              total,
              showSizeChanger: true,
              pageSizeOptions: ['15', '30', '50', '100'],
              showTotal: (t) => `共 ${t} 条威胁事件`,
              onChange: (p, ps) => {
                setPage(p);
                setPageSize(ps);
                fetchData(p, ps);
              },
            }}
          />
        </Card>

        {/* 图2风格详情 Modal 弹窗 */}
        <Modal
          title={
            <span>
              【<strong style={{ color: '#059669' }}>{currentDetail.ip || ''}</strong>】详情信息 -- {formatTime(currentDetail.created_at)}
            </span>
          }
          open={detailVisible}
          onCancel={() => setDetailVisible(false)}
          width={680}
          footer={
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                {!currentDetail.is_banned ? (
                  <Popconfirm
                    title={`确定一键永久封禁 IP 【${currentDetail.ip}】 吗？`}
                    onConfirm={() => handleBanIp(currentDetail.ip)}
                    okText="确定封禁"
                    okButtonProps={{ danger: true }}
                  >
                    <Button type="primary" danger icon={<LockOutlined />}>
                      一键永久封禁此 IP
                    </Button>
                  </Popconfirm>
                ) : (
                  <Tag color="red">该 IP 已处于永久封禁中</Tag>
                )}
              </div>
              <Space>
                <Button
                  icon={<LeftOutlined />}
                  disabled={currentIndex <= 0}
                  onClick={() => setCurrentIndex((idx) => Math.max(0, idx - 1))}
                >
                  上一条
                </Button>
                <Button
                  type="primary"
                  style={{ background: '#10b981', borderColor: '#10b981' }}
                  icon={<RightOutlined />}
                  disabled={currentIndex >= list.length - 1}
                  onClick={() => setCurrentIndex((idx) => Math.min(list.length - 1, idx + 1))}
                >
                  下一条
                </Button>
              </Space>
            </div>
          }
        >
          {currentDetail && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div>
                <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>客户端IP</div>
                <div style={{ fontSize: 16, fontWeight: 700, fontFamily: 'monospace', color: '#059669' }}>
                  {currentDetail.ip} <span style={{ fontSize: 13, color: '#475569', fontWeight: 'normal' }}>({currentDetail.location || '未知归属'})</span>
                </div>
              </div>

              <Row gutter={12}>
                <Col span={6}>
                  <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>类型</div>
                  <div style={{ fontWeight: 600 }}>{currentDetail.method}</div>
                </Col>
                <Col span={6}>
                  <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>状态码</div>
                  <div style={{ color: '#dc2626', fontWeight: 700, fontSize: 15 }}>{currentDetail.status_code}</div>
                </Col>
                <Col span={6}>
                  <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>响应类型</div>
                  <div>application/json</div>
                </Col>
                <Col span={6}>
                  <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>处理耗时</div>
                  <div>{currentDetail.duration || 1} ms</div>
                </Col>
              </Row>

              <div>
                <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>URL</div>
                <div style={{ background: '#f1f5f9', padding: '8px 12px', borderRadius: 6, color: '#b91c1c', fontFamily: 'monospace', fontSize: 13, wordBreak: 'break-all' }}>
                  {currentDetail.url}
                </div>
              </div>

              <div>
                <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>User-Agent</div>
                <div style={{ background: '#f8fafc', padding: '8px 12px', borderRadius: 6, border: '1px solid #e2e8f0', fontSize: 12, color: '#475569', wordBreak: 'break-all' }}>
                  {currentDetail.user_agent || '-'}
                </div>
              </div>

              <div>
                <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>防御命中规则</div>
                <div style={{ color: '#d97706', fontWeight: 700 }}>
                  {currentDetail.rule_name || '入侵感知防御'}
                </div>
              </div>

              <div>
                <div style={{ color: '#64748b', fontSize: 13, marginBottom: 4 }}>IP详情</div>
                <div style={{ background: '#ecfdf5', color: '#065f46', padding: '8px 12px', borderRadius: 6, border: '1px solid #a7f3d0' }}>
                  位置：{currentDetail.location || '未知地理信息'}
                </div>
              </div>
            </div>
          )}
        </Modal>

        {/* 黑名单管理抽屉 */}
        <Drawer
          title="🚫 永久封禁 IP 黑名单库"
          open={blacklistVisible}
          onClose={() => setBlacklistVisible(false)}
          width={580}
        >
          <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
            <Input
              placeholder="输入 IP 地址 (IPv4 / IPv6)"
              value={manualIp}
              onChange={(e) => setManualIp(e.target.value)}
              style={{ flex: 1 }}
            />
            <Input
              placeholder="封禁备注原因"
              value={manualReason}
              onChange={(e) => setManualReason(e.target.value)}
              style={{ width: 140 }}
            />
            <Button type="primary" danger icon={<PlusOutlined />} onClick={handleManualBan}>
              封禁
            </Button>
          </div>

          <Table
            rowKey="id"
            dataSource={blacklist}
            pagination={false}
            columns={[
              {
                title: '已封禁 IP',
                dataIndex: 'ip',
                key: 'ip',
                render: (ip: string) => <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{ip}</span>,
              },
              {
                title: '封禁原因',
                dataIndex: 'reason',
                key: 'reason',
              },
              {
                title: '封禁时间',
                dataIndex: 'created_at',
                key: 'created_at',
                render: (ts: number) => formatTime(ts),
              },
              {
                title: '操作',
                key: 'action',
                align: 'center' as const,
                render: (_: any, record: BlacklistItem) => (
                  <Popconfirm title="确定解封此 IP 吗？" onConfirm={() => handleUnbanIp(record.ip)}>
                    <Button type="link" size="small" icon={<UnlockOutlined />}>解封</Button>
                  </Popconfirm>
                ),
              },
            ]}
          />
        </Drawer>
      </div>
    </AdminLayout>
  );
}
