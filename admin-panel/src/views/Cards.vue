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
        <div>
          <el-button v-if="!activeProduct" type="primary" icon="Plus" @click="openCreateProductDialog">添加商品</el-button>
          <div v-else class="flex gap-10">
            <el-button type="success" icon="Download" @click="openImportDialog">导入卡密</el-button>
            <el-button type="info" icon="Back" @click="backToProducts">返回商品列表</el-button>
          </div>
        </div>
      </div>
    </el-card>

    <!-- 1. 商品管理列表 -->
    <el-card v-if="!activeProduct" class="table-card mt-20" shadow="hover">
      <el-table :data="products" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="name" label="商品名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="price" label="单价" width="110" align="right">
          <template #default="scope">
            <span style="font-weight: 600">￥{{ (scope.row.price / 100).toFixed(2) }}</span>
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
        <el-table-column v-if="!isMobile" prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="操作" :width="isMobile ? '120' : '220'" align="right">
          <template #default="scope">
            <el-button type="success" link @click="viewProductCards(scope.row)">卡密管理</el-button>
            <el-button type="primary" link @click="openEditProductDialog(scope.row)">编辑</el-button>
            <el-button type="danger" link @click="handleDeleteProduct(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 2. 卡密数据管理列表 -->
    <el-card v-else class="table-card mt-20" shadow="hover">
      <div class="flex-between align-center mb-20 flex-wrap gap-10">
        <div class="flex-center gap-10">
          <span class="font-14 text-secondary">库存状态：</span>
          <el-radio-group v-model="cardStatusFilter" size="small" @change="fetchCards">
            <el-radio-button :label="null">全部</el-radio-button>
            <el-radio-button :label="0">未售出</el-radio-button>
            <el-radio-button :label="1">已售出</el-radio-button>
          </el-radio-group>
        </div>
      </div>

      <el-table :data="cards" v-loading="cardsLoading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
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
        <el-table-column v-if="!isMobile" label="更新时间" width="160">
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
          <el-input :model-value="activeProduct ? activeProduct.name : ''" disabled />
        </el-form-item>
        <el-form-item label="卡密数据" prop="codes">
          <el-input
            v-model="importForm.codes"
            type="textarea"
            :rows="12"
            placeholder="请粘贴卡密数据，支持一行一条。
例如：
账号: abcd@gmail.com ---- 密码: password1 ---- 密保: xx
账号: efgh@gmail.com ---- 密码: password8 ---- 密保: yy"
          />
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
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const loading = ref(false);
const cardsLoading = ref(false);
const submitLoading = ref(false);

const products = ref([]);
const activeProduct = ref(null);
const cards = ref([]);
const cardStatusFilter = ref(null); // null, 0, 1

// Product Dialog
const productDialogVisible = ref(false);
const productDialogTitle = ref('添加商品');
const productFormRef = ref(null);
const productForm = reactive({
  id: null,
  name: '',
  price: 10,
  show: 1,
  sort: 0,
  description: ''
});

const productRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }]
};

// Import Dialog
const importDialogVisible = ref(false);
const importFormRef = ref(null);
const importForm = reactive({
  codes: ''
});
const importRules = {
  codes: [{ required: true, message: '请粘贴需要导入的卡密数据', trigger: 'blur' }]
};

// Time Formatter
const formatTime = (ts) => {
  if (!ts) return '-';
  return new Date(ts * 1000).toLocaleString();
};

// API Fetch Products
const fetchProducts = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/card/product/fetch`);
    if (res.data) {
      products.value = res.data;
    }
  } catch (err) {
    ElMessage.error(err.message || '获取商品失败');
  } finally {
    loading.value = false;
  }
};

// API Fetch Cards
const fetchCards = async () => {
  if (!activeProduct.value) return;
  cardsLoading.value = true;
  try {
    const securePath = getSecurePath();
    const params = {
      product_id: activeProduct.value.id
    };
    if (cardStatusFilter.value !== null) {
      params.status = cardStatusFilter.value;
    }
    const res = await api.get(`/${securePath}/card/fetch`, { params });
    if (res.data) {
      cards.value = res.data;
    }
  } catch (err) {
    ElMessage.error(err.message || '获取卡密数据失败');
  } finally {
    cardsLoading.value = false;
  }
};

// Navigation
const viewProductCards = (product) => {
  activeProduct.value = product;
  cardStatusFilter.value = null;
  fetchCards();
};

const backToProducts = () => {
  activeProduct.value = null;
  cards.value = [];
  fetchProducts();
};

// Product CRUD Actions
const openCreateProductDialog = () => {
  productDialogTitle.value = '添加商品';
  productForm.id = null;
  productForm.name = '';
  productForm.price = 10;
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
      const payload = {
        id: productForm.id,
        name: productForm.name,
        price: Math.round(productForm.price * 100), // To cents
        show: productForm.show,
        sort: productForm.sort,
        description: productForm.description
      };
      await api.post(`/${securePath}/card/product/save`, payload);
      ElMessage.success(productForm.id ? '编辑商品成功' : '创建商品成功');
      productDialogVisible.value = false;
      fetchProducts();
    } catch (err) {
      ElMessage.error(err.message || '保存失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleToggleShow = async (row) => {
  try {
    const securePath = getSecurePath();
    const payload = {
      id: row.id,
      name: row.name,
      price: row.price,
      show: row.show,
      sort: row.sort
    };
    await api.post(`/${securePath}/card/product/save`, payload);
    ElMessage.success(row.show ? '商品已上架' : '商品已下架');
  } catch (err) {
    ElMessage.error(err.message || '操作失败');
    row.show = row.show === 1 ? 0 : 1; // Revert
  }
};

const handleDeleteProduct = (row) => {
  ElMessageBox.confirm('确定删除该商品吗？若商品内有卡密数据则无法删除。', '警告', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
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

// Card Inventory CRUD Actions
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
      const payload = {
        product_id: activeProduct.value.id,
        codes: importForm.codes
      };
      const res = await api.post(`/${securePath}/card/import`, payload);
      ElMessage.success(`成功导入 ${res.data.count} 条卡密数据`);
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
  ElMessageBox.confirm('确定彻底删除这一条卡密数据吗？该操作不可撤销！', '警告', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
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
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}
.action-text {
  font-size: 16px;
  font-weight: 600;
}
.table-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
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
.mt-20 {
  margin-top: 20px;
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
:deep(.mobile-table) {
  font-size: 12px;
}
:deep(.mobile-table .el-table__cell) {
  padding: 6px 0 !important;
}
</style>
