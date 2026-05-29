<template>
  <div class="settings-container">
    <el-card class="settings-card" shadow="hover" v-loading="loading">
      <template #header>
        <div class="flex-between">
          <span class="settings-title">系统配置中心</span>
          <el-button type="primary" :loading="submitLoading" @click="handleSave">保存配置</el-button>
        </div>
      </template>
      
      <el-tabs tab-position="left" v-model="activeTab" style="min-height: 500px">
        <!-- 站点设置 -->
        <el-tab-pane label="站点设置" name="site">
          <div class="pane-title">站点基础信息设置</div>
          <el-form :model="configData.site" label-width="150px">
            <el-form-item label="站点名称">
              <el-input v-model="configData.site.app_name" />
            </el-form-item>
            <el-form-item label="站点描述">
              <el-input v-model="configData.site.app_description" type="textarea" :rows="2" />
            </el-form-item>
            <el-form-item label="站点 URL">
              <el-input v-model="configData.site.app_url" placeholder="例如 https://tianque.com" />
            </el-form-item>
            <el-form-item label="自定义订阅地址">
              <el-input v-model="configData.site.subscribe_url" placeholder="留空默认使用站点URL，多域名用英文逗号分隔" />
            </el-form-item>
            <el-form-item label="LOGO 图片 URL">
              <el-input v-model="configData.site.logo" placeholder="站点 Logo 的网络地址" />
            </el-form-item>
            <el-form-item label="强制 HTTPS">
              <el-switch v-model="configData.site.force_https" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="停止新用户注册">
              <el-switch v-model="configData.site.stop_register" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="试用订阅 ID">
                  <el-input-number v-model="configData.site.try_out_plan_id" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="试用时长 (小时)">
                  <el-input-number v-model="configData.site.try_out_hour" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>

        <!-- 订阅设置 -->
        <el-tab-pane label="订阅设置" name="subscribe">
          <div class="pane-title">订阅与分流控制</div>
          <el-form :model="configData.subscribe" label-width="150px">
            <el-form-item label="允许折价变更计划">
              <el-switch v-model="configData.subscribe.plan_change_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">开启后，用户补差价可以升级更高等级订阅</div>
            </el-form-item>
            <el-form-item label="流量重置方式">
              <el-select v-model="configData.subscribe.reset_traffic_method" style="width: 100%">
                <el-option label="每月1号重置" :value="0" />
                <el-option label="按注册日周期重置" :value="1" />
                <el-option label="不重置" :value="2" />
              </el-select>
            </el-form-item>
            <el-form-item label="结余旧流量">
              <el-switch v-model="configData.subscribe.surplus_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="允许新周期叠加">
              <el-switch v-model="configData.subscribe.allow_new_period" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="隐藏连接信息">
              <el-switch v-model="configData.subscribe.show_info_to_server_enable" :active-value="1" :inactive-value="0" />
              <div class="form-tip">是否在订阅信息中隐藏连接节点等额外参数</div>
            </el-form-item>
          </el-form>
        </el-tab-pane>

          <!-- 邀请推广设置 -->
        <el-tab-pane label="邀请设置" name="invite">
          <div class="pane-title">邀请返利与提现配置</div>
          <el-form :model="configData.invite" label-width="150px">
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="强制使用邀请码">
                  <el-switch v-model="configData.invite.invite_force" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="邀请码不过期">
                  <el-switch v-model="configData.invite.invite_never_expire" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="返利比例 (%)">
                  <el-input-number v-model="configData.invite.invite_commission" :min="0" :max="100" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="提现门槛 (元)">
                  <el-input-number v-model="configData.invite.commission_withdraw_limit" :min="1" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="关闭提现申请">
              <el-switch v-model="configData.invite.withdraw_close_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="提现渠道 (逗号隔开)">
              <el-input v-model="configData.invite.commission_withdraw_method" placeholder="如 微信,支付宝" />
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 节点通信配置 -->
        <el-tab-pane label="节点通信" name="server">
          <div class="pane-title">节点控制器与 API 配置</div>
          <el-form :model="configData.server" label-width="150px">
            <el-form-item label="核心 API URL">
              <el-input v-model="configData.server.server_api_url" placeholder="主要给节点后端调用，多域名用逗号分隔" />
            </el-form-item>
            <el-form-item label="通信密钥 (Token)">
              <el-input v-model="configData.server.server_token" placeholder="通信验证密钥，用于节点同步" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="下发拉取间隔">
                  <el-input-number v-model="configData.server.server_pull_interval" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="上传数据间隔">
                  <el-input-number v-model="configData.server.server_push_interval" :min="10" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>

        <!-- 邮件发送设置 -->
        <el-tab-pane label="邮件设置" name="email">
          <div class="pane-title">SMTP 发信配置</div>
          <el-form :model="configData.email" label-width="150px">
            <el-form-item label="发信地址 (From)">
              <el-input v-model="configData.email.email_from_address" placeholder="例如 service@tianque.com" />
            </el-form-item>
            <el-form-item label="SMTP 主机">
              <el-input v-model="configData.email.email_host" placeholder="smtp.qq.com" />
            </el-form-item>
            <el-form-item label="SMTP 端口">
              <el-input-number v-model="configData.email.email_port" :controls="false" style="width: 150px" />
            </el-form-item>
            <el-form-item label="SMTP 用户名">
              <el-input v-model="configData.email.email_username" />
            </el-form-item>
            <el-form-item label="SMTP 密码">
              <el-input v-model="configData.email.email_password" type="password" show-password />
            </el-form-item>
            <el-form-item label="加密协议">
              <el-select v-model="configData.email.email_encryption" style="width: 150px">
                <el-option label="SSL" value="ssl" />
                <el-option label="TLS" value="tls" />
                <el-option label="无加密" value="" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="warning" :loading="testMailLoading" @click="handleTestMail">发送测试邮件</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- Telegram 机器人 -->
        <el-tab-pane label="Telegram机器人" name="telegram">
          <div class="pane-title">Telegram 消息推送机器人</div>
          <el-form :model="configData.telegram" label-width="150px">
            <el-form-item label="启用机器人">
              <el-switch v-model="configData.telegram.telegram_bot_enable" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="机器人 Token">
              <el-input v-model="configData.telegram.telegram_bot_token" placeholder="输入从 BotFather 获取的 Token" />
            </el-form-item>
            <el-form-item label="群组/频道链接">
              <el-input v-model="configData.telegram.telegram_discuss_link" placeholder="例如 https://t.me/tianque" />
            </el-form-item>
            <el-form-item v-if="configData.telegram.telegram_bot_token">
              <el-button type="success" :loading="webhookLoading" @click="handleSetWebhook">设置 Webhook</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 客户端下载 -->
        <el-tab-pane label="客户端版本" name="app">
          <div class="pane-title">客户端下载与版本管理</div>
          <el-form :model="configData.app" label-width="150px">
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="Windows 版本">
                  <el-input v-model="configData.app.windows_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="Windows 链接">
                  <el-input v-model="configData.app.windows_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="Android 版本">
                  <el-input v-model="configData.app.android_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="Android 链接">
                  <el-input v-model="configData.app.android_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12">
                <el-form-item label="macOS 版本">
                  <el-input v-model="configData.app.macos_version" placeholder="1.0.0" />
                </el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="macOS 链接">
                  <el-input v-model="configData.app.macos_download_url" />
                </el-form-item>
              </el-col>
            </el-row>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage } from 'element-plus';

const loading = ref(false);
const submitLoading = ref(false);
const testMailLoading = ref(false);
const webhookLoading = ref(false);
const activeTab = ref('site');

const configData = reactive({
  site: {
    app_name: '',
    app_description: '',
    app_url: '',
    subscribe_url: '',
    logo: '',
    force_https: 0,
    stop_register: 0,
    try_out_plan_id: 0,
    try_out_hour: 1,
  },
  subscribe: {
    plan_change_enable: 1,
    reset_traffic_method: 0,
    surplus_enable: 1,
    allow_new_period: 0,
    show_info_to_server_enable: 0,
  },
  invite: {
    invite_force: 0,
    invite_commission: 10,
    commission_withdraw_limit: 100,
    invite_never_expire: 0,
    withdraw_close_enable: 0,
    commission_withdraw_method: '微信,支付宝',
  },
  server: {
    server_api_url: '',
    server_token: '',
    server_pull_interval: 60,
    server_push_interval: 60,
  },
  email: {
    email_from_address: '',
    email_host: '',
    email_port: 465,
    email_username: '',
    email_password: '',
    email_encryption: 'ssl',
  },
  telegram: {
    telegram_bot_enable: 0,
    telegram_bot_token: '',
    telegram_discuss_link: '',
  },
  app: {
    windows_version: '',
    windows_download_url: '',
    android_version: '',
    android_download_url: '',
    macos_version: '',
    macos_download_url: '',
  }
});

const fetchConfig = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/config/fetch`);
    if (res.data) {
      // Map structures
      Object.keys(res.data).forEach(key => {
        if (configData[key] !== undefined) {
          Object.assign(configData[key], res.data[key]);
        }
      });
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleSave = async () => {
  submitLoading.value = true;
  try {
    const securePath = getSecurePath();
    
    // Construct single flat object for save (ConfigSave Laravel validation format)
    const payload = {};
    Object.keys(configData).forEach(section => {
      Object.keys(configData[section]).forEach(key => {
        payload[key] = configData[section][key];
      });
    });

    await api.post(`/${securePath}/config/save`, payload);
    ElMessage.success('配置更新成功，已写入系统！');
  } catch (err) {
    console.error(err);
  } finally {
    submitLoading.value = false;
  }
};

const handleTestMail = async () => {
  testMailLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/config/testSendMail`);
    ElMessage.success('测试邮件发送成功，请前往您的管理员邮箱查收！');
  } catch (err) {
    console.error(err);
  } finally {
    testMailLoading.value = false;
  }
};

const handleSetWebhook = async () => {
  webhookLoading.value = true;
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/config/setTelegramWebhook`, {
      telegram_bot_token: configData.telegram.telegram_bot_token
    });
    ElMessage.success('Telegram Webhook 设置成功！');
  } catch (err) {
    console.error(err);
  } finally {
    webhookLoading.value = false;
  }
};

onMounted(() => {
  fetchConfig();
});
</script>

<style scoped>
.settings-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.settings-title {
  font-size: 15px;
  font-weight: 600;
}

.pane-title {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 25px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--el-border-color-extra-light);
  color: var(--el-text-color-primary);
}

.form-tip {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  line-height: 1.4;
  margin-top: 5px;
  width: 100%;
}

.el-tab-pane {
  padding-right: 20px;
}
</style>
