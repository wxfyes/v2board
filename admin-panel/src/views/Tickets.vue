<template>
  <div class="tickets-layout-container" :class="{ 'is-mobile': isMobile }">
    <!-- 左侧工单列表 -->
    <div 
      v-show="!isMobile || !mobileShowChat" 
      class="tickets-sidebar"
      :class="{ 'mobile-full': isMobile }"
    >
      <el-card class="sidebar-card" shadow="never">
        <!-- 列表头部：标题与状态切换 -->
        <div class="sidebar-header">
          <span class="sidebar-title">工单列表</span>
          <el-radio-group v-model="filterStatus" size="small" @change="handleStatusChange">
            <el-radio-button :label="0">已开启</el-radio-button>
            <el-radio-button :label="1">已关闭</el-radio-button>
          </el-radio-group>
        </div>

        <!-- 搜索框 -->
        <div class="sidebar-search">
          <el-input
            v-model="searchKeyword"
            placeholder="搜索工单标题、内容或用户邮箱..."
            prefix-icon="Search"
            clearable
            size="default"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          />
        </div>

        <!-- 工单卡片列表 -->
        <div class="sidebar-list-wrapper" v-loading="loading">
          <el-scrollbar class="sidebar-scroll">
            <div v-if="tickets.length > 0" class="ticket-items">
              <div
                v-for="ticket in tickets"
                :key="ticket.id"
                class="ticket-card"
                :class="{ 'is-active': activeTicket && activeTicket.id === ticket.id }"
                @click="handleSelectTicket(ticket)"
              >
                <div class="ticket-card-top">
                  <span class="ticket-id-title">#{{ ticket.id }} {{ ticket.subject }}</span>
                  <div class="ticket-status-badge">
                    <el-tag v-if="ticket.status === 1" size="small" type="info">已关闭</el-tag>
                    <span v-else class="reply-status-indicator" :class="ticket.reply_status ? 'replied' : 'pending'">
                      <span class="dot"></span>
                      <span>{{ ticket.reply_status ? '已回复' : '待回复' }}</span>
                    </span>
                  </div>
                </div>

                <div class="ticket-card-bottom">
                  <span class="ticket-time">{{ formatTime(ticket.created_at) }}</span>
                  <el-tag :type="getLevelTagType(ticket.level)" size="small" effect="plain">
                    {{ levelMap[ticket.level] || '未知' }}优先级
                  </el-tag>
                </div>
              </div>
            </div>
            <el-empty v-else-if="!loading" description="暂无相关工单" :image-size="60" />
          </el-scrollbar>
        </div>

        <!-- 底部紧凑分页 -->
        <div class="sidebar-pagination">
          <el-pagination
            v-model:current-page="currentPage"
            v-model:page-size="pageSize"
            :pager-count="5"
            layout="prev, pager, next"
            :total="total"
            small
            @current-change="handleCurrentChange"
          />
        </div>
      </el-card>
    </div>

    <!-- 右侧工作台聊天区 -->
    <div 
      v-show="!isMobile || mobileShowChat" 
      class="tickets-main"
      :class="{ 'mobile-full': isMobile }"
    >
      <el-card class="main-card" shadow="never">
        <template v-if="activeTicket && activeTicket.id">
          <!-- 聊天头部 Header -->
          <div class="chat-header">
            <div class="header-left">
              <el-button 
                v-if="isMobile" 
                type="primary" 
                link 
                icon="ArrowLeft" 
                class="back-btn" 
                @click="mobileShowChat = false"
              >
                返回
              </el-button>
              <span class="header-title">#{{ activeTicket.id }} {{ activeTicket.subject }}</span>
              <el-tag v-if="activeTicket.status === 1" size="small" type="info">已关闭</el-tag>
              <el-tag v-else size="small" type="success" effect="light">进行中</el-tag>
            </div>

            <div class="header-actions">
              <el-button 
                v-if="activeTicket.status === 0" 
                size="small" 
                type="danger" 
                plain
                @click="handleCloseTicket(activeTicket)"
              >
                关闭工单
              </el-button>
              
              <el-tooltip content="查看用户全息档案" placement="top">
                <el-button size="small" circle icon="User" @click="openUserDetail(activeTicket.user_id)" />
              </el-tooltip>
            </div>
          </div>

          <!-- 聊天记录滚动展示区 -->
          <div class="chat-body-wrapper">
            <el-scrollbar ref="chatScrollRef" class="chat-scroll">
              <div class="chat-messages" v-loading="chatLoading">
                <div 
                  v-for="msg in chatMessages" 
                  :key="msg.id" 
                  :class="['message-row', msg.is_me ? 'is-admin' : 'is-user']"
                >
                  <div class="message-time-label">
                    {{ formatTime(msg.created_at) }}
                  </div>
                  
                  <div class="message-bubble-box">
                    <div class="bubble-content" :class="msg.is_me ? 'bubble-admin' : 'bubble-user'">
                      <template v-for="(part, pIdx) in parseMessageContent(msg.message)" :key="pIdx">
                        <div v-if="part.type === 'text'" class="text-part">{{ part.content }}</div>
                        <div v-else-if="part.type === 'image'" class="image-part">
                          <el-image 
                            :src="part.url" 
                            :preview-src-list="[part.url]"
                            fit="contain"
                            class="chat-render-img"
                            preview-teleported
                          />
                        </div>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </el-scrollbar>
          </div>

          <!-- 底部输入回复区 -->
          <div class="chat-footer">
            <div v-if="activeTicket.status === 0" class="input-active-area">
              <el-input
                v-model="replyText"
                type="textarea"
                :rows="isMobile ? 3 : 4"
                placeholder="输入回复内容，支持粘贴图片或拖拽上传..."
                resize="none"
                @paste="handlePaste"
                @keydown.enter="handleEnterKey"
              />
              <div class="input-toolbar">
                <div class="toolbar-left">
                  <el-button size="small" icon="Picture" :loading="imageUploading" @click="triggerImageUpload">
                    发送图片
                  </el-button>
                  <span v-if="!isMobile" class="shortcut-tip">Enter 发送，Shift + Enter 换行</span>
                  <input
                    ref="imageInputRef"
                    type="file"
                    accept="image/*"
                    style="display: none"
                    @change="handleImageFileSelect"
                  />
                </div>
                <div class="toolbar-right">
                  <el-button type="primary" size="small" :loading="replyLoading" @click="handleReply">
                    发送
                  </el-button>
                </div>
              </div>
            </div>
            <div v-else class="closed-notice">
              <el-alert title="该工单已关闭，如需继续沟通请重新开启" type="info" :closable="false" show-icon />
            </div>
          </div>
        </template>

        <!-- 初始空状态 -->
        <div v-else class="empty-placeholder">
          <el-empty description="请在左侧选择一个工单进行回复" />
        </div>
      </el-card>
    </div>

    <!-- 用户全息档案联动弹窗 -->
    <UserDetailDialog 
      v-model="userDetailVisible" 
      :user-id="selectedUserId" 
      @change="fetchTickets" 
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { uploadImage } from '../utils/imageUploadHelper';
import { useMobile } from '../utils/useMobile';
import UserDetailDialog from '../components/UserDetailDialog.vue';

const { isMobile } = useMobile();

const loading = ref(false);
const tickets = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(15);

const searchKeyword = ref('');
const filterStatus = ref(0); // 默认已开启 (0)，已关闭为 (1)

// 选中的工单与聊天状态
const activeTicket = ref(null);
const chatMessages = ref([]);
const chatLoading = ref(false);
const replyText = ref('');
const replyLoading = ref(false);
const imageUploading = ref(false);
const chatScrollRef = ref(null);
const imageInputRef = ref(null);
const refreshInterval = ref(null);

// 手机端聊天页展现开关
const mobileShowChat = ref(false);

// 用户全息弹窗状态
const userDetailVisible = ref(false);
const selectedUserId = ref(null);

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
  const d = new Date(ts * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

// 获取工单列表
const fetchTickets = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const params = {
      current: currentPage.value,
      pageSize: pageSize.value,
      status: filterStatus.value
    };
    if (searchKeyword.value.trim()) {
      params.email = searchKeyword.value.trim();
    }

    const res = await api.get(`/${securePath}/ticket/fetch`, { params });
    if (res.data) {
      tickets.value = Array.isArray(res.data) ? res.data : (res.data.data || []);
      total.value = res.total || tickets.value.length || 0;
      
      // PC 端默认激活第一个工单
      if (!isMobile.value && !activeTicket.value && tickets.value.length > 0) {
        handleSelectTicket(tickets.value[0]);
      }
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleStatusChange = () => {
  currentPage.value = 1;
  activeTicket.value = null;
  fetchTickets();
};

const handleSearch = () => {
  currentPage.value = 1;
  fetchTickets();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchTickets();
};

// 选中某个工单
const handleSelectTicket = async (ticket) => {
  activeTicket.value = ticket;
  chatMessages.value = [];
  replyText.value = '';
  if (isMobile.value) {
    mobileShowChat.value = true;
  }
  await fetchTicketDetail(ticket.id);
  setupRefreshInterval(ticket.id);
};

// 获取工单详情
const fetchTicketDetail = async (ticketId, isSilent = false) => {
  if (!isSilent) chatLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/ticket/fetch`, { params: { id: ticketId } });
    if (res.data) {
      activeTicket.value = res.data;
      const newMessages = res.data.message || [];
      // 消息数量有变动时才滚动
      if (newMessages.length !== chatMessages.value.length) {
        chatMessages.value = newMessages;
        scrollToBottom();
      }
    }
  } catch (err) {
    console.error('获取工单详情失败:', err);
  } finally {
    if (!isSilent) chatLoading.value = false;
  }
};

const setupRefreshInterval = (ticketId) => {
  clearRefreshInterval();
  if (activeTicket.value && activeTicket.value.status === 0) {
    refreshInterval.value = setInterval(() => {
      if (activeTicket.value && activeTicket.value.id === ticketId) {
        fetchTicketDetail(ticketId, true);
      } else {
        clearRefreshInterval();
      }
    }, 5000);
  }
};

const clearRefreshInterval = () => {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value);
    refreshInterval.value = null;
  }
};

const scrollToBottom = () => {
  nextTick(() => {
    if (chatScrollRef.value) {
      chatScrollRef.value.setScrollTop(999999);
    }
  });
};

// 发送回复
const handleReply = async () => {
  if (!replyText.value.trim() || !activeTicket.value) return;
  replyLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/ticket/reply`, {
      id: activeTicket.value.id,
      message: replyText.value.trim()
    });
    replyText.value = '';
    ElMessage.success('回复发送成功');
    await fetchTicketDetail(activeTicket.value.id);
    fetchTickets();
  } catch (err) {
    ElMessage.error(err.message || '回复失败');
  } finally {
    replyLoading.value = false;
  }
};

const handleEnterKey = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    handleReply();
  }
};

// 关闭工单
const handleCloseTicket = (ticket) => {
  ElMessageBox.confirm('确定要关闭该工单吗？关闭后用户将无法继续追加提问。', '关闭工单', {
    type: 'warning',
    confirmButtonText: '确定关闭',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/ticket/close`, { id: ticket.id });
      ElMessage.success('工单已成功关闭');
      if (activeTicket.value && activeTicket.value.id === ticket.id) {
        activeTicket.value.status = 1;
        clearRefreshInterval();
      }
      fetchTickets();
    } catch (err) {
      ElMessage.error(err.message || '操作失败');
    }
  }).catch(() => {});
};

// 查看用户详情
const openUserDetail = (userId) => {
  selectedUserId.value = userId;
  userDetailVisible.value = true;
};

// 图片上传处理
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
      ElMessage.success('图片已插入输入框');
    }
  } catch (err) {
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
          ElMessage.error(err.message || '图片上传失败');
        } finally {
          imageUploading.value = false;
        }
        break;
      }
    }
  }
};

// 解析消息中的 Markdown 图片
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

onUnmounted(() => {
  clearRefreshInterval();
});
</script>

<style scoped>
.tickets-layout-container {
  display: flex;
  height: calc(100vh - 120px);
  min-height: 580px;
  gap: 16px;
  box-sizing: border-box;
}

.tickets-layout-container.is-mobile {
  height: calc(100vh - 100px);
  gap: 0;
}

/* 左侧栏 */
.tickets-sidebar {
  width: 330px;
  flex-shrink: 0;
  height: 100%;
}

.tickets-sidebar.mobile-full {
  width: 100%;
}

.sidebar-card {
  height: 100%;
  display: flex;
  flex-direction: column;
  border-radius: 8px;
  border: 1px solid var(--el-border-color-lighter);
}

.sidebar-card :deep(.el-card__body) {
  padding: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.sidebar-header {
  padding: 14px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--el-border-color-extra-light);
}

.sidebar-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.sidebar-search {
  padding: 12px 14px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  background: var(--el-fill-color-blank);
}

.sidebar-list-wrapper {
  flex: 1;
  overflow: hidden;
  position: relative;
}

.sidebar-scroll {
  height: 100%;
}

.ticket-items {
  display: flex;
  flex-direction: column;
}

.ticket-card {
  padding: 14px 16px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  cursor: pointer;
  transition: all 0.2s ease;
  border-left: 3px solid transparent;
  background: var(--el-fill-color-blank);
}

.ticket-card:hover {
  background: var(--el-fill-color-light);
}

.ticket-card.is-active {
  background: #e6f7ff;
  border-left-color: var(--el-color-primary);
}

.ticket-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.ticket-id-title {
  font-weight: 600;
  font-size: 14px;
  color: var(--el-text-color-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 200px;
}

.reply-status-indicator {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
}

.reply-status-indicator .dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.reply-status-indicator.pending {
  color: var(--el-color-danger);
}
.reply-status-indicator.pending .dot {
  background-color: var(--el-color-danger);
}

.reply-status-indicator.replied {
  color: var(--el-color-primary);
}
.reply-status-indicator.replied .dot {
  background-color: var(--el-color-primary);
}

.ticket-card-bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.ticket-time {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.sidebar-pagination {
  padding: 10px 14px;
  border-top: 1px solid var(--el-border-color-extra-light);
  display: flex;
  justify-content: center;
  background: var(--el-fill-color-blank);
}

/* 右侧工作台 */
.tickets-main {
  flex: 1;
  height: 100%;
  overflow: hidden;
}

.tickets-main.mobile-full {
  width: 100%;
}

.main-card {
  height: 100%;
  display: flex;
  flex-direction: column;
  border-radius: 8px;
  border: 1px solid var(--el-border-color-lighter);
}

.main-card :deep(.el-card__body) {
  padding: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.chat-header {
  padding: 12px 16px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--el-fill-color-light);
  flex-shrink: 0;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.back-btn {
  font-weight: bold;
  padding: 0 4px 0 0;
  height: auto;
}

.header-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.chat-body-wrapper {
  flex: 1;
  overflow: hidden;
  background: #ffffff;
}

.chat-scroll {
  height: 100%;
}

.chat-messages {
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.message-row {
  display: flex;
  flex-direction: column;
}

.message-row.is-admin {
  align-items: flex-end;
}

.message-row.is-user {
  align-items: flex-start;
}

.message-time-label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 6px;
}

.message-bubble-box {
  max-width: 85%;
}

.bubble-content {
  padding: 12px 16px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.6;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

/* 用户气泡：图二浅绿色样式 */
.bubble-user {
  background-color: #e8f8f0;
  border: 1px solid #c2e7b0;
  color: #274e13;
}

/* 管理员气泡：灰白色样式 */
.bubble-admin {
  background-color: #f4f4f5;
  border: 1px solid #e4e7ed;
  color: var(--el-text-color-primary);
}

.text-part {
  white-space: pre-wrap;
  word-break: break-all;
}

.image-part {
  margin-top: 8px;
}

.chat-render-img {
  max-width: 100%;
  max-height: 240px;
  border-radius: 4px;
}

.chat-footer {
  border-top: 1px solid var(--el-border-color-extra-light);
  background: var(--el-fill-color-light);
  flex-shrink: 0;
  padding: 12px 16px;
}

.input-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
}

.toolbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.shortcut-tip {
  font-size: 11px;
  color: var(--el-text-color-placeholder);
}

.closed-notice {
  padding: 4px 0;
}

.empty-placeholder {
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
