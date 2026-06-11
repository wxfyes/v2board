<template>
  <div class="tickets-container">
    <el-card class="filter-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <div class="filter-left flex-center flex-wrap gap-10">
          <el-input
            v-model="searchEmail"
            placeholder="搜索用户邮箱..."
            prefix-icon="Search"
            clearable
            style="width: 240px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          />
          <el-select v-model="filterStatus" placeholder="工单状态" clearable style="width: 140px" @change="handleSearch">
            <el-option label="全部状态" value="" />
            <el-option label="开启中" :value="0" />
            <el-option label="已关闭" :value="1" />
          </el-select>
          <el-select v-model="filterReply" placeholder="回复状态" clearable style="width: 140px" @change="handleSearch">
            <el-option label="全部回复" value="" />
            <el-option label="待回复" :value="0" />
            <el-option label="已回复" :value="1" />
          </el-select>
          <el-button type="primary" @click="handleSearch">筛选</el-button>
        </div>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="tickets" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        <el-table-column prop="subject" label="主题" min-width="200" show-overflow-tooltip />
        <el-table-column prop="user_id" label="用户 ID" width="100" align="center" />
        
        <el-table-column prop="level" label="等级" width="100" align="center">
          <template #default="scope">
            <el-tag :type="getLevelTagType(scope.row.level)" size="small">
              {{ levelMap[scope.row.level] || '普通' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="status" label="状态" width="100" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.status === 0 ? 'success' : 'info'" size="small" effect="dark">
              {{ scope.row.status === 0 ? '开启中' : '已关闭' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="reply_status" label="回复状态" width="120" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.reply_status === 0 ? 'warning' : 'success'" size="small">
              {{ scope.row.reply_status === 0 ? '待回复' : '已回复' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="更新时间" width="180">
          <template #default="scope">
            <span>{{ formatTime(scope.row.updated_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="180" align="right">
          <template #default="scope">
            <el-button type="primary" link @click="openTicketChat(scope.row)">回复沟通</el-button>
            <el-button 
              v-if="scope.row.status === 0" 
              type="danger" 
              link 
              @click="handleCloseTicket(scope.row)"
            >
              关闭
            </el-button>
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

    <!-- Chat Drawer -->
    <el-drawer
      v-model="chatVisible"
      title="工单沟通详情"
      :size="isMobile ? '100%' : '600px'"
      destroy-on-close
    >
      <template #header>
        <div class="drawer-header-info">
          <span style="font-size: 16px; font-weight: 600;">工单 #{{ activeTicket.id }} - {{ activeTicket.subject }}</span>
          <div class="mt-5 text-secondary flex-center gap-15 flex-wrap">
            <span>用户 ID: 
              <el-link type="primary" @click="handleEditUser(activeTicket.user_id)" style="font-family: monospace; font-weight: bold;">
                {{ activeTicket.user_id }} <el-icon style="margin-left: 2px;"><Edit /></el-icon>
              </el-link>
            </span>
            <span v-if="userEmail">邮箱: 
              <el-link type="primary" @click="handleEditUser(activeTicket.user_id)" style="font-family: monospace; font-weight: bold;">
                {{ userEmail }} <el-icon style="margin-left: 2px;"><Edit /></el-icon>
              </el-link>
            </span>
          </div>
        </div>
      </template>

      <div class="chat-container">
        <!-- Message History -->
        <el-scrollbar class="chat-history-scroll" ref="chatScrollRef">
          <div class="chat-history-list" style="padding: 10px;">
            <div 
              v-for="msg in chatMessages" 
              :key="msg.id" 
              :class="['chat-bubble-row', msg.is_me ? 'admin-row' : 'user-row']"
            >
              <div class="chat-bubble">
                <div class="bubble-header">
                  <span class="sender-name">{{ msg.is_me ? '管理员' : '用户' }}</span>
                  <span class="message-time ml-10">{{ formatTime(msg.created_at) }}</span>
                </div>
                <div class="bubble-body">
                  <template v-for="(part, pIdx) in parseMessageContent(msg.message)" :key="pIdx">
                    <span v-if="part.type === 'text'" style="white-space: pre-wrap; word-break: break-all;">{{ part.content }}</span>
                    <div v-else-if="part.type === 'image'" class="chat-image-wrapper">
                      <el-image 
                        :src="part.url" 
                        :preview-src-list="[part.url]"
                        fit="contain"
                        class="chat-image"
                        preview-teleported
                      />
                    </div>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </el-scrollbar>

        <!-- Input Box -->
        <div class="chat-input-box" v-if="activeTicket.status === 0">
          <el-input
            type="textarea"
            :rows="3"
            v-model="replyText"
            placeholder="请输入回复内容... (支持拖拽或 Ctrl+V 直接粘贴上传截图)"
            @keyup.ctrl.enter="handleReply"
            @paste="handlePaste"
          />
          <div class="flex-between align-center mt-10">
            <span class="font-11 text-secondary flex-center gap-10">
              <span>Ctrl + Enter 快捷发送</span>
              <span>&bull;</span>
              <el-link type="primary" :loading="imageUploading" @click="triggerImageUpload" style="font-size: 11px;">
                <el-icon class="mr-5"><Picture /></el-icon>发送图片
              </el-link>
            </span>
            <div>
              <el-button type="danger" plain @click="handleCloseTicket(activeTicket)">关闭工单</el-button>
              <el-button type="primary" :loading="replyLoading" @click="handleReply">发送回复</el-button>
            </div>
          </div>
          <input
            ref="imageInputRef"
            type="file"
            accept="image/*"
            style="display: none"
            @change="handleImageFileSelect"
          />
        </div>
        <div class="chat-closed-box" v-else>
          <el-alert title="该工单已关闭" type="info" show-icon :closable="false" />
        </div>
      </div>
    </el-drawer>

    <!-- Edit User Dialog -->
    <el-dialog v-model="editVisible" title="编辑用户" :width="isMobile ? '95%' : '550px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="editForm" ref="editFormRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '100px'" v-if="editForm.id">
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
            <el-form-item label="管理员权限">
              <el-radio-group v-model="editForm.is_admin">
                <el-radio :label="0">否</el-radio>
                <el-radio :label="1">是</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="员工权限">
              <el-radio-group v-model="editForm.is_staff">
                <el-radio :label="0">否</el-radio>
                <el-radio :label="1">是</el-radio>
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
import { ref, reactive, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { uploadImage } from '../utils/imageUploadHelper';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const loading = ref(false);
const tickets = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);

const searchEmail = ref('');
const filterStatus = ref('');
const filterReply = ref('');

const levelMap = {
  0: '低',
  1: '中',
  2: '高'
};

const getLevelTagType = (lvl) => {
  if (lvl === 2) return 'danger';
  if (lvl === 1) return 'warning';
  return 'info';
};

const formatTime = (ts) => {
  if (!ts) return '-';
  return new Date(ts * 1000).toLocaleString();
};

const fetchTickets = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const params = {
      current: currentPage.value,
      pageSize: pageSize.value
    };
    if (filterStatus.value !== '') params.status = filterStatus.value;
    if (filterReply.value !== '') params.reply_status = [filterReply.value];
    if (searchEmail.value) params.email = searchEmail.value;

    const res = await api.get(`/${securePath}/ticket/fetch`, { params });
    if (res.data) {
      tickets.value = res.data;
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
  fetchTickets();
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchTickets();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchTickets();
};

// Chat Drawer Logic
const chatVisible = ref(false);
const activeTicket = ref({});
const chatMessages = ref([]);
const userEmail = ref('');
const replyText = ref('');
const replyLoading = ref(false);
const chatScrollRef = ref(null);

const refreshInterval = ref(null);

const clearRefreshInterval = () => {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value);
    refreshInterval.value = null;
  }
};

const setupRefreshInterval = (ticketId) => {
  clearRefreshInterval();
  if (activeTicket.value && activeTicket.value.status === 0) {
    refreshInterval.value = setInterval(async () => {
      if (chatVisible.value && activeTicket.value && activeTicket.value.id === ticketId) {
        try {
          const securePath = getSecurePath();
          const res = await api.get(`/${securePath}/ticket/fetch`, { params: { id: ticketId } });
          if (res.data) {
            // Check if messages count or update time changed before replacing to prevent unnecessary DOM updates
            const newMessages = res.data.message || [];
            if (newMessages.length !== chatMessages.value.length) {
              activeTicket.value = res.data;
              chatMessages.value = newMessages;
              scrollToBottom();
            }
          }
        } catch (err) {
          console.error('Auto refresh error:', err);
        }
      } else {
        clearRefreshInterval();
      }
    }, 5000);
  }
};

watch(chatVisible, (newVal) => {
  if (!newVal) {
    clearRefreshInterval();
  }
});

onUnmounted(() => {
  clearRefreshInterval();
});

const openTicketChat = async (row) => {
  activeTicket.value = row;
  chatMessages.value = [];
  userEmail.value = '';
  replyText.value = '';
  chatVisible.value = true;
  
  // Fetch details and history
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/ticket/fetch`, { params: { id: row.id } });
    if (res.data) {
      activeTicket.value = res.data;
      chatMessages.value = res.data.message || [];
      scrollToBottom();
      
      setupRefreshInterval(row.id);
    }
    
    // Asynchronously get user email
    const userRes = await api.get(`/${securePath}/user/getUserInfoById`, { params: { id: row.user_id } });
    if (userRes.data) {
      userEmail.value = userRes.data.email;
    }
  } catch (err) {
    console.error(err);
  }
};

const scrollToBottom = () => {
  nextTick(() => {
    if (chatScrollRef.value) {
      chatScrollRef.value.setScrollTop(999999);
    }
  });
};

const handleReply = async () => {
  if (!replyText.value.trim()) return;
  replyLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/ticket/reply`, {
      id: activeTicket.value.id,
      message: replyText.value.trim()
    });
    ElMessage.success('发送回复成功');
    replyText.value = '';
    // Reload messages
    const res = await api.get(`/${securePath}/ticket/fetch`, { params: { id: activeTicket.value.id } });
    if (res.data) {
      chatMessages.value = res.data.message || [];
      scrollToBottom();
    }
    fetchTickets();
  } catch (err) {
    ElMessage.error(err.message || '回复失败');
  } finally {
    replyLoading.value = false;
  }
};

const handleCloseTicket = (row) => {
  ElMessageBox.confirm('确定要关闭该工单吗？关闭后用户将无法再追问，除非您或他重新开启。', '提示', {
    type: 'warning',
    confirmButtonText: '确定关闭',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/ticket/close`, { id: row.id });
      ElMessage.success('工单已关闭');
      if (chatVisible.value && activeTicket.value.id === row.id) {
        activeTicket.value.status = 1;
        clearRefreshInterval();
      }
      fetchTickets();
    } catch (err) {
      ElMessage.error(err.message || '关闭失败');
    }
  }).catch(() => {});
};

// Edit User Dialog logic
const editVisible = ref(false);
const editFormRef = ref(null);
const activeTab = ref('profile');
const submitLoading = ref(false);
const plans = ref([]);

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
  is_admin: 0,
  is_staff: 0,
});

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

const handleEditUser = async (userId) => {
  try {
    const securePath = getSecurePath();
    const userRes = await api.get(`/${securePath}/user/getUserInfoById`, { params: { id: userId } });
    if (userRes.data) {
      const row = userRes.data;
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
      editForm.is_admin = row.is_admin !== undefined ? Number(row.is_admin) : 0;
      editForm.is_staff = row.is_staff !== undefined ? Number(row.is_staff) : 0;
      
      if (plans.value.length === 0) {
        await fetchPlans();
      }
      
      activeTab.value = 'profile';
      editVisible.value = true;
    }
  } catch (err) {
    console.error(err);
    ElMessage.error('获取用户信息失败');
  }
};

const handleUpdateUser = async () => {
  submitLoading.value = true;
  try {
    const securePath = getSecurePath();
    
    const parseIntegerOrNull = (val) => {
      if (val === null || val === undefined || val === '') return null;
      const parsed = parseInt(val);
      return isNaN(parsed) ? null : parsed;
    };

    const payload = {
      id: editForm.id,
      email: editForm.email,
      balance: Math.round(editForm.balance * 100),
      commission_balance: Math.round(editForm.commission_balance * 100),
      plan_id: editForm.plan_id,
      transfer_enable: Math.round(editForm.transfer_enable_gb * 1073741824),
      u: Math.round(editForm.u_gb * 1073741824),
      d: Math.round(editForm.d_gb * 1073741824),
      expired_at: parseIntegerOrNull(editForm.expired_at),
      device_limit: parseIntegerOrNull(editForm.device_limit),
      speed_limit: parseIntegerOrNull(editForm.speed_limit),
      banned: editForm.banned,
      is_admin: editForm.is_admin,
      is_staff: editForm.is_staff,
    };
    
    if (editForm.password) {
      payload.password = editForm.password;
    }
    
    await api.post(`/${securePath}/user/update`, payload);
    ElMessage.success('保存用户信息成功');
    editVisible.value = false;
    
    if (activeTicket.value && activeTicket.value.user_id === editForm.id) {
      userEmail.value = editForm.email;
    }
  } catch (err) {
    console.error(err);
    ElMessage.error(err.message || '保存失败');
  } finally {
    submitLoading.value = false;
  }
};

// Image upload and markdown rendering logic
const imageInputRef = ref(null);
const imageUploading = ref(false);

const triggerImageUpload = () => {
  if (imageInputRef.value) {
    imageInputRef.value.click();
  }
};

const handleImageFileSelect = async (event) => {
  const file = event.target.files[0];
  if (!file) return;
  
  imageUploading.value = true;
  try {
    const res = await uploadImage(file);
    if (res && res.markdown) {
      replyText.value = (replyText.value ? replyText.value + '\n' : '') + res.markdown;
      ElMessage.success('图片上传成功，已插入输入框');
    }
  } catch (err) {
    console.error(err);
    ElMessage.error(err.message || '图片上传失败');
  } finally {
    imageUploading.value = false;
    event.target.value = '';
  }
};

const handlePaste = async (event) => {
  const items = event.clipboardData?.items;
  if (!items) return;
  
  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile();
      if (file) {
        event.preventDefault();
        imageUploading.value = true;
        try {
          const res = await uploadImage(file);
          if (res && res.markdown) {
            replyText.value = (replyText.value ? replyText.value + '\n' : '') + res.markdown;
            ElMessage.success('已从剪贴板粘贴并上传图片');
          }
        } catch (err) {
          console.error(err);
          ElMessage.error(err.message || '图片上传失败');
        } finally {
          imageUploading.value = false;
        }
        break;
      }
    }
  }
};

const parseMessageContent = (text) => {
  if (!text) return [];
  const regex = /!\[(.*?)\]\((.*?)\)/g;
  const parts = [];
  let lastIndex = 0;
  let match;
  
  while ((match = regex.exec(text)) !== null) {
    const textBefore = text.substring(lastIndex, match.index);
    if (textBefore) {
      parts.push({ type: 'text', content: textBefore });
    }
    parts.push({ type: 'image', alt: match[1], url: match[2] });
    lastIndex = regex.lastIndex;
  }
  
  const textAfter = text.substring(lastIndex);
  if (textAfter) {
    parts.push({ type: 'text', content: textAfter });
  }
  
  return parts;
};

onMounted(() => {
  fetchTickets();
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
.text-secondary {
  color: var(--el-text-color-secondary);
}
.ml-15 {
  margin-left: 15px;
}
.mt-20 {
  margin-top: 20px;
}
.mt-5 {
  margin-top: 5px;
}
.mt-10 {
  margin-top: 10px;
}
.ml-10 {
  margin-left: 10px;
}

.chat-container {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 120px);
}
.chat-history-scroll {
  flex: 1;
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  background-color: var(--el-fill-color-blank);
}
.chat-history-list {
  display: flex;
  flex-direction: column;
  gap: 15px;
}
.chat-bubble-row {
  display: flex;
  width: 100%;
}
.user-row {
  justify-content: flex-start;
}
.admin-row {
  justify-content: flex-end;
}
.chat-bubble {
  max-width: 80%;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.5;
}
.user-row .chat-bubble {
  background-color: var(--el-fill-color-light);
  color: var(--el-text-color-primary);
  border-top-left-radius: 2px;
}
.admin-row .chat-bubble {
  background-color: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
  border-top-right-radius: 2px;
  border: 1px solid var(--el-color-primary-light-7);
}
.bubble-header {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  margin-bottom: 5px;
  display: flex;
  justify-content: space-between;
}
.bubble-body {
  white-space: pre-wrap;
  word-break: break-all;
}

.chat-input-box {
  padding-top: 15px;
}
.chat-closed-box {
  padding-top: 15px;
}
.pagination-info {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.flex-center {
  display: flex;
  align-items: center;
}
.gap-15 {
  gap: 15px;
}
.gap-10 {
  gap: 10px;
}
.flex-wrap {
  flex-wrap: wrap;
}
.mr-5 {
  margin-right: 5px;
}
.chat-image-wrapper {
  margin-top: 5px;
  max-width: 250px;
}
.chat-image {
  width: 100%;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid var(--el-border-color-lighter);
  transition: opacity 0.2s;
}
.chat-image:hover {
  opacity: 0.9;
}
</style>
