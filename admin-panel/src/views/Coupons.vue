<template>
  <div class="coupons-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center">
        <span class="action-text">优惠券管理</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">添加优惠券</el-button>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="coupons" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" :width="isMobile ? '40' : '70'" align="center" />
        <el-table-column v-if="!isMobile" prop="name" label="名称" min-width="120" show-overflow-tooltip />
        <el-table-column v-if="!isMobile" prop="code" label="券码" min-width="120" show-overflow-tooltip>
          <template #default="scope">
            <code>{{ scope.row.code }}</code>
          </template>
        </el-table-column>
        
        <!-- Mobile Combined Column -->
        <el-table-column v-if="isMobile" label="优惠券" min-width="110">
          <template #default="scope">
            <div style="font-weight: 600; line-height: 1.2;">{{ scope.row.name }}</div>
            <code style="font-size: 10px; opacity: 0.8;">{{ scope.row.code }}</code>
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" prop="type" label="类型" width="100" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.type === 1 ? 'primary' : 'success'" size="small">
              {{ scope.row.type === 1 ? '金额抵扣' : '比例折价' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="value" label="面值" :width="isMobile ? '70' : '110'" :align="isMobile ? 'center' : 'right'">
          <template #default="scope">
            <span v-if="scope.row.type === 1" style="font-weight: 600">
              ￥{{ (scope.row.value / 100).toFixed(isMobile ? 1 : 2) }}
            </span>
            <span v-else style="font-weight: 600">
              {{ scope.row.value }}%
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
        <el-table-column label="限制" :width="isMobile ? '65' : '150'" align="center">
          <template #default="scope">
            <span>{{ scope.row.use_count || 0 }}/{{ scope.row.limit_use || '∞' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="show" label="启用" :width="isMobile ? '55' : '100'" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.show"
              :active-value="1"
              :inactive-value="0"
              @change="handleToggleShow(scope.row)"
              :size="isMobile ? 'small' : 'default'"
            />
          </template>
        </el-table-column>
        <el-table-column label="操作" :width="isMobile ? '50' : '120'" :align="isMobile ? 'center' : 'right'">
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
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '600px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '120px'">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="优惠券名称，如：新春8折" />
        </el-form-item>
        <el-form-item label="类型" prop="type">
          <el-radio-group v-model="form.type">
            <el-radio :label="1">金额抵扣 (分)</el-radio>
            <el-radio :label="2">比例折价 (%)</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="form.type === 1 ? '抵扣金额 (元)' : '折价比例 (%)'" prop="value">
          <el-input-number v-model="form.value" :min="1" :precision="form.type === 1 ? 2 : 0" style="width: 150px" />
          <span class="form-tip ml-10">
            {{ form.type === 1 ? '抵扣对应的余额数值' : '输入折扣比例，如 80 表示 8 折，95 表示 95 折' }}
          </span>
        </el-form-item>
        <el-form-item label="券码" prop="code">
          <el-input v-model="form.code" placeholder="留空自动随机生成" />
        </el-form-item>
        <el-form-item label="生成数量" prop="generate_count">
          <el-input-number v-model="form.generate_count" :min="1" :max="500" style="width: 150px" />
          <span class="form-tip ml-10">批量生成多个券码时填入，最大 500</span>
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
          <span class="form-tip ml-10">总共可被兑换的次数，留空或 0 表示不限制</span>
        </el-form-item>

        <el-form-item label="单用户限制">
          <el-input-number v-model="form.limit_use_with_user" :min="0" style="width: 150px" />
          <span class="form-tip ml-10">每个用户可兑换的次数，留空或 0 表示不限制</span>
        </el-form-item>

        <el-form-item label="限绑定订阅">
          <el-select v-model="form.limit_plan_ids" multiple placeholder="不绑定则不限制" style="width: 100%">
            <el-option v-for="p in plans" :key="p.id" :label="p.name" :value="p.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="限价格周期">
          <el-select v-model="form.limit_period" multiple placeholder="不限制周期" style="width: 100%">
            <el-option v-for="(label, key) in periods" :key="key" :label="label" :value="key" />
          </el-select>
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
const dialogTitle = ref('添加优惠券');

const coupons = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);
const plans = ref([]);

const periods = {
  month_price: '按月',
  quarter_price: '按季',
  half_year_price: '半年',
  year_price: '按年',
  two_year_price: '两年',
  three_year_price: '三年',
  onetime_price: '一次性',
  reset_price: '重置包'
};

const formRef = ref(null);
const form = reactive({
  name: '',
  type: 1,
  value: 10,
  code: '',
  generate_count: 1,
  time_range: [],
  limit_use: null,
  limit_use_with_user: null,
  limit_plan_ids: [],
  limit_period: []
});

const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择类型', trigger: 'change' }],
  value: [{ required: true, message: '请输入数值', trigger: 'blur' }]
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

const fetchCoupons = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/coupon/fetch`, {
      params: {
        current: currentPage.value,
        pageSize: pageSize.value
      }
    });
    if (res.data) {
      coupons.value = res.data;
      total.value = res.total;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchCoupons();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchCoupons();
};

const openCreateDialog = () => {
  dialogTitle.value = '添加优惠券';
  form.name = '';
  form.type = 1;
  form.value = 10;
  form.code = '';
  form.generate_count = 1;
  
  const now = Math.floor(Date.now() / 1000);
  form.time_range = [String(now), String(now + 86400 * 30)]; // Default 30 days
  form.limit_use = null;
  form.limit_use_with_user = null;
  form.limit_plan_ids = [];
  form.limit_period = [];
  dialogVisible.value = true;
};

const handleToggleShow = async (row) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/coupon/show`, { id: row.id });
    ElMessage.success('启用状态更新成功');
  } catch (err) {
    console.error(err);
    row.show = row.show ? 0 : 1;
  }
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
        limit_use: form.limit_use || null,
        limit_use_with_user: form.limit_use_with_user || null,
        limit_plan_ids: form.limit_plan_ids.length > 0 ? form.limit_plan_ids : null,
        limit_period: form.limit_period.length > 0 ? form.limit_period : null
      };
      if (form.code) {
        payload.code = form.code;
      }

      if (form.generate_count > 1) {
        // Multi-generate returns CSV text
        const res = await api.post(`/${securePath}/coupon/generate`, payload, {
          responseType: 'text'
        });
        // Download CSV
        const blob = new Blob([res], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', `coupons_${Date.now()}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        ElMessage.success('已批量生成并下载优惠券 CSV 文件');
      } else {
        await api.post(`/${securePath}/coupon/generate`, payload);
        ElMessage.success('添加优惠券成功');
      }
      
      dialogVisible.value = false;
      fetchCoupons();
    } catch (err) {
      ElMessage.error(err.message || '生成失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定删除该优惠券吗？删除后用户将无法再进行兑换！', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/coupon/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchCoupons();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchPlans();
  fetchCoupons();
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
