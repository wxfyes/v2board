<template>
  <div class="routes-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center">
        <span class="action-text">路由管理</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">添加路由</el-button>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="routes" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" :width="isMobile ? '40' : '80'" align="center" />
        <el-table-column v-if="!isMobile" prop="remarks" label="备注说明" min-width="150" />
        <el-table-column v-if="!isMobile" prop="action" label="动作" width="150" align="center">
          <template #default="scope">
            <el-tag :type="getActionTagType(scope.row.action)" size="small" effect="dark">
              {{ actionMap[scope.row.action] || scope.row.action }}
            </el-tag>
          </template>
        </el-table-column>
        
        <!-- Mobile Combined Column -->
        <el-table-column v-if="isMobile" label="路由规则" min-width="120">
          <template #default="scope">
            <div style="font-weight: 600; line-height: 1.2;">{{ scope.row.remarks }}</div>
            <div style="font-size: 10px; margin-top: 2px;">
              <span :style="{color: scope.row.action.startswith('block') ? 'var(--el-color-danger)' : 'var(--el-color-primary)'}">[{{ actionMap[scope.row.action] ? actionMap[scope.row.action].split(' ')[0] : scope.row.action }}]</span>
              <span style="opacity: 0.8; margin-left: 4px;">{{ scope.row.action_value }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" prop="action_value" label="动作值" min-width="120" show-overflow-tooltip />
        <el-table-column prop="match" label="规则数" :width="isMobile ? '65' : '120'" align="center">
          <template #default="scope">
            <el-tooltip placement="top" :disabled="!scope.row.match || scope.row.match.length === 0">
              <template #content>
                <div v-for="(rule, idx) in scope.row.match" :key="idx">{{ rule }}</div>
              </template>
              <span v-if="isMobile">{{ (scope.row.match && scope.row.match.length) || 0 }} 条</span>
              <el-tag v-else type="info" size="small">{{ (scope.row.match && scope.row.match.length) || 0 }} 条规则</el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="操作" :width="isMobile ? '80' : '180'" :align="isMobile ? 'center' : 'right'">
          <template #default="scope">
            <el-button type="primary" link @click="openEditDialog(scope.row)" :style="isMobile ? 'margin-right: 2px; padding: 0;' : ''">编辑</el-button>
            <span v-if="isMobile" style="color: var(--el-border-color); font-size: 10px;">|</span>
            <el-button type="danger" link @click="handleDelete(scope.row)" :style="isMobile ? 'margin-left: 2px; padding: 0;' : ''">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '550px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '110px'">
        <el-form-item label="备注说明" prop="remarks">
          <el-input v-model="form.remarks" placeholder="请输入备注，用于标识该路由" />
        </el-form-item>
        <el-form-item label="动作类型" prop="action">
          <el-select v-model="form.action" style="width: 100%">
            <el-option v-for="(label, key) in actionMap" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="动作值" prop="action_value" v-if="form.action !== 'default_out'">
          <el-input v-model="form.action_value" placeholder="可选输入动作对应的值，如 proxy/direct" />
        </el-form-item>
        <el-form-item label="匹配规则" prop="match_str" v-if="form.action !== 'default_out'">
          <el-input 
            type="textarea" 
            :rows="6" 
            v-model="form.match_str" 
            placeholder="请输入匹配规则，每行一条。如：
domain:google.com
geosite:cn
geoip:private" 
          />
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
const dialogTitle = ref('添加路由');
const isEdit = ref(false);

const routes = ref([]);
const formRef = ref(null);
const form = reactive({
  id: null,
  remarks: '',
  action: 'block',
  action_value: '',
  match_str: ''
});

const actionMap = {
  block: '阻断域名 (block)',
  block_ip: '阻断IP (block_ip)',
  block_port: '阻断端口 (block_port)',
  protocol: '协议分流 (protocol)',
  dns: 'DNS服务器分流 (dns)',
  route: '域名分流路由 (route)',
  route_ip: 'IP分流路由 (route_ip)',
  default_out: '默认出口 (default_out)'
};

const getActionTagType = (action) => {
  if (action.startsWith('block')) return 'danger';
  if (action === 'default_out') return 'success';
  return 'primary';
};

const rules = {
  remarks: [{ required: true, message: '请输入备注说明', trigger: 'blur' }],
  action: [{ required: true, message: '请选择动作类型', trigger: 'change' }]
};

const fetchRoutes = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/route/fetch`);
    if (res.data) {
      routes.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const openCreateDialog = () => {
  isEdit.value = false;
  dialogTitle.value = '添加路由';
  form.id = null;
  form.remarks = '';
  form.action = 'block';
  form.action_value = '';
  form.match_str = '';
  dialogVisible.value = true;
};

const openEditDialog = (row) => {
  isEdit.value = true;
  dialogTitle.value = '编辑路由';
  form.id = row.id;
  form.remarks = row.remarks;
  form.action = row.action;
  form.action_value = row.action_value || '';
  form.match_str = Array.isArray(row.match) ? row.match.join('\n') : '';
  dialogVisible.value = true;
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const match = form.action === 'default_out' 
        ? [] 
        : form.match_str.split('\n').map(x => x.trim()).filter(x => x !== '');

      const payload = {
        remarks: form.remarks,
        action: form.action,
        action_value: form.action_value || null,
        match
      };
      if (isEdit.value) {
        payload.id = form.id;
      }

      await api.post(`/${securePath}/server/route/save`, payload);
      ElMessage.success(isEdit.value ? '编辑路由成功' : '添加路由成功');
      dialogVisible.value = false;
      fetchRoutes();
    } catch (err) {
      ElMessage.error(err.message || '保存失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定删除该路由规则吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/server/route/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchRoutes();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchRoutes();
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
.mt-20 {
  margin-top: 20px;
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
