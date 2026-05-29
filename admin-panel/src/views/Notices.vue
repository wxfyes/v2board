<template>
  <div class="notices-container">
    <!-- Action Bar -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between">
        <span class="action-text">系统公告管理</span>
        <el-button type="primary" icon="Plus" @click="openCreateDialog">发布新公告</el-button>
      </div>
    </el-card>

    <!-- Notices Table -->
    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="notices" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="title" label="公告标题" min-width="200" show-overflow-tooltip />
        
        <el-table-column prop="created_at" label="发布时间" width="180">
          <template #default="scope">
            {{ formatTime(scope.row.created_at) }}
          </template>
        </el-table-column>

        <el-table-column prop="show" label="展示状态" width="100" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.show"
              :active-value="1"
              :inactive-value="0"
              @change="() => handleToggleShow(scope.row)"
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

    <!-- Notice Dialog (Create/Edit) -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑公告' : '发布新公告'" width="650px">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="80px">
        <el-form-item label="公告标题" prop="title">
          <el-input v-model="form.title" placeholder="请输入公告标题" />
        </el-form-item>
        
        <el-form-item label="封面图片" prop="img_url">
          <el-input v-model="form.img_url" placeholder="可选，输入封面图片 URL 链接" />
        </el-form-item>

        <el-form-item label="公告内容" prop="content">
          <el-input 
            v-model="form.content" 
            type="textarea" 
            :rows="10" 
            placeholder="支持 Markdown 格式内容" 
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

const loading = ref(false);
const submitLoading = ref(false);
const notices = ref([]);

const dialogVisible = ref(false);
const isEdit = ref(false);
const formRef = ref(null);

const form = reactive({
  id: null,
  title: '',
  img_url: '',
  content: '',
});

const rules = {
  title: [{ required: true, message: '请输入公告标题', trigger: 'blur' }],
  content: [{ required: true, message: '请输入公告内容', trigger: 'blur' }],
};

const formatTime = (time) => {
  if (!time) return '-';
  // Check if timestamp is in seconds or ms
  const date = new Date(time * 1000);
  return date.toLocaleString();
};

const fetchNotices = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/notice/fetch`);
    if (res.data) {
      notices.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleToggleShow = async (row) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/notice/show`, {
      id: row.id
    });
    ElMessage.success('更新展示状态成功');
  } catch (err) {
    console.error(err);
    row.show = row.show === 1 ? 0 : 1; // Revert status
  }
};

const openCreateDialog = () => {
  isEdit.value = false;
  form.id = null;
  form.title = '';
  form.img_url = '';
  form.content = '';
  dialogVisible.value = true;
};

const openEditDialog = (row) => {
  isEdit.value = true;
  form.id = row.id;
  form.title = row.title;
  form.img_url = row.img_url || '';
  form.content = row.content || '';
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
        title: form.title,
        content: form.content,
        img_url: form.img_url || null,
      };
      
      if (isEdit.value) {
        payload.id = form.id;
      }

      await api.post(`/${securePath}/notice/save`, payload);
      ElMessage.success(isEdit.value ? '公告更新成功' : '公告发布成功');
      dialogVisible.value = false;
      fetchNotices();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定要永久删除该公告吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/notice/drop`, { id: row.id });
    ElMessage.success('删除成功');
    fetchNotices();
  }).catch(() => {});
};

onMounted(() => {
  fetchNotices();
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
</style>
