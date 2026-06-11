<template>
  <div class="groups-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center">
        <span class="action-text">权限组管理</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">添加权限组</el-button>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="groups" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" :width="isMobile ? '45' : '100'" align="center" />
        <el-table-column prop="name" label="权限组名称" :min-width="isMobile ? '100' : '200'" />
        <el-table-column prop="user_count" label="用户数量" :width="isMobile ? '70' : '150'" align="center">
          <template #default="scope">
            <span v-if="isMobile">👤 {{ scope.row.user_count || 0 }}</span>
            <el-tag v-else type="info" size="small">{{ scope.row.user_count || 0 }} 人</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="server_count" label="节点数量" :width="isMobile ? '70' : '150'" align="center">
          <template #default="scope">
            <span v-if="isMobile">📄 {{ scope.row.server_count || 0 }}</span>
            <el-tag v-else type="success" size="small">{{ scope.row.server_count || 0 }} 个</el-tag>
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
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '450px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '100px'">
        <el-form-item label="权限组名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入权限组名称" />
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
const dialogTitle = ref('添加权限组');
const isEdit = ref(false);

const groups = ref([]);
const formRef = ref(null);
const form = reactive({
  id: null,
  name: ''
});

const rules = {
  name: [{ required: true, message: '请输入权限组名称', trigger: 'blur' }]
};

const fetchGroups = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/group/fetch`);
    if (res.data) {
      groups.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const openCreateDialog = () => {
  isEdit.value = false;
  dialogTitle.value = '添加权限组';
  form.id = null;
  form.name = '';
  dialogVisible.value = true;
};

const openEditDialog = (row) => {
  isEdit.value = true;
  dialogTitle.value = '编辑权限组';
  form.id = row.id;
  form.name = row.name;
  dialogVisible.value = true;
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/server/group/save`, form);
      ElMessage.success(isEdit.value ? '编辑权限组成功' : '添加权限组成功');
      dialogVisible.value = false;
      fetchGroups();
    } catch (err) {
      ElMessage.error(err.message || '保存失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定删除该权限组吗？删除前请确保该组内没有任何关联的用户、订阅及节点！', '警告', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/server/group/drop`, { id: row.id });
      ElMessage.success('删除成功');
      fetchGroups();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
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
