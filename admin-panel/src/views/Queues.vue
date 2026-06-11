<template>
  <div class="queues-container">
    <div class="header-section flex-between">
      <div class="title-group">
        <span class="page-title-text">异步队列监控</span>
        <span class="page-subtitle-text">实时监控系统的任务队列、守护进程以及任务执行状态</span>
      </div>
      <div class="controls-group flex-center">
        <el-select v-model="refreshInterval" size="default" style="width: 120px" @change="setupInterval">
          <el-option label="手动刷新" :value="0" />
          <el-option label="每 5 秒刷新" :value="5000" />
          <el-option label="每 10 秒刷新" :value="10000" />
          <el-option label="每 30 秒刷新" :value="30000" />
        </el-select>
        <el-button type="primary" :loading="loading" @click="fetchData">
          <el-icon><Refresh /></el-icon>
          <span>立即刷新</span>
        </el-button>
      </div>
    </div>

    <!-- Status Cards Grid -->
    <el-row :gutter="20" class="mt-20">
      <el-col :span="24" :md="6">
        <el-card class="stat-card" shadow="hover" v-loading="loading">
          <div class="flex-between align-center">
            <div>
              <div class="stat-title">Horizon 状态</div>
              <div class="stat-value" :class="stats.status ? 'text-success' : 'text-danger'">
                {{ stats.status ? '运行中' : '未启动' }}
              </div>
            </div>
            <div class="stat-icon-wrapper" :class="stats.status ? 'bg-success-light' : 'bg-danger-light'">
              <el-icon :class="stats.status ? 'text-success' : 'text-danger'">
                <CircleCheck v-if="stats.status" />
                <Warning v-else />
              </el-icon>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="24" :md="6">
        <el-card class="stat-card" shadow="hover" v-loading="loading">
          <div class="flex-between align-center">
            <div>
              <div class="stat-title">总活动进程数</div>
              <div class="stat-value">{{ stats.processes }}</div>
            </div>
            <div class="stat-icon-wrapper bg-primary-light">
              <el-icon class="text-primary"><Cpu /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="24" :md="6">
        <el-card class="stat-card" shadow="hover" v-loading="loading">
          <div class="flex-between align-center">
            <div>
              <div class="stat-title">最近任务数 / 失败数</div>
              <div class="stat-value">
                <span>{{ stats.recentJobs }}</span>
                <span class="text-separator">/</span>
                <span :class="stats.failedJobs > 0 ? 'text-danger font-semibold' : 'text-muted'">{{ stats.failedJobs }}</span>
              </div>
            </div>
            <div class="stat-icon-wrapper" :class="stats.failedJobs > 0 ? 'bg-danger-light' : 'bg-warning-light'">
              <el-icon :class="stats.failedJobs > 0 ? 'text-danger' : 'text-warning'"><Odometer /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="24" :md="6">
        <el-card class="stat-card" shadow="hover" v-loading="loading">
          <div class="flex-between align-center">
            <div>
              <div class="stat-title">每分钟处理任务</div>
              <div class="stat-value">{{ stats.jobsPerMinute }}</div>
            </div>
            <div class="stat-icon-wrapper bg-info-light">
              <el-icon class="text-info"><Timer /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Workload & Max Run Info -->
    <el-row :gutter="20" class="mt-20">
      <el-col :span="24" :lg="16">
        <el-card class="workload-card" shadow="hover">
          <template #header>
            <div class="flex-between align-center">
              <span class="section-title-text">当前队列负载</span>
              <el-tag type="info" size="small">实时刷新</el-tag>
            </div>
          </template>

          <el-table :data="workloads" v-loading="loading" stripe style="width: 100%">
            <el-table-column prop="name" label="队列名称" min-width="150" />
            <el-table-column prop="processes" label="已分配进程数" width="130" align="center" />
            <el-table-column prop="length" label="待处理任务数 (排队中)" width="180" align="center">
              <template #default="scope">
                <el-tag :type="scope.row.length > 50 ? 'danger' : (scope.row.length > 10 ? 'warning' : 'info')" size="small">
                  {{ scope.row.length }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="wait" label="预计等待时间" min-width="150">
              <template #default="scope">
                <span>{{ formatWaitTime(scope.row.wait) }}</span>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <el-col :span="24" :lg="8">
        <el-card class="info-card" shadow="hover" v-loading="loading">
          <template #header>
            <span class="section-title-text">吞吐与运行峰值</span>
          </template>

          <div class="info-list">
            <div class="info-item flex-between">
              <span class="info-label">最高吞吐量队列</span>
              <span class="info-val">{{ stats.queueWithMaxThroughput || '无数据' }}</span>
            </div>
            <div class="info-item flex-between">
              <span class="info-label">最大执行时长队列</span>
              <span class="info-val">{{ stats.queueWithMaxRuntime || '无数据' }}</span>
            </div>
            <div class="info-item flex-between">
              <span class="info-label">暂停的 Master</span>
              <span class="info-val" :class="stats.pausedMasters > 0 ? 'text-warning' : 'text-muted'">
                {{ stats.pausedMasters }}
              </span>
            </div>
            <div class="info-item flex-between">
              <span class="info-label">最近任务修剪期</span>
              <span class="info-val">{{ stats.periods?.recentJobs ? `${stats.periods.recentJobs / 60} 分钟` : '-' }}</span>
            </div>
            <div class="info-item flex-between">
              <span class="info-label">失败任务修剪期</span>
              <span class="info-val">{{ stats.periods?.failedJobs ? `${stats.periods.failedJobs / 60} 分钟` : '-' }}</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';

const loading = ref(false);
const refreshInterval = ref(5000); // default 5s
let timerId = null;

const stats = reactive({
  failedJobs: 0,
  jobsPerMinute: 0,
  pausedMasters: 0,
  periods: {
    failedJobs: 0,
    recentJobs: 0
  },
  processes: 0,
  queueWithMaxRuntime: '',
  queueWithMaxThroughput: '',
  recentJobs: 0,
  status: false,
  wait: []
});

const workloads = ref([]);

const fetchData = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    
    // Fetch Queue Stats
    const statsRes = await api.get(`/${securePath}/system/getQueueStats`);
    if (statsRes.data) {
      Object.assign(stats, statsRes.data);
    }
    
    // Fetch Queue Workloads
    const workloadRes = await api.get(`/${securePath}/system/getQueueWorkload`);
    if (workloadRes.data) {
      workloads.value = workloadRes.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const formatWaitTime = (seconds) => {
  if (seconds === null || seconds === undefined || seconds === 0) return '无延迟';
  if (seconds < 60) return `${seconds} 秒`;
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  return secs > 0 ? `${mins} 分 ${secs} 秒` : `${mins} 分钟`;
};

const setupInterval = () => {
  if (timerId) {
    clearInterval(timerId);
    timerId = null;
  }
  if (refreshInterval.value > 0) {
    timerId = setInterval(fetchData, refreshInterval.value);
  }
};

onMounted(() => {
  fetchData();
  setupInterval();
});

onUnmounted(() => {
  if (timerId) {
    clearInterval(timerId);
  }
});
</script>

<style scoped>
.header-section {
  align-items: center;
}

.title-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.page-title-text {
  font-size: 20px;
  font-weight: 700;
  color: var(--el-text-color-primary);
}

.page-subtitle-text {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.controls-group {
  gap: 12px;
}

.stat-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
  padding: 5px;
}

.stat-title {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-bottom: 8px;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--el-text-color-primary);
}

.text-separator {
  margin: 0 6px;
  color: var(--el-border-color);
  font-weight: 400;
}

.stat-icon-wrapper {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
}

.workload-card, .info-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.section-title-text {
  font-size: 15px;
  font-weight: 600;
}

.info-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.info-item {
  border-bottom: 1px solid var(--el-border-color-extra-light);
  padding-bottom: 12px;
}

.info-item:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

.info-label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.info-val {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
}

/* Theme color modifications */
.bg-success-light { background-color: rgba(103, 194, 58, 0.1); }
.bg-danger-light { background-color: rgba(245, 108, 108, 0.1); }
.bg-primary-light { background-color: rgba(64, 158, 255, 0.1); }
.bg-warning-light { background-color: rgba(230, 162, 60, 0.1); }
.bg-info-light { background-color: rgba(144, 147, 153, 0.1); }

.text-success { color: var(--el-color-success); }
.text-danger { color: var(--el-color-danger); }
.text-primary { color: var(--el-color-primary); }
.text-warning { color: var(--el-color-warning); }
.text-info { color: var(--el-color-info); }

.mt-20 { margin-top: 20px; }
.font-semibold { font-weight: 600; }
</style>
