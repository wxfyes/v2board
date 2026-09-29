<template>
  <div class="cards-container">
    <!-- Action Card -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center flex-wrap gap-10">
        <div class="flex-center">
          <span class="action-text">卡密发卡管理</span>
          <el-breadcrumb separator="/" class="ml-20 font-13" v-if="activeProduct">
            <el-breadcrumb-item @click="backToProducts" class="cursor-pointer">商品列表</el-breadcrumb-item>
            <el-breadcrumb-item>{{ activeProduct.name }} (库存)</el-breadcrumb-item>
          </el-breadcrumb>
        </div>
        <div :style="{ width: isMobile ? '100%' : 'auto' }">
          <el-button v-if="!activeProduct" type="primary" :style="{ width: isMobile ? '100%' : 'auto' }" icon="Plus" @click="openCreateProductDialog">
            添加商品
          </el-button>
          <div v-else class="flex gap-10" :style="{ width: isMobile ? '100%' : 'auto' }">
            <el-button type="success" :style="{ flex: isMobile ? 1 : 'none' }" icon="Download" @click="openImportDialog">导入卡密</el-button>
            <el-button type="info" :style="{ flex: isMobile ? 1 : 'none' }" icon="Back" @click="backToProducts">返回列表</el-button>
          </div>
        </div>
      </div>
    </el-card>

    <!-- 1. 商品管理列表 -->
    <div v-if="!activeProduct" class="mt-20">
      <!-- 移动端商品卡片流 -->
      <div v-if="isMobile" v-loading="loading" class="mobile-card-list">
        <el-card
          v-for="item in products"
          :key="item.id"
          class="mobile-item-card"
          shadow="hover"
        >
          <div class="card-header-row">
            <div class="product-title-wrap">
              <span class="product-name">{{ item.name }}</span>
              <span class="product-sub-info">ID: {{ item.id }} | 排序: {{ item.sort }}</span>
            </div>
            <div class="product-switch-wrap">
              <span :style="{ fontSize: '12px', color: item.show === 1 ? 'var(--el-color-success)' : 'var(--el-text-color-secondary)' }">
                {{ item.show === 1 ? '上架' : '下架' }}
              </span>
              <el-switch
                v-model="item.show"
                size="small"
                :active-value="1"
                :inactive-value="0"
                @change="handleToggleShow(item)"
              />
            </div>
          </div>

          <div class="card-price-stock-row">
            <div class="price-box">
              <span class="price-label">单价：</span>
              <span class="price-val">￥{{ (item.price / 100).toFixed(2) }}</span>
            </div>
            <div class="stock-box">
              <span class="stock-label">库存：</span>
              <el-tag :type="item.unsold_stock > 0 ? 'success' : 'danger'" size="small">
                余 {{ item.unsold_stock }} / 总 {{ item.total_stock }}
              </el-tag>
            </div>
          </div>

          <div v-if="item.description" class="product-desc-box">
            {{ item.description }}
          </div>

          <div class="card-actions-row">
            <el-button type="success" size="small" icon="Key" @click="viewProductCards(item)">
              卡密 ({{ item.unsold_stock }})
            </el-button>
            <el-button type="primary" size="small" icon="Edit" @click="openEditProductDialog(item)">
              编辑
            </el-button>
            <el-button type="danger" size="small" icon="Delete" @click="handleDeleteProduct(item)">
              删除
            </el-button>
          </div>
        </el-card>

        <el-empty v-if="products.length === 0 && !loading" description="暂无发卡商品" />
      </div>

      <!-- PC 端商品表格 -->
      <el-card v-else class="table-card" shadow="hover">
        <el-table :data="products" v-loading="loading" stripe style="width: 100%">
          <el-table-column prop="id" label="ID" width="70" align="center" />
          <el-table-column prop="name" label="商品名称" min-width="150" show-overflow-tooltip />
          <el-table-column prop="price" label="单价" width="110" align="right">
            <template #default="scope">
              <span style="font-weight: 600; color: var(--el-color-danger)">￥{{ (scope.row.price / 100).toFixed(2) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="库存 (余/总)" width="120" align="center">
            <template #default="scope">
              <el-tag :type="scope.row.unsold_stock > 0 ? 'success' : 'danger'" size="small">
                {{ scope.row.unsold_stock }} / {{ scope.row.total_stock }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="show" label="上架状态" width="100" align="center">
            <template #default="scope">
              <el-switch
                v-model="scope.row.show"
                :active-value="1"
                :inactive-value="0"
                @change="handleToggleShow(scope.row)"
              />
            </template>
          </el-table-column>
          <el-table-column prop="sort" label="排序" width="80" align="center" />
          <el-table-column label="操作" width="220" align="right">
            <template #default="scope">
              <el-button type="success" link @click="viewProductCards(scope.row)">卡密管理</el-button>
              <el-button type="primary" link @click="openEditProductDialog(scope.row)">编辑</el-button>
              <el-button type="danger" link @click="handleDeleteProduct(scope.row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>

    <!-- 2. 卡密数据管理列表 -->
    <div v-else class="mt-20">
      <el-card class="table-card mb-15" shadow="hover">
        <div class="flex-between align-center flex-wrap gap-10">
          <div class="flex-center gap-10">
            <span class="font-14 text-secondary">库存状态：</span>
            <el-radio-group v-model="cardStatusFilter" size="small" @change="fetchCards">
              <el-radio-button :label="null">全部</el-radio-button>
              <el-radio-button :label="0">未售出</el-radio-button>
              <el-radio-button :label="1">已售出</el-radio-button>
            </el-radio-group>
          </div>
          <span class="font-13 text-secondary">共计 <strong>{{ cards.length }}</strong> 条卡密</span>
        </div>
      </el-card>

      <!-- 移动端卡密卡片流 -->
      <div v-if="isMobile" v-loading="cardsLoading" class="mobile-card-list">
        <el-card
          v-for="item in cards"
          :key="item.id"
          class="mobile-item-card"
          shadow="hover"
        >
          <div class="card-header-row">
            <el-tag :type="item.status === 1 ? 'info' : 'success'" size="small">
              {{ item.status === 1 ? '已售出' : '未售出' }}
            </el-tag>
            <span style="font-size: 11px; color: #999">#{{ item.id }}</span>
          </div>

          <div class="card-code-box">
            <code>{{ item.code }}</code>
          </div>

          <div class="card-meta-row">
            <span>用户: {{ item.user_email || '未售出' }}</span>
            <span>{{ formatTime(item.updated_at) }}</span>
          </div>

          <div v-if="item.trade_no" class="card-order-row">
            订单号: {{ item.trade_no }}
          </div>

          <div class="card-actions-row mt-10">
            <el-button type="danger" size="small" icon="Delete" plain @click="handleDeleteCard(item)">
              删除卡密
            </el-button>
          </div>
        </el-card>

        <el-empty v-if="cards.length === 0 && !cardsLoading" description="暂无对应卡密数据" />
      </div>

      <!-- PC 端卡密表格 -->
      <el-card v-else class="table-card" shadow="hover">
        <el-table :data="cards" v-loading="cardsLoading" stripe style="width: 100%">
          <el-table-column prop="id" label="ID" width="70" align="center" />
          <el-table-column prop="code" label="卡密内容" min-width="200" show-overflow-tooltip>
            <template #default="scope">
              <code class="code-block">{{ scope.row.code }}</code>
            </template>
          </el-table-column>
          <el-table-column prop="status" label="状态" width="100" align="center">
            <template #default="scope">
              <el-tag :type="scope.row.status === 1 ? 'info' : 'success'" size="small">
                {{ scope.row.status === 1 ? '已售出' : '未售出' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column prop="user_email" label="购买用户" min-width="150" show-overflow-tooltip>
            <template #default="scope">
              <span>{{ scope.row.user_email || '-' }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="trade_no" label="关联订单" width="160" show-overflow-tooltip>
            <template #default="scope">
              <span v-if="scope.row.trade_no" class="font-12">{{ scope.row.trade_no }}</span>
              <span v-else>-</span>
            </template>
          </el-table-column>
          <el-table-column label="更新时间" width="160">
            <template #default="scope">
              <span class="font-12">{{ formatTime(scope.row.updated_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80" align="right">
            <template #default="scope">
              <el-button type="danger" link @click="handleDeleteCard(scope.row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>

    <!-- Dialog 1: 添加/编辑商品 -->
    <el-dialog v-model="productDialogVisible" :title="productDialogTitle" :width="isMobile ? '95%' : '600px'">
      <el-form :model="productForm" :rules="productRules" ref="productFormRef" :label-position="isMobile ? 'top' : 'right'" label-width="100px">
        <el-form-item label="商品名称" prop="name">
          <el-input v-model="productForm.name" placeholder="请输入商品名称，如：美区小火箭账号 (独立)" />
        </el-form-item>
        <el-form-item label="单价(元)" prop="price">
          <el-input-number v-model="productForm.price" :min="0" :precision="2" style="width: 150px" />
        </el-form-item>
        <el-form-item label="上架状态" prop="show">
          <el-radio-group v-model="productForm.show">
            <el-radio :label="1">上架</el-radio>
            <el-radio :label="0">下架</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="排序权重" prop="sort">
          <el-input-number v-model="productForm.sort" :min="0" style="width: 150px" />
          <span class="form-tip ml-10">数字越小越靠前</span>
        </el-form-item>
        <el-form-item label="商品描述" prop="description">
          <el-input
            v-model="productForm.description"
            type="textarea"
            :rows="5"
            placeholder="商品详细说明，支持 HTML。在此可以写入账号的质保规则或引导说明。"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="productDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleProductSubmit">确定</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Dialog 2: 批量导入卡密 -->
    <el-dialog v-model="importDialogVisible" title="批量导入卡密" :width="isMobile ? '95%' : '600px'">
      <el-form :model="importForm" :rules="importRules" ref="importFormRef" label-position="top">
        <el-form-item label="商品名称">
          <el-input :value="activeProduct?.name" disabled />
        </el-form-item>
        <el-form-item label="卡密数据" prop="codes">
          <el-input
            v-model="importForm.codes"
            type="textarea"
            :rows="10"
            placeholder="请粘贴卡密数据，支持一行一条。例如：账号: abcd@gmail.com ---- 密码: 123456"
          />
          <div class="form-tip mt-5">
            每行一条卡密，支持任意文本格式（如带有账号密码、密保等），系统会自动按行切分入库。
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="importDialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleImportSubmit">导入</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api, { getSecurePath } from '../api';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const products = ref([]);
const loading = ref(false);
const submitLoading = ref(false);

const activeProduct = ref(null);
const cards = ref([]);
const cardsLoading = ref(false);
const cardStatusFilter = ref(null);

const productDialogVisible = ref(false);
const productDialogTitle = ref('添加商品');
const productFormRef = ref(null);
const productForm = reactive({
  id: undefined,
  name: '',
  price: 10.00,
  show: 1,
  sort: 0,
  description: ''
});

const productRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入商品价格', trigger: 'blur' }]
};

const importDialogVisible = ref(false);
const importFormRef = ref(null);
const importForm = reactive({
  codes: ''
});

const importRules = {
  codes: [{ required: true, message: '请填写卡密数据', trigger: 'blur' }]
};

const formatTime = (timestamp) => {
  if (!timestamp) return '-';
  const d = new Date(timestamp * 1000);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
};

const fetchProducts = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/card/product/fetch`);
    products.value = res.data || [];
  } catch (err) {
    ElMessage.error(err.message || '获取商品失败');
  } finally {
    loading.value = false;
  }
};

const fetchCards = async () => {
  if (!activeProduct.value) return;
  cardsLoading.value = true;
  try {
    const securePath = getSecurePath();
    const params = { product_id: activeProduct.value.id };
    if (cardStatusFilter.value !== null) {
      params.status = cardStatusFilter.value;
    }
    const res = await api.get(`/${securePath}/card/fetch`, { params });
    cards.value = res.data || [];
  } catch (err) {
    ElMessage.error(err.message || '获取卡密失败');
  } finally {
    cardsLoading.value = false;
  }
};

const viewProductCards = (row) => {
  activeProduct.value = row;
  cardStatusFilter.value = null;
  fetchCards();
};

const backToProducts = () => {
  activeProduct.value = null;
  cards.value = [];
  fetchProducts();
};

const handleToggleShow = async (row) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/card/product/save`, {
      id: row.id,
      name: row.name,
      price: row.price,
      show: row.show,
      sort: row.sort
    });
    ElMessage.success(row.show === 1 ? '商品已上架' : '商品已下架');
  } catch (err) {
    ElMessage.error(err.message || '更新状态失败');
    row.show = row.show === 1 ? 0 : 1;
  }
};

const openCreateProductDialog = () => {
  productDialogTitle.value = '添加商品';
  productForm.id = undefined;
  productForm.name = '';
  productForm.price = 10.00;
  productForm.show = 1;
  productForm.sort = 0;
  productForm.description = '';
  productDialogVisible.value = true;
};

const openEditProductDialog = (row) => {
  productDialogTitle.value = '编辑商品';
  productForm.id = row.id;
  productForm.name = row.name;
  productForm.price = row.price / 100;
  productForm.show = row.show;
  productForm.sort = row.sort;
  productForm.description = row.description;
  productDialogVisible.value = true;
};

const handleProductSubmit = async () => {
  if (!productFormRef.value) return;
  await productFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/card/product/save`, {
        id: productForm.id,
        name: productForm.name,
        price: Math.round(productForm.price * 100),
        show: productForm.show,
        sort: productForm.sort,
        description: productForm.description
      });
      ElMessage.success(productForm.id ? '编辑商品成功' : '添加商品成功');
      productDialogVisible.value = false;
      fetchProducts();
    } catch (err) {
      ElMessage.error(err.message || '操作失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDeleteProduct = (row) => {
  ElMessageBox.confirm(`确定要删除商品 "${row.name}" 吗？`, '警告', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/card/product/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchProducts();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

const openImportDialog = () => {
  importForm.codes = '';
  importDialogVisible.value = true;
};

const handleImportSubmit = async () => {
  if (!importFormRef.value) return;
  await importFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const res = await api.post(`/${securePath}/card/import`, {
        product_id: activeProduct.value.id,
        codes: importForm.codes
      });
      ElMessage.success(`成功导入 ${res.data?.count || 0} 条卡密数据`);
      importDialogVisible.value = false;
      fetchCards();
    } catch (err) {
      ElMessage.error(err.message || '导入失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDeleteCard = (row) => {
  ElMessageBox.confirm('确定要彻底删除这条卡密吗？', '提示', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/card/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchCards();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchProducts();
});
</script>

<style scoped>
.cards-container {
  padding: 0 4px;
}
.action-card {
  border-radius: 12px;
  border: 1px solid var(--el-border-color-light);
}
.action-text {
  font-size: 16px;
  font-weight: 600;
}
.table-card {
  border-radius: 12px;
  border: 1px solid var(--el-border-color-light);
}

/* 移动端卡片流 */
.mobile-card-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.mobile-item-card {
  border-radius: 10px;
  border: 1px solid var(--el-border-color-lighter);
  background: #fafafa;
}
.card-header-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}
.product-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex: 1;
  margin-right: 8px;
}
.product-name {
  font-weight: 700;
  font-size: 15px;
  color: var(--el-text-color-primary);
}
.product-sub-info {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}
.product-switch-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.card-price-stock-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  margin: 6px 0;
  border-top: 1px dashed var(--el-border-color-lighter);
  border-bottom: 1px dashed var(--el-border-color-lighter);
}
.price-label, .stock-label {
  font-size: 12px;
  color: var(--el-text-color-regular);
}
.price-val {
  font-size: 16px;
  font-weight: 700;
  color: var(--el-color-danger);
}
.product-desc-box {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.card-actions-row {
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  flex-wrap: wrap;
}
.card-code-box {
  background: var(--el-fill-color-light);
  padding: 6px 10px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
  color: var(--el-color-primary);
  word-break: break-all;
  margin-bottom: 6px;
}
.card-meta-row {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.card-order-row {
  font-size: 11px;
  color: var(--el-text-color-placeholder);
  margin-top: 4px;
}

.code-block {
  background: var(--el-fill-color-light);
  padding: 4px 8px;
  border-radius: 4px;
  font-family: monospace;
  color: var(--el-color-primary);
  font-size: 13px;
}
.form-tip {
  font-size: 11px;
  color: var(--el-text-color-secondary);
}
.cursor-pointer {
  cursor: pointer;
}
.gap-10 {
  gap: 10px;
}
.mt-5 {
  margin-top: 5px;
}
.mt-10 {
  margin-top: 10px;
}
.mt-20 {
  margin-top: 20px;
}
.mb-15 {
  margin-bottom: 15px;
}
.mb-20 {
  margin-bottom: 20px;
}
.ml-20 {
  margin-left: 20px;
}
.ml-10 {
  margin-left: 10px;
}
.font-12 {
  font-size: 12px;
}
.font-13 {
  font-size: 13px;
}
.font-14 {
  font-size: 14px;
}
.text-secondary {
  color: var(--el-text-color-secondary);
}
</style>
