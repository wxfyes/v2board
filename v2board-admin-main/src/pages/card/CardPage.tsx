import {
  ArrowLeftOutlined,
  DeleteOutlined,
  EditOutlined,
  KeyOutlined,
  PlusOutlined,
  UploadOutlined,
  CopyOutlined
} from '@ant-design/icons'
import {
  Breadcrumb,
  Button,
  Card,
  Empty,
  Form,
  Input,
  InputNumber,
  Modal,
  Pagination,
  Popconfirm,
  Radio,
  Space,
  Switch,
  Table,
  Tag,
  Typography,
  type TableColumnsType,
} from 'antd'
import { useEffect, useState } from 'react'
import { get, post } from '@/api/request'
import { adminPath } from '@/app/settings'
import { message } from '@/app/staticApi'
import { TableBlock } from '@/components/TableBlock'
import { useMobile } from '@/hooks/useMobile'
import { AdminLayout } from '@/layouts/AdminLayout'
import { formatTime } from '@/utils/format'

const { Text } = Typography

export interface CardProduct {
  id: number
  name: string
  price: number
  unsold_stock: number
  total_stock: number
  show: number
  sort: number
  description?: string
}

export interface CardItem {
  id: number
  product_id: number
  code: string
  status: number
  user_email?: string
  trade_no?: string
  updated_at?: number
}

export default function CardPage() {
  const isMobile = useMobile()
  const [products, setProducts] = useState<CardProduct[]>([])
  const [loading, setLoading] = useState(false)
  const [activeProduct, setActiveProduct] = useState<CardProduct | null>(null)

  // 移动端分页状态
  const [productPage, setProductPage] = useState(1)
  const productPageSize = 10
  const [cardPage, setCardPage] = useState(1)
  const cardPageSize = 15

  // 卡密列表相关
  const [cards, setCards] = useState<CardItem[]>([])
  const [cardsLoading, setCardsLoading] = useState(false)
  const [statusFilter, setStatusFilter] = useState<number | null>(null)

  // 商品弹窗相关
  const [productModalVisible, setProductModalVisible] = useState(false)
  const [productSubmitting, setProductSubmitting] = useState(false)
  const [productForm] = Form.useForm()
  const [editingProduct, setEditingProduct] = useState<CardProduct | null>(null)

  // 导入卡密弹窗相关
  const [importModalVisible, setImportModalVisible] = useState(false)
  const [importSubmitting, setImportSubmitting] = useState(false)
  const [importForm] = Form.useForm()

  // 获取商品列表
  const fetchProducts = async () => {
    setLoading(true)
    try {
      const res = await get<CardProduct[]>(adminPath('/card/product/fetch'))
      if (res.data) {
        setProducts(res.data)
      }
    } catch {
      // 错误由 request 层弹出
    } finally {
      setLoading(false)
    }
  }

  // 获取指定商品的卡密列表
  const fetchCards = async (productId: number, status: number | null = statusFilter) => {
    setCardsLoading(true)
    try {
      const params: Record<string, unknown> = { product_id: productId }
      if (status !== null) params.status = status
      const res = await get<CardItem[]>(adminPath('/card/fetch'), params)
      if (res.data) {
        setCards(res.data)
      }
    } catch {
      // 错误由 request 层弹出
    } finally {
      setCardsLoading(false)
    }
  }

  useEffect(() => {
    void fetchProducts()
  }, [])

  // 切换查看卡密
  const handleViewCards = (product: CardProduct) => {
    setActiveProduct(product)
    setStatusFilter(null)
    setCardPage(1)
    void fetchCards(product.id, null)
  }

  const handleBackToProducts = () => {
    setActiveProduct(null)
    setCards([])
    void fetchProducts()
  }

  // 商品开关上架
  const handleToggleShow = async (record: CardProduct, checked: boolean) => {
    try {
      const payload = {
        id: record.id,
        name: record.name,
        price: record.price,
        show: checked ? 1 : 0,
        sort: record.sort,
      }
      await post(adminPath('/card/product/save'), payload)
      message.success(checked ? '商品已上架' : '商品已下架')
      setProducts((prev) => prev.map((item) => (item.id === record.id ? { ...item, show: checked ? 1 : 0 } : item)))
    } catch {
      // 错误由 request 层弹出
    }
  }

  // 打开创建商品弹窗
  const openCreateProduct = () => {
    setEditingProduct(null)
    productForm.resetFields()
    productForm.setFieldsValue({
      price: 10,
      show: 1,
      sort: 0,
    })
    setProductModalVisible(true)
  }

  // 打开编辑商品弹窗
  const openEditProduct = (record: CardProduct) => {
    setEditingProduct(record)
    productForm.resetFields()
    productForm.setFieldsValue({
      name: record.name,
      price: Number((record.price / 100).toFixed(2)),
      show: record.show,
      sort: record.sort,
      description: record.description,
    })
    setProductModalVisible(true)
  }

  // 提交商品创建/编辑
  const handleProductSubmit = async () => {
    try {
      const values = await productForm.validateFields()
      setProductSubmitting(true)
      const payload = {
        id: editingProduct?.id,
        name: values.name,
        price: Math.round(Number(values.price) * 100),
        show: values.show,
        sort: values.sort ?? 0,
        description: values.description,
      }
      const res = await post(adminPath('/card/product/save'), payload)
      if (res.code === 200) {
        message.success(editingProduct ? '编辑商品成功' : '创建商品成功')
        setProductModalVisible(false)
        void fetchProducts()
      }
    } finally {
      setProductSubmitting(false)
    }
  }

  // 删除商品
  const handleDeleteProduct = async (record: CardProduct) => {
    const res = await post(adminPath('/card/product/drop'), { id: record.id })
    if (res.code === 200) {
      message.success('删除成功')
      void fetchProducts()
    }
  }

  // 打开导入卡密弹窗
  const openImportModal = () => {
    importForm.resetFields()
    setImportModalVisible(true)
  }

  // 提交导入卡密
  const handleImportSubmit = async () => {
    if (!activeProduct) return
    try {
      const values = await importForm.validateFields()
      setImportSubmitting(true)
      const payload = {
        product_id: activeProduct.id,
        codes: values.codes,
      }
      const res = await post<{ count: number }>(adminPath('/card/import'), payload)
      if (res.code === 200) {
        message.success(`成功导入 ${res.data?.count ?? 0} 条卡密数据`)
        setImportModalVisible(false)
        void fetchCards(activeProduct.id)
      }
    } finally {
      setImportSubmitting(false)
    }
  }

  // 删除卡密
  const handleDeleteCard = async (record: CardItem) => {
    if (!activeProduct) return
    const res = await post(adminPath('/card/drop'), { id: record.id })
    if (res.code === 200) {
      message.success('删除成功')
      void fetchCards(activeProduct.id)
    }
  }

  // 商品列表列定义 (PC 端)
  const productColumns: TableColumnsType<CardProduct> = [
    { title: 'ID', dataIndex: 'id', key: 'id', width: 70, align: 'center' },
    { title: '商品名称', dataIndex: 'name', key: 'name', minWidth: 140 },
    {
      title: '单价',
      dataIndex: 'price',
      key: 'price',
      width: 100,
      align: 'right',
      render: (price: number) => <span style={{ fontWeight: 600, color: '#cf1322' }}>¥{(price / 100).toFixed(2)}</span>,
    },
    {
      title: '库存 (余/总)',
      key: 'stock',
      width: 120,
      align: 'center',
      render: (_, record) => (
        <Tag color={record.unsold_stock > 0 ? 'success' : 'error'}>
          {record.unsold_stock} / {record.total_stock}
        </Tag>
      ),
    },
    {
      title: '上架状态',
      dataIndex: 'show',
      key: 'show',
      width: 100,
      align: 'center',
      render: (show: number, record) => (
        <Switch checked={show === 1} onChange={(checked) => void handleToggleShow(record, checked)} />
      ),
    },
    { title: '排序', dataIndex: 'sort', key: 'sort', width: 80, align: 'center' },
    {
      title: '操作',
      key: 'action',
      width: 200,
      align: 'right',
      render: (_, record) => (
        <Space size="middle">
          <Button type="link" size="small" icon={<KeyOutlined />} onClick={() => handleViewCards(record)}>
            卡密
          </Button>
          <Button type="link" size="small" icon={<EditOutlined />} onClick={() => openEditProduct(record)}>
            编辑
          </Button>
          <Popconfirm
            title="确定删除该商品吗？"
            description="若商品内有未清理的卡密数据可能无法删除。"
            onConfirm={() => void handleDeleteProduct(record)}
            okText="删除"
            cancelText="取消"
            okButtonProps={{ danger: true }}
          >
            <Button type="link" danger size="small" icon={<DeleteOutlined />}>
              删除
            </Button>
          </Popconfirm>
        </Space>
      ),
    },
  ]

  // 卡密列表列定义 (PC 端)
  const cardColumns: TableColumnsType<CardItem> = [
    { title: 'ID', dataIndex: 'id', key: 'id', width: 70, align: 'center' },
    {
      title: '卡密内容',
      dataIndex: 'code',
      key: 'code',
      minWidth: 180,
      render: (code: string) => (
        <code style={{ background: '#f5f5f5', padding: '2px 8px', borderRadius: 4, fontFamily: 'monospace' }}>
          {code}
        </code>
      ),
    },
    {
      title: '状态',
      dataIndex: 'status',
      key: 'status',
      width: 90,
      align: 'center',
      render: (status: number) => (
        <Tag color={status === 1 ? 'default' : 'success'}>{status === 1 ? '已售出' : '未售出'}</Tag>
      ),
    },
    {
      title: '购买用户',
      dataIndex: 'user_email',
      key: 'user_email',
      minWidth: 140,
      render: (email: string) => email || '-',
    },
    {
      title: '关联订单',
      dataIndex: 'trade_no',
      key: 'trade_no',
      width: 180,
      render: (no: string) => (no ? <span style={{ fontSize: 12 }}>{no}</span> : '-'),
    },
    {
      title: '更新时间',
      dataIndex: 'updated_at',
      key: 'updated_at',
      width: 170,
      render: (ts: number) => formatTime(ts),
    },
    {
      title: '操作',
      key: 'action',
      width: 70,
      align: 'right',
      render: (_, record) => (
        <Popconfirm
          title="确定彻底删除这一条卡密数据吗？"
          description="该操作不可撤销！"
          onConfirm={() => void handleDeleteCard(record)}
          okText="删除"
          cancelText="取消"
          okButtonProps={{ danger: true }}
        >
          <Button type="link" danger size="small" icon={<DeleteOutlined />}>
            删除
          </Button>
        </Popconfirm>
      ),
    },
  ]

  // 移动端分页切片
  const pagedProducts = products.slice((productPage - 1) * productPageSize, productPage * productPageSize)
  const pagedCards = cards.slice((cardPage - 1) * cardPageSize, cardPage * cardPageSize)

  return (
    <AdminLayout title="发卡管理">
      <TableBlock>
        <div
          style={{
            display: 'flex',
            flexDirection: isMobile ? 'column' : 'row',
            justifyContent: 'space-between',
            alignItems: isMobile ? 'flex-start' : 'center',
            gap: isMobile ? 12 : 0,
            marginBottom: 16,
          }}
        >
          <Space size="middle" wrap>
            <h2 className="content-heading" style={{ margin: 0, padding: 0, border: 'none', fontSize: isMobile ? 18 : 22 }}>
              卡密发卡管理
            </h2>
            {activeProduct && (
              <Breadcrumb
                items={[
                  {
                    title: (
                      <a onClick={handleBackToProducts} style={{ cursor: 'pointer', color: '#1677ff' }}>
                        商品列表
                      </a>
                    ),
                  },
                  { title: `${activeProduct.name} (库存)` },
                ]}
              />
            )}
          </Space>
          <div style={{ width: isMobile ? '100%' : 'auto' }}>
            {!activeProduct ? (
              <Button type="primary" block={isMobile} icon={<PlusOutlined />} onClick={openCreateProduct}>
                添加商品
              </Button>
            ) : (
              <Space style={{ width: isMobile ? '100%' : 'auto' }} wrap>
                <Button type="primary" icon={<UploadOutlined />} onClick={openImportModal}>
                  导入卡密
                </Button>
                <Button icon={<ArrowLeftOutlined />} onClick={handleBackToProducts}>
                  返回列表
                </Button>
              </Space>
            )}
          </div>
        </div>

        {!activeProduct ? (
          /* 商品列表区 */
          isMobile ? (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
              {pagedProducts.map((item) => (
                <Card
                  key={item.id}
                  size="small"
                  style={{
                    borderRadius: 10,
                    boxShadow: '0 1px 4px rgba(0,0,0,0.05)',
                    border: '1px solid #f0f0f0',
                    background: '#fafafa',
                  }}
                  bodyStyle={{ padding: '12px 14px' }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 }}>
                    <div style={{ minWidth: 0, flex: 1, marginRight: 8 }}>
                      <div style={{ fontWeight: 700, fontSize: 15, color: '#1f2937', marginBottom: 2 }}>
                        {item.name}
                      </div>
                      <span style={{ fontSize: 12, color: '#9ca3af' }}>ID: {item.id} | 排序: {item.sort}</span>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexShrink: 0 }}>
                      <span style={{ fontSize: 12, color: item.show === 1 ? '#52c41a' : '#8c8c8c' }}>
                        {item.show === 1 ? '上架' : '下架'}
                      </span>
                      <Switch
                        size="small"
                        checked={item.show === 1}
                        onChange={(checked) => void handleToggleShow(item, checked)}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '8px 0', padding: '6px 0', borderTop: '1px dashed #f0f0f0', borderBottom: '1px dashed #f0f0f0' }}>
                    <div>
                      <span style={{ fontSize: 12, color: '#6b7280' }}>单价：</span>
                      <span style={{ fontSize: 17, fontWeight: 700, color: '#cf1322' }}>
                        ¥{(item.price / 100).toFixed(2)}
                      </span>
                    </div>
                    <div>
                      <span style={{ fontSize: 12, color: '#6b7280', marginRight: 4 }}>库存：</span>
                      <Tag color={item.unsold_stock > 0 ? 'success' : 'error'} style={{ margin: 0, fontWeight: 600 }}>
                        余 {item.unsold_stock} / 总 {item.total_stock}
                      </Tag>
                    </div>
                  </div>

                  {item.description && (
                    <div style={{ fontSize: 12, color: '#6b7280', marginBottom: 8, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      说明: {item.description}
                    </div>
                  )}

                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 6, marginTop: 4 }}>
                    <Button
                      size="small"
                      type="primary"
                      icon={<KeyOutlined />}
                      onClick={() => handleViewCards(item)}
                    >
                      卡密库存 ({item.unsold_stock})
                    </Button>
                    <Button
                      size="small"
                      icon={<EditOutlined />}
                      onClick={() => openEditProduct(item)}
                    >
                      编辑
                    </Button>
                    <Popconfirm
                      title="确定删除该商品吗？"
                      description="若商品内有未清理的卡密数据可能无法删除。"
                      onConfirm={() => void handleDeleteProduct(item)}
                      okText="删除"
                      cancelText="取消"
                      okButtonProps={{ danger: true }}
                    >
                      <Button size="small" danger icon={<DeleteOutlined />}>
                        删除
                      </Button>
                    </Popconfirm>
                  </div>
                </Card>
              ))}

              {products.length === 0 && !loading && (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无发卡商品" />
              )}

              <div style={{ display: 'flex', justifyContent: 'center', marginTop: 12 }}>
                <Pagination
                  simple
                  current={productPage}
                  pageSize={productPageSize}
                  total={products.length}
                  onChange={(page) => setProductPage(page)}
                />
              </div>
            </div>
          ) : (
            <Table<CardProduct>
              rowKey="id"
              loading={loading}
              dataSource={products}
              columns={productColumns}
              pagination={{ defaultPageSize: 15, showSizeChanger: true }}
            />
          )
        ) : (
          /* 卡密库存管理区 */
          <div>
            <div style={{ marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
              <div>
                <span style={{ marginRight: 8, color: '#666' }}>库存状态：</span>
                <Radio.Group
                  value={statusFilter}
                  size={isMobile ? 'small' : 'middle'}
                  onChange={(e) => {
                    setStatusFilter(e.target.value)
                    setCardPage(1)
                    void fetchCards(activeProduct.id, e.target.value)
                  }}
                >
                  <Radio.Button value={null}>全部</Radio.Button>
                  <Radio.Button value={0}>未售出</Radio.Button>
                  <Radio.Button value={1}>已售出</Radio.Button>
                </Radio.Group>
              </div>
              <span style={{ fontSize: 13, color: '#888' }}>
                共计 <strong>{cards.length}</strong> 条卡密
              </span>
            </div>

            {isMobile ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {pagedCards.map((item) => (
                  <Card
                    key={item.id}
                    size="small"
                    style={{
                      borderRadius: 8,
                      boxShadow: '0 1px 3px rgba(0,0,0,0.05)',
                      border: '1px solid #f0f0f0',
                      background: '#fafafa',
                    }}
                    bodyStyle={{ padding: '10px 12px' }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                      <Tag color={item.status === 1 ? 'default' : 'success'} style={{ margin: 0, fontWeight: 600 }}>
                        {item.status === 1 ? '已售出' : '未售出'}
                      </Tag>
                      <span style={{ fontSize: 11, color: '#999' }}>#{item.id}</span>
                    </div>

                    <div style={{ background: '#fff', border: '1px solid #eee', borderRadius: 4, padding: '6px 8px', marginBottom: 6, wordBreak: 'break-all', fontFamily: 'monospace', fontSize: 12 }}>
                      {item.code}
                    </div>

                    <div style={{ fontSize: 11, color: '#666', display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                      <span>用户: {item.user_email || '未售出'}</span>
                      <span>{item.updated_at ? formatTime(item.updated_at) : '-'}</span>
                    </div>

                    {item.trade_no && (
                      <div style={{ fontSize: 11, color: '#888', marginTop: 4 }}>
                        订单号: {item.trade_no}
                      </div>
                    )}

                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 8, paddingTop: 6, borderTop: '1px dashed #e8e8e8' }}>
                      <Popconfirm
                        title="确定彻底删除这一条卡密数据吗？"
                        description="该操作不可撤销！"
                        onConfirm={() => void handleDeleteCard(item)}
                        okText="删除"
                        cancelText="取消"
                        okButtonProps={{ danger: true }}
                      >
                        <Button danger size="small" icon={<DeleteOutlined />}>
                          删除
                        </Button>
                      </Popconfirm>
                    </div>
                  </Card>
                ))}

                {cards.length === 0 && !cardsLoading && (
                  <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无对应卡密数据" />
                )}

                <div style={{ display: 'flex', justifyContent: 'center', marginTop: 12 }}>
                  <Pagination
                    simple
                    current={cardPage}
                    pageSize={cardPageSize}
                    total={cards.length}
                    onChange={(page) => setCardPage(page)}
                  />
                </div>
              </div>
            ) : (
              <Table<CardItem>
                rowKey="id"
                loading={cardsLoading}
                dataSource={cards}
                columns={cardColumns}
                pagination={{ defaultPageSize: 20, showSizeChanger: true }}
              />
            )}
          </div>
        )}
      </TableBlock>

      {/* 创建 / 编辑商品弹窗 */}
      <Modal
        title={editingProduct ? '编辑商品' : '添加商品'}
        open={productModalVisible}
        onOk={() => void handleProductSubmit()}
        confirmLoading={productSubmitting}
        onCancel={() => setProductModalVisible(false)}
        destroyOnClose
        width={isMobile ? '95%' : 560}
      >
        <Form form={productForm} layout="vertical" preserve={false}>
          <Form.Item name="name" label="商品名称" rules={[{ required: true, message: '请输入商品名称' }]}>
            <Input placeholder="请输入商品名称，如：美区小火箭账号 (独立)" />
          </Form.Item>
          <Form.Item name="price" label="单价 (元)" rules={[{ required: true, message: '请输入价格' }]}>
            <InputNumber min={0} precision={2} style={{ width: 160 }} prefix="¥" />
          </Form.Item>
          <Form.Item name="show" label="上架状态" rules={[{ required: true }]}>
            <Radio.Group>
              <Radio value={1}>上架</Radio>
              <Radio value={0}>下架</Radio>
            </Radio.Group>
          </Form.Item>
          <Form.Item
            name="sort"
            label="排序权重"
            extra="数字越小越靠前"
          >
            <InputNumber min={0} style={{ width: 160 }} />
          </Form.Item>
          <Form.Item
            name="description"
            label="商品描述"
            extra="商品详细说明，支持 HTML。在此可以写入账号的质保规则或引导说明。"
          >
            <Input.TextArea rows={4} placeholder="支持填写质保说明、卡密使用教程等" />
          </Form.Item>
        </Form>
      </Modal>

      {/* 导入卡密弹窗 */}
      <Modal
        title="批量导入卡密"
        open={importModalVisible}
        onOk={() => void handleImportSubmit()}
        confirmLoading={importSubmitting}
        onCancel={() => setImportModalVisible(false)}
        destroyOnClose
        width={isMobile ? '95%' : 600}
      >
        <Form form={importForm} layout="vertical" preserve={false}>
          <Form.Item label="商品名称">
            <Input value={activeProduct?.name} disabled />
          </Form.Item>
          <Form.Item
            name="codes"
            label="卡密数据"
            rules={[{ required: true, message: '请粘贴需要导入的卡密数据' }]}
            extra="支持一行一条卡密，例如：账号: abcd@gmail.com ---- 密码: 123456"
          >
            <Input.TextArea
              rows={10}
              placeholder={`请粘贴卡密数据，支持一行一条。
例如：
账号: abcd@gmail.com ---- 密码: password1 ---- 密保: xx
账号: efgh@gmail.com ---- 密码: password8 ---- 密保: yy`}
            />
          </Form.Item>
        </Form>
      </Modal>
    </AdminLayout>
  )
}
