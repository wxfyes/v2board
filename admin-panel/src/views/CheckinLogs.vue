<template>
  <div class="app-container">
    <!-- 顶部统计指标卡片 -->
    <el-row :gutter="16" class="stat-cards">
      <el-col :xs="12" :sm="6">
        <el-card shadow="hover" class="stat-card stat-today">
          <div class="stat-content">
            <div class="stat-info">
              <div class="stat-label">今日签到人数</div>
              <div class="stat-value text-primary">
                {{ statistics.today_user_count || 0 }} <span class="stat-unit">人</span>
              </div>
            </div>
            <div class="stat-icon-wrap primary">
              <el-icon><Calendar /></el-icon>
            </div>
          </div>
          <div class="stat-sub">
            <span>较昨日: {{ statistics.yesterday_user_count || 0 }} 人</span>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="12" :sm="6">
        <el-card shadow="hover" class="stat-card stat-traffic">
          <div class="stat-content">
            <div class="stat-info">
              <div class="stat-label">今日赠送流量</div>
              <div class="stat-value text-success">
                {{ statistics.today_traffic_formatted || '0 B' }}
              </div>
            </div>
            <div class="stat-icon-wrap success">
              <el-icon><Present /></el-icon>
            </div>
          </div>
          <div class="stat-sub">
            <span>昨日赠送: {{ statistics.yesterday_traffic_formatted || '0 B' }}</span>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="12" :sm="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-info">
              <div class="stat-label">本月签到人次</div>
              <div class="stat-value text-warning">
                {{ statistics.month_user_count || 0 }} <span class="stat-unit">次</span>
              </div>
            </div>
            <div class="stat-icon-wrap warning">
              <el-icon><Histogram /></el-icon>
            </div>
          </div>
          <div class="stat-sub">
            <span>本月累计送: {{ statistics.month_traffic_formatted || '0 B' }}</span>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="12" :sm="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-info">
              <div class="stat-label">历史累计赠送</div>
              <div class="stat-value text-info">
                {{ statistics.total_traffic_formatted || '0 B' }}
              </div>
            </div>
            <div class="stat-icon-wrap info">
              <el-icon><Coin /></el-icon>
            </div>
          </div>
          <div class="stat-sub">
            <span>累计签到: {{ statistics.total_count || 0 }} 人次</span>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 主数据卡片 -->
    <el-card class="box-card" style="margin-top: 16px;">
      <template #header>
        <div class="card-header">
          <div class="header-title">
            <el-icon><Calendar /></el-icon>
            <span>每日打卡签到记录明细</span>
            <el-tag size="small" type="info" style="margin-left: 8px;">共 {{ total }} 条记录</el-tag>
          </div>
          <div class="header-actions">
            <el-button size="small" icon="Refresh" @click="fetchList">刷新</el-button>
          </div>
        </div>
      </template>

      <!-- 搜索过滤栏 -->
      <div class="filter-container">
        <el-input
          v-model="listQuery.email"
          placeholder="搜索用户邮箱"
          style="width: 200px; margin-right: 10px; margin-bottom: 8px;"
          clearable
          @keyup.enter="handleFilter"
        />
        <el-input
          v-model="listQuery.user_id"
          placeholder="UID"
          style="width: 110px; margin-right: 10px; margin-bottom: 8px;"
          clearable
          @keyup.enter="handleFilter"
        />
        <el-date-picker
          v-model="listQuery.date"
          type="date"
          placeholder="选择签到日期"
          format="YYYY-MM-DD"
          value-format="YYYY-MM-DD"
          style="width: 160px; margin-right: 10px; margin-bottom: 8px;"
          clearable
          @change="handleFilter"
        />

        <div class="btn-group">
          <el-button type="primary" icon="Search" @click="handleFilter">查询</el-button>
          <el-button icon="Refresh" @click="resetFilter">重置</el-button>
          <el-button-group style="margin-left: 6px;">
            <el-button
              size="default"
              :type="listQuery.date === todayDate ? 'success' : 'default'"
              @click="setQuickDate(todayDate)"
            >
              今日
            </el-button>
            <el-button
              size="default"
              :type="listQuery.date === yesterdayDate ? 'success' : 'default'"
              @click="setQuickDate(yesterdayDate)"
            >
              昨日
            </el-button>
          </el-button-group>
        </div>
      </div>

      <!-- 单用户筛选提示横幅 -->
      <div v-if="filteredUser" class="filter-alert-banner">
        <span>🔍 正在查看用户 <strong>{{ filteredUser.email }} (UID: {{ filteredUser.id }})</strong> 的所有签到记录</span>
        <el-button size="small" type="primary" text icon="Close" @click="resetFilter">清除筛选</el-button>
      </div>

      <!-- 表格数据 -->
      <el-table
        v-loading="listLoading"
        :data="list"
        border
        stripe
        style="width: 100%; margin-top: 12px;"
      >
        <el-table-column prop="id" label="ID" width="80" align="center" />

        <el-table-column label="签到时间 (精确到分秒)" min-width="170">
          <template #default="{ row }">
            <div class="time-cell">
              <el-icon class="time-icon"><Clock /></el-icon>
              <span class="time-text">{{ row.created_at_formatted }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="用户账号 / UID" min-width="220">
          <template #default="{ row }">
            <div class="user-cell">
              <span
                class="user-email clickable"
                title="点击仅查看该用户的签到记录"
                @click="filterByUser(row.user_id, row.email)"
              >
                {{ row.email }}
              </span>
              <el-tag size="small" type="info" class="uid-tag">UID: {{ row.user_id }}</el-tag>
              <el-tag v-if="row.telegram_id" size="small" type="success" effect="plain" class="tg-tag">
                TG已绑
              </el-tag>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="当时套餐" min-width="160">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{ row.plan_name }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="获得流量奖励" min-width="140" align="center">
          <template #default="{ row }">
            <el-tag size="default" type="success" effect="dark" class="traffic-tag">
              +{{ row.traffic_formatted }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="checkin_date" label="签到归属日" width="120" align="center">
          <template #default="{ row }">
            <span class="date-badge">{{ row.checkin_date }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="120" align="center">
          <template #default="{ row }">
            <el-button
              size="small"
              type="primary"
              link
              @click="filterByUser(row.user_id, row.email)"
            >
              TA的签到
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页组件 -->
      <div class="pagination-container">
        <el-pagination
          v-model:current-page="listQuery.current"
          v-model:page-size="listQuery.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import api, { getSecurePath } from '../api';
import {
  Calendar,
  Present,
  Histogram,
  Coin,
  Clock,
} from '@element-plus/icons-vue';

const listLoading = ref(false);
const list = ref([]);
const total = ref(0);

const todayDate = computed(() => {
  const d = new Date();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${month}-${day}`;
});

const yesterdayDate = computed(() => {
  const d = new Date();
  d.setDate(d.getDate() - 1);
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${month}-${day}`;
});

const statistics = reactive({
  today_date: '',
  today_user_count: 0,
  today_traffic: 0,
  today_traffic_formatted: '0 B',
  yesterday_user_count: 0,
  yesterday_traffic_formatted: '0 B',
  month_user_count: 0,
  month_traffic: 0,
  month_traffic_formatted: '0 B',
  total_count: 0,
  total_traffic: 0,
  total_traffic_formatted: '0 B',
});

const listQuery = reactive({
  current: 1,
  pageSize: 20,
  email: '',
  user_id: '',
  date: '',
});

const filteredUser = ref(null);

const fetchList = async () => {
  listLoading.value = true;
  try {
    const securePath = getSecurePath();
    const params = {
      current: listQuery.current,
      pageSize: listQuery.pageSize,
    };
    if (listQuery.email) params.email = listQuery.email.trim();
    if (listQuery.user_id) params.user_id = listQuery.user_id;
    if (listQuery.date) params.date = listQuery.date;

    const res = await api.get(`/${securePath}/checkin/fetch`, { params });
    if (res?.data) {
      list.value = res.data;
      total.value = res.total || 0;
      if (res.statistics) {
        Object.assign(statistics, res.statistics);
      }
    }
  } catch (err) {
    console.error('获取每日签到记录失败:', err);
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  listQuery.current = 1;
  fetchList();
};

const resetFilter = () => {
  listQuery.email = '';
  listQuery.user_id = '';
  listQuery.date = '';
  filteredUser.value = null;
  listQuery.current = 1;
  fetchList();
};

const setQuickDate = (dateStr) => {
  if (listQuery.date === dateStr) {
    listQuery.date = '';
  } else {
    listQuery.date = dateStr;
  }
  handleFilter();
};

const filterByUser = (userId, email) => {
  listQuery.user_id = userId;
  listQuery.email = '';
  filteredUser.value = { id: userId, email };
  handleFilter();
};

onMounted(() => {
  fetchList();
});
</script>

<style scoped>
.app-container {
  padding: 16px;
}

.stat-cards .stat-card {
  border-radius: 12px;
  border: 1px solid var(--el-border-color-light);
  margin-bottom: 12px;
}

.stat-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.stat-info {
  flex: 1;
}

.stat-label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-bottom: 6px;
}

.stat-value {
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
}

.stat-unit {
  font-size: 13px;
  font-weight: 500;
  color: var(--el-text-color-secondary);
  margin-left: 2px;
}

.stat-sub {
  margin-top: 8px;
  padding-top: 6px;
  border-top: 1px dashed var(--el-border-color-extra-light);
  font-size: 11px;
  color: var(--el-text-color-placeholder);
}

.stat-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
}

.stat-icon-wrap.primary {
  background: rgba(64, 158, 255, 0.12);
  color: #409eff;
}

.stat-icon-wrap.success {
  background: rgba(103, 194, 58, 0.12);
  color: #67c23a;
}

.stat-icon-wrap.warning {
  background: rgba(230, 162, 60, 0.12);
  color: #e6a23c;
}

.stat-icon-wrap.info {
  background: rgba(144, 147, 153, 0.12);
  color: #909399;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: bold;
  font-size: 15px;
}

.filter-container {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.btn-group {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.filter-alert-banner {
  padding: 8px 14px;
  margin-bottom: 12px;
  background-color: var(--el-color-primary-light-9);
  border: 1px solid var(--el-color-primary-light-7);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  color: var(--el-color-primary-dark-2);
}

.time-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.time-icon {
  color: var(--el-color-info);
  font-size: 14px;
}

.time-text {
  font-family: monospace;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.user-cell {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.user-email.clickable {
  font-weight: 600;
  color: var(--el-color-primary);
  cursor: pointer;
  text-decoration: underline text-decoration-style dashed;
}

.user-email.clickable:hover {
  color: var(--el-color-primary-light-3);
}

.uid-tag {
  font-size: 11px;
}

.traffic-tag {
  font-weight: bold;
  letter-spacing: 0.5px;
}

.date-badge {
  font-size: 12px;
  color: var(--el-text-color-regular);
}

.pagination-container {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
