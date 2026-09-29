<template>
  <div class="traitors-container">
    <el-card shadow="never" class="traitors-card">
      <template #header>
        <div class="card-header" :class="{ 'mobile-header': isMobile }">
          <div class="header-left">
            <span class="title">内鬼主动防御名单</span>
            <el-tag size="small" type="danger" style="margin-left: 6px;">防守拦截</el-tag>
            <el-popover v-if="matchCount > 0" placement="bottom" title="已注册的疑似内鬼" width="300" trigger="hover">
              <template #reference>
                <el-tag size="small" type="warning" style="margin-left: 6px; cursor: pointer;">发现 {{ matchCount }} 个疑似内鬼</el-tag>
              </template>
              <div style="max-height: 200px; overflow-y: auto;">
                <div v-for="email in matchedEmails" :key="email" style="margin-bottom: 5px;">
                  <el-tag size="small" type="danger">{{ email }}</el-tag>
                </div>
              </div>
            </el-popover>
            <el-tag v-else-if="matchCount === 0 && (emails || ips)" size="small" type="success" style="margin-left: 6px;">暂无命中</el-tag>
          </div>
          <div class="header-right">
            <el-button type="primary" :block="isMobile" :icon="Check" :loading="loading" @click="saveConfig">
              保存配置
            </el-button>
          </div>
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
              <el-tag size="small" type="info" effect="plain" style="margin-left: 8px;">共 {{ emailCount }} 个</el-tag>
            </div>
            <div class="section-desc">一行一个邮箱，自动转小写并去重去空行</div>
            <el-input
              v-model="emails"
              type="textarea"
              :rows="isMobile ? 8 : 15"
              placeholder="example1@gmail.com&#10;example2@gmail.com"
            />
          </div>
        </el-col>

        <el-col :span="12" :xs="24">
          <div class="config-section mt-xs">
            <div class="section-title">
              <el-icon><Place /></el-icon>
              <span>内鬼 IP 列表</span>
              <el-tag size="small" type="info" effect="plain" style="margin-left: 8px;">共 {{ ipCount }} 个</el-tag>
            </div>
            <div class="section-desc">一行一个 IP 地址，建议只填具体的恶意 IP</div>
            <el-input
              v-model="ips"
              type="textarea"
              :rows="isMobile ? 8 : 15"
              placeholder="192.168.1.1&#10;8.8.8.8"
            />
          </div>
        </el-col>
      </el-row>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { ElMessage } from 'element-plus';
import { Check, Message, Place } from '@element-plus/icons-vue';
import { getSecurePath } from '../api';
import api from '../api';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const loading = ref(false);
const emails = ref('');
const ips = ref('');
const matchCount = ref(0);
const matchedEmails = ref([]);

const emailCount = computed(() => {
  if (!emails.value) return 0;
  return emails.value.split('\n').filter(line => line.trim() !== '').length;
});

const ipCount = computed(() => {
  if (!ips.value) return 0;
  return ips.value.split('\n').filter(line => line.trim() !== '').length;
});

const fetchConfig = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getSubscriptionAnomalies`);
    if (res.data && res.data.config) {
      if (res.data.config.traitor_emails) {
        emails.value = res.data.config.traitor_emails.join('\n');
      }
      if (res.data.config.traitor_ips) {
        ips.value = res.data.config.traitor_ips.join('\n');
      }
    }
    // 检查是否有匹配的内鬼
    checkMatches();
  } catch (err) {
    console.error(err);
  }
};

const checkMatches = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/checkTraitorMatches`);
    if (res.data) {
      matchCount.value = res.data.count || 0;
      matchedEmails.value = res.data.matched_emails || [];
    }
  } catch (err) {
    console.error(err);
  }
};

const saveConfig = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    
    // 解析邮箱列表
    const emailList = emails.value
      .split('\n')
      .map(item => item.trim().toLowerCase())
      .filter(item => item !== '');
      
    // 解析IP列表
    const ipList = ips.value
      .split('\n')
      .map(item => item.trim())
      .filter(item => item !== '');

    await api.post(`/${securePath}/stat/saveSecurityConfig`, {
      traitor_emails: Array.from(new Set(emailList)),
      traitor_ips: Array.from(new Set(ipList))
    });

    ElMessage.success('内鬼主动防御名单已保存生效！');
    fetchConfig();
  } catch (err) {
    ElMessage.error(err.message || '保存配置失败');
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
  padding: 0 4px;
}
.traitors-card {
  border-radius: 12px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.card-header.mobile-header {
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
}
.header-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.header-right {
  width: auto;
}
.mobile-header .header-right {
  width: 100%;
}
.title {
  font-size: 16px;
  font-weight: 700;
}
.config-section {
  background: var(--el-fill-color-light);
  padding: 16px;
  border-radius: 8px;
  border: 1px solid var(--el-border-color-lighter);
}
.section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 6px;
}
.section-desc {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 12px;
}
.mt-xs {
  margin-top: 16px;
}
@media (min-width: 768px) {
  .mt-xs {
    margin-top: 0;
  }
}
</style>
