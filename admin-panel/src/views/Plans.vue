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
        <el-table-column prop="sort" label="排序" width="60" align="center" />
        <el-table-column prop="name" label="计划名称" min-width="150" />
        
        <el-table-column prop="transfer_enable" label="流量额度" width="120">
          <template #default="scope">
            <el-tag type="info" effect="plain">{{ scope.row.transfer_enable }} GB</el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="month_price" label="月付价格" width="110" align="right">
          <template #default="scope">
            {{ formatPrice(scope.row.month_price) }}
          </template>
        </el-table-column>

        <el-table-column prop="year_price" label="年付价格" width="110" align="right">
          <template #default="scope">
            {{ formatPrice(scope.row.year_price) }}
          </template>
        </el-table-column>

        <el-table-column prop="onetime_price" label="一次性价格" width="110" align="right">
          <template #default="scope">
            {{ formatPrice(scope.row.onetime_price) }}
          </template>
        </el-table-column>

        <el-table-column prop="count" label="活动用户数" width="110" align="center">
          <template #default="scope">
            <el-tag type="success" size="small">{{ scope.row.count }} 人</el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="show" label="销售状态" width="100" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.show"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleStatus(scope.row, 'show', val)"
            />
          </template>
        </el-table-column>

        <el-table-column prop="renew" label="允许续费" width="100" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.renew"
              :active-value="1"
              :inactive-value="0"
              @change="(val) => handleToggleStatus(scope.row, 'renew', val)"
            />
          </template>
        </el-table-column>

        <el-table-column label="操作" width="180" align="right">
          <template #default="scope">
            <el-button type="primary" link @click="openEditDialog(scope.row)">编辑</el-button>
            <el-button type="danger" link @click="handleDelete(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Plan Dialog (Create/Edit) -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑订阅计划' : '创建订阅计划'" width="700px">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="120px">
        <el-tabs v-model="activeTab">
          <el-tab-pane label="基本设置" name="basic">
            <el-form-item label="计划名称" prop="name">
              <el-input v-model="form.name" placeholder="请输入订阅名称" />
            </el-form-item>
            
            <el-form-item label="流量额度 (GB)" prop="transfer_enable">
              <el-input-number v-model="form.transfer_enable" :min="1" style="width: 180px" />
            </el-form-item>

            <el-form-item label="分配节点组" prop="group_id">
              <el-input-number v-model="form.group_id" :min="0" style="width: 180px" />
              <div class="form-tip">只有该节点组的用户能连接对应组的节点</div>
            </el-form-item>

            <el-form-item label="计划描述" prop="content">
              <el-input 
                v-model="form.content" 
                type="textarea" 
                :rows="4" 
                placeholder="一行一个特性，用于在用户前台展示购买卡片" 
              />
            </el-form-item>

            <el-row>
              <el-col :span="12">
                <el-form-item label="销售状态">
                  <el-radio-group v-model="form.show">
                    <el-radio :label="1">上架销售</el-radio>
                    <el-radio :label="0">下架隐藏</el-radio>
                  </el-radio-group>
                </el-form-item>
              </el-col>
              <el-col :span="12">
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
              <el-col :span="12">
                <el-form-item label="月付">
                  <el-input-number v-model="form.month_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="季付">
                  <el-input-number v-model="form.quarter_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="半年付">
                  <el-input-number v-model="form.half_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="年付">
                  <el-input-number v-model="form.year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="两年付">
                  <el-input-number v-model="form.two_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="三年付">
                  <el-input-number v-model="form.three_year_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="一次性">
                  <el-input-number v-model="form.onetime_price" :precision="2" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
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

const loading = ref(false);
const submitLoading = ref(false);
const plans = ref([]);

const dialogVisible = ref(false);
const isEdit = ref(false);
const activeTab = ref('basic');
const formRef = ref(null);

const form = reactive({
  id: null,
  name: '',
  transfer_enable: 100,
  group_id: 0,
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
  group_id: [{ required: true, message: '请输入节点组ID', trigger: 'blur' }],
};

const formatPrice = (price) => {
  if (price === null || price === undefined || price === 0) return '未提供';
  return '¥' + (price / 100).toFixed(2);
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
</style>
