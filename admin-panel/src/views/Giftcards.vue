<template>
  <div class="giftcards-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center">
        <span class="action-text">礼品卡管理</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">添加礼品卡</el-button>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="giftcards" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" :width="isMobile ? '40' : '70'" align="center" />
        <el-table-column v-if="!isMobile" prop="name" label="名称" min-width="120" show-overflow-tooltip />
        <el-table-column v-if="!isMobile" prop="code" label="卡密" min-width="150" show-overflow-tooltip>
          <template #default="scope">
            <code>{{ scope.row.code }}</code>
          </template>
        </el-table-column>
        
        <!-- Mobile Combined Column -->
        <el-table-column v-if="isMobile" label="礼品卡" min-width="110">
          <template #default="scope">
            <div style="font-weight: 600; line-height: 1.2;">{{ scope.row.name }}</div>
            <code style="font-size: 10px; opacity: 0.8;">{{ scope.row.code }}</code>
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" prop="type" label="类型" width="110" align="center">
          <template #default="scope">
            <el-tag :type="getTypeTagType(scope.row.type)" size="small">
              {{ typeMap[scope.row.type] || scope.row.type }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="value" label="数值" :width="isMobile ? '70' : '110'" :align="isMobile ? 'center' : 'right'">
          <template #default="scope">
            <span style="font-weight: 600">
              {{ formatValue(scope.row) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" label="有效期" min-width="280">
          <template #default="scope">
            <span class="font-12">
              {{ formatTime(scope.row.started_at) }} 至 {{ formatTime(scope.row.ended_at) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="使用" :width="isMobile ? '65' : '120'" align="center">
          <template #default="scope">
            <span>{{ scope.row.use_count || 0 }}/{{ scope.row.limit_use || '∞' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" :width="isMobile ? '50' : '100'" :align="isMobile ? 'center' : 'right'">
          <template #default="scope">
            <el-button type="danger" link @click="handleDelete(scope.row)" style="padding: 0;">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination flex-between mt-20">
        <span class="pagination-info">共 {{ total }} 条记录</span>
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50]"
          layout="sizes, prev, pager, next"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <!-- Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '550px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '110px'">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="礼品卡名称，如：充值10元" />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-select v-model="form.type" style="width: 100%" @change="handleTypeChange">
            <el-option v-for="(label, key) in typeMap" :key="key" :label="label" :value="parseInt(key)" />
          </el-select>
        </el-form-item>
        
        <el-form-item label="对应订阅" prop="plan_id" v-if="form.type === 5">
          <el-select v-model="form.plan_id" placeholder="选择套餐订阅" style="width: 100%">
            <el-option v-for="p in plans" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="对应值" prop="value" v-if="form.type !== 4">
          <el-input-number v-model="form.value" :min="1" :precision="form.type === 1 ? 2 : 0" style="width: 150px" />
          <span class="form-tip ml-10">
            {{ getValueTip() }}
          </span>
        </el-form-item>

        <el-form-item label="卡密" prop="code">
          <el-input v-model="form.code" placeholder="留空自动随机生成卡密" />
        </el-form-item>
        <el-form-item label="生成数量" prop="generate_count">
          <el-input-number v-model="form.generate_count" :min="1" :max="500" style="width: 150px" />
          <span class="form-tip ml-10">批量生成多个卡密时填入，最大 500</span>
        </el-form-item>

        <el-form-item label="有效期">
          <el-date-picker
            v-model="form.time_range"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            value-format="X"
            style="width: 100%"
          />
        </el-form-item>

        <el-form-item label="最大使用次数">
          <el-input-number v-model="form.limit_use" :min="0" style="width: 150px" />
          <span class="form-tip ml-10">卡密总兑换上限，留空或 0 为不限</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
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
const submitLoading = ref(false);
const dialogVisible = ref(false);
const dialogTitle = ref('添加礼品卡');

const giftcards = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);
const plans = ref([]);

const typeMap = {
  1: '账户余额',
  2: '订阅时长',
  3: '流量包',
  4: '流量重置包',
  5: '购买套餐'
};

const getTypeTagType = (type) => {
  const map = {
    1: 'primary',
    2: 'warning',
    3: 'success',
    4: 'info',
    5: 'danger'
  };
  return map[type] || 'info';
};

const formatValue = (row) => {
  if (row.type === 1) return `￥${(row.value / 100).toFixed(2)}`;
  if (row.type === 2) return `${row.value} 天`;
  if (row.type === 3) return `${row.value} GB`;
  if (row.type === 4) return `重置流量`;
  if (row.type === 5) return `${row.value} 天`;
  return row.value;
};

const getValueTip = () => {
  if (form.type === 1) return '单位：元';
  if (form.type === 2) return '增加订阅时长 (天)';
  if (form.type === 3) return '充值一次性流量 (GB)';
  if (form.type === 5) return '购买对应订阅的时长 (天)';
  return '';
};

const formRef = ref(null);
const form = reactive({
  name: '',
  type: 1,
  value: 10,
  plan_id: null,
  code: '',
  generate_count: 1,
  time_range: [],
  limit_use: 1
});

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }]
};

const formatTime = (ts) => {
  if (!ts) return '-';
  return new Date(ts * 1000).toLocaleString();
};

const fetchPlans = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/plan/fetch`);
    if (res.data) plans.value = res.data;
  } catch (err) {
    console.error(err);
  }
};

const fetchGiftcards = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/giftcard/fetch`, {
      params: {
        current: currentPage.value,
        pageSize: pageSize.value
      }
    });
    if (res.data) {
      giftcards.value = res.data;
      total.value = res.total;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleTypeChange = () => {
  form.value = 10;
  form.plan_id = plans.value.length > 0 ? plans.value[0].id : null;
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchGiftcards();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchGiftcards();
};

const openCreateDialog = () => {
  dialogTitle.value = '添加礼品卡';
  form.name = '';
  form.type = 1;
  form.value = 10;
  form.plan_id = plans.value.length > 0 ? plans.value[0].id : null;
  form.code = '';
  form.generate_count = 1;
  
  const now = Math.floor(Date.now() / 1000);
  form.time_range = [String(now), String(now + 86400 * 30)]; // Default 30 days
  form.limit_use = 1;
  dialogVisible.value = true;
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    if (!form.time_range || form.time_range.length < 2) {
      ElMessage.warning('请选择有效期时间范围');
      return;
    }
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      
      const payload = {
        name: form.name,
        type: form.type,
        value: form.type === 1 ? Math.round(form.value * 100) : form.value, // convert to cents if amount
        started_at: parseInt(form.time_range[0]),
        ended_at: parseInt(form.time_range[1]),
        generate_count: form.generate_count > 1 ? form.generate_count : null,
        limit_use: form.limit_use || null
      };
      if (form.type === 5) {
        payload.plan_id = form.plan_id;
      }
      if (form.code) {
        payload.code = form.code;
      }

      if (form.generate_count > 1) {
        // Multi-generate returns CSV text
        const res = await api.post(`/${securePath}/giftcard/generate`, payload, {
          responseType: 'text'
        });
        // Download CSV
        const blob = new Blob([res], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', `giftcards_${Date.now()}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        ElMessage.success('已批量生成并下载礼品卡 CSV 文件');
      } else {
        await api.post(`/${securePath}/giftcard/generate`, payload);
        ElMessage.success('添加礼品卡成功');
      }
      
      dialogVisible.value = false;
      fetchGiftcards();
    } catch (err) {
      ElMessage.error(err.message || '生成失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定删除该礼品卡吗？删除后用户将无法再兑换此券！', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/giftcard/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchGiftcards();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchPlans();
  fetchGiftcards();
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
.form-tip {
  font-size: 11px;
  color: var(--el-text-color-secondary);
}
.mt-20 {
  margin-top: 20px;
}
.ml-10 {
  margin-left: 10px;
}
.pagination-info {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
:deep(.mobile-table) {
  font-size: 12px;
}
:deep(.mobile-table .el-table__cell) {
  padding: 6px 0 !important;
}
:deep(.mobile-table .cell) {
  padding-left: 4px !important;
  padding-right: 4px !important;
}
</style>
