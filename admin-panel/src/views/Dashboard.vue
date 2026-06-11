<template>
  <div class="dashboard-container">
    <!-- Pending Commission Warning Banner -->
    <el-row :gutter="16" class="mb-20" v-if="overrideData.commission_pending_total > 0">
      <el-col :span="24">
        <el-alert
          title="返佣佣金待确认提醒"
          type="warning"
          :closable="false"
          show-icon
          style="border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); padding: 16px;"
        >
          <template #default>
            <div class="flex-between flex-wrap gap-10" style="margin-top: 8px;">
              <span style="font-size: 13px; line-height: 1.5;">
                当前有 <strong style="color: var(--el-color-warning); font-size: 15px;">{{ overrideData.commission_pending_total }}</strong> 笔返佣订单等待确认（处理后符合条件的佣金才会发放）。
              </span>
              <el-button type="warning" size="small" icon="Document" @click="goToCommissionOrders">
                立即前往订单管理处理
              </el-button>
            </div>
          </template>
        </el-alert>
      </el-col>
    </el-row>

    <!-- Security Audit Quick Warning Banner -->
    <el-row :gutter="16" class="mb-20" v-if="flaggedCount > 0 || suspectedCount > 0">
      <el-col :span="24">
        <el-alert
          :title="alertTitle"
          :type="flaggedCount > 0 ? 'error' : 'warning'"
          :closable="false"
          show-icon
          style="border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); padding: 16px;"
        >
          <template #default>
            <div class="flex-between flex-wrap gap-10" style="margin-top: 8px;">
              <span style="font-size: 13px; line-height: 1.5;">
                当前有 <strong :style="{ color: flaggedCount > 0 ? 'var(--el-color-danger)' : 'var(--el-color-warning)', fontSize: '15px' }">{{ flaggedCount }}</strong> 个高风险订阅拉取拦截账号，
                以及 <strong style="color: var(--el-color-warning); font-size: 15px;">{{ suspectedCount }}</strong> 个疑似命令行/爬虫工具拉取的异常记录待审计。
              </span>
              <el-button :type="flaggedCount > 0 ? 'danger' : 'warning'" size="small" icon="Platform" @click="goToSecurityAudit">
                立即前往安全审计页面处理
              </el-button>
            </div>
          </template>
        </el-alert>
      </el-col>
    </el-row>

    <!-- Top Stats Cards -->
    <el-row :gutter="16" class="stat-row">
      <el-col :xs="12" :sm="12" :md="6" v-for="(card, index) in statCards" :key="index" class="stat-col">
        <el-card class="stat-card" :class="{ 'clickable-card': card.route }" shadow="hover" @click="handleCardClick(card)">
          <div class="stat-card-body">
            <div class="card-header-row">
              <div class="card-icon-small" :style="{ backgroundColor: card.bgColor, color: card.iconColor }">
                <el-icon><component :is="card.icon" /></el-icon>
              </div>
              <span class="card-title-text">{{ card.title }}</span>
              <el-button
                v-if="index === 0"
                link
                style="padding: 0; margin-left: auto; color: var(--el-text-color-secondary);"
                @click.stop="toggleIncomeHidden"
              >
                <el-icon><component :is="isIncomeHidden ? 'View' : 'Hide'" /></el-icon>
              </el-button>
            </div>
            
            <h3 class="card-value-text">
              <template v-if="index === 0">
                {{ isIncomeHidden ? '****' : card.value }}
              </template>
              <template v-else>
                {{ card.value }}
              </template>
            </h3>
            
            <div class="card-footer-text" :title="card.sub">
              {{ card.sub }}
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Main Chart Section -->
    <el-row :gutter="16" class="mt-20">
      <el-col :span="24">
        <el-card class="chart-card" shadow="hover">
          <template #header>
            <div class="flex-between">
              <div>
                <span class="chart-title-text">收入与注册趋势</span>
                <div class="rank-subtitle-text">最近 30 天数据走向</div>
              </div>
            </div>
          </template>
          <div ref="chartRef" class="echart-box"></div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Node Rankings Section -->
    <el-row :gutter="16" class="mt-20">
      <el-col :span="24" :md="12" class="mb-20">
        <el-card class="rank-card" shadow="hover">
          <template #header>
            <div class="flex-between flex-wrap gap-10">
              <div>
                <span class="rank-title-text">节点流量排行</span>
                <div class="rank-subtitle-text">按节点统计</div>
              </div>
              <el-radio-group v-model="nodeRankActiveTab" size="small">
                <el-radio-button value="today">今日</el-radio-button>
                <el-radio-button value="yesterday">昨日</el-radio-button>
              </el-radio-group>
            </div>
          </template>
          
          <div class="rank-list">
            <div v-if="activeNodeRankData.length === 0" class="empty-rank">暂无数据</div>
            <div v-else v-for="(item, index) in activeNodeRankData" :key="index" class="rank-item">
              <div class="rank-item-index" :class="'rank-' + (index + 1)">{{ index + 1 }}</div>
              <div class="rank-item-info">
                <div class="rank-item-name-tag">
                  <span class="rank-item-name">{{ item.server_name }}</span>
                  <el-tag size="small" effect="plain" type="info" class="rank-item-tag">
                    {{ item.server_type ? item.server_type.toUpperCase() : 'UNKNOWN' }}
                  </el-tag>
                </div>
                <div class="rank-item-progress">
                  <el-progress :percentage="getNodeTrafficPercentage(item.total)" :show-text="false" :stroke-width="6" :color="getRankColor(index)" />
                </div>
              </div>
              <div class="rank-item-value">{{ formatRankTraffic(item.total) }}</div>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="24" :md="12" class="mb-20">
        <el-card class="rank-card" shadow="hover">
          <template #header>
            <div class="flex-between flex-wrap gap-10">
              <div>
                <span class="rank-title-text">用户流量排行</span>
                <div class="rank-subtitle-text">按使用量统计</div>
              </div>
              <el-radio-group v-model="userRankActiveTab" size="small">
                <el-radio-button value="today">今日</el-radio-button>
                <el-radio-button value="yesterday">昨日</el-radio-button>
              </el-radio-group>
            </div>
          </template>
          
          <div class="rank-list">
            <div v-if="activeUserRankData.length === 0" class="empty-rank">暂无数据</div>
            <div v-else v-for="(item, index) in activeUserRankData" :key="index" class="rank-item">
              <div class="rank-item-index" :class="'rank-' + (index + 1)">{{ index + 1 }}</div>
              <div class="rank-item-info">
                <div class="rank-item-name-tag">
                  <span class="rank-item-name">{{ item.email }}</span>
                </div>
                <div class="rank-item-progress">
                  <el-progress :percentage="getUserTrafficPercentage(item.total)" :show-text="false" :stroke-width="6" :color="getRankColor(index)" />
                </div>
              </div>
              <div class="rank-item-value">{{ formatRankTraffic(item.total) }}</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, watch, computed } from 'vue';
import { useRouter } from 'vue-router';
import { getSecurePath } from '../api';
import api from '../api';
import * as echarts from 'echarts';
import { ElMessage, ElMessageBox } from 'element-plus';

const router = useRouter();
const systemName = computed(() => {
  return window.settings?.title || '';
});
const alertTitle = computed(() => {
  const prefix = systemName.value ? systemName.value + '安全审计中心' : '安全审计中心';
  return flaggedCount.value > 0 ? `${prefix}发现严重安全威胁！` : `${prefix}提示：发现疑似工具拉取`;
});
const chartRef = ref(null);
let myChart = null;

const nodeRankActiveTab = ref('today');
const userRankActiveTab = ref('today');

const activeNodeRankData = computed(() => {
  return nodeRankActiveTab.value === 'today' ? serverTodayRank.value : serverYesterdayRank.value;
});

const activeUserRankData = computed(() => {
  return userRankActiveTab.value === 'today' ? userTodayRank.value : userYesterdayRank.value;
});

const getNodeTrafficPercentage = (total) => {
  const data = activeNodeRankData.value;
  if (!data || data.length === 0) return 0;
  const max = Math.max(...data.map(item => item.total));
  if (max === 0) return 0;
  return Math.min(100, Math.floor((total / max) * 100));
};

const getUserTrafficPercentage = (total) => {
  const data = activeUserRankData.value;
  if (!data || data.length === 0) return 0;
  const max = Math.max(...data.map(item => item.total));
  if (max === 0) return 0;
  return Math.min(100, Math.floor((total / max) * 100));
};

const getRankColor = (index) => {
  if (index === 0) return '#f56c6c';
  if (index === 1) return '#e6a23c';
  if (index === 2) return '#e6a23c';
  return '#909399';
};

const formatRankTraffic = (total) => {
  return `${total.toFixed(2)} GB`;
};

const overrideData = reactive({
  online_user: 0,
  month_income: 0,
  day_income: 0,
  last_month_income: 0,
  total_user: 0,
  day_traffic: 0,
  ticket_pending_total: 0,
  commission_pending_total: 0,
  day_register_total: 0,
  month_register_total: 0,
  commission_month_payout: 0,
  commission_last_month_payout: 0,
});

const statCards = ref([]);
const serverTodayRank = ref([]);
const serverYesterdayRank = ref([]);
const userTodayRank = ref([]);
const userYesterdayRank = ref([]);
let orderRawData = [];

const isIncomeHidden = ref(localStorage.getItem('is_income_hidden') === 'true');
const toggleIncomeHidden = () => {
  isIncomeHidden.value = !isIncomeHidden.value;
  localStorage.setItem('is_income_hidden', isIncomeHidden.value);
};

const handleCardClick = (card) => {
  if (card.route) {
    router.push(card.route);
  }
};

// Helper functions
const formatTraffic = (bytes) => {
  if (!bytes) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatMoney = (amount) => {
  return '¥ ' + parseFloat((amount / 100).toFixed(2));
};

const anomaliesRawList = ref([]);
const anomaliesLoading = ref(false);

const flaggedCount = computed(() => {
  return anomaliesRawList.value.filter(item => item.type === 'flagged').length;
});

const suspectedCount = computed(() => {
  return anomaliesRawList.value.filter(item => item.type === 'suspected').length;
});

const fetchAnomalies = async () => {
  anomaliesLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getSubscriptionAnomalies`);
    if (res.data) {
      anomaliesRawList.value = res.data.list || [];
    }
  } catch (err) {
    console.error(err);
  } finally {
    anomaliesLoading.value = false;
  }
};

const goToSecurityAudit = () => {
  router.push('/security-audit');
};

const goToCommissionOrders = () => {
  router.push({ path: '/orders', query: { is_commission: '1' } });
};

const updateCards = () => {
  statCards.value = [
    {
      title: '今日收入',
      value: formatMoney(overrideData.day_income),
      sub: `本月 ${formatMoney(overrideData.month_income)} | 上月 ${formatMoney(overrideData.last_month_income)}`,
      icon: 'Wallet',
      bgColor: 'rgba(103, 194, 58, 0.1)',
      iconColor: '#67c23a',
    },
    {
      title: '在线用户',
      value: `${overrideData.online_user} 人`,
      sub: `今日注册 +${overrideData.day_register_total} | 本月注册 ${overrideData.month_register_total}`,
      icon: 'User',
      bgColor: 'rgba(64, 158, 255, 0.1)',
      iconColor: '#409eff',
    },
    {
      title: '今日流量',
      value: formatTraffic(overrideData.day_traffic),
      sub: `有效订阅 ${overrideData.total_user} 人 | 系统正常`,
      icon: 'Odometer',
      bgColor: 'rgba(156, 39, 176, 0.1)',
      iconColor: '#9c27b0',
    },
    {
      title: '待办工单',
      value: `${overrideData.ticket_pending_total} 件`,
      sub: `待审提现 ${overrideData.commission_pending_total} 笔 | 本月发佣 ${formatMoney(overrideData.commission_month_payout)}`,
      icon: 'Notification',
      bgColor: 'rgba(230, 162, 44, 0.1)',
      iconColor: '#e6a23c',
      route: '/tickets',
    }
  ];
};

const fetchOverride = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getOverride`);
    if (res.data) {
      Object.assign(overrideData, res.data);
      updateCards();
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchRanks = async () => {
  try {
    const securePath = getSecurePath();
    const [serverTodayRes, serverYesterdayRes, userTodayRes, userYesterdayRes] = await Promise.all([
      api.get(`/${securePath}/stat/getServerTodayRank`),
      api.get(`/${securePath}/stat/getServerLastRank`),
      api.get(`/${securePath}/stat/getUserTodayRank`),
      api.get(`/${securePath}/stat/getUserLastRank`)
    ]);

    if (serverTodayRes.data) {
      serverTodayRank.value = serverTodayRes.data.slice(0, 10);
    }
    if (serverYesterdayRes.data) {
      serverYesterdayRank.value = serverYesterdayRes.data.slice(0, 10);
    }
    if (userTodayRes.data) {
      userTodayRank.value = userTodayRes.data.slice(0, 10);
    }
    if (userYesterdayRes.data) {
      userYesterdayRank.value = userYesterdayRes.data.slice(0, 10);
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchChartData = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getOrder`);
    if (res.data) {
      orderRawData = res.data;
      renderChart();
    }
  } catch (err) {
    console.error(err);
  }
};

const renderChart = () => {
  if (!chartRef.value || orderRawData.length === 0) return;
  
  if (!myChart) {
    myChart = echarts.init(chartRef.value);
  }
  
  const incomeData = orderRawData.filter(d => d.type === '收款金额');
  const registerData = orderRawData.filter(d => d.type === '注册人数');
  const commissionData = orderRawData.filter(d => d.type === '佣金金额(已发放)');
  const ordersCountData = orderRawData.filter(d => d.type === '收款笔数');
  const commissionCountData = orderRawData.filter(d => d.type === '佣金笔数(已发放)');
  
  const sortByDate = (a, b) => a.date.localeCompare(b.date);
  incomeData.sort(sortByDate);
  registerData.sort(sortByDate);
  commissionData.sort(sortByDate);
  ordersCountData.sort(sortByDate);
  commissionCountData.sort(sortByDate);
  
  const dates = incomeData.map(d => d.date);
  const incomeValues = incomeData.map(d => d.value);
  const registerValues = registerData.map(d => d.value);
  const commissionValues = commissionData.map(d => d.value);
  const ordersCountValues = ordersCountData.map(d => d.value);
  const commissionCountValues = commissionCountData.map(d => d.value);
  
  const isDarkTheme = document.documentElement.classList.contains('dark');
  
  const option = {
    tooltip: {
      trigger: 'axis',
      backgroundColor: isDarkTheme ? '#1e1e1e' : '#fff',
      borderColor: isDarkTheme ? '#333' : '#e4e7ed',
      textStyle: {
        color: isDarkTheme ? '#eee' : '#333'
      }
    },
    legend: {
      data: ['收入', '注册', '佣金', '账单笔数', '佣金笔数'],
      selected: {
        '收入': true,
        '注册': true,
        '佣金': false,
        '账单笔数': false,
        '佣金笔数': false
      },
      textStyle: {
        color: isDarkTheme ? '#eee' : '#333'
      },
      top: 0
    },
    grid: {
      left: '3%',
      right: '3%',
      bottom: '3%',
      top: '15%',
      containLabel: true
    },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: dates,
      axisLabel: {
        color: isDarkTheme ? '#888' : '#666'
      },
      axisLine: {
        lineStyle: {
          color: isDarkTheme ? '#333' : '#e4e7ed'
        }
      }
    },
    yAxis: [
      {
        type: 'value',
        name: '金额',
        axisLabel: {
          color: isDarkTheme ? '#888' : '#666',
          formatter: '¥{value}'
        },
        splitLine: {
          lineStyle: {
            color: isDarkTheme ? '#222' : '#f0f2f5'
          }
        }
      },
      {
        type: 'value',
        name: '人数/笔数',
        axisLabel: {
          color: isDarkTheme ? '#888' : '#666'
        },
        splitLine: {
          show: false
        }
      }
    ],
    series: [
      {
        name: '收入',
        type: 'line',
        smooth: true,
        data: incomeValues,
        itemStyle: {
          color: '#67C23A'
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: 'rgba(103, 194, 58, 0.1)'
        }
      },
      {
        name: '注册',
        type: 'line',
        smooth: true,
        yAxisIndex: 1,
        data: registerValues,
        itemStyle: {
          color: '#409EFF'
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: 'rgba(64, 158, 255, 0.1)'
        }
      },
      {
        name: '佣金',
        type: 'line',
        smooth: true,
        data: commissionValues,
        itemStyle: {
          color: '#f56c6c'
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: 'rgba(245, 108, 108, 0.1)'
        }
      },
      {
        name: '账单笔数',
        type: 'line',
        smooth: true,
        yAxisIndex: 1,
        data: ordersCountValues,
        itemStyle: {
          color: '#e6a23c'
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: 'rgba(230, 162, 44, 0.1)'
        }
      },
      {
        name: '佣金笔数',
        type: 'line',
        smooth: true,
        yAxisIndex: 1,
        data: commissionCountValues,
        itemStyle: {
          color: '#9c27b0'
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: 'rgba(156, 39, 176, 0.1)'
        }
      }
    ]
  };
  
  myChart.setOption(option);
};

const handleResize = () => {
  if (myChart) {
    myChart.resize();
  }
};

// Monitor theme change
watch(
  () => document.documentElement.className,
  () => {
    // Redraw ECharts to update background grids and tooltip colors
    if (myChart) {
      myChart.dispose();
      myChart = null;
    }
    renderChart();
  },
  { deep: true }
);

onMounted(async () => {
  updateCards();
  await Promise.all([
    fetchOverride(),
    fetchRanks(),
    fetchChartData(),
    fetchAnomalies()
  ]);
  
  window.addEventListener('resize', handleResize);
});

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
  if (myChart) {
    myChart.dispose();
  }
});
</script>

<style scoped>
.dashboard-container {
  padding-bottom: 20px;
}

.stat-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
  height: 100%;
}

.stat-card :deep(.el-card__body) {
  padding: 16px;
}

.card-header-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.card-icon-small {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.card-title-text {
  font-size: 13px;
  font-weight: 500;
  color: var(--el-text-color-secondary);
}

.card-value-text {
  font-size: clamp(15px, 4.2vw, 24px);
  font-weight: 700;
  color: var(--el-text-color-primary);
  margin: 4px 0 8px 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-footer-text {
  font-size: clamp(10px, 2.8vw, 12px);
  color: var(--el-text-color-placeholder);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.font-mono {
  font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
}

.text-success {
  color: var(--el-color-success);
}

.font-semibold {
  font-weight: 600;
}

.mt-20 {
  margin-top: 20px;
}

.mb-20 {
  margin-bottom: 20px;
}

.chart-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
  margin-bottom: 20px;
}

.chart-title-text {
  font-size: 15px;
  font-weight: 600;
}

.echart-box {
  height: 350px;
  width: 100%;
}

.rank-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
  margin-bottom: 20px;
}

.rank-title-text {
  font-size: 15px;
  font-weight: 600;
}

.rank-subtitle-text {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  margin-top: 4px;
}

.rank-list {
  padding: 5px 0;
  max-height: 350px;
  overflow-y: auto;
}

.rank-item {
  display: flex;
  align-items: center;
  margin-bottom: 16px;
}

.rank-item:last-child {
  margin-bottom: 0;
}

.rank-item-index {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background-color: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  margin-right: 12px;
  flex-shrink: 0;
}

.rank-item-index.rank-1 {
  background-color: rgba(245, 108, 108, 0.15);
  color: var(--el-color-danger);
}

.rank-item-index.rank-2 {
  background-color: rgba(230, 162, 44, 0.15);
  color: var(--el-color-warning);
}

.rank-item-index.rank-3 {
  background-color: rgba(230, 162, 44, 0.15);
  color: var(--el-color-warning);
}

.rank-item-info {
  flex: 1;
  min-width: 0;
  margin-right: 15px;
}

.rank-item-name-tag {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}

.rank-item-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--el-text-color-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.rank-item-tag {
  flex-shrink: 0;
  font-size: 10px;
  padding: 0 4px;
  height: 16px;
  line-height: 14px;
}

.rank-item-progress {
  width: 100%;
}

.rank-item-progress :deep(.el-progress-bar__outer) {
  background-color: var(--el-fill-color-lighter);
}

.rank-item-value {
  font-size: 13px;
  font-weight: 700;
  color: var(--el-color-success);
  font-family: SFMono-Regular, Consolas, monospace;
  white-space: nowrap;
  flex-shrink: 0;
}

.empty-rank {
  text-align: center;
  padding: 40px 0;
  color: var(--el-text-color-placeholder);
  font-size: 13px;
}

.clickable-card {
  cursor: pointer;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.clickable-card:hover {
  transform: translateY(-4px);
  box-shadow: var(--el-box-shadow-light);
}

@media (max-width: 768px) {
  .stat-card :deep(.el-card__body) {
    padding: 12px;
  }
  .card-value-text {
    font-size: 20px;
  }
  .echart-box {
    height: 280px;
  }
}
</style>
