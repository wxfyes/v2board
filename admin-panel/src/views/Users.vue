<template>
  <div class="users-container">
    <!-- Action Bar & Filter -->
    <el-card class="filter-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <div class="filter-left flex-center flex-wrap gap-10">
          <el-input
            v-model="searchQuery"
            placeholder="搜索邮箱..."
            prefix-icon="Search"
            clearable
            style="width: 240px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          />
          
          <el-select v-model="filterPlan" placeholder="订阅计划" clearable style="width: 160px" @change="handleSearch">
            <el-option label="所有计划" value="" />
            <el-option label="无订阅" value="null" />
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>

          <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="所有状态" value="" />
            <el-option label="正常" value="0" />
            <el-option label="已封禁" value="1" />
          </el-select>

          <el-button type="primary" @click="handleSearch">筛选</el-button>
        </div>

        <div class="filter-right flex-center gap-10">
          <el-button type="success" icon="Plus" @click="openCreateDialog">添加用户</el-button>
        </div>
      </div>
    </el-card>

    <!-- User Table -->
    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="users" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="email" label="用户邮箱" min-width="180" show-overflow-tooltip>
          <template #default="scope">
            <div class="email-cell flex-center" style="justify-content: flex-start; gap: 6px;">
              <span>{{ scope.row.email }}</span>
              <el-tooltip :content="`在线设备数: ${scope.row.alive_ip || 0} (${scope.row.ips || '无IP记录'})`" placement="top">
                <el-badge v-if="scope.row.alive_ip > 0" :value="scope.row.alive_ip" type="success" class="online-badge" />
              </el-tooltip>
            </div>
          </template>
        </el-table-column>
        
        <el-table-column prop="plan_name" label="订阅计划" width="130">
          <template #default="scope">
            <el-tag :type="scope.row.plan_name ? 'primary' : 'info'" size="small">
              {{ scope.row.plan_name || '无订阅' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="流量使用 (已用 / 总额)" min-width="200">
          <template #default="scope">
            <div class="traffic-progress">
              <div class="flex-between traffic-text">
                <span>{{ formatTraffic(scope.row.u + scope.row.d) }}</span>
                <span>{{ formatTraffic(scope.row.transfer_enable) }}</span>
              </div>
              <el-progress 
                :percentage="calculatePercentage(scope.row.u + scope.row.d, scope.row.transfer_enable)" 
                :status="getProgressStatus(scope.row.u + scope.row.d, scope.row.transfer_enable)"
                :show-text="false"
              />
            </div>
          </template>
        </el-table-column>

        <el-table-column prop="expired_at" label="到期时间" width="160">
          <template #default="scope">
            <span :class="{ 'text-danger': isExpired(scope.row.expired_at) }">
              {{ formatTime(scope.row.expired_at) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column prop="banned" label="状态" width="90" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.banned ? 'danger' : 'success'" size="small">
              {{ scope.row.banned ? '已封禁' : '正常' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="200" align="right" fixed="right">
          <template #default="scope">
            <el-button type="primary" link @click="openEditDialog(scope.row)">编辑</el-button>
            <el-dropdown trigger="click" @command="(cmd) => handleMoreCommand(cmd, scope.row)">
              <el-button type="primary" link style="margin-left: 12px">
                更多<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="reset">重置重置订阅密钥</el-dropdown-item>
                  <el-dropdown-item command="copy">复制订阅链接</el-dropdown-item>
                  <el-dropdown-item command="delete" divided style="color: var(--el-color-danger)">删除用户</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

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

    <!-- Create User Dialog -->
    <el-dialog v-model="createVisible" title="创建用户" width="500px">
      <el-form :model="createForm" :rules="createRules" ref="createFormRef" label-width="90px">
        <el-form-item label="邮箱前缀" prop="email_prefix">
          <el-input v-model="createForm.email_prefix" placeholder="例如 user" />
        </el-form-item>
        <el-form-item label="邮箱后缀" prop="email_suffix">
          <el-input v-model="createForm.email_suffix" placeholder="例如 qq.com" />
        </el-form-item>
        <el-form-item label="登录密码" prop="password">
          <el-input v-model="createForm.password" placeholder="留空默认和邮箱相同" show-password />
        </el-form-item>
        <el-form-item label="订阅计划" prop="plan_id">
          <el-select v-model="createForm.plan_id" placeholder="选择计划" style="width: 100%">
            <el-option label="不绑定订阅" :value="null" />
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="createForm.expired_at"
            type="datetime"
            placeholder="选择过期时间，留空长期有效"
            style="width: 100%"
            value-format="X"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleCreateUser">确定</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Edit User Dialog -->
    <el-dialog v-model="editVisible" title="编辑用户" width="550px">
      <el-form :model="editForm" ref="editFormRef" label-width="100px" v-if="editForm.id">
        <el-tabs v-model="activeTab">
          <!-- Basic Profile Info -->
          <el-tab-pane label="基本资料" name="profile">
            <el-form-item label="用户邮箱" required>
              <el-input v-model="editForm.email" />
            </el-form-item>
            <el-form-item label="登录密码">
              <el-input v-model="editForm.password" placeholder="留空表示不修改" show-password />
            </el-form-item>
            <el-form-item label="余额 (元)">
              <el-input-number v-model="editForm.balance" :precision="2" :step="10" style="width: 150px" />
            </el-form-item>
            <el-form-item label="佣金 (元)">
              <el-input-number v-model="editForm.commission_balance" :precision="2" :step="10" style="width: 150px" />
            </el-form-item>
          </el-tab-pane>

          <!-- Traffic and Plan config -->
          <el-tab-pane label="订阅 & 流量" name="subscription">
            <el-form-item label="订阅计划">
              <el-select v-model="editForm.plan_id" placeholder="选择计划" style="width: 100%">
                <el-option label="无订阅" :value="null" />
                <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="总流量 (GB)">
              <el-input-number v-model="editForm.transfer_enable_gb" :min="0" :step="10" style="width: 150px" />
            </el-form-item>
            <el-form-item label="已用上行 (GB)">
              <el-input-number v-model="editForm.u_gb" :min="0" :precision="2" style="width: 150px" />
            </el-form-item>
            <el-form-item label="已用下行 (GB)">
              <el-input-number v-model="editForm.d_gb" :min="0" :precision="2" style="width: 150px" />
            </el-form-item>
            <el-form-item label="到期时间">
              <el-date-picker
                v-model="editForm.expired_at"
                type="datetime"
                placeholder="选择过期时间，留空长期有效"
                style="width: 100%"
                value-format="X"
              />
            </el-form-item>
          </el-tab-pane>

          <!-- Settings config -->
          <el-tab-pane label="其它设置" name="settings">
            <el-form-item label="设备数限制">
              <el-input-number v-model="editForm.device_limit" :min="0" :step="1" placeholder="留空或0不限制" style="width: 150px" />
            </el-form-item>
            <el-form-item label="端口速度限制 (Mbps)">
              <el-input-number v-model="editForm.speed_limit" :min="0" :step="10" placeholder="留空不限制" style="width: 150px" />
            </el-form-item>
            <el-form-item label="账号状态">
              <el-radio-group v-model="editForm.banned">
                <el-radio :label="0">正常</el-radio>
                <el-radio :label="1">封禁</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="editVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleUpdateUser">保存</el-button>
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

const loading = ref(false);
const submitLoading = ref(false);
const users = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);

const searchQuery = ref('');
const filterPlan = ref('');
const filterStatus = ref('');
const plans = ref([]);

const createVisible = ref(false);
const createFormRef = ref(null);
const createForm = reactive({
  email_prefix: '',
  email_suffix: 'qq.com',
  password: '',
  plan_id: null,
  expired_at: null,
});

const createRules = {
  email_prefix: [{ required: true, message: '请输入邮箱前缀', trigger: 'blur' }],
  email_suffix: [{ required: true, message: '请输入邮箱后缀', trigger: 'blur' }],
};

const editVisible = ref(false);
const editFormRef = ref(null);
const activeTab = ref('profile');
const editForm = reactive({
  id: null,
  email: '',
  password: '',
  balance: 0,
  commission_balance: 0,
  plan_id: null,
  transfer_enable_gb: 0,
  u_gb: 0,
  d_gb: 0,
  expired_at: null,
  device_limit: 0,
  speed_limit: 0,
  banned: 0,
});

// Helper formatting functions
const formatTraffic = (bytes) => {
  if (bytes === null || bytes === undefined) return '不限';
  if (bytes === 0) return '0 GB';
  const g = 1073741824; // 1024^3
  if (bytes >= 1099511627776) { // 1TB
    return (bytes / 1099511627776).toFixed(2) + ' TB';
  }
  return (bytes / g).toFixed(2) + ' GB';
};

const calculatePercentage = (used, total) => {
  if (!total) return 0;
  const pct = (used / total) * 100;
  return Math.min(parseFloat(pct.toFixed(1)), 100);
};

const getProgressStatus = (used, total) => {
  if (!total) return 'success';
  const pct = used / total;
  if (pct >= 0.9) return 'exception';
  if (pct >= 0.75) return 'warning';
  return 'success';
};

const formatTime = (timestamp) => {
  if (!timestamp) return '长期有效';
  const date = new Date(timestamp * 1000);
  return date.toLocaleString();
};

const isExpired = (timestamp) => {
  if (!timestamp) return false;
  return timestamp < Date.now() / 1000;
};

// Fetch plans for dropdowns
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

// Fetch user data with search filters
const fetchUsers = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    
    // Construct filters
    const filter = [];
    if (searchQuery.value) {
      filter.push({ key: 'email', condition: '模糊', value: searchQuery.value });
    }
    if (filterPlan.value) {
      filter.push({ key: 'plan_id', condition: '=', value: filterPlan.value });
    }
    if (filterStatus.value !== '') {
      filter.push({ key: 'banned', condition: '=', value: filterStatus.value });
    }

    const res = await api.get(`/${securePath}/user/fetch`, {
      params: {
        current: currentPage.value,
        pageSize: pageSize.value,
        filter: filter,
      }
    });

    if (res.data) {
      users.value = res.data;
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
  fetchUsers();
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchUsers();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchUsers();
};

// Create User dialog actions
const openCreateDialog = () => {
  createForm.email_prefix = '';
  createForm.password = '';
  createForm.plan_id = null;
  createForm.expired_at = null;
  createVisible.value = true;
};

const handleCreateUser = async () => {
  if (!createFormRef.value) return;
  await createFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const payload = {
        email_prefix: createForm.email_prefix,
        email_suffix: createForm.email_suffix,
        plan_id: createForm.plan_id,
        expired_at: createForm.expired_at,
      };
      if (createForm.password) {
        payload.password = createForm.password;
      }
      
      await api.post(`/${securePath}/user/generate`, payload);
      ElMessage.success('创建用户成功');
      createVisible.value = false;
      fetchUsers();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

// Edit user actions
const openEditDialog = (row) => {
  activeTab.value = 'profile';
  
  // Map row fields into the form variables
  editForm.id = row.id;
  editForm.email = row.email;
  editForm.password = '';
  editForm.balance = row.balance / 100;
  editForm.commission_balance = row.commission_balance / 100;
  editForm.plan_id = row.plan_id;
  editForm.transfer_enable_gb = row.transfer_enable / 1073741824;
  editForm.u_gb = row.u / 1073741824;
  editForm.d_gb = row.d / 1073741824;
  editForm.expired_at = row.expired_at ? String(row.expired_at) : null;
  editForm.device_limit = row.device_limit || 0;
  editForm.speed_limit = row.speed_limit || 0;
  editForm.banned = row.banned;
  
  editVisible.value = true;
};

const handleUpdateUser = async () => {
  submitLoading.value = true;
  try {
    const securePath = getSecurePath();
    const payload = {
      id: editForm.id,
      email: editForm.email,
      balance: Math.round(editForm.balance * 100),
      commission_balance: Math.round(editForm.commission_balance * 100),
      plan_id: editForm.plan_id,
      transfer_enable: editForm.transfer_enable_gb * 1073741824,
      u: editForm.u_gb * 1073741824,
      d: editForm.d_gb * 1073741824,
      expired_at: editForm.expired_at ? parseInt(editForm.expired_at) : null,
      device_limit: editForm.device_limit || null,
      speed_limit: editForm.speed_limit || null,
      banned: editForm.banned,
    };
    
    if (editForm.password) {
      payload.password = editForm.password;
    }
    
    await api.post(`/${securePath}/user/update`, payload);
    ElMessage.success('保存用户信息成功');
    editVisible.value = false;
    fetchUsers();
  } catch (err) {
    console.error(err);
  } finally {
    submitLoading.value = false;
  }
};

// Dropdown commands
const handleMoreCommand = async (command, row) => {
  const securePath = getSecurePath();
  
  if (command === 'reset') {
    ElMessageBox.confirm('确定要重置该用户的重置订阅密钥和连接 Token 吗？重置后，该用户现有的客户端配置将失效，需要重新导入！', '提示', {
      type: 'warning',
      confirmButtonText: '确定重置',
      cancelButtonText: '取消'
    }).then(async () => {
      await api.post(`/${securePath}/user/resetSecret`, { id: row.id });
      ElMessage.success('密钥重置成功，请让用户重新导入订阅');
      fetchUsers();
    }).catch(() => {});
    
  } else if (command === 'copy') {
    if (row.subscribe_url) {
      navigator.clipboard.writeText(row.subscribe_url);
      ElMessage.success('订阅链接已复制到剪贴板');
    }
    
  } else if (command === 'delete') {
    ElMessageBox.confirm('确定要永久删除该用户吗？删除后该用户的所有订单、工单、推广关联等数据都将被清理！此操作无法撤销！', '高危操作', {
      type: 'error',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    }).then(async () => {
      await api.post(`/${securePath}/user/delUser`, { id: row.id });
      ElMessage.success('删除用户成功');
      fetchUsers();
    }).catch(() => {});
  }
};

onMounted(() => {
  fetchPlans();
  fetchUsers();
});
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

.traffic-progress {
  max-width: 250px;
}

.traffic-text {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  margin-bottom: 4px;
}

.text-danger {
  color: var(--el-color-danger);
  font-weight: 500;
}

.online-badge {
  transform: translateY(-2px);
}

.pagination-info {
  font-size: 13px;
  color: var(--el-text-color-secondary);
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
</style>
