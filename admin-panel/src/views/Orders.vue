<template>
  <div class="orders-container">
    <!-- Action / Filter Card -->
    <el-card class="filter-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <div class="filter-left flex-center flex-wrap gap-10">
          <el-input
            v-model="searchQuery"
            placeholder="搜索用户邮箱 / 订单号..."
            prefix-icon="Search"
            clearable
            style="width: 260px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          />
          
          <el-select v-model="filterStatus" placeholder="订单状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="所有状态" value="" />
            <el-option label="待支付" value="0" />
            <el-option label="已完成" value="3" />
            <el-option label="已取消" value="2" />
            <el-option label="已折抵" value="4" />
          </el-select>

          <el-select v-model="filterCommission" placeholder="订单分类" style="width: 120px" @change="handleSearch">
            <el-option label="所有订单" value="all" />
            <el-option label="仅返佣订单" value="commission" />
          </el-select>

          <el-button type="primary" @click="handleSearch">筛选</el-button>
        </div>

        <div class="filter-right">
          <el-button type="success" icon="Plus" @click="openAssignDialog">手动分配订单</el-button>
        </div>
      </div>
    </el-card>

    <!-- Filter Indicator for User/Inviter -->
    <el-card v-if="routeFilterEmail || routeFilterInviteId" class="filter-indicator-card mt-20" shadow="never">
      <div class="flex-between">
        <div class="flex-center" style="gap: 8px;">
          <el-icon><InfoFilled /></el-icon>
          <span v-if="routeFilterEmail">
            正在查看用户 <strong style="color: var(--el-color-primary);">{{ routeFilterEmail }}</strong> 的订单列表
          </span>
          <span v-else-if="routeFilterInviteId">
            正在查看用户 <strong style="color: var(--el-color-primary);">{{ routeFilterInviteEmail || routeFilterInviteId }}</strong> 的邀请人返利订单
          </span>
        </div>
        <el-button type="danger" size="small" icon="Close" @click="clearRouteFilter">清除过滤</el-button>
      </div>
    </el-card>

    <!-- Orders Table -->
    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="orders" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="trade_no" label="订单号" min-width="180" show-overflow-tooltip>
          <template #default="scope">
            <code>{{ scope.row.trade_no }}</code>
          </template>
        </el-table-column>

        <el-table-column prop="user_id" label="购买用户" min-width="160" show-overflow-tooltip>
          <template #default="scope">
            <!-- Note: Usually we would join the email, but since order fetch filters by email, 
                 we will display user ID or simple placeholder if email fetching is complex, 
                 but wait, the backend fetch returns email or user_id?
                 Let's check the API: it returns user_id, but we can search by email. -->
            <span>用户 ID: {{ scope.row.user_id }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="plan_name" label="订阅计划" width="130">
          <template #default="scope">
            <el-tag size="small" type="primary" effect="plain">
              {{ scope.row.plan_name || '已失效订阅' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="type" label="类型" width="90" align="center">
          <template #default="scope">
            <el-tag :type="getTypeTag(scope.row.type)" size="small">
              {{ getTypeText(scope.row.type) }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="total_amount" label="订单金额" width="110" align="right">
          <template #default="scope">
            <span class="amount-text">{{ formatPrice(scope.row.total_amount) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="commission_balance" label="返佣金额" width="110" align="right">
          <template #default="scope">
            <span v-if="scope.row.commission_balance" class="amount-text" style="color: var(--el-color-danger)">{{ formatPrice(scope.row.commission_balance) }}</span>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column prop="commission_status" label="佣金状态" width="150" align="center">
          <template #default="scope">
            <template v-if="scope.row.invite_user_id && scope.row.commission_balance > 0">
              <el-dropdown trigger="click" @command="(cmd) => handleUpdateCommissionStatus(scope.row, cmd)" :disabled="scope.row.commission_status === 2">
                <span class="el-dropdown-link" style="cursor: pointer;" :style="{ cursor: scope.row.commission_status === 2 ? 'not-allowed' : 'pointer' }">
                  <el-tag :type="getCommissionStatusTag(scope.row.commission_status)" size="small">
                    {{ getCommissionStatusText(scope.row.commission_status) }}
                    <el-icon v-if="scope.row.commission_status !== 2" class="el-icon--right"><ArrowDown /></el-icon>
                  </el-tag>
                </span>
                <template #dropdown v-if="scope.row.commission_status !== 2">
                  <el-dropdown-menu>
                    <el-dropdown-item command="0" :disabled="scope.row.commission_status === 0">待确认</el-dropdown-item>
                    <el-dropdown-item command="1" :disabled="scope.row.commission_status === 1">有效 (待发放)</el-dropdown-item>
                    <el-dropdown-item command="3" :disabled="scope.row.commission_status === 3">无效</el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
            </template>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column prop="created_at" label="创建时间" width="160">
          <template #default="scope">
            {{ formatTime(scope.row.created_at) }}
          </template>
        </el-table-column>

        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="scope">
            <el-tag :type="getStatusTag(scope.row.status)" effect="dark" size="small">
              {{ getStatusText(scope.row.status) }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="160" align="right" fixed="right">
          <template #default="scope">
            <template v-if="scope.row.status === 0">
              <el-button type="success" link @click="handleMarkPaid(scope.row)">入账</el-button>
              <el-button type="danger" link @click="handleCancel(scope.row)">取消</el-button>
            </template>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
      </el-table>

      <!-- Pagination -->
      <div class="pagination flex-between mt-20">
        <span class="pagination-info">共 {{ total }} 条记录</span>
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          layout="sizes, prev, pager, next"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <!-- Assign Order Dialog -->
    <el-dialog v-model="assignVisible" title="手动分配订阅订单" :width="isMobile ? '95%' : '550px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="assignForm" :rules="assignRules" ref="assignFormRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '100px'">
        <el-form-item label="用户邮箱" prop="email">
          <el-input v-model="assignForm.email" placeholder="请输入绑定的用户邮箱" />
        </el-form-item>

        <el-form-item label="选择订阅" prop="plan_id">
          <el-select v-model="assignForm.plan_id" placeholder="选择计划" style="width: 100%" @change="handlePlanChange">
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="账单周期" prop="period">
          <el-select v-model="assignForm.period" placeholder="选择购买周期" style="width: 100%">
            <el-option label="月付" value="month_price" />
            <el-option label="季付" value="quarter_price" />
            <el-option label="半年付" value="half_year_price" />
            <el-option label="年付" value="year_price" />
            <el-option label="两年付" value="two_year_price" />
            <el-option label="三年付" value="three_year_price" />
            <el-option label="一次性" value="onetime_price" />
            <el-option label="流量重置包" value="reset_price" />
          </el-select>
        </el-form-item>

        <el-form-item label="订单金额 (元)" prop="total_amount">
          <el-input-number v-model="assignForm.total_amount" :precision="2" :min="0" style="width: 180px" />
        </el-form-item>
      </el-form>
      
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="assignVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleAssignSubmit">分配并激活</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();
const route = useRoute();

const loading = ref(false);
const submitLoading = ref(false);
const orders = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);

const searchQuery = ref('');
const filterStatus = ref('');
const filterCommission = ref('all');
const plans = ref([]);

const routeFilterEmail = ref('');
const routeFilterInviteId = ref('');
const routeFilterInviteEmail = ref('');

const clearRouteFilter = () => {
  routeFilterEmail.value = '';
  routeFilterInviteId.value = '';
  routeFilterInviteEmail.value = '';
  currentPage.value = 1;
  fetchOrders();
};

const assignVisible = ref(false);
const assignFormRef = ref(null);
const assignForm = reactive({
  email: '',
  plan_id: null,
  period: 'month_price',
  total_amount: 0,
});

const assignRules = {
  email: [
    { required: true, message: '请输入用户邮箱', trigger: 'blur' },
    { type: 'email', message: '邮箱格式不正确', trigger: 'blur' }
  ],
  plan_id: [{ required: true, message: '请选择订阅计划', trigger: 'change' }],
  period: [{ required: true, message: '请选择账单周期', trigger: 'change' }],
  total_amount: [{ required: true, message: '请输入订单金额', trigger: 'blur' }],
};

const formatPrice = (cents) => {
  if (!cents) return '¥0.00';
  return '¥' + (cents / 100).toFixed(2);
};

const formatTime = (time) => {
  if (!time) return '-';
  const date = new Date(time * 1000);
  return date.toLocaleString();
};

const getTypeText = (type) => {
  const map = { 1: '新购', 2: '续费', 3: '升级', 4: '重置包' };
  return map[type] || '其他';
};

const getTypeTag = (type) => {
  const map = { 1: 'success', 2: 'primary', 3: 'warning', 4: 'info' };
  return map[type] || '';
};

const getStatusText = (status) => {
  const map = { 0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已折抵' };
  return map[status] || '未知';
};

const getStatusTag = (status) => {
  const map = { 0: 'warning', 1: 'info', 2: 'danger', 3: 'success', 4: 'info' };
  return map[status] || '';
};

// Fetch plans for order assigning dropdown
const fetchPlans = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/plan/fetch`);
    if (res.data) {
      plans.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchOrders = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const filter = [];
    if (routeFilterEmail.value) {
      filter.push({ key: 'email', condition: '=', value: routeFilterEmail.value });
    } else if (routeFilterInviteId.value) {
      filter.push({ key: 'invite_user_id', condition: '=', value: routeFilterInviteId.value });
    } else if (searchQuery.value) {
      // Check if it's an email or trade number
      if (searchQuery.value.includes('@')) {
        filter.push({ key: 'email', condition: '=', value: searchQuery.value });
      } else {
        filter.push({ key: 'trade_no', condition: '=', value: searchQuery.value });
      }
    }
    if (filterStatus.value !== '') {
      filter.push({ key: 'status', condition: '=', value: filterStatus.value });
    }

    const params = {
      current: currentPage.value,
      pageSize: pageSize.value,
      filter: filter,
    };

    if (filterCommission.value === 'commission' || routeFilterInviteId.value) {
      params.is_commission = 1;
    }

    const res = await api.get(`/${securePath}/order/fetch`, {
      params: params
    });

    if (res.data) {
      orders.value = res.data;
      total.value = res.total;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleSearch = () => {
  currentPage.value = 1;
  fetchOrders();
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchOrders();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchOrders();
};

const handleMarkPaid = (row) => {
  ElMessageBox.confirm('确定要将该订单手动置为已入账吗？置为已支付后，系统将自动为该用户开通/激活订阅套餐！', '确认收款', {
    type: 'warning',
    confirmButtonText: '确认入账',
    cancelButtonText: '取消',
  }).then(async () => {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/order/paid`, { trade_no: row.trade_no });
    ElMessage.success('订单收款入账成功！订阅已自动开通。');
    fetchOrders();
  }).catch(() => {});
};

const handleCancel = (row) => {
  ElMessageBox.confirm('确定要取消该订单吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定取消',
    cancelButtonText: '取消',
  }).then(async () => {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/order/cancel`, { trade_no: row.trade_no });
    ElMessage.success('订单已取消');
    fetchOrders();
  }).catch(() => {});
};

// Assign Order dialog actions
const openAssignDialog = () => {
  assignForm.email = '';
  assignForm.plan_id = null;
  assignForm.period = 'month_price';
  assignForm.total_amount = 0;
  assignVisible.value = true;
};

const handlePlanChange = (planId) => {
  const plan = plans.value.find(p => p.id === planId);
  if (plan && plan.month_price) {
    assignForm.total_amount = plan.month_price / 100;
  }
};

const handleAssignSubmit = async () => {
  if (!assignFormRef.value) return;
  await assignFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const payload = {
        email: assignForm.email,
        plan_id: assignForm.plan_id,
        period: assignForm.period,
        total_amount: Math.round(assignForm.total_amount * 100), // convert to cents
      };

      // 1. Assign/Create the order
      const res = await api.post(`/${securePath}/order/assign`, payload);
      
      // 2. Automatically mark it paid so it activates instantly!
      if (res.data) {
        await api.post(`/${securePath}/order/paid`, { trade_no: res.data });
        ElMessage.success('分配并开通订阅成功！');
      }
      
      assignVisible.value = false;
      fetchOrders();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

const getCommissionStatusText = (status) => {
  const map = { 0: '待确认', 1: '有效 (待发放)', 2: '已发放', 3: '无效' };
  return map[status] ?? '未知';
};

const getCommissionStatusTag = (status) => {
  const map = { 0: 'warning', 1: 'primary', 2: 'success', 3: 'danger' };
  return map[status] ?? 'info';
};

const handleUpdateCommissionStatus = async (row, newStatus) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/order/update`, {
      trade_no: row.trade_no,
      commission_status: parseInt(newStatus)
    });
    ElMessage.success('更新佣金状态成功！');
    fetchOrders();
  } catch (err) {
    console.error(err);
  }
};

onMounted(() => {
  if (route.query.is_commission === '1') {
    filterCommission.value = 'commission';
  }
  
  if (route.query.email) {
    routeFilterEmail.value = route.query.email;
  }
  if (route.query.invite_user_id) {
    routeFilterInviteId.value = route.query.invite_user_id;
    filterCommission.value = 'commission';
  }
  if (route.query.invite_user_email) {
    routeFilterInviteEmail.value = route.query.invite_user_email;
  }

  fetchPlans();
  fetchOrders();
});

watch(
  () => route.query,
  (newQuery) => {
    if (newQuery.email) {
      routeFilterEmail.value = newQuery.email;
      routeFilterInviteId.value = '';
      routeFilterInviteEmail.value = '';
    } else if (newQuery.invite_user_id) {
      routeFilterInviteId.value = newQuery.invite_user_id;
      routeFilterInviteEmail.value = newQuery.invite_user_email || '';
      routeFilterEmail.value = '';
      filterCommission.value = 'commission';
    } else {
      routeFilterEmail.value = '';
      routeFilterInviteId.value = '';
      routeFilterInviteEmail.value = '';
    }
    currentPage.value = 1;
    fetchOrders();
  }
);
</script>

<style scoped>
.filter-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.table-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.amount-text {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.text-muted {
  color: var(--el-text-color-placeholder);
}

.mt-20 {
  margin-top: 20px;
}

.flex-wrap {
  flex-wrap: wrap;
}

.gap-10 {
  gap: 10px;
}

.filter-indicator-card {
  border-radius: 16px;
  border: 1px solid var(--el-color-primary-light-8);
  background-color: var(--el-color-primary-light-9);
  padding: 0px 10px;
}
</style>
