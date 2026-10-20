<template>
  <div class="app-container">
    <el-card class="box-card">
      <template #header>
        <div class="card-header">
          <div class="header-left">
            <span class="title">🛡️ 入侵感知防御系统</span>
            <el-tag type="danger" effect="dark" size="small" style="margin-left: 10px;">实时威胁感知</el-tag>
          </div>
          <div class="header-right">
            <el-button type="danger" plain icon="Lock" @click="openBlacklistDrawer">黑名单库 ({{ stats.blacklist_count || 0 }})</el-button>
            <el-button type="info" plain icon="Delete" @click="handleDropLogs">清空旧日志</el-button>
            <el-button type="primary" icon="Refresh" @click="fetchData">刷新</el-button>
          </div>
        </div>
      </template>

      <!-- 统计指标卡片 -->
      <el-row :gutter="16" class="stats-row">
        <el-col :xs="24" :sm="8">
          <div class="stat-card stat-today">
            <div class="stat-icon">🚨</div>
            <div class="stat-info">
              <div class="stat-label">今日防御拦截</div>
              <div class="stat-value text-danger">{{ stats.today_count || 0 }} <span class="unit">次</span></div>
            </div>
          </div>
        </el-col>
        <el-col :xs="24" :sm="8">
          <div class="stat-card stat-total">
            <div class="stat-icon">🛡️</div>
            <div class="stat-info">
              <div class="stat-label">累计恶意阻断</div>
              <div class="stat-value text-primary">{{ stats.total_blocked || 0 }} <span class="unit">次</span></div>
            </div>
          </div>
        </el-col>
        <el-col :xs="24" :sm="8">
          <div class="stat-card stat-blacklist">
            <div class="stat-icon">🚫</div>
            <div class="stat-info">
              <div class="stat-label">永久封禁黑名单</div>
              <div class="stat-value text-warning">{{ stats.blacklist_count || 0 }} <span class="unit">个</span></div>
            </div>
          </div>
        </el-col>
      </el-row>

      <!-- 搜索筛选栏 -->
      <div class="filter-container">
        <el-input v-model="listQuery.ip" placeholder="搜索攻击者 IP" style="width: 180px; margin-right: 10px; margin-bottom: 8px;" clearable @keyup.enter="handleFilter" />
        <el-select v-model="listQuery.status_code" placeholder="拦截状态码" clearable style="width: 130px; margin-right: 10px; margin-bottom: 8px;">
          <el-option label="200 OK" :value="200" />
          <el-option label="403 Forbidden" :value="403" />
          <el-option label="404 Not Found" :value="404" />
          <el-option label="500 Error" :value="500" />
        </el-select>
        <el-select v-model="listQuery.rule_name" placeholder="事件类型" clearable style="width: 170px; margin-right: 10px; margin-bottom: 8px;">
          <el-option label="特权管理登录" value="特权管理登录" />
          <el-option label="类型混淆与越权探测" value="类型混淆与越权探测" />
          <el-option label="恶意探针扫描" value="恶意探针扫描" />
          <el-option label="敏感文件与漏洞扫描" value="敏感文件与漏洞扫描" />
          <el-option label="黑名单IP拦截" value="黑名单IP拦截" />
        </el-select>
        <el-input v-model="listQuery.keyword" placeholder="搜索关键词 / URL / 规则" style="width: 220px; margin-right: 10px; margin-bottom: 8px;" clearable @keyup.enter="handleFilter" />
        <el-button type="primary" icon="Search" style="margin-bottom: 8px;" @click="handleFilter">查询</el-button>
        <el-button icon="RefreshRight" style="margin-bottom: 8px;" @click="resetFilter">重置</el-button>
      </div>

      <!-- 入侵记录表格 -->
      <el-table v-loading="listLoading" :data="list" border fit highlight-current-row style="width: 100%; margin-top: 10px;">
        <el-table-column label="时间" width="165" align="center">
          <template #default="{ row }">
            <span>{{ formatTime(row.created_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="威胁源 IP / 归属地" min-width="210">
          <template #default="{ row }">
            <div class="ip-cell">
              <span class="ip-text">{{ row.ip }}</span>
              <el-tag size="small" type="info" class="loc-tag">{{ row.location || '未知归属' }}</el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="事件类型" width="160" align="center">
          <template #default="{ row }">
            <el-tag :type="row.rule_name && (row.rule_name.includes('登录') || row.rule_name.includes('特权')) ? 'success' : (row.rule_name && row.rule_name.includes('越权') ? 'danger' : 'warning')" size="small">
              {{ row.rule_name || '异常探测' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="攻击请求目标 (URL)" min-width="280">
          <template #default="{ row }">
            <div class="url-cell" :title="row.url">
              <el-tag size="small" type="primary" style="margin-right: 6px;">{{ row.method }}</el-tag>
              <span class="url-text">{{ row.url }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.status_code === 200 ? 'success' : (row.status_code === 403 ? 'danger' : (row.status_code === 404 ? 'warning' : 'info'))" effect="dark" size="small">
              {{ row.status_code }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="耗时" width="80" align="center">
          <template #default="{ row }">
            <span class="duration-text">{{ row.duration || 1 }}ms</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="190" align="center" fixed="right">
          <template #default="{ row, $index }">
            <el-button size="small" type="success" plain @click="openDetail(row, $index)">详情</el-button>
            <el-button
              v-if="!row.is_banned"
              size="small"
              type="danger"
              @click="handleBanIp(row.ip)"
            >
              一键封禁
            </el-button>
            <el-tag v-else type="info" size="small" style="margin-left: 8px;">已在黑名单</el-tag>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页组件 -->
      <div class="pagination-container">
        <el-pagination
          v-model:current-page="listQuery.page"
          v-model:page-size="listQuery.page_size"
          :total="total"
          :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <!-- 图2风格的高仿真威胁详情弹窗 -->
    <el-dialog
      v-model="detailVisible"
      :title="`【${currentDetail.ip || ''}】详情信息 -- ${formatTime(currentDetail.created_at)}`"
      width="680px"
      custom-class="safeline-style-dialog"
      destroy-on-close
    >
      <div class="detail-container" v-if="currentDetail">
        <div class="detail-row">
          <div class="detail-item full-width">
            <span class="label">客户端IP</span>
            <div class="value highlight-ip">
              {{ currentDetail.ip }} <span class="ip-geo">({{ currentDetail.location || '未知归属' }})</span>
            </div>
          </div>
        </div>

        <div class="detail-grid">
          <div class="detail-item">
            <span class="label">类型</span>
            <div class="value">{{ currentDetail.method }}</div>
          </div>
          <div class="detail-item">
            <span class="label">状态码</span>
            <div class="value text-danger font-bold">{{ currentDetail.status_code }}</div>
          </div>
          <div class="detail-item">
            <span class="label">响应类型</span>
            <div class="value">application/json</div>
          </div>
          <div class="detail-item">
            <span class="label">处理耗时</span>
            <div class="value">{{ currentDetail.duration || 1 }} ms</div>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item full-width">
            <span class="label">URL</span>
            <div class="value code-box">{{ currentDetail.url }}</div>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item full-width">
            <span class="label">User-Agent</span>
            <div class="value ua-box">{{ currentDetail.user_agent || '-' }}</div>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item full-width">
            <span class="label">防御命中规则</span>
            <div class="value text-warning font-bold">{{ currentDetail.rule_name || '入侵感知防御' }}</div>
          </div>
        </div>

        <div class="detail-row">
          <div class="detail-item full-width">
            <span class="label">IP详情</span>
            <div class="value location-box">
              位置：{{ currentDetail.location || '未知地理信息' }}
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="dialog-footer">
          <div class="footer-left">
            <el-button
              type="danger"
              icon="Lock"
              :disabled="currentDetail.is_banned"
              @click="handleBanIp(currentDetail.ip)"
            >
              {{ currentDetail.is_banned ? '该 IP 已封禁' : '一键永久封禁此 IP' }}
            </el-button>
          </div>
          <div class="footer-right">
            <el-button :disabled="currentIndex <= 0" @click="prevDetail">上一条</el-button>
            <el-button type="success" :disabled="currentIndex >= list.length - 1" @click="nextDetail">下一条</el-button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- 黑名单抽屉 -->
    <el-drawer v-model="blacklistVisible" title="🚫 永久封禁 IP 黑名单库" size="620px">
      <div class="blacklist-container">
        <div class="manual-ban-box">
          <el-input v-model="manualIp" placeholder="输入要封禁的 IP 地址 (IPv4 / IPv6)" style="width: 260px; margin-right: 10px;" />
          <el-input v-model="manualReason" placeholder="封禁备注原因" style="width: 180px; margin-right: 10px;" />
          <el-button type="danger" icon="Plus" @click="handleManualBan">添加封禁</el-button>
        </div>

        <el-table :data="blacklist" border style="width: 100%; margin-top: 15px;">
          <el-table-column prop="ip" label="已封禁 IP" min-width="160" />
          <el-table-column prop="reason" label="封禁原因" min-width="150" />
          <el-table-column label="封禁时间" width="165" align="center">
            <template #default="{ row }">
              <span>{{ formatTime(row.created_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="100" align="center">
            <template #default="{ row }">
              <el-button type="success" size="small" plain @click="handleUnbanIp(row.ip)">解封</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import axios from 'axios';
import { getSecurePath } from '../api';

const api = axios.create({
  baseURL: '/api/v1',
  timeout: 15000,
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('authorization');
  if (token) {
    config.headers['Authorization'] = token;
  }
  return config;
});

api.interceptors.response.use(
  (res) => res.data,
  (err) => Promise.reject(err)
);

const listLoading = ref(false);
const list = ref([]);
const total = ref(0);
const stats = reactive({
  today_count: 0,
  total_blocked: 0,
  blacklist_count: 0,
});

const listQuery = reactive({
  page: 1,
  page_size: 15,
  ip: '',
  status_code: null,
  rule_name: '',
  keyword: '',
});

// 详情弹窗相关
const detailVisible = ref(false);
const currentDetail = ref({});
const currentIndex = ref(0);

// 黑名单管理相关
const blacklistVisible = ref(false);
const blacklist = ref([]);
const manualIp = ref('');
const manualReason = ref('');

const formatTime = (ts) => {
  if (!ts) return '-';
  const d = new Date(ts * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
};

const fetchData = async () => {
  listLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/security/logs`, {
      params: {
        page: listQuery.page,
        page_size: listQuery.page_size,
        ip: listQuery.ip,
        status_code: listQuery.status_code,
        rule_name: listQuery.rule_name,
        keyword: listQuery.keyword,
      },
    });
    list.value = res.data || [];
    total.value = res.total || 0;

    // 拉取大盘统计
    const statsRes = await api.get(`/${securePath}/security/stats`);
    if (statsRes.data) {
      Object.assign(stats, statsRes.data);
    }
  } catch (error) {
    ElMessage.error('获取入侵感知记录失败');
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  listQuery.page = 1;
  fetchData();
};

const resetFilter = () => {
  listQuery.page = 1;
  listQuery.ip = '';
  listQuery.status_code = null;
  listQuery.rule_name = '';
  listQuery.keyword = '';
  fetchData();
};

const handleSizeChange = (val) => {
  listQuery.page_size = val;
  fetchData();
};

const handleCurrentChange = (val) => {
  listQuery.page = val;
  fetchData();
};

// 详情弹窗逻辑
const openDetail = (row, index) => {
  currentDetail.value = { ...row };
  currentIndex.value = index;
  detailVisible.value = true;
};

const prevDetail = () => {
  if (currentIndex.value > 0) {
    currentIndex.value -= 1;
    currentDetail.value = { ...list.value[currentIndex.value] };
  }
};

const nextDetail = () => {
  if (currentIndex.value < list.value.length - 1) {
    currentIndex.value += 1;
    currentDetail.value = { ...list.value[currentIndex.value] };
  }
};

// 一键封禁逻辑
const handleBanIp = (ip) => {
  ElMessageBox.confirm(
    `确定要将 IP 【${ip}】 加入永久封禁黑名单吗？封禁后该 IP 将无法连接此服务器任何服务！`,
    '高危封禁确认',
    {
      confirmButtonText: '确定封禁',
      cancelButtonText: '取消',
      type: 'warning',
      confirmButtonClass: 'el-button--danger',
    }
  ).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/security/ban`, {
        ip: ip,
        reason: '入侵感知后台一键封禁',
      });
      ElMessage.success(`IP 【${ip}】 封禁成功，已生效！`);
      fetchData();
      if (currentDetail.value && currentDetail.value.ip === ip) {
        currentDetail.value.is_banned = true;
      }
    } catch (e) {
      ElMessage.error(e.response?.data?.message || '封禁操作失败');
    }
  }).catch(() => {});
};

// 黑名单管理抽屉
const openBlacklistDrawer = async () => {
  blacklistVisible.value = true;
  fetchBlacklist();
};

const fetchBlacklist = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/security/blacklist`);
    blacklist.value = res.data || [];
  } catch (e) {
    ElMessage.error('加载黑名单库失败');
  }
};

const handleManualBan = async () => {
  if (!manualIp.value) {
    ElMessage.warning('请输入 IP 地址');
    return;
  }
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/security/ban`, {
      ip: manualIp.value.trim(),
      reason: manualReason.value.trim() || '后台手动添加',
    });
    ElMessage.success('添加封禁成功');
    manualIp.value = '';
    manualReason.value = '';
    fetchBlacklist();
    fetchData();
  } catch (e) {
    ElMessage.error(e.response?.data?.message || '添加失败');
  }
};

const handleUnbanIp = async (ip) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/security/unban`, { ip });
    ElMessage.success(`IP 【${ip}】 已成功解除封禁`);
    fetchBlacklist();
    fetchData();
  } catch (e) {
    ElMessage.error('解除封禁失败');
  }
};

const handleDropLogs = () => {
  ElMessageBox.confirm(
    '确定要清空 7 天前的入侵感知旧记录以释放空间吗？',
    '清理日志',
    {
      confirmButtonText: '确定清理',
      cancelButtonText: '取消',
      type: 'info',
    }
  ).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/security/dropLogs`);
      ElMessage.success('旧日志已成功清理');
      fetchData();
    } catch (e) {
      ElMessage.error('清理失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchData();
});
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.title {
  font-size: 18px;
  font-weight: bold;
}
.stats-row {
  margin-bottom: 18px;
}
.stat-card {
  display: flex;
  align-items: center;
  padding: 16px 20px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  transition: all 0.3s;
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.stat-icon {
  font-size: 32px;
  margin-right: 16px;
}
.stat-label {
  font-size: 13px;
  color: #64748b;
  margin-bottom: 4px;
}
.stat-value {
  font-size: 24px;
  font-weight: bold;
}
.stat-value .unit {
  font-size: 13px;
  font-weight: normal;
  color: #94a3b8;
}
.text-danger { color: #ef4444; }
.text-primary { color: #3b82f6; }
.text-warning { color: #f59e0b; }
.font-bold { font-weight: bold; }

.ip-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ip-text {
  font-family: monospace;
  font-weight: 600;
  color: #1e293b;
}
.loc-tag {
  width: fit-content;
}
.url-cell {
  display: flex;
  align-items: center;
}
.url-text {
  font-family: monospace;
  font-size: 13px;
  color: #0f172a;
  word-break: break-all;
}
.duration-text {
  font-size: 12px;
  color: #64748b;
}

/* 图2高仿详情弹窗样式 */
.detail-container {
  padding: 6px 12px;
}
.detail-row {
  margin-bottom: 14px;
}
.detail-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 14px;
}
.detail-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.detail-item .label {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}
.detail-item .value {
  font-size: 14px;
  color: #1e293b;
  word-break: break-all;
}
.highlight-ip {
  font-size: 16px;
  font-weight: bold;
  font-family: monospace;
  color: #059669;
}
.ip-geo {
  font-size: 13px;
  color: #4b5563;
  font-weight: normal;
}
.code-box {
  background: #f1f5f9;
  padding: 8px 12px;
  border-radius: 6px;
  font-family: monospace;
  color: #b91c1c;
  font-size: 13px;
}
.ua-box {
  background: #f8fafc;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 12px;
  color: #475569;
  border: 1px solid #e2e8f0;
}
.location-box {
  background: #ecfdf5;
  color: #065f46;
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid #a7f3d0;
}

.dialog-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
}
.manual-ban-box {
  display: flex;
  align-items: center;
  padding-bottom: 12px;
  border-bottom: 1px solid #e2e8f0;
}
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
