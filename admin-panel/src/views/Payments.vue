<template>
  <div class="payments-container">
    <!-- Action Bar -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <span class="action-text">支付配置</span>
        <el-button type="primary" icon="Plus" @click="handleCreate">
          添加支付方式
        </el-button>
      </div>
    </el-card>

    <!-- Table -->
    <el-card class="table-card mt-20" shadow="hover" v-loading="loading">
      <!-- PC View Table -->
      <el-table v-if="!isMobile" :data="paymentList" stripe style="width: 100%">
        <el-table-column label="排序" width="55" align="center">
          <template #default="scope">
            <el-tooltip content="按住鼠标拖动可直接调整支付方式顺序" placement="top">
              <div 
                class="drag-handle" 
                draggable="true" 
                @dragstart="handleDragStart(scope.$index)"
                @dragover.prevent="handleDragOver(scope.$index)"
                @dragend="handleDragEnd"
              >
                <el-icon :size="16"><Rank /></el-icon>
              </div>
            </el-tooltip>
          </template>
        </el-table-column>
        
        <el-table-column prop="id" label="ID" width="70" align="center" />
        
        <el-table-column prop="enable" label="启用状态" width="100" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.enable"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleEnable(scope.row, val)"
            />
          </template>
        </el-table-column>

        <el-table-column prop="name" label="显示名称" min-width="150">
          <template #default="scope">
            <div class="flex-center-y gap-8">
              <el-avatar v-if="scope.row.icon" :size="24" :src="scope.row.icon" shape="square" class="payment-icon-avatar" />
              <span class="font-weight-600">{{ scope.row.name }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="payment" label="网关插件" width="160">
          <template #default="scope">
            <el-tag type="info" size="small" effect="plain">{{ scope.row.payment }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="手续费" min-width="160">
          <template #default="scope">
            <div class="fee-info">
              <span v-if="scope.row.handling_fee_fixed">固定: <code>¥{{ (scope.row.handling_fee_fixed / 100).toFixed(2) }}</code></span>
              <span v-if="scope.row.handling_fee_percent" class="ml-10">比例: <code>{{ scope.row.handling_fee_percent }}%</code></span>
              <span v-if="!scope.row.handling_fee_fixed && !scope.row.handling_fee_percent" class="text-muted">-</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="notify_url" label="回调地址" min-width="250" show-overflow-tooltip>
          <template #default="scope">
            <div class="notify-url-copy flex-center-y gap-5">
              <code>{{ scope.row.notify_url }}</code>
              <el-button type="primary" link size="small" icon="CopyDocument" @click="copyText(scope.row.notify_url)" />
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="150" align="right">
          <template #default="scope">
            <el-button type="primary" link @click="handleEdit(scope.row)">编辑</el-button>
            <el-button type="danger" link @click="handleDelete(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- Mobile View -->
      <div v-else class="mobile-payment-list">
        <div v-if="paymentList.length === 0" class="empty-placeholder">
          <el-empty description="暂无支付方式" />
        </div>
        <div v-else v-for="(item, index) in paymentList" :key="item.id" class="mobile-payment-card">
          <div class="card-header flex-between">
            <div class="flex-center-y gap-8">
              <span class="payment-id">#{{ item.id }}</span>
              <el-avatar v-if="item.icon" :size="20" :src="item.icon" shape="square" />
              <span class="payment-name">{{ item.name }}</span>
            </div>
            <el-switch
              v-model="item.enable"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleEnable(item, val)"
              size="small"
            />
          </div>
          <div class="card-body mt-10">
            <div class="body-row flex-between">
              <span class="text-muted font-12">网关插件:</span>
              <el-tag type="info" size="small" effect="plain">{{ item.payment }}</el-tag>
            </div>
            <div class="body-row flex-between mt-5">
              <span class="text-muted font-12">手续费:</span>
              <span class="font-12">
                固定: ¥{{ (item.handling_fee_fixed || 0 / 100).toFixed(2) }} / 比例: {{ item.handling_fee_percent || 0 }}%
              </span>
            </div>
            <div class="body-row flex-between mt-5">
              <span class="text-muted font-12">回调域名:</span>
              <span class="font-12 truncate-text" style="max-width: 200px;">{{ item.notify_domain || '默认站点URL' }}</span>
            </div>
          </div>
          <div class="card-actions flex-between mt-10 pt-10">
            <div 
              class="drag-handle-mobile flex-center"
              style="cursor: grab; touch-action: none; padding: 6px 10px; border-radius: 6px; background-color: var(--el-fill-color-light);"
              @touchstart="handleTouchStart($event, index)"
              @touchmove="handleTouchMove($event, index)"
              @touchend="handleTouchEnd"
            >
              <el-icon :size="16" class="mr-5"><Rank /></el-icon>
              <span class="sort-text">拖动排序</span>
            </div>
            <div class="action-buttons flex-center gap-15">
              <el-button type="primary" link size="small" @click="handleEdit(item)">编辑</el-button>
              <el-button type="danger" link size="small" @click="handleDelete(item)">删除</el-button>
            </div>
          </div>
        </div>
      </div>
    </el-card>

    <!-- Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '650px'">
      <el-scrollbar max-height="70vh">
        <el-form :model="form" :rules="rules" ref="formRef" label-width="120px" style="padding-right: 15px;">
          <el-form-item label="显示名称" prop="name">
            <el-input v-model="form.name" placeholder="例如：支付宝、微信支付" />
          </el-form-item>
          <el-form-item label="图标 URL" prop="icon">
            <el-input v-model="form.icon" placeholder="支付图标的网络链接 (选填)" />
          </el-form-item>
          <el-form-item label="网关类型" prop="payment">
            <el-select v-model="form.payment" placeholder="请选择网关插件" style="width: 100%" @change="handlePaymentChange" :disabled="isEdit">
              <el-option v-for="method in paymentMethods" :key="method" :label="method" :value="method" />
            </el-select>
          </el-form-item>
          <el-row :gutter="20">
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="固定手续费" prop="handling_fee_fixed">
                <el-input-number v-model="form.handling_fee_fixed" :min="0" :controls="false" placeholder="固定每笔额外收费" style="width: 100%">
                  <template #suffix>分</template>
                </el-input-number>
                <div class="form-tip">单位为分 (1元 = 100分)</div>
              </el-form-item>
            </el-col>
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="百分比手续费" prop="handling_fee_percent">
                <el-input-number v-model="form.handling_fee_percent" :min="0" :max="100" :precision="2" :controls="false" placeholder="额外抽取的费率" style="width: 100%">
                  <template #suffix>%</template>
                </el-input-number>
              </el-form-item>
            </el-col>
          </el-row>
          <el-form-item label="自定义回调域名" prop="notify_domain">
            <el-input v-model="form.notify_domain" placeholder="留空则使用当前站点域名，如：https://api.my-domain.com" />
            <div class="form-tip">如遇到支付回调失败，可单独绑定能过防火墙或CDN的回调域名</div>
          </el-form-item>

          <!-- Dynamic Configuration Fields -->
          <template v-if="Object.keys(dynamicFields).length > 0">
            <div class="section-title">网关参数配置</div>
            <el-form-item 
              v-for="(field, key) in dynamicFields" 
              :key="key" 
              :label="field.label"
              :prop="'config.' + key"
              :rules="[{ required: true, message: field.label + '不能为空', trigger: 'blur' }]"
            >
              <el-input 
                v-model="form.config[key]" 
                :type="field.type === 'textarea' ? 'textarea' : 'text'" 
                :rows="field.type === 'textarea' ? 3 : 1"
                :placeholder="field.description || ('请输入 ' + field.label)" 
              />
              <div class="form-tip" v-if="field.description">{{ field.description }}</div>
            </el-form-item>
          </template>
        </el-form>
      </el-scrollbar>
      <template #footer>
        <div class="flex-end gap-10">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api, { getSecurePath } from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();
const loading = ref(false);
const submitLoading = ref(false);
const dialogVisible = ref(false);
const dialogTitle = ref('添加支付方式');
const isEdit = ref(false);

const paymentList = ref([]);
const paymentMethods = ref([]);
const dynamicFields = ref({});

const form = reactive({
  id: null,
  name: '',
  icon: '',
  payment: '',
  handling_fee_fixed: 0,
  handling_fee_percent: 0,
  notify_domain: '',
  config: {}
});

const rules = {
  name: [{ required: true, message: '请输入显示名称', trigger: 'blur' }],
  payment: [{ required: true, message: '请选择网关类型', trigger: 'change' }]
};

const fetchPayments = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/payment/fetch`);
    if (res.data) {
      paymentList.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const fetchPaymentMethods = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/payment/getPaymentMethods`);
    if (res.data) {
      paymentMethods.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const handlePaymentChange = async (val) => {
  form.config = {};
  dynamicFields.value = {};
  if (!val) return;
  
  try {
    const securePath = getSecurePath();
    const res = await api.post(`/${securePath}/payment/getPaymentForm`, {
      payment: val,
      id: form.id
    });
    if (res.data) {
      dynamicFields.value = res.data;
      // Initialize form config with empty values
      Object.keys(res.data).forEach(key => {
        form.config[key] = '';
      });
    }
  } catch (err) {
    console.error(err);
  }
};

const handleCreate = () => {
  isEdit.value = false;
  dialogTitle.value = '添加支付方式';
  
  form.id = null;
  form.name = '';
  form.icon = '';
  form.payment = '';
  form.handling_fee_fixed = 0;
  form.handling_fee_percent = 0;
  form.notify_domain = '';
  form.config = {};
  
  dynamicFields.value = {};
  dialogVisible.value = true;
};

const handleEdit = async (row) => {
  isEdit.value = true;
  dialogTitle.value = '编辑支付方式';
  
  form.id = row.id;
  form.name = row.name;
  form.icon = row.icon || '';
  form.payment = row.payment;
  form.handling_fee_fixed = row.handling_fee_fixed || 0;
  form.handling_fee_percent = row.handling_fee_percent || 0;
  form.notify_domain = row.notify_domain || '';
  
  // Load dynamic fields schema first
  try {
    const securePath = getSecurePath();
    const res = await api.post(`/${securePath}/payment/getPaymentForm`, {
      payment: row.payment,
      id: row.id
    });
    if (res.data) {
      dynamicFields.value = res.data;
      // Populate saved config values
      form.config = { ...row.config };
    }
  } catch (err) {
    console.error(err);
  }
  
  dialogVisible.value = true;
};

const formRef = ref(null);
const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const payload = {
        name: form.name,
        icon: form.icon,
        payment: form.payment,
        handling_fee_fixed: form.handling_fee_fixed,
        handling_fee_percent: form.handling_fee_percent,
        notify_domain: form.notify_domain,
        config: form.config
      };
      
      if (isEdit.value) {
        payload.id = form.id;
      }
      
      await api.post(`/${securePath}/payment/save`, payload);
      ElMessage.success(isEdit.value ? '保存成功' : '创建成功');
      dialogVisible.value = false;
      fetchPayments();
    } catch (err) {
      console.error(err);
      ElMessage.error(err.message || '保存失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleToggleEnable = async (row, val) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/payment/show`, { id: row.id });
    ElMessage.success('启用状态已更新');
  } catch (err) {
    console.error(err);
    row.enable = val === 1 ? 0 : 1;
  }
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定要删除该支付方式吗？', '提示', {
    type: 'warning'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/payment/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchPayments();
    } catch (err) {
      console.error(err);
    }
  }).catch(() => {});
};

// Drag and drop sorting logic
const dragIndex = ref(-1);

const handleDragStart = (index) => {
  dragIndex.value = index;
};

const handleDragOver = (index) => {
  if (dragIndex.value === -1 || dragIndex.value === index) return;
  const list = [...paymentList.value];
  const temp = list[dragIndex.value];
  list.splice(dragIndex.value, 1);
  list.splice(index, 0, temp);
  paymentList.value = list;
  dragIndex.value = index;
};

const handleDragEnd = async () => {
  dragIndex.value = -1;
  await saveSort();
};

// Touch drag sorting for mobile
const touchStartIndex = ref(-1);
const touchStartY = ref(0);

const handleTouchStart = (event, index) => {
  touchStartIndex.value = index;
  touchStartY.value = event.touches[0].clientY;
};

const handleTouchMove = (event, index) => {
  if (touchStartIndex.value === -1) return;
  const currentY = event.touches[0].clientY;
  const diffY = currentY - touchStartY.value;
  
  const step = 90; // Mobile card height step
  if (Math.abs(diffY) > step) {
    const direction = diffY > 0 ? 1 : -1;
    const targetIndex = touchStartIndex.value + direction;
    
    if (targetIndex >= 0 && targetIndex < paymentList.value.length) {
      const list = [...paymentList.value];
      const temp = list[touchStartIndex.value];
      list.splice(touchStartIndex.value, 1);
      list.splice(targetIndex, 0, temp);
      paymentList.value = list;
      
      touchStartIndex.value = targetIndex;
      touchStartY.value = currentY;
    }
  }
};

const handleTouchEnd = async () => {
  touchStartIndex.value = -1;
  await saveSort();
};

const saveSort = async () => {
  try {
    const ids = paymentList.value.map(item => item.id);
    const securePath = getSecurePath();
    await api.post(`/${securePath}/payment/sort`, { ids });
    ElMessage.success('排序更新成功');
  } catch (err) {
    console.error(err);
    ElMessage.error('排序更新失败');
  }
};

const copyText = (text) => {
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('复制成功！');
  }).catch(() => {
    ElMessage.error('复制失败，请手动选择复制');
  });
};

onMounted(() => {
  fetchPayments();
  fetchPaymentMethods();
});
</script>

<style scoped>
.action-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.action-text {
  font-size: 15px;
  font-weight: 600;
}

.table-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.payment-icon-avatar {
  background-color: var(--el-fill-color-light);
}

.drag-handle {
  cursor: grab;
  color: var(--el-text-color-secondary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  border-radius: 4px;
  transition: all 0.2s ease;
}

.drag-handle:active {
  cursor: grabbing;
  color: var(--el-color-primary);
  background-color: var(--el-fill-color-light);
}

.drag-handle:hover {
  color: var(--el-color-primary);
  background-color: var(--el-fill-color-lighter);
}

.notify-url-copy code {
  background-color: var(--el-fill-color-light);
  padding: 2px 6px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  margin: 25px 0 15px 0;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
}

.form-tip {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  line-height: 1.4;
  margin-top: 4px;
  width: 100%;
}

.gap-10 {
  gap: 10px;
}

.gap-8 {
  gap: 8px;
}

.gap-5 {
  gap: 5px;
}

.mt-20 {
  margin-top: 20px;
}

.ml-10 {
  margin-left: 10px;
}

.text-muted {
  color: var(--el-text-color-secondary);
}

.fee-info code {
  font-family: monospace;
  font-weight: 600;
}

/* Mobile Payments */
.mobile-payment-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.mobile-payment-card {
  background-color: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 12px;
  padding: 14px 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
}

.payment-id {
  font-family: monospace;
  font-weight: bold;
  color: var(--el-text-color-secondary);
  background-color: var(--el-fill-color-light);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
}

.payment-name {
  font-weight: 600;
  font-size: 14px;
}

.card-actions {
  border-top: 1px dashed var(--el-border-color-lighter);
}

.drag-handle-mobile {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.sort-text {
  font-size: 12px;
}

.empty-placeholder {
  padding: 40px 0;
  text-align: center;
}
</style>
