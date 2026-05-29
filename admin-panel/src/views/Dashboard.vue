<template>
  <div class="dashboard-container">
    <!-- Top Stats Cards -->
    <el-row :gutter="20">
      <el-col :xs="24" :sm="12" :md="6" v-for="(card, index) in statCards" :key="index">
        <el-card class="stat-card" shadow="hover">
          <div class="card-content flex-between">
            <div class="card-info">
              <span class="card-title">{{ card.title }}</span>
              <h3 class="card-value">{{ card.value }}</h3>
              <span class="card-sub">{{ card.sub }}</span>
            </div>
            <div class="card-icon" :style="{ backgroundColor: card.bgColor, color: card.iconColor }">
              <el-icon><component :is="card.icon" /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Main Chart Section -->
    <el-row :gutter="20" class="mt-20">
      <el-col :span="24">
        <el-card class="chart-card" shadow="hover">
          <template #header>
            <div class="flex-between">
              <span class="chart-title-text">平台 30 天运营趋势</span>
              <el-radio-group v-model="chartMetric" size="small" @change="renderChart">
                <el-radio-button label="income">收入金额</el-radio-button>
                <el-radio-button label="register">注册人数</el-radio-button>
                <el-radio-button label="orders">收款笔数</el-radio-button>
              </el-radio-group>
            </div>
          </template>
          <div ref="chartRef" class="echart-box"></div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Rankings Section -->
    <el-row :gutter="20" class="mt-20">
      <el-col :xs="24" :md="12">
        <el-card class="rank-card" shadow="hover">
          <template #header>
            <div class="flex-between">
              <span class="rank-title-text">今日节点流量排名</span>
              <el-icon><Connection /></el-icon>
            </div>
          </template>
          <el-table :data="serverRank" stripe style="width: 100%" height="320">
            <el-table-column type="index" label="排名" width="60" align="center" />
            <el-table-column prop="server_name" label="节点名称" show-overflow-tooltip />
            <el-table-column prop="server_type" label="类型" width="100" align="center">
              <template #default="scope">
                <el-tag size="small" effect="plain">{{ scope.row.server_type.toUpperCase() }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="total" label="流量 (GB)" width="120" align="right">
              <template #default="scope">
                {{ scope.row.total.toFixed(2) }} GB
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="12">
        <el-card class="rank-card" shadow="hover">
          <template #header>
            <div class="flex-between">
              <span class="rank-title-text">今日用户流量排名</span>
              <el-icon><User /></el-icon>
            </div>
          </template>
          <el-table :data="userRank" stripe style="width: 100%" height="320">
            <el-table-column type="index" label="排名" width="60" align="center" />
            <el-table-column prop="email" label="用户邮箱" show-overflow-tooltip />
            <el-table-column prop="total" label="使用量" width="140" align="right">
              <template #default="scope">
                <span class="traffic-text">{{ scope.row.total.toFixed(2) }} GB</span>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, watch } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import * as echarts from 'echarts';

const chartRef = ref(null);
const chartMetric = ref('income');
let myChart = null;

const overrideData = reactive({
  online_user: 0,
  month_income: 0,
  day_income: 0,
  last_month_income: 0,
  total_user: 0,
  day_traffic: 0,
  ticket_pending_total: 0,
  commission_pending_total: 0,
});

const statCards = ref([]);
const serverRank = ref([]);
const userRank = ref([]);
let orderRawData = [];

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

const updateCards = () => {
  statCards.value = [
    {
      title: '今日收入',
      value: formatMoney(overrideData.day_income),
      sub: `本月收入: ${formatMoney(overrideData.month_income)}`,
      icon: 'Money',
      bgColor: 'rgba(64, 158, 255, 0.1)',
      iconColor: 'var(--el-color-primary)',
    },
    {
      title: '在线用户',
      value: `${overrideData.online_user} 人`,
      sub: `有效订阅用户: ${overrideData.total_user} 人`,
      icon: 'User',
      bgColor: 'rgba(103, 194, 58, 0.1)',
      iconColor: 'var(--el-color-success)',
    },
    {
      title: '今日流量',
      value: formatTraffic(overrideData.day_traffic),
      sub: '全节点综合流量消耗',
      icon: 'Odometer',
      bgColor: 'rgba(230, 162, 44, 0.1)',
      iconColor: 'var(--el-color-warning)',
    },
    {
      title: '待办工单',
      value: `${overrideData.ticket_pending_total} 件`,
      sub: `待审核提现: ${overrideData.commission_pending_total} 笔`,
      icon: 'Notification',
      bgColor: 'rgba(245, 108, 108, 0.1)',
      iconColor: 'var(--el-color-danger)',
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
    const serverRes = await api.get(`/${securePath}/stat/getServerTodayRank`);
    if (serverRes.data) {
      serverRank.value = serverRes.data.slice(0, 10);
    }
    
    const userRes = await api.get(`/${securePath}/stat/getUserTodayRank`);
    if (userRes.data) {
      userRank.value = userRes.data.slice(0, 10);
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
  
  // Filter data based on metric
  let typeLabel = '收款金额';
  let color = '#409EFF';
  let areaColor = 'rgba(64, 158, 255, 0.1)';
  
  if (chartMetric.value === 'register') {
    typeLabel = '注册人数';
    color = '#67C23A';
    areaColor = 'rgba(103, 194, 58, 0.1)';
  } else if (chartMetric.value === 'orders') {
    typeLabel = '收款笔数';
    color = '#E6A23C';
    areaColor = 'rgba(230, 162, 44, 0.1)';
  }
  
  const filtered = orderRawData.filter(d => d.type === typeLabel);
  const dates = filtered.map(d => d.date);
  const values = filtered.map(d => d.value);
  
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
    grid: {
      left: '3%',
      right: '4%',
      bottom: '3%',
      top: '5%',
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
    yAxis: {
      type: 'value',
      axisLabel: {
        color: isDarkTheme ? '#888' : '#666'
      },
      splitLine: {
        lineStyle: {
          color: isDarkTheme ? '#222' : '#f0f2f5'
        }
      }
    },
    series: [
      {
        name: typeLabel,
        type: 'line',
        smooth: true,
        data: values,
        itemStyle: {
          color: color
        },
        lineStyle: {
          width: 3
        },
        areaStyle: {
          color: areaColor
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
    fetchChartData()
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
}

.card-info {
  display: flex;
  flex-direction: column;
}

.card-title {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  font-weight: 500;
}

.card-value {
  font-size: 26px;
  font-weight: 700;
  margin: 6px 0;
  letter-spacing: -0.5px;
}

.card-sub {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.card-icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
}

.mt-20 {
  margin-top: 20px;
}

.chart-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
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
}

.rank-title-text {
  font-size: 15px;
  font-weight: 600;
}

.traffic-text {
  font-weight: 600;
  color: var(--el-color-primary);
}
</style>
