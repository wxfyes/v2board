<template>
  <el-dialog v-model="visible" title="用户详情与操作" width="500px" @close="handleClose">
    <div v-loading="loading">
      <template v-if="userInfo">
        <el-descriptions :column="1" border size="small">
          <el-descriptions-item label="用户邮箱">{{ userInfo.email }}</el-descriptions-item>
          <el-descriptions-item label="账户余额">{{ (userInfo.balance / 100).toFixed(2) }} ￥</el-descriptions-item>
          <el-descriptions-item label="注册时间">{{ formatTime(userInfo.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="到期时间">{{ userInfo.expired_at ? formatTime(userInfo.expired_at) : '长期有效' }}</el-descriptions-item>
          <el-descriptions-item label="账号状态">
            <el-tag :type="userInfo.banned ? 'danger' : 'success'">{{ userInfo.banned ? '已封禁' : '正常' }}</el-tag>
            <el-tag v-if="userInfo.is_honeypot" type="warning" style="margin-left: 10px;">蜜罐账号</el-tag>
          </el-descriptions-item>
        </el-descriptions>
        
        <div style="margin-top: 20px; display: flex; justify-content: center; gap: 10px;">
          <el-button 
            :type="userInfo.banned ? 'success' : 'danger'" 
            @click="toggleBan"
            :loading="actionLoading"
          >
            {{ userInfo.banned ? '解封账号' : '封禁账号' }}
          </el-button>
          
          <el-button 
            :type="userInfo.is_honeypot ? 'info' : 'warning'" 
            @click="toggleHoneypot"
            :loading="actionLoading"
          >
            {{ userInfo.is_honeypot ? '移出蜜罐' : '加入蜜罐' }}
          </el-button>
        </div>
      </template>
      <el-empty v-else-if="!loading" description="未能获取到用户信息" />
    </div>
  </el-dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import api, { getSecurePath } from '../api';

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  userId: {
    type: [Number, String],
    default: null
  }
});

const emit = defineEmits(['update:modelValue', 'change']);

const visible = ref(false);
const loading = ref(false);
const actionLoading = ref(false);
const userInfo = ref(null);

watch(() => props.modelValue, (val) => {
  visible.value = val;
  if (val && props.userId) {
    fetchUserInfo();
  }
});

const handleClose = () => {
  emit('update:modelValue', false);
  userInfo.value = null;
};

const formatTime = (timestamp) => {
  if (!timestamp) return '-';
  const d = new Date(timestamp * 1000);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
};

const fetchUserInfo = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/user/getUserInfoById`, { params: { id: props.userId } });
    if (res.data) {
      userInfo.value = res.data;
    }
  } catch (error) {
    console.error(error);
  } finally {
    loading.value = false;
  }
};

const toggleBan = () => {
  const isBanned = userInfo.value.banned;
  const actionText = isBanned ? '解封' : '封禁';
  
  ElMessageBox.confirm(`确定要${actionText}该账号吗？`, '提示', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    actionLoading.value = true;
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/user/update`, {
        id: userInfo.value.id,
        banned: isBanned ? 0 : 1
      });
      ElMessage.success(`${actionText}成功`);
      await fetchUserInfo();
      emit('change');
    } catch (e) {
      console.error(e);
    } finally {
      actionLoading.value = false;
    }
  }).catch(() => {});
};

const toggleHoneypot = () => {
  const isHoneypot = userInfo.value.is_honeypot;
  const actionText = isHoneypot ? '移出蜜罐' : '加入蜜罐';
  
  ElMessageBox.confirm(`确定要将该账号${actionText}吗？`, '提示', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    actionLoading.value = true;
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/user/toggleHoneypot`, {
        id: userInfo.value.id
      });
      ElMessage.success(`${actionText}成功`);
      await fetchUserInfo();
      emit('change');
    } catch (e) {
      console.error(e);
    } finally {
      actionLoading.value = false;
    }
  }).catch(() => {});
};
</script>
