import React, { useState, useEffect, useMemo } from 'react';
import {
  Card, Row, Col, Table, Tag, Button, Input, Select, Modal, Tabs, Form,
  Switch, InputNumber, Radio, Progress, Descriptions, Tooltip, Timeline,
  message, Dropdown, Space, Avatar, Checkbox, Pagination, Empty
} from 'antd';
import {
  WarningOutlined, DashboardOutlined, LockOutlined, SearchOutlined,
  SettingOutlined, DeleteOutlined, SyncOutlined, InfoCircleOutlined,
  QuestionCircleOutlined, DownOutlined, UserOutlined, ProfileOutlined,
  SafetyCertificateOutlined, ApiOutlined, BlockOutlined
} from '@ant-design/icons';
import { get, post } from '@/api/request';
import { adminPath } from '@/app/settings';
import { UserDrawer } from '../user/UserDrawer';
import { useNavigate } from 'react-router';
import { AdminLayout } from '@/layouts/AdminLayout';
import { useMobile } from '@/hooks/useMobile';

const { Option } = Select;
const { TabPane } = Tabs;

export default function SecurityAuditPage() {
  const navigate = useNavigate();

  const handleToggleBan = async () => {
    try {
      await post(adminPath('/user/ban'), { filter: [{ key: 'id', condition: '=', value: userId }] });
      message.success('操作成功');
      get(adminPath('/user/fetch'), { filter: [{ key: 'id', condition: '=', value: userId }] })
        .then((res: any) => { const list = res?.data?.data || res?.data || res || []; if (list && list.length > 0) setData(list[0]); });
    } catch (err: any) { message.error(err.message || '操作失败'); }
  };

  const handleToggleHoneypot = async () => {
    try {
      await post(adminPath('/user/toggleHoneypot'), { id: userId });
      message.success('操作成功');
      get(adminPath('/user/fetch'), { filter: [{ key: 'id', condition: '=', value: userId }] })
        .then((res: any) => { const list = res?.data?.data || res?.data || res || []; if (list && list.length > 0) setData(list[0]); });
    } catch (err: any) { message.error(err.message || '操作失败'); }
  };
  
  // Data States
  const [anomaliesRawList, setAnomaliesRawList] = useState<any[]>([]);
  const [flaggedCount, setFlaggedCount] = useState(0);
  const [suspectedCount, setSuspectedCount] = useState(0);
  const [whitelistList, setWhitelistList] = useState<string[]>([]);
  const [honeypotList, setHoneypotList] = useState<string[]>([]);
  const [bannedIpsList, setBannedIpsList] = useState<string[]>([]);
  const [ignoreIpsList, setIgnoreIpsList] = useState<string[]>([]);
  const [config, setConfig] = useState<any>({});
  
  // UI States
  const [loading, setLoading] = useState(false);
  const [searchKw, setSearchKw] = useState('');
  const [filterType, setFilterType] = useState('all');

  const fetchAnomalies = async () => {
    setLoading(true);
    try {
      const res: any = await get(adminPath('/stat/getSubscriptionAnomalies'));
      if (res) {
        setAnomaliesRawList((res.data || res).list || []);
        setWhitelistList((res.data || res).whitelist || []);
        setBannedIpsList((res.data || res).banned_ips || []);
        setIgnoreIpsList((res.data || res).ignore_ips || []);
        setConfig((res.data || res).config || {});
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchAnomalies();
  }, []);

  const filteredAnomaliesList = useMemo(() => {
    let list = anomaliesRawList;
    if (searchKw.trim()) {
      const q = searchKw.trim().toLowerCase();
      list = list.filter(item => 
        String(item.email || '').toLowerCase().includes(q) || 
        String(item.user_id).includes(q)
      );
    }
    if (filterType === 'flagged') {
      list = list.filter(item => item.type === 'flagged');
    } else if (filterType === 'suspected') {
      list = list.filter(item => item.type === 'suspected');
    } else if (filterType === 'honeypot') {
      list = list.filter(item => item.in_honeypot === 1);
    }
    return list;
  }, [anomaliesRawList, searchKw, filterType]);

  
  
  const formatTime = (timestamp: number) => {
    if (!timestamp) return '无记录';
    const date = new Date(timestamp * 1000);
    return date.toLocaleString();
  };

  const handleClearAllAnomalies = () => {
    Modal.confirm({
      title: '警告',
      content: '确定要忽略全部待处理的审计预警吗？此操作将清除所有当前的警报记录。',
      okType: 'danger',
      onOk: async () => {
        try {
          await post(adminPath('/stat/clearAllAnomalies'));
          message.success('已成功清空所有审计记录');
          fetchAnomalies();
        } catch (e) {}
      }
    });
  };

  // --- Modals State ---
  const [settingsVisible, setSettingsVisible] = useState(false);
  const [radarVisible, setRadarVisible] = useState(false);
  const [customAuditVisible, setCustomAuditVisible] = useState(false);
  const [userDetailVisible, setUserDetailVisible] = useState(false);
  const [activeUserId, setActiveUserId] = useState<number | null>(null);
  const isMobile = useMobile();
  const [mobilePage, setMobilePage] = useState(1);
  const [expandedMobileIds, setExpandedMobileIds] = useState<number[]>([]);
  const toggleMobileExpand = (id: number) => {
    setExpandedMobileIds(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  };
  const mobilePageSize = 10;
  const pagedAnomalies = useMemo(() => {
    const start = (mobilePage - 1) * mobilePageSize;
    return filteredAnomaliesList.slice(start, start + mobilePageSize);
  }, [filteredAnomaliesList, mobilePage]);

  return (
    <AdminLayout title="安全审计">
      <div style={{ padding: isMobile ? "0 2px" : "0 4px" }}>
      <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
        <Col xs={24} sm={8}>
          <Card hoverable bodyStyle={{ padding: isMobile ? 14 : 20 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <div style={{ color: '#8c8c8c', marginBottom: 4, fontSize: 13 }}>待处理高风险拦截</div>
                <div style={{ fontSize: isMobile ? 20 : 24, fontWeight: 'bold', color: '#ff4d4f' }}>{flaggedCount}</div>
              </div>
              <div style={{ width: 40, height: 40, borderRadius: 10, background: 'rgba(245, 108, 108, 0.12)', color: '#ff4d4f', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18 }}>
                <WarningOutlined />
              </div>
            </div>
          </Card>
        </Col>
        <Col xs={24} sm={8}>
          <Card hoverable bodyStyle={{ padding: isMobile ? 14 : 20 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <div style={{ color: '#8c8c8c', marginBottom: 4, fontSize: 13 }}>疑似工具拉取记录</div>
                <div style={{ fontSize: isMobile ? 20 : 24, fontWeight: 'bold', color: '#faad14' }}>{suspectedCount}</div>
              </div>
              <div style={{ width: 40, height: 40, borderRadius: 10, background: 'rgba(250, 173, 20, 0.12)', color: '#faad14', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18 }}>
                <DashboardOutlined />
              </div>
            </div>
          </Card>
        </Col>
        <Col xs={24} sm={8}>
          <Card hoverable bodyStyle={{ padding: isMobile ? 14 : 20 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <div>
                <div style={{ color: '#8c8c8c', marginBottom: 4, fontSize: 13 }}>已启用白名单/蜜罐</div>
                <div style={{ fontSize: isMobile ? 20 : 24, fontWeight: 'bold', color: '#1890ff' }}>
                  {whitelistList.length} <span style={{ fontSize: 12, fontWeight: 'normal', color: '#8c8c8c' }}>白</span> / {honeypotList.length} <span style={{ fontSize: 12, fontWeight: 'normal', color: '#8c8c8c' }}>蜜</span>
                </div>
              </div>
              <div style={{ width: 40, height: 40, borderRadius: 10, background: 'rgba(24, 144, 255, 0.12)', color: '#1890ff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18 }}>
                <LockOutlined />
              </div>
            </div>
          </Card>
        </Col>
      </Row>

      <Card 
        title={
          <div>
            <div style={{ fontSize: 16, fontWeight: 'bold' }}>订阅安全审计中心</div>
            <div style={{ fontSize: 12, color: '#8c8c8c', fontWeight: 'normal', marginTop: 4 }}>对多 IP 扩散分享、高频测活、命令行客户端等进行精细化审查与蜜罐重定向管理</div>
          </div>
        }
        extra={
          !isMobile ? (
            <Space wrap>
              <Button type="primary" ghost icon={<ApiOutlined />} onClick={() => setCustomAuditVisible(true)}>自定义特征探测</Button>
              <Button style={{ color: '#faad14', borderColor: '#faad14' }} icon={<BlockOutlined />} onClick={() => setRadarVisible(true)}>IP 关联分析</Button>
              <Button type="primary" icon={<SettingOutlined />} onClick={() => setSettingsVisible(true)}>审计规则 & 白名单</Button>
              <Button danger icon={<DeleteOutlined />} disabled={flaggedCount === 0} onClick={handleClearAllAnomalies}>一键忽略全部</Button>
              <Button icon={<SyncOutlined />} loading={loading} onClick={fetchAnomalies}>刷新</Button>
            </Space>
          ) : null
        }
      >
        {isMobile && (
          <div style={{ marginBottom: 14 }}>
            <Space wrap size={[6, 6]}>
              <Button size="small" type="primary" ghost icon={<ApiOutlined />} onClick={() => setCustomAuditVisible(true)}>特征探测</Button>
              <Button size="small" style={{ color: '#faad14', borderColor: '#faad14' }} icon={<BlockOutlined />} onClick={() => setRadarVisible(true)}>IP关联</Button>
              <Button size="small" type="primary" icon={<SettingOutlined />} onClick={() => setSettingsVisible(true)}>审计设置</Button>
              <Button size="small" danger icon={<DeleteOutlined />} disabled={flaggedCount === 0} onClick={handleClearAllAnomalies}>忽略全部</Button>
              <Button size="small" icon={<SyncOutlined />} loading={loading} onClick={fetchAnomalies}>刷新</Button>
            </Space>
          </div>
        )}

        <div style={{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', justifyContent: 'space-between', gap: 10, marginBottom: 16 }}>
          <Space direction={isMobile ? 'vertical' : 'horizontal'} style={{ width: isMobile ? '100%' : 'auto' }}>
            <Input 
              placeholder="搜索邮箱或用户 ID..." 
              prefix={<SearchOutlined />} 
              value={searchKw}
              onChange={e => setSearchKw(e.target.value)}
              allowClear
              style={{ width: isMobile ? '100%' : 220 }}
            />
            <Select value={filterType} onChange={setFilterType} style={{ width: isMobile ? '100%' : 180 }}>
              <Option value="all">全部记录</Option>
              <Option value="flagged">仅看审计拦截 (高风险)</Option>
              <Option value="suspected">仅看疑似工具 (低风险)</Option>
              <Option value="honeypot">仅看已接管蜜罐</Option>
            </Select>
          </Space>
          <div style={{ fontSize: 13, color: '#8c8c8c', alignSelf: isMobile ? 'flex-start' : 'center' }}>
            共筛选出 <strong>{filteredAnomaliesList.length}</strong> 条审计数据
          </div>
        </div>

        {isMobile ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {pagedAnomalies.map((record) => {
              const isExpanded = expandedMobileIds.includes(record.user_id);
              return (
                <Card
                  key={record.user_id}
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
                        onClick={() => { setActiveUserId(record.user_id); setUserDetailVisible(true); }}
                        style={{ fontWeight: 600, fontSize: 14, color: '#1677ff', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                      >
                        {record.email || '未知用户'}
                      </a>
                      <Tag color="default" style={{ margin: 0, fontSize: 11, flexShrink: 0 }}>UID:{record.user_id}</Tag>
                    </div>
                    <div style={{ display: 'flex', gap: 4, flexShrink: 0 }}>
                      {record.risk_level === 'high' ? <Tag color="red" style={{ margin: 0 }}>高危</Tag> : <Tag color="orange" style={{ margin: 0 }}>疑似</Tag>}
                      {record.in_honeypot === 1 && <Tag color="warning" style={{ margin: 0 }}>蜜罐</Tag>}
                    </div>
                  </div>

                  <div style={{ fontSize: 12, color: '#888', marginBottom: 6 }}>
                    时间: {formatTime(record.flagged_at)}
                  </div>

                  {record.reasons && record.reasons.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginBottom: 8 }}>
                      {record.reasons.map((r: string, i: number) => (
                        <Tag key={i} color={record.risk_level === 'high' ? 'red' : 'orange'} style={{ margin: 0, fontSize: 11 }}>
                          {r}
                        </Tag>
                      ))}
                    </div>
                  )}

                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px dashed #e8e8e8', paddingTop: 8, marginTop: 4 }}>
                    <Space size={4} wrap>
                      <Button
                        size="small"
                        type={record.in_honeypot === 1 ? 'primary' : 'default'}
                        danger={record.in_honeypot !== 1}
                        onClick={() => {
                          Modal.confirm({
                            title: '提示',
                            content: `确定要将该用户 ${record.email} ${record.in_honeypot === 1 ? '移出蜜罐' : '加入蜜罐'}吗？`,
                            onOk: async () => {
                              await post(adminPath('/user/toggleHoneypot'), { id: record.user_id });
                              message.success('操作成功');
                              fetchAnomalies();
                            }
                          });
                        }}
                      >
                        {record.in_honeypot === 1 ? '解除蜜罐' : '一键蜜罐'}
                      </Button>
                      <Button
                        size="small"
                        danger
                        disabled={record.banned === 1}
                        onClick={() => {
                          Modal.confirm({
                            title: '警告',
                            content: `确定要封禁用户 ${record.email} 吗？`,
                            okType: 'danger',
                            onOk: async () => {
                              await post(adminPath('/user/ban'), { filter: [{ key: 'id', condition: '=', value: record.user_id }] });
                              message.success('封禁成功');
                              fetchAnomalies();
                            }
                          });
                        }}
                      >
                        {record.banned === 1 ? '已封' : '封禁'}
                      </Button>
                      <Dropdown
                        menu={{
                          items: [
                            ...(record.type === 'flagged' ? [{
                              key: 'ignore',
                              label: '忽略预警',
                              onClick: () => {
                                Modal.confirm({
                                  title: '提示',
                                  content: `确定要忽略此条对用户 ${record.email} 的审计拦截吗？`,
                                  onOk: async () => {
                                    await post(adminPath('/stat/ignoreAnomaly'), { id: record.user_id });
                                    message.success('已忽略');
                                    fetchAnomalies();
                                  }
                                });
                              }
                            }] : []),
                            {
                              key: 'whitelist',
                              label: '加入白名单',
                              onClick: () => {
                                Modal.confirm({
                                  title: '提示',
                                  content: `确定要将用户 ${record.email} 加入白名单吗？`,
                                  onOk: async () => {
                                    await post(adminPath('/stat/whitelistUser'), { id: record.user_id });
                                    message.success('已加入白名单');
                                    fetchAnomalies();
                                  }
                                });
                              }
                            }
                          ]
                        }}
                      >
                        <Button size="small">更多 <DownOutlined /></Button>
                      </Dropdown>
                    </Space>

                    <Button type="link" size="small" style={{ padding: 0 }} onClick={() => toggleMobileExpand(record.user_id)}>
                      {isExpanded ? '收起轨迹' : '轨迹'}
                    </Button>
                  </div>

                  {isExpanded && (
                    <div style={{ marginTop: 10, paddingTop: 10, borderTop: '1px solid #f0f0f0' }}>
                      <AnomalyHistory record={record} onRefresh={fetchAnomalies} />
                    </div>
                  )}
                </Card>
              );
            })}

            {filteredAnomaliesList.length === 0 && !loading && (
              <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无审计记录" />
            )}

            <div style={{ display: 'flex', justifyContent: 'center', marginTop: 14 }}>
              <Pagination
                simple
                current={mobilePage}
                pageSize={mobilePageSize}
                total={filteredAnomaliesList.length}
                onChange={setMobilePage}
              />
            </div>
          </div>
        ) : (
          <Table 
            dataSource={filteredAnomaliesList} 
            rowKey="user_id" 
            loading={loading}
            pagination={{ pageSize: 15 }}
            expandable={{
              expandedRowRender: record => <AnomalyHistory record={record} onRefresh={fetchAnomalies} />
            }}
            columns={[
              {
                title: 'ID',
                dataIndex: 'user_id',
                width: 80,
                render: (text) => <a onClick={() => { setActiveUserId(text); setUserDetailVisible(true); }}>{text}</a>
              },
              {
                title: '邮箱',
                dataIndex: 'email',
                render: (text, record) => <a onClick={() => { setActiveUserId(record.user_id); setUserDetailVisible(true); }}>{text}</a>
              },
              {
                title: '审计时间',
                dataIndex: 'flagged_at',
                render: text => formatTime(text)
              },
              {
                title: '风险评估',
                dataIndex: 'risk_level',
                render: text => text === 'high' ? <Tag color="red">审计拦截 (高)</Tag> : <Tag color="orange">疑似工具 (低)</Tag>
              },
              {
                title: '判定原委',
                dataIndex: 'reasons',
                render: (reasons: string[], record) => (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
                    {reasons && reasons.map((r, i) => (
                      <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                        <Tag color={record.risk_level === 'high' ? 'red' : 'orange'} style={{ whiteSpace: 'normal', height: 'auto', padding: '2px 6px' }}>
                          {r}
                        </Tag>
                        {r.toLowerCase().includes('curl') && (
                          <Tooltip title="提示: curl 请求极有可能是 OpenWrt 软路由插件正常拉取，请结合下方的拉取 IP 记录进行确认，不要误封正常用户。">
                            <QuestionCircleOutlined style={{ color: '#faad14', cursor: 'help' }} />
                          </Tooltip>
                        )}
                      </div>
                    ))}
                  </div>
                )
              },
              {
                title: '蜜罐状态',
                dataIndex: 'in_honeypot',
                render: val => val === 1 ? <Tag color="orange">蜜罐接管中</Tag> : <Tag>未接管</Tag>
              },
              {
                title: '操作',
                key: 'action',
                width: 250,
                align: 'right',
                render: (_, record) => (
                  <Space>
                    <Button 
                      size="small" 
                      type={record.in_honeypot === 1 ? 'primary' : 'default'} 
                      danger={record.in_honeypot !== 1}
                      onClick={() => {
                        Modal.confirm({
                          title: '提示',
                          content: `确定要将该用户 ${record.email} ${record.in_honeypot === 1 ? '移出蜜罐' : '加入蜜罐'}吗？`,
                          onOk: async () => {
                            await post(adminPath('/user/toggleHoneypot'), { id: record.user_id });
                            message.success('操作成功');
                            fetchAnomalies();
                          }
                        });
                      }}
                    >
                      {record.in_honeypot === 1 ? '解除蜜罐' : '一键蜜罐'}
                    </Button>
                    <Button 
                      size="small" 
                      danger 
                      disabled={record.banned === 1}
                      onClick={() => {
                        Modal.confirm({
                          title: '警告',
                          content: `确定要封禁用户 ${record.email} 吗？`,
                          okType: 'danger',
                          onOk: async () => {
                            await post(adminPath('/user/ban'), { filter: [{ key: 'id', condition: '=', value: record.user_id }] });
                            message.success('封禁成功');
                            fetchAnomalies();
                          }
                        });
                      }}
                    >
                      {record.banned === 1 ? '已封禁' : '封禁'}
                    </Button>
                    <Dropdown
                      menu={{
                        items: [
                          ...(record.type === 'flagged' ? [{
                            key: 'ignore',
                            label: '忽略预警',
                            onClick: () => {
                              Modal.confirm({
                                title: '提示',
                                content: `确定要忽略此条对用户 ${record.email} 的审计拦截吗？`,
                                onOk: async () => {
                                  await post(adminPath('/stat/ignoreAnomaly'), { id: record.user_id });
                                  message.success('已忽略');
                                  fetchAnomalies();
                                }
                              });
                            }
                          }] : []),
                          {
                            key: 'whitelist',
                            label: '加入白名单',
                            onClick: () => {
                              Modal.confirm({
                                title: '提示',
                                content: `确定要将用户 ${record.email} 加入白名单吗？`,
                                onOk: async () => {
                                  await post(adminPath('/stat/whitelistUser'), { id: record.user_id });
                                  message.success('已加入白名单');
                                  fetchAnomalies();
                                }
                              });
                            }
                          }
                        ]
                      }}
                    >
                      <Button size="small">更多 <DownOutlined /></Button>
                    </Dropdown>
                  </Space>
                )
              }
            ]}
          />
        )}
      </Card>

      {settingsVisible && (
        <SettingsModal 
          visible={settingsVisible} 
          onCancel={() => setSettingsVisible(false)} 
          config={config} 
          whitelist={whitelistList}
          bannedIps={bannedIpsList}
          ignoreIps={ignoreIpsList}
          onRefresh={fetchAnomalies}
        />
      )}

      {radarVisible && (
        <IpRadarModal 
          visible={radarVisible} 
          onCancel={() => setRadarVisible(false)} 
          onRefresh={fetchAnomalies}
        />
      )}

      {customAuditVisible && (
        <CustomAuditModal 
          visible={customAuditVisible} 
          onCancel={() => setCustomAuditVisible(false)} 
          onRefresh={fetchAnomalies}
        />
      )}

      {userDetailVisible && activeUserId && (
        <UserDetailModal 
          visible={userDetailVisible}
          userId={activeUserId}
          onCancel={() => { setUserDetailVisible(false); setActiveUserId(null); }}
        />
      )}
    </div>
    </AdminLayout>
  );
}

// --- Sub Components ---

function AnomalyHistory({ record, onRefresh }: { record: any, onRefresh: () => void }) {
  const formatTime = (ts: number) => new Date(ts * 1000).toLocaleString();
  
  if (!record.history || record.history.length === 0) {
    return <div style={{ textAlign: 'center', padding: '10px 0', color: '#999' }}>暂无历史拉取记录</div>;
  }

  return (
    <div style={{ padding: '10px 20px', background: '#fafafa', borderRadius: 8 }}>
      <h4 style={{ marginBottom: 16 }}><DashboardOutlined /> 最近 5 次拉取详细审计轨迹</h4>
      <Timeline>
        {record.history.map((h: any, i: number) => {
          const isCurl = h.ua && (h.ua.toLowerCase().includes('curl') || h.ua.toLowerCase().includes('wget'));
          return (
            <Timeline.Item key={i} color={isCurl ? 'orange' : 'blue'}>
              <div style={{ marginBottom: 4, fontWeight: 'bold' }}>{formatTime(h.time)}</div>
              <Card size="small" bordered={false} style={{ width: '100%', maxWidth: 800, background: '#fff' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 4 }}>
                  <span><strong>拉取 IP:</strong> <code style={{ margin: '0 4px', padding: '2px 4px', background: '#f0f0f0', borderRadius: 4 }}>{h.ip}</code> <span style={{ color: '#888', fontSize: 12 }}>({h.location})</span></span>
                  {h.ip && (
                    <Button type="link" danger size="small" style={{ padding: 0 }} onClick={() => {
                      Modal.confirm({
                        title: '警告',
                        content: `确定要封禁该 IP 地址 ${h.ip} 吗？`,
                        onOk: async () => {
                          await post(adminPath('/stat/banIp'), { ip: h.ip });
                          message.success('封禁成功');
                          onRefresh();
                        }
                      });
                    }}>
                      (一键封禁此 IP)
                    </Button>
                  )}
                  {isCurl && (
                    <span style={{ color: '#faad14', fontSize: 12 }}><InfoCircleOutlined /> 该 IP 使用 curl 拉取，多为 OpenWrt 等设备</span>
                  )}
                </div>
                <div style={{ marginBottom: 4 }}><strong>拉取类型:</strong> <Tag style={{ marginLeft: 4 }}>{h.type}</Tag></div>
                <div><strong>客户端 User-Agent:</strong> <code style={{ color: '#666' }}>{h.ua}</code></div>
              </Card>
            </Timeline.Item>
          );
        })}
      </Timeline>
    </div>
  );
}

function SettingsModal({ visible, onCancel, config, whitelist, bannedIps, ignoreIps, onRefresh }: any) {
  const [form] = Form.useForm();
  const [saving, setSaving] = useState(false);
  const isMobile = useMobile();
  
  const [newWhite, setNewWhite] = useState('');
  const [newBanned, setNewBanned] = useState('');
  const [newIgnore, setNewIgnore] = useState('');

  useEffect(() => {
    form.setFieldsValue({
      ip_limit: config.ip_limit || 10,
      audit_ua_enabled: config.audit_ua_enabled !== false,
      audit_ua_keywords: Array.isArray(config.audit_ua_keywords) ? config.audit_ua_keywords.join('\n') : '',
      banned_strategy: config.banned_strategy || 'bait',
      banned_redirect_url: config.banned_redirect_url || '',
      banned_keywords: config.banned_keywords || '',
      replace_keyword_to: config.replace_keyword_to || '精品线路',
      subconverter_enable: config.subconverter_enable !== false,
      subconverter_url: config.subconverter_url || 'https://api.wcc.best/sub',
      banned_traffic_enable: !!config.banned_traffic_enable,
      banned_traffic_min: config.banned_traffic_min || 100,
      banned_traffic_max: config.banned_traffic_max || 300,
    });
  }, [config, form]);

  const handleSave = async () => {
    try {
      const vals = await form.validateFields();
      setSaving(true);
      const keywordsArray = (vals.audit_ua_keywords || '').split('\n').map((k: string) => k.trim()).filter((k: string) => k.length > 0);
      await post(adminPath('/stat/saveSubscriptionAuditSettings'), {
        ...vals,
        audit_ua_keywords: keywordsArray
      });
      message.success('保存成功');
      onRefresh();
      onCancel();
    } catch (e) {} finally {
      setSaving(false);
    }
  };

  const actionDirectly = async (url: string, payload: any, successMsg: string) => {
    try {
      await post(adminPath(url), payload);
      message.success(successMsg);
      onRefresh();
    } catch (e) {}
  };

  return (
    <Modal open={visible} onCancel={onCancel} title="订阅审计与白名单设置" width={isMobile ? '95%' : 700}
      footer={[
        <Button key="cancel" onClick={onCancel}>取消</Button>,
        <Button key="save" type="primary" loading={saving} onClick={handleSave}>保存修改</Button>
      ]}
    >
      <Tabs defaultActiveKey="rules">
        <TabPane tab="审计参数规则" key="rules">
          <Form form={form} layout="vertical">
            <Form.Item label="24h独立IP阈值" name="ip_limit" extra="同一个订阅 24 小时内独立拉取 IP 达到该数值后，会被自动判定并拦截预警。">
              <InputNumber min={1} max={100} style={{ width: isMobile ? '100%' : undefined }} />
            </Form.Item>
            <Form.Item label="命令行/客户端 UA 审计" name="audit_ua_enabled" valuePropName="checked" extra="是否对使用指定的客户端 UA 拉取订阅的行为进行检测和审计。">
              <Switch />
            </Form.Item>
            <Form.Item noStyle shouldUpdate={(prev, curr) => prev.audit_ua_enabled !== curr.audit_ua_enabled}>
              {({ getFieldValue }) => getFieldValue('audit_ua_enabled') ? (
                <Form.Item label="UA 审计关键字" name="audit_ua_keywords" extra="每行输入一个 UA 关键字。">
                  <Input.TextArea rows={5} placeholder="curl&#10;ClashMetaForAndroid/733" />
                </Form.Item>
              ) : null}
            </Form.Item>
          </Form>
        </TabPane>
        <TabPane tab="拦截与蜜罐防御" key="honeypot">
          <Form form={form} layout="vertical">
            <Form.Item label="拦截防探测策略" name="banned_strategy">
              <Radio.Group>
                <Radio value="bait">诱饵模式</Radio>
                <Radio value="redirect">重定向模式</Radio>
              </Radio.Group>
            </Form.Item>
            <Form.Item label="诱捕/重定向订阅地址" name="banned_redirect_url" extra="诱饵模式下作为假配置数据源；重定向模式下作为 302 跳转目标。">
              <Input />
            </Form.Item>
            <Form.Item label="拦截过滤敏感词" name="banned_keywords" extra="诱饵下发时过滤节点名称敏感词。">
              <Input />
            </Form.Item>
            <Form.Item label="敏感词替换为" name="replace_keyword_to">
              <Input />
            </Form.Item>
            <Form.Item label="转换器防封脱敏" name="subconverter_enable" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item noStyle shouldUpdate={(prev, curr) => prev.subconverter_enable !== curr.subconverter_enable}>
              {({ getFieldValue }) => getFieldValue('subconverter_enable') ? (
                <Form.Item label="订阅转换 API 地址" name="subconverter_url">
                  <Input />
                </Form.Item>
              ) : null}
            </Form.Item>
            <Form.Item label="防封随机流量包" name="banned_traffic_enable" valuePropName="checked">
              <Switch />
            </Form.Item>
            <Form.Item noStyle shouldUpdate={(prev, curr) => prev.banned_traffic_enable !== curr.banned_traffic_enable}>
              {({ getFieldValue }) => getFieldValue('banned_traffic_enable') ? (
                <Space direction={isMobile ? 'vertical' : 'horizontal'} style={{ width: '100%' }}>
                  <Form.Item name="banned_traffic_min" label="最小(GB)"><InputNumber min={1} /></Form.Item>
                  <Form.Item name="banned_traffic_max" label="最大(GB)"><InputNumber min={2} /></Form.Item>
                </Space>
              ) : null}
            </Form.Item>
          </Form>
        </TabPane>
        <TabPane tab="白名单管理" key="whitelist">
          <div style={{ marginBottom: 16 }}>
            <Space direction={isMobile ? 'vertical' : 'horizontal'} style={{ width: '100%' }}>
              <Input value={newWhite} onChange={e => setNewWhite(e.target.value)} placeholder="输入用户邮箱或ID" style={{ width: isMobile ? '100%' : 300 }} />
              <Button type="primary" block={isMobile} onClick={() => {
                const isId = /^\d+$/.test(newWhite);
                actionDirectly('/stat/whitelistUser', isId ? { id: parseInt(newWhite) } : { identity: newWhite }, '添加白名单成功');
                setNewWhite('');
              }}>添加白名单</Button>
            </Space>
          </div>
          <Table dataSource={whitelist.map((w: string) => ({ id: w }))} rowKey="id" pagination={false} size="small" scroll={{ x: 300, y: 250 }}>
            <Table.Column title="白名单标识" dataIndex="id" />
            <Table.Column title="操作" width={80} render={(_, rec: any) => (
              <a onClick={() => actionDirectly('/stat/removeWhitelistUser', { identity: rec.id }, '移除成功')}>移除</a>
            )} />
          </Table>
        </TabPane>
        <TabPane tab="IP黑名单" key="blacklist">
          <div style={{ marginBottom: 16 }}>
            <Space direction={isMobile ? 'vertical' : 'horizontal'} style={{ width: '100%' }}>
              <Input value={newBanned} onChange={e => setNewBanned(e.target.value)} placeholder="输入要封禁的 IP" style={{ width: isMobile ? '100%' : 300 }} />
              <Button type="primary" block={isMobile} onClick={() => {
                actionDirectly('/stat/banIp', { ip: newBanned }, '添加封禁成功');
                setNewBanned('');
              }}>添加封禁</Button>
            </Space>
          </div>
          <Table dataSource={bannedIps.map((w: string) => ({ ip: w }))} rowKey="ip" pagination={false} size="small" scroll={{ x: 300, y: 250 }}>
            <Table.Column title="封禁 IP" dataIndex="ip" />
            <Table.Column title="操作" width={80} render={(_, rec: any) => (
              <a onClick={() => actionDirectly('/stat/removeBanIp', { ip: rec.ip }, '解封成功')}>解封</a>
            )} />
          </Table>
        </TabPane>
        <TabPane tab="节点IP免审" key="ignoreip">
          <div style={{ marginBottom: 16 }}>
            <Space direction={isMobile ? 'vertical' : 'horizontal'} style={{ width: '100%' }}>
              <Input value={newIgnore} onChange={e => setNewIgnore(e.target.value)} placeholder="节点 IP 或网段(CIDR)" style={{ width: isMobile ? '100%' : 300 }} />
              <Button type="primary" block={isMobile} onClick={() => {
                actionDirectly('/stat/addIgnoreIp', { ip: newIgnore }, '添加免审成功');
                setNewIgnore('');
              }}>添加免审</Button>
            </Space>
          </div>
          <Table dataSource={ignoreIps.map((w: string) => ({ ip: w }))} rowKey="ip" pagination={false} size="small" scroll={{ y: 250 }}>
            <Table.Column title="免审 IP/网段" dataIndex="ip" />
            <Table.Column title="操作" width={80} render={(_, rec: any) => (
              <a onClick={() => actionDirectly('/stat/removeIgnoreIp', { ip: rec.ip }, '移除成功')}>移除</a>
            )} />
          </Table>
        </TabPane>
      </Tabs>
    </Modal>
  );
}

function IpRadarModal({ visible, onCancel, onRefresh }: any) {
  const [data, setData] = useState<any[]>([]);
  const [loading, setLoading] = useState(false);
  const isMobile = useMobile();

  useEffect(() => {
    if (visible) {
      setLoading(true);
      get(adminPath('/stat/getIpAssociationAnalysis')).then((res: any) => {
        setData(res.data || res || []);
      }).finally(() => setLoading(false));
    }
  }, [visible]);

  const banIp = (ip: string) => {
    Modal.confirm({
      title: '警告',
      content: `确认要封禁共用 IP ${ip} 吗？`,
      onOk: async () => {
        await post(adminPath('/stat/banIp'), { ip });
        message.success('封禁成功');
        setLoading(true);
        get(adminPath('/stat/getIpAssociationAnalysis')).then((res: any) => setData(res.data || res || [])).finally(() => setLoading(false));
        onRefresh();
      }
    });
  };

  return (
    <Modal open={visible} onCancel={onCancel} title="多账号共用 IP 关联分析雷达" width={isMobile ? '95%' : 900} footer={[<Button key="close" onClick={onCancel}>关闭</Button>]}>
      <Table dataSource={data} rowKey="ip" loading={loading} size="small" scroll={{ x: 600, y: 450 }}>
        <Table.Column title="共用 IP" dataIndex="ip" render={(ip, rec: any) => <div><strong>{ip}</strong><div style={{ fontSize: 11, color: '#999' }}>{rec.location}</div></div>} />
        <Table.Column title="关联账号数" render={(_, rec: any) => <span><strong>{rec.associated_accounts_count}</strong> 个账号 {rec.honeypot_accounts_count > 0 && <span style={{ color: '#faad14' }}>({rec.honeypot_accounts_count} 蜜罐)</span>}</span>} />
        <Table.Column title="共用账号列表" dataIndex="associated_users" render={(users: any[]) => <Space wrap>{users.map(u => <Tag key={u.id} color={u.in_honeypot ? 'warning' : 'success'}>{u.email} ({u.id})</Tag>)}</Space>} />
        <Table.Column title="总频次" dataIndex="total_pulls" align="center" />
        <Table.Column title="最近拉取" dataIndex="latest_time" render={ts => <span style={{ fontSize: 12 }}>{new Date(ts * 1000).toLocaleString()}</span>} />
        <Table.Column title="操作" render={(_, rec: any) => rec.is_banned === 0 ? <Button size="small" danger onClick={() => banIp(rec.ip)}>封禁</Button> : <Button size="small" disabled>已封锁</Button>} />
      </Table>
    </Modal>
  );
}

function CustomAuditModal({ visible, onCancel, onRefresh }: any) {
  const [form] = Form.useForm();
  const [loading, setLoading] = useState(false);
  const [results, setResults] = useState<any[]>([]);
  const [selectedKeys, setSelectedKeys] = useState<React.Key[]>([]);
  const isMobile = useMobile();

  const handleScan = async () => {
    try {
      const vals = await form.validateFields();
      setLoading(true);
      const res: any = await post(adminPath('/stat/customAuditScan'), vals);
      if (res) {
        const arr = (res || []).sort((a: any, b: any) => {
          if (a.match_status === 'matched' && b.match_status !== 'matched') return -1;
          if (a.match_status !== 'matched' && b.match_status === 'matched') return 1;
          return b.user_id - a.user_id;
        });
        setResults(arr);
        message.success(`探测完成`);
      }
    } catch (e) {
    } finally {
      setLoading(false);
    }
  };

  const handleHoneypot = (userIds: number[]) => {
    if (!userIds.length) return;
    Modal.confirm({
      title: '确认',
      content: `确定要将选中的 ${userIds.length} 个账号拖入蜜罐中接管吗？`,
      onOk: async () => {
        setLoading(true);
        try {
          await post(adminPath('/stat/customAuditHoneypot'), { user_ids: userIds });
          message.success('接管成功');
          setResults(results.map(r => userIds.includes(r.user_id) ? { ...r, in_honeypot: 1 } : r));
          setSelectedKeys([]);
          onRefresh();
        } catch (e) {} finally {
          setLoading(false);
        }
      }
    });
  };

  return (
    <Modal open={visible} onCancel={onCancel} title="自定义特征探测雷达" width={isMobile ? '95%' : 1000} footer={[<Button key="close" onClick={onCancel}>关闭</Button>]}>
      <Card bodyStyle={{ padding: isMobile ? 10 : 16, marginBottom: 16 }}>
        <Form form={form} layout={isMobile ? 'vertical' : 'inline'} initialValues={{ id_min: 10000, ua_keyword: 'clash-verge/733', province_count: 5, time_range: 86400, max_traffic: 1024, only_idc: false }}>
          <Space wrap size={[8, 8]}>
            <Form.Item label="ID >=" name="id_min" style={{ marginBottom: 8 }}><InputNumber style={{ width: 90 }} /></Form.Item>
            <Form.Item label="UA 包含" name="ua_keyword" style={{ marginBottom: 8 }}><Input style={{ width: 130 }} /></Form.Item>
            <Form.Item label="时间" name="time_range" style={{ marginBottom: 8 }}>
              <Select style={{ width: 100 }}>
                <Option value={86400}>24小时</Option>
                <Option value={129600}>36小时</Option>
                <Option value={172800}>48小时</Option>
                <Option value={259200}>72小时</Option>
              </Select>
            </Form.Item>
            <Form.Item label="跨省 >=" name="province_count" style={{ marginBottom: 8 }}><InputNumber min={0} max={34} style={{ width: 70 }} /></Form.Item>
            <Form.Item name="only_idc" valuePropName="checked" style={{ marginBottom: 8 }}><Checkbox>仅限机房</Checkbox></Form.Item>
            <Form.Item label="流量 <=" name="max_traffic" style={{ marginBottom: 8 }}><InputNumber min={0} style={{ width: 80 }} /></Form.Item>
            <Form.Item style={{ marginBottom: 8 }}>
              <Button type="primary" onClick={handleScan} loading={loading}>开始扫描</Button>
            </Form.Item>
          </Space>
        </Form>
      </Card>
      
      {selectedKeys.length > 0 && (
        <div style={{ marginBottom: 16 }}>
          <span>已选中 <strong>{selectedKeys.length}</strong> 个账号</span>
          <Button type="primary" danger style={{ marginLeft: 16 }} onClick={() => handleHoneypot(selectedKeys as number[])}>批量拖入蜜罐</Button>
        </div>
      )}

      <Table 
        dataSource={results} 
        rowKey="user_id" 
        loading={loading} 
        size="small" 
        scroll={{ x: 750, y: 400 }}
        rowSelection={{ selectedRowKeys: selectedKeys, onChange: setSelectedKeys }}
      >
        <Table.Column title="ID" dataIndex="user_id" width={70} />
        <Table.Column title="邮箱" dataIndex="email" />
        <Table.Column title="账号状态" render={(_, rec: any) => rec.banned === 1 ? <Tag color="red">已封禁</Tag> : <Tag color="green">正常</Tag>} />
        <Table.Column title="蜜罐" render={(_, rec: any) => rec.in_honeypot === 1 ? <Tag color="orange">接管中</Tag> : <Tag>未接管</Tag>} />
        <Table.Column title="诊断/排除原因" render={(_, rec: any) => rec.match_status === 'matched' ? <span style={{ color: '#52c41a', fontWeight: 'bold' }}>完全吻合特征</span> : <span style={{ color: '#aaa', fontStyle: 'italic', fontSize: 12 }}>{rec.exclude_reason}</span>} />
        <Table.Column title="活跃IP" dataIndex="ip_count" width={70} align="center" />
        <Table.Column title="跨省" dataIndex="province_count" width={60} align="center" />
        <Table.Column title="机房IP" dataIndex="idc_count" width={70} align="center" />
        <Table.Column title="操作" render={(_, rec: any) => rec.in_honeypot === 0 ? <Button size="small" type="primary" ghost onClick={() => handleHoneypot([rec.user_id])}>放入蜜罐</Button> : <Button size="small" disabled>已接管</Button>} />
      </Table>
    </Modal>
  );
}

export function UserDetailModal({ visible, userId, onCancel }: any) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const isMobile = useMobile();

  const handleToggleBan = async () => {
    try {
      await post(adminPath('/user/ban'), { filter: [{ key: 'id', condition: '=', value: userId }] });
      message.success('操作成功');
      get(adminPath('/user/fetch'), { filter: [{ key: 'id', condition: '=', value: userId }] })
        .then((res: any) => { const list = res?.data?.data || res?.data || res || []; if (list && list.length > 0) setData(list[0]); });
    } catch (err: any) { message.error(err.message || '操作失败'); }
  };

  const handleToggleHoneypot = async () => {
    try {
      await post(adminPath('/user/toggleHoneypot'), { id: userId });
      message.success('操作成功');
      get(adminPath('/user/fetch'), { filter: [{ key: 'id', condition: '=', value: userId }] })
        .then((res: any) => { const list = res?.data?.data || res?.data || res || []; if (list && list.length > 0) setData(list[0]); });
    } catch (err: any) { message.error(err.message || '操作失败'); }
  };

  useEffect(() => {
    if (visible && userId) {
      setLoading(true);
      get(adminPath('/user/fetch'), { filter: [{ key: 'id', condition: '=', value: userId }] })
        .then((res: any) => { const list = res?.data?.data || res?.data || res || []; if (list && list.length > 0) setData(list[0]); })
        .finally(() => setLoading(false));
    }
  }, [visible, userId]);

  if (!data && !loading) return <Modal open={visible} onCancel={onCancel} title="用户全息档案" footer={<Button onClick={onCancel}>关闭</Button>}><div style={{textAlign:"center", padding: 40}}>未能获取到用户详细信息，或该用户已被完全删除。</div></Modal>;

  const used = (data?.u || 0) + (data?.d || 0);
  const total = data?.transfer_enable || 0;
  const pct = total > 0 ? Math.min(100, (used / total) * 100) : 0;
  
  const formatGb = (b: number) => (b / 1073741824).toFixed(2) + ' GB';

  return (
    <Modal open={visible} onCancel={onCancel} title="用户全息档案" width={isMobile ? '95%' : 600}
      footer={
        <div style={{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', justifyContent: 'space-between', gap: 8 }}>
          <Space wrap>
            <Button 
              type={data?.banned === 1 ? 'default' : 'primary'} 
              danger={data?.banned !== 1} 
              onClick={handleToggleBan}
            >
              {data?.banned === 1 ? '解封账号' : '封禁账号'}
            </Button>
            <Button 
              type="primary" 
              style={{ backgroundColor: data?.in_honeypot === 1 ? '#8c8c8c' : '#faad14', borderColor: data?.in_honeypot === 1 ? '#8c8c8c' : '#faad14' }} 
              onClick={handleToggleHoneypot}
            >
              {data?.in_honeypot === 1 ? '移出蜜罐' : '加入蜜罐'}
            </Button>
          </Space>

          <Space wrap>
            <Button onClick={() => { onCancel(); navigate(`/subscribe-logs?user_id=${data?.id}`); }}>TA的拉取记录</Button>
            <Button onClick={() => { onCancel(); navigate(`/login-logs?user_id=${data?.id}&email=${encodeURIComponent(data?.email || '')}`); }}>TA的登录记录</Button>
            <Button onClick={() => { onCancel(); navigate(`/order`); }}>TA的订单</Button>
            <UserDrawer userId={data?.id}>
              <Button>编辑资料</Button>
            </UserDrawer>
            <Button onClick={onCancel}>关闭</Button>
          </Space>
        </div>
      }
    >
      <div style={{ padding: isMobile ? 8 : 16 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, borderBottom: '1px solid #f0f0f0', paddingBottom: 12, marginBottom: 16 }}>
          <Avatar size={isMobile ? 48 : 64} icon={<UserOutlined />} style={{ backgroundColor: '#e6f7ff', color: '#1890ff' }} />
          <div>
            <div style={{ fontSize: isMobile ? 15 : 18, fontWeight: 'bold', wordBreak: 'break-all' }}>{data?.email}</div>
            <div style={{ color: '#888', marginTop: 4, fontSize: 12 }}>
              ID: <code>{data?.id}</code> | 注册: {data?.created_at ? new Date(data.created_at * 1000).toLocaleDateString() : '-'}
            </div>
          </div>
        </div>

        <Space wrap style={{ marginBottom: 16 }}>
          <Tag color={data?.banned === 1 ? 'error' : 'success'}>{data?.banned === 1 ? '已封禁' : '正常'}</Tag>
          <Tag color={data?.in_honeypot === 1 ? 'warning' : 'default'}>{data?.in_honeypot === 1 ? '蜜罐接管中' : '未接管'}</Tag>
          <Tag color="blue">{data?.plan_name ? `套餐: ${data.plan_name}` : '无订阅套餐'}</Tag>
        </Space>

        <Card size="small" style={{ background: '#fafafa', marginBottom: 16 }} title={<span>📊 流量使用概览</span>} extra={`${formatGb(used)} / ${formatGb(total)}`}>
          <Progress percent={parseFloat(pct.toFixed(1))} status={pct > 90 ? 'exception' : 'active'} />
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: '#888', marginTop: 8 }}>
            <span>上行: {formatGb(data?.u)} | 下行: {formatGb(data?.d)}</span>
            <span>剩余: <strong style={{ color: total - used > 0 ? '#52c41a' : '#f5222d' }}>{formatGb(Math.max(0, total - used))}</strong></span>
          </div>
        </Card>

        <Descriptions bordered size="small" column={isMobile ? 1 : 2}>
          <Descriptions.Item label="到期时间" span={isMobile ? 1 : 2}>
            {data?.expired_at ? new Date(data.expired_at * 1000).toLocaleString() : '长期有效'}
          </Descriptions.Item>
          <Descriptions.Item label="账户余额">{((data?.balance || 0) / 100).toFixed(2)} 元</Descriptions.Item>
          <Descriptions.Item label="推广佣金">{((data?.commission_balance || 0) / 100).toFixed(2)} 元</Descriptions.Item>
          <Descriptions.Item label="设备限制">{data?.device_limit ? `${data.device_limit} 台` : '无限制'}</Descriptions.Item>
          <Descriptions.Item label="速率限制">{data?.speed_limit ? `${data.speed_limit} Mbps` : '无限制'}</Descriptions.Item>
        </Descriptions>
      </div>
    </Modal>
  );
}



