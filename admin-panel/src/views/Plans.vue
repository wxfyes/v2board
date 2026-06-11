<template>
  <div class="plans-container">
    <!-- Header Action -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between">
        <span class="action-text">订阅计划列表</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">创建订阅计划</el-button>
      </div>
    </el-card>

    <!-- Plans List / Table -->
    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="plans" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="sort" label="排序" width="55" align="center">
          <template #default>
            <el-icon style="cursor: move; color: #909399;"><Grid /></el-icon>
          </template>
        </el-table-column>
        
        <el-table-column prop="show" label="销售状态" width="80" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.show"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleStatus(scope.row, 'show', val)"
            />
          </template>
        </el-table-column>

        <el-table-column prop="renew" label="续费" width="80" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.renew"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleStatus(scope.row, 'renew', val)"
            />
          </template>
        </el-table-column>

        <el-table-column prop="name" label="名称" min-width="120" />

        <el-table-column prop="count" label="统计" width="75" align="center">
          <template #default="scope">
            <span class="flex-align justify-center" style="gap: 4px">
              <el-icon><User /></el-icon>
              <span>{{ scope.row.count }}</span>
            </span>
          </template>
        </el-table-column>

        <el-table-column prop="transfer_enable" label="流量" width="95">
          <template #default="scope">
            <span>{{ scope.row.transfer_enable }} GB</span>
          </template>
        </el-table-column>

        <el-table-column prop="device_limit" label="设备数限制" width="90" align="center">
          <template #default="scope">
            <span>{{ scope.row.device_limit || '-' }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="month_price" label="月付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.month_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="quarter_price" label="季付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.quarter_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="half_year_price" label="半年付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.half_year_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="year_price" label="年付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.year_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="two_year_price" label="两年付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.two_year_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="three_year_price" label="三年付" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.three_year_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="onetime_price" label="一次性" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.onetime_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="reset_price" label="重置包" width="80" align="right">
          <template #default="scope">
            <span>{{ formatPriceSimple(scope.row.reset_price) }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="group_id" label="权限组" width="110" align="center">
          <template #default="scope">
            <el-tag size="small" type="success">{{ getGroupName(scope.row.group_id) }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="100" align="center" fixed="right">
          <template #default="scope">
            <el-dropdown trigger="click">
              <el-button type="primary" link>
                操作<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item @click="openEditDialog(scope.row)">编辑</el-dropdown-item>
                  <el-dropdown-item @click="handleDelete(scope.row)" style="color: var(--el-color-danger)">删除</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Plan Dialog (Create/Edit) -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑订阅计划' : '创建订阅计划'" :width="isMobile ? '95%' : '700px'" :top="isMobile ? '2vh' : '6vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '120px'">
        <el-tabs v-model="activeTab">
          <el-tab-pane label="基本设置" name="basic">
            <el-form-item label="计划名称" prop="name">
              <el-input v-model="form.name" placeholder="请输入订阅名称" />
            </el-form-item>
            
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="流量额度 (GB)" prop="transfer_enable">
                  <el-input-number v-model="form.transfer_enable" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="权限组" prop="group_id">
                  <div class="flex-align" style="gap: 10px; width: 100%">
                    <el-select v-model="form.group_id" style="flex: 1" placeholder="请选择权限组">
                      <el-option label="默认权限组 (0)" :value="0" />
                      <el-option 
                        v-for="g in groups" 
                        :key="g.id" 
                        :label="g.name" 
                        :value="g.id" 
                      />
                    </el-select>
                    <el-button type="primary" link @click="$router.push('/groups')">添加权限组</el-button>
                  </div>
                  <div class="form-tip">只有该权限组的用户能连接对应组的节点</div>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="流量重置方式">
                  <el-select v-model="form.reset_traffic_method" style="width: 100%">
                    <el-option label="跟随系统设置" :value="0" />
                    <el-option label="每月1号重置" :value="1" />
                    <el-option label="按注册日周期重置" :value="2" />
                    <el-option label="不重置" :value="3" />
                    <el-option label="每年1号重置" :value="4" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="最大容纳用户">
                  <el-input-number v-model="form.capacity_limit" :min="0" placeholder="留空或0不限制" style="width: 100%" />
                  <div class="form-tip">限制购买此套餐的最大用户数量，0为不限制</div>
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item label="计划描述" prop="content">
              <el-input 
                v-model="form.content" 
                type="textarea" 
                :rows="3" 
                placeholder="一行一个特性，用于在用户前台展示购买卡片" 
              />
            </el-form-item>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="销售状态">
                  <el-radio-group v-model="form.show">
                    <el-radio :label="1">上架销售</el-radio>
                    <el-radio :label="0">下架隐藏</el-radio>
                  </el-radio-group>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="允许续费">
                  <el-radio-group v-model="form.renew">
                    <el-radio :label="1">允许</el-radio>
                    <el-radio :label="0">禁止</el-radio>
                  </el-radio-group>
                </el-form-item>
              </el-col>
            </el-row>
          </el-tab-pane>

          <el-tab-pane label="定价体系 (元)" name="pricing">
            <div class="pricing-tip">留空或0表示不提供该周期的购买方式</div>
            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="月付">
                  <el-input-number v-model="form.month_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="季付">
                  <el-input-number v-model="form.quarter_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="半年付">
                  <el-input-number v-model="form.half_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="年付">
                  <el-input-number v-model="form.year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="两年付">
                  <el-input-number v-model="form.two_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="三年付">
                  <el-input-number v-model="form.three_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :xs="24" :sm="12">
                <el-form-item label="一次性">
                  <el-input-number v-model="form.onetime_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="重置流量费">
                  <el-input-number v-model="form.reset_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-tab-pane>

          <el-tab-pane label="高级设置" name="advanced">
            <el-form-item label="设备数限制">
              <el-input-number v-model="form.device_limit" :min="0" style="width: 180px" />
              <div class="form-tip">限制该计划下用户的最大同时在线设备数，0表示不限制</div>
            </el-form-item>
            
            <el-form-item label="限速 (Mbps)">
              <el-input-number v-model="form.speed_limit" :min="0" style="width: 180px" />
              <div class="form-tip">限制节点端连接的最高带宽，0表示不限制</div>
            </el-form-item>

            <el-form-item label="强制同步设置" v-if="isEdit">
              <el-checkbox v-model="form.force_update">同步至所有购买此订阅的用户</el-checkbox>
              <div class="form-tip text-danger">警告：开启此项后，保存时会将该订阅下的所有用户的节点组、流量额度、限速、设备限制重置为此计划配置！</div>
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
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
const plans = ref([]);
const groups = ref([]);

const dialogVisible = ref(false);
const isEdit = ref(false);
const activeTab = ref('basic');
const formRef = ref(null);

const form = reactive({
  id: null,
  name: '',
  transfer_enable: 100,
  group_id: 0,
  reset_traffic_method: 0,
  capacity_limit: null,
  content: '',
  show: 1,
  renew: 1,
  month_price: 0,
  quarter_price: 0,
  half_year_price: 0,
  year_price: 0,
  two_year_price: 0,
  three_year_price: 0,
  onetime_price: 0,
  reset_price: 0,
  device_limit: 0,
  speed_limit: 0,
  force_update: false,
});

const rules = {
  name: [{ required: true, message: '请输入计划名称', trigger: 'blur' }],
  transfer_enable: [{ required: true, message: '请输入流量额度', trigger: 'blur' }],
  group_id: [{ required: true, message: '请选择权限组', trigger: 'change' }],
};

const formatPriceSimple = (price) => {
  if (price === null || price === undefined || price === 0) return '-';
  return (price / 100).toFixed(2);
};

const fetchPlans = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/plan/fetch`);
    if (res.data) {
      plans.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const fetchGroups = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/group/fetch`);
    if (res.data) {
      groups.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const getGroupName = (groupId) => {
  const group = groups.value.find(g => g.id === groupId);
  return group ? group.name : `组 ${groupId}`;
};

const handleToggleStatus = async (row, field, val) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/plan/update`, {
      id: row.id,
      [field]: val
    });
    ElMessage.success('更新成功');
  } catch (err) {
    console.error(err);
    row[field] = val === 1 ? 0 : 1; // Revert status on failure
  }
};

const openCreateDialog = () => {
  isEdit.value = false;
  activeTab.value = 'basic';
  
  // Reset form fields
  form.id = null;
  form.name = '';
  form.transfer_enable = 100;
  form.group_id = 0;
  form.reset_traffic_method = 0;
  form.capacity_limit = null;
  form.content = '';
  form.show = 1;
  form.renew = 1;
  form.month_price = 0;
  form.quarter_price = 0;
  form.half_year_price = 0;
  form.year_price = 0;
  form.two_year_price = 0;
  form.three_year_price = 0;
  form.onetime_price = 0;
  form.reset_price = 0;
  form.device_limit = 0;
  form.speed_limit = 0;
  form.force_update = false;
  
  dialogVisible.value = true;
};

const openEditDialog = (row) => {
  isEdit.value = true;
  activeTab.value = 'basic';
  
  // Map values
  form.id = row.id;
  form.name = row.name;
  form.transfer_enable = row.transfer_enable;
  form.group_id = row.group_id;
  form.reset_traffic_method = row.reset_traffic_method !== undefined && row.reset_traffic_method !== null ? Number(row.reset_traffic_method) : 0;
  form.capacity_limit = row.capacity_limit || null;
  form.content = row.content || '';
  form.show = row.show;
  form.renew = row.renew;
  form.month_price = row.month_price ? row.month_price / 100 : 0;
  form.quarter_price = row.quarter_price ? row.quarter_price / 100 : 0;
  form.half_year_price = row.half_year_price ? row.half_year_price / 100 : 0;
  form.year_price = row.year_price ? row.year_price / 100 : 0;
  form.two_year_price = row.two_year_price ? row.two_year_price / 100 : 0;
  form.three_year_price = row.three_year_price ? row.three_year_price / 100 : 0;
  form.onetime_price = row.onetime_price ? row.onetime_price / 100 : 0;
  form.reset_price = row.reset_price ? row.reset_price / 100 : 0;
  form.device_limit = row.device_limit || 0;
  form.speed_limit = row.speed_limit || 0;
  form.force_update = false;

  dialogVisible.value = true;
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      
      const payload = {
        name: form.name,
        transfer_enable: form.transfer_enable,
        group_id: form.group_id,
        reset_traffic_method: form.reset_traffic_method,
        capacity_limit: form.capacity_limit || null,
        content: form.content,
        show: form.show,
        renew: form.renew,
        month_price: form.month_price ? Math.round(form.month_price * 100) : null,
        quarter_price: form.quarter_price ? Math.round(form.quarter_price * 100) : null,
        half_year_price: form.half_year_price ? Math.round(form.half_year_price * 100) : null,
        year_price: form.year_price ? Math.round(form.year_price * 100) : null,
        two_year_price: form.two_year_price ? Math.round(form.two_year_price * 100) : null,
        three_year_price: form.three_year_price ? Math.round(form.three_year_price * 100) : null,
        onetime_price: form.onetime_price ? Math.round(form.onetime_price * 100) : null,
        reset_price: form.reset_price ? Math.round(form.reset_price * 100) : null,
        device_limit: form.device_limit || null,
        speed_limit: form.speed_limit || null,
      };

      if (isEdit.value) {
        payload.id = form.id;
        payload.force_update = form.force_update ? 1 : 0;
      }
      
      await api.post(`/${securePath}/plan/save`, payload);
      ElMessage.success(isEdit.value ? '保存修改成功' : '创建计划成功');
      dialogVisible.value = false;
      fetchPlans();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定要删除该订阅计划吗？如果有用户或订单绑定了此计划，删除将会失败！', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/plan/drop`, { id: row.id });
    ElMessage.success('删除成功');
    fetchPlans();
  }).catch(() => {});
};

onMounted(() => {
  fetchPlans();
  fetchGroups();
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
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  line-height: 1.4;
  margin-top: 5px;
  width: 100%;
}

.pricing-tip {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  background-color: var(--el-fill-color-light);
  padding: 10px 15px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.text-danger {
  color: var(--el-color-danger);
}

.mt-20 {
  margin-top: 20px;
}

.flex-align {
  display: flex;
  align-items: center;
}
</style>
