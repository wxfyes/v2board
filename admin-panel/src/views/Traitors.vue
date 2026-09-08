<template>
  <div class="traitors-container">
    <el-card shadow="never">
      <template #header>
        <div class="card-header flex-between">
          <div class="header-left">
            <span class="title">内鬼主动防御名单</span>
            <el-tag size="small" type="danger" style="margin-left: 10px;">防守拦截</el-tag>
          </div>
          <el-button type="primary" :icon="Check" :loading="loading" @click="saveConfig">
            保存配置
          </el-button>
        </div>
      </template>

      <el-alert
        title="防御机制说明"
        type="warning"
        description="系统会在用户注册或登录时自动检查此名单。只要用户的注册邮箱或登录 IP 命中，将在颁发鉴权令牌前将其自动加入蜜罐，全程静默拦截。"
        show-icon
        :closable="false"
        style="margin-bottom: 20px;"
      />

      <el-row :gutter="20">
        <el-col :span="12" :xs="24">
          <div class="config-section">
            <div class="section-title">
              <el-icon><Message /></el-icon>
              <span>内鬼邮箱列表</span>
            </div>
            <div class="section-desc">一行一个邮箱，自动转小写并去重去空行</div>
            <el-input
              v-model="emails"
              type="textarea"
              :rows="15"
              placeholder="example1@gmail.com&#10;example2@gmail.com"
            />
          </div>
        </el-col>

        <el-col :span="12" :xs="24">
          <div class="config-section mt-xs">
            <div class="section-title">
              <el-icon><Place /></el-icon>
              <span>内鬼 IP 列表</span>
            </div>
            <div class="section-desc">一行一个 IP 地址，建议只填具体的恶意 IP</div>
            <el-input
              v-model="ips"
              type="textarea"
              :rows="15"
              placeholder="192.168.1.1&#10;8.8.8.8"
            />
          </div>
        </el-col>
      </el-row>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { Check, Message, Place } from '@element-plus/icons-vue';
import axios from 'axios';

const loading = ref(false);
const emails = ref('');
const ips = ref('');

const fetchConfig = async () => {
  try {
    const res = await axios.get('/api/v1/admin/traitor/fetch', {
      headers: { Authorization: localStorage.getItem('authorization') }
    });
    if (res.data.data) {
      emails.value = res.data.data.emails || '';
      ips.value = res.data.data.ips || '';
    }
  } catch (err) {
    ElMessage.error(err.response?.data?.message || '获取配置失败');
  }
};

const saveConfig = async () => {
  loading.value = true;
  try {
    await axios.post('/api/v1/admin/traitor/save', {
      emails: emails.value,
      ips: ips.value
    }, {
      headers: { Authorization: localStorage.getItem('authorization') }
    });
    ElMessage.success('保存成功');
    fetchConfig(); // 刷新格式化后的数据
  } catch (err) {
    ElMessage.error(err.response?.data?.message || '保存失败');
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  fetchConfig();
});
</script>

<style scoped>
.traitors-container {
  max-width: 1200px;
}

.title {
  font-size: 16px;
  font-weight: 600;
}

.config-section {
  background-color: var(--el-fill-color-light);
  padding: 16px;
  border-radius: 8px;
  height: 100%;
}

.section-title {
  font-size: 15px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}

.section-desc {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 12px;
}

@media (max-width: 768px) {
  .mt-xs {
    margin-top: 20px;
  }
}
</style>
