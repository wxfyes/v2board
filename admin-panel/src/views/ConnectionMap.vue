<template>
  <div class="connection-map-page">
    <!-- 顶部 4 项统计指标卡片 -->
    <el-row :gutter="16" class="stat-row">
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">总在线用户数</div>
          <div class="stat-value-box text-danger">
            <span class="stat-num">{{ stats.total_online_users || 0 }}</span>
            <span class="stat-unit">位</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">总活跃连接数 (IP)</div>
          <div class="stat-value-box text-primary">
            <span class="stat-num">{{ stats.total_active_ips || 0 }}</span>
            <span class="stat-unit">条</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">覆盖中国省份数</div>
          <div class="stat-value-box text-success">
            <span class="stat-num">{{ stats.covered_provinces || 0 }}</span>
            <span class="stat-unit">个省市</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">海外 / 局域网</div>
          <div class="stat-value-box text-purple">
            <span class="stat-num">{{ stats.overseas_or_lan || 0 }}</span>
            <span class="stat-unit">个</span>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 下方主区域：左侧地图 + 右侧统计排名 -->
    <el-row :gutter="16" class="main-content-row">
      <!-- 左侧地图看板 -->
      <el-col :xs="24" :lg="14" class="map-col">
        <el-card shadow="hover" class="map-card" :body-style="{ padding: '16px 20px 20px' }">
          <div class="map-toolbar">
            <div class="toolbar-left">
              <el-radio-group v-model="mapScope" size="default" class="scope-radio-group">
                <el-radio-button label="china">中国地区</el-radio-button>
                <el-radio-button label="all">全部 / 包含海外</el-radio-button>
              </el-radio-group>
            </div>
            <div class="toolbar-right">
              <span class="map-tip">
                <el-icon class="mr-4"><InfoFilled /></el-icon>
                提示：滚轮可缩放，点击省份可筛选右侧列表
              </span>
              <el-button
                size="small"
                circle
                icon="Refresh"
                :loading="loading"
                title="刷新数据"
                class="refresh-btn"
                @click="fetchData(true)"
              />
            </div>
          </div>

          <!-- 地图当前选中筛选状态提示 -->
          <div v-if="selectedProvince" class="filter-tag-bar">
            <span>当前筛选省份：</span>
            <el-tag closable type="danger" effect="plain" @close="clearSelectedProvince">
              {{ selectedProvince }}
            </el-tag>
          </div>

          <!-- ECharts 地图容器 -->
          <div v-loading="loading && !chartInitialized" class="echarts-map-wrapper">
            <div ref="chartContainer" class="chart-dom"></div>
          </div>
        </el-card>
      </el-col>

      <!-- 右侧排名与明细 -->
      <el-col :xs="24" :lg="10" class="rank-col">
        <el-card shadow="hover" class="rank-card" :body-style="{ padding: '12px 16px 16px' }">
          <el-tabs v-model="activeTab" class="custom-tabs">
            <!-- Tab 1: 省份连接排名 -->
            <el-tab-pane name="provinces">
              <template #label>
                <span class="tab-label-text">
                  省份连接排名
                  <span class="tab-badge text-danger-bg">{{ filteredProvinces.length }}</span>
                </span>
              </template>
              <div class="table-container">
                <el-table
                  :data="filteredProvinces"
                  style="width: 100%"
                  height="510"
                  size="small"
                  :row-class-name="tableRowClassName"
                  @row-click="handleRowClick"
                >
                  <el-table-column label="排名" width="56" align="center">
                    <template #default="{ row }">
                      <span
                        class="rank-badge"
                        :class="{
                          'rank-top-1': row.rank === 1,
                          'rank-top-2': row.rank === 2,
                          'rank-top-3': row.rank === 3,
                          'rank-normal': row.rank > 3,
                        }"
                      >
                        {{ row.rank }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column label="省份 / 地区" min-width="130">
                    <template #default="{ row }">
                      <span class="province-name">{{ row.name }}</span>
                      <span v-if="row.region" class="province-region">({{ row.region }})</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="连接数" width="86" align="center" sortable prop="value">
                    <template #default="{ row }">
                      <span class="count-capsule">{{ row.value }} 人</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="占比" min-width="90" align="left">
                    <template #default="{ row }">
                      <div class="ratio-cell">
                        <div class="ratio-progress-bg">
                          <div class="ratio-progress-bar" :style="{ width: Math.min(row.ratio, 100) + '%' }"></div>
                        </div>
                        <span class="ratio-text">{{ row.ratio }}%</span>
                      </div>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-tab-pane>

            <!-- Tab 2: 大区统计 -->
            <el-tab-pane name="regions">
              <template #label>
                <span class="tab-label-text">大区统计</span>
              </template>
              <div class="table-container">
                <el-table :data="stats.regions || []" style="width: 100%" height="510" size="small">
                  <el-table-column type="index" label="#" width="50" align="center" />
                  <el-table-column label="大区分区" prop="name" min-width="110">
                    <template #default="{ row }">
                      <span style="font-weight: 600; color: #2c3e50;">{{ row.name }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="活跃连接数" prop="value" width="100" align="center">
                    <template #default="{ row }">
                      <span class="count-capsule">{{ row.value }} 人</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="占比" min-width="100">
                    <template #default="{ row }">
                      <div class="ratio-cell">
                        <div class="ratio-progress-bg">
                          <div class="ratio-progress-bar bar-blue" :style="{ width: Math.min(row.ratio, 100) + '%' }"></div>
                        </div>
                        <span class="ratio-text">{{ row.ratio }}%</span>
                      </div>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-tab-pane>

            <!-- Tab 3: 在线节点明细 -->
            <el-tab-pane name="details">
              <template #label>
                <span class="tab-label-text">
                  在线节点明细
                  <span class="tab-badge text-blue-bg">{{ (stats.details || []).length }}</span>
                </span>
              </template>
              <div class="table-container">
                <el-table :data="filteredDetails" style="width: 100%" height="510" size="small">
                  <el-table-column label="客户端 IP" prop="ip" min-width="115">
                    <template #default="{ row }">
                      <span style="font-family: monospace; font-size: 12px; font-weight: 500;">{{ row.ip }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="归属地" min-width="120">
                    <template #default="{ row }">
                      <span>{{ row.province }}{{ row.city ? (' ' + row.city) : '' }}</span>
                      <div style="font-size: 11px; color: #909399;">{{ row.isp || row.region }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="节点 / 用户" min-width="110">
                    <template #default="{ row }">
                      <div class="node-tag" :title="row.node">{{ row.node }}</div>
                      <div style="font-size: 11px; color: #909399; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" :title="row.email">
                        {{ row.email }}
                      </div>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-tab-pane>
          </el-tabs>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import * as echarts from 'echarts';
import api from '../api';
import { ElMessage } from 'element-plus';
import { InfoFilled, Refresh } from '@element-plus/icons-vue';

// 响应式数据
const loading = ref(false);
const chartInitialized = ref(false);
const chartContainer = ref(null);
let chartInstance = null;

const mapScope = ref('china'); // 'china' | 'all'
const activeTab = ref('provinces'); // 'provinces' | 'regions' | 'details'
const selectedProvince = ref('');

const stats = ref({
  total_online_users: 0,
  total_active_ips: 0,
  covered_provinces: 0,
  overseas_or_lan: 0,
  provinces: [],
  regions: [],
  details: [],
});

// 计算属性：筛选后的省份
const filteredProvinces = computed(() => {
  const list = stats.value.provinces || [];
  if (!selectedProvince.value) return list;
  return list.filter((p) => p.name === selectedProvince.value);
});

// 计算属性：筛选后的明细
const filteredDetails = computed(() => {
  const list = stats.value.details || [];
  if (!selectedProvince.value) return list;
  return list.filter((d) => d.province === selectedProvince.value);
});

// 加载后台数据
const fetchData = async (forceRefresh = false) => {
  loading.value = true;
  try {
    const res = await api.get('/admin/stat/getConnectionMap', {
      params: { refresh: forceRefresh ? 1 : 0 },
    });
    if (res && res.data) {
      stats.value = res.data;
      updateChartData();
    }
  } catch (error) {
    console.error('Failed to load connection map data:', error);
  } finally {
    loading.value = false;
  }
};

// 初始化 ECharts 地图
const initChart = async () => {
  if (!chartContainer.value) return;

  try {
    // 异步动态加载中国地图 GeoJSON，不增加首屏 Bundle 压力
    const chinaJsonModule = await import('../assets/china.json');
    const chinaJson = chinaJsonModule.default || chinaJsonModule;

    echarts.registerMap('china', chinaJson);

    if (chartInstance) {
      chartInstance.dispose();
    }

    chartInstance = echarts.init(chartContainer.value);
    chartInitialized.value = true;

    // 监听地图省份点击事件
    chartInstance.on('click', (params) => {
      if (params.seriesType === 'map') {
        const provinceName = params.name;
        if (selectedProvince.value === provinceName) {
          selectedProvince.value = ''; // 再次点击取消筛选
        } else {
          selectedProvince.value = provinceName;
          activeTab.value = 'provinces';
        }
      }
    });

    updateChartData();
  } catch (err) {
    console.error('ECharts map init failed:', err);
    ElMessage.error('地图数据加载失败');
  }
};

// 更新地图视图数据
const updateChartData = () => {
  if (!chartInstance) return;

  const provinceData = (stats.value.provinces || []).map((p) => ({
    name: p.name,
    value: p.value,
    region: p.region,
    ratio: p.ratio,
  }));

  const maxVal = Math.max(...provinceData.map((d) => d.value), 20);

  const option = {
    tooltip: {
      trigger: 'item',
      backgroundColor: 'rgba(255, 255, 255, 0.95)',
      borderColor: '#e4e7ed',
      borderWidth: 1,
      padding: [8, 12],
      textStyle: { color: '#303133', fontSize: 13 },
      formatter: (params) => {
        if (!params.name) return '';
        const count = params.value || 0;
        const region = params.data?.region ? ` (${params.data.region})` : '';
        const ratio = params.data?.ratio ? ` · 占比 ${params.data.ratio}%` : '';
        return `
          <div style="font-weight: 600; margin-bottom: 4px; color: #1f2d3d;">${params.name}${region}</div>
          <div style="color: #606266; font-size: 12px;">活跃连接数：<b style="color: #f56c6c; font-size: 14px;">${count}</b> 人${ratio}</div>
        `;
      },
    },
    visualMap: {
      min: 0,
      max: maxVal,
      left: '20',
      bottom: '20',
      show: true,
      text: ['高', '0'],
      calculable: false,
      itemWidth: 12,
      itemHeight: 80,
      inRange: {
        // 绿 -> 黄绿 -> 橙 -> 红
        color: ['#00a870', '#85db44', '#ffc53d', '#ff5722'],
      },
      textStyle: {
        color: '#909399',
        fontSize: 11,
      },
    },
    series: [
      {
        name: '活跃连接',
        type: 'map',
        map: 'china',
        roam: true,
        scaleLimit: { min: 1, max: 4 },
        zoom: 1.15,
        center: [105, 36],
        label: {
          show: true,
          fontSize: 9,
          color: '#ffffff',
          fontWeight: 400,
        },
        itemStyle: {
          borderColor: '#ffffff',
          borderWidth: 0.8,
          areaColor: '#eef2f7', // 无数据时展示浅灰底色
        },
        emphasis: {
          label: {
            show: true,
            color: '#1f2d3d',
            fontWeight: 'bold',
          },
          itemStyle: {
            areaColor: '#ff9800',
            borderWidth: 1.2,
            borderColor: '#ffffff',
            shadowBlur: 8,
            shadowColor: 'rgba(0, 0, 0, 0.2)',
          },
        },
        select: {
          itemStyle: {
            areaColor: '#e64242',
            borderColor: '#ffffff',
          },
        },
        data: provinceData,
      },
    ],
  };

  chartInstance.setOption(option, true);
};

// 清除选中的省份筛选
const clearSelectedProvince = () => {
  selectedProvince.value = '';
};

// 点击表格某行联动地图高亮
const handleRowClick = (row) => {
  if (selectedProvince.value === row.name) {
    selectedProvince.value = '';
  } else {
    selectedProvince.value = row.name;
    if (chartInstance) {
      chartInstance.dispatchAction({
        type: 'highlight',
        seriesIndex: 0,
        name: row.name,
      });
    }
  }
};

// 选中的行样式
const tableRowClassName = ({ row }) => {
  if (selectedProvince.value && row.name === selectedProvince.value) {
    return 'selected-table-row';
  }
  return '';
};

// 响应窗口 resize
const handleResize = () => {
  if (chartInstance) {
    chartInstance.resize();
  }
};

// 监听视图范围切换
watch(mapScope, (newScope) => {
  if (newScope === 'all') {
    ElMessage.info('海外及局域网连接共 ' + (stats.value.overseas_or_lan || 0) + ' 个，详情请查看右侧明细');
  }
});

onMounted(async () => {
  await nextTick();
  await initChart();
  await fetchData();
  window.addEventListener('resize', handleResize);
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize);
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
});
</script>

<style scoped>
.connection-map-page {
  padding: 16px 20px 24px;
  background-color: #f7f9fa;
  min-height: calc(100vh - 64px);
}

/* 顶部 4 张卡片 */
.stat-row {
  margin-bottom: 16px;
}
.stat-col {
  margin-bottom: 12px;
}
.stat-card {
  border-radius: 10px;
  border: 1px solid #edf0f5;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
  padding: 16px 20px;
  background: #ffffff;
}
:deep(.stat-card .el-card__body) {
  padding: 0 !important;
}
.stat-title {
  font-size: 13px;
  color: #718096;
  font-weight: 500;
  margin-bottom: 8px;
}
.stat-value-box {
  display: flex;
  align-items: baseline;
  gap: 6px;
}
.stat-num {
  font-size: 28px;
  font-weight: 700;
  line-height: 1;
}
.stat-unit {
  font-size: 13px;
  font-weight: 500;
}

.text-danger {
  color: #f53f3f;
}
.text-primary {
  color: #2468f2;
}
.text-success {
  color: #00b42a;
}
.text-purple {
  color: #722ed1;
}

/* 主内容区 */
.main-content-row {
  display: flex;
  flex-wrap: wrap;
}
.map-col,
.rank-col {
  margin-bottom: 16px;
}
.map-card,
.rank-card {
  border-radius: 12px;
  border: 1px solid #edf0f5;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
  background: #ffffff;
  min-height: 600px;
}

/* 地图工具栏 */
.map-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.scope-radio-group :deep(.el-radio-button__inner) {
  border-radius: 6px;
  font-size: 13px;
  padding: 8px 16px;
}
.scope-radio-group :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
  background-color: #f53f3f;
  border-color: #f53f3f;
  box-shadow: -1px 0 0 0 #f53f3f;
  color: #ffffff;
}
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.map-tip {
  font-size: 12px;
  color: #909399;
  display: flex;
  align-items: center;
}
.mr-4 {
  margin-right: 4px;
}
.refresh-btn {
  color: #606266;
}

/* 过滤提示条 */
.filter-tag-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #606266;
  margin-bottom: 8px;
  padding: 4px 10px;
  background-color: #fef0f0;
  border-radius: 6px;
}

/* 地图容器 */
.echarts-map-wrapper {
  position: relative;
  width: 100%;
  height: 520px;
}
.chart-dom {
  width: 100%;
  height: 100%;
}

/* 右侧 Tab 与列表样式 */
.custom-tabs :deep(.el-tabs__header) {
  margin-bottom: 12px;
}
.custom-tabs :deep(.el-tabs__item) {
  font-size: 14px;
  font-weight: 500;
  color: #606266;
  padding: 0 16px;
}
.custom-tabs :deep(.el-tabs__item.is-active) {
  color: #f53f3f;
  font-weight: 600;
}
.custom-tabs :deep(.el-tabs__active-bar) {
  background-color: #f53f3f;
  height: 3px;
  border-radius: 2px;
}
.tab-label-text {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.tab-badge {
  font-size: 11px;
  padding: 1px 7px;
  border-radius: 10px;
  font-weight: 600;
  line-height: 1.4;
}
.text-danger-bg {
  background-color: #fef0f0;
  color: #f53f3f;
}
.text-blue-bg {
  background-color: #ecf5ff;
  color: #409eff;
}

/* 表格排行榜 */
.table-container {
  overflow: hidden;
}
.rank-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 12px;
  font-weight: bold;
}
.rank-top-1 {
  background-color: #f53f3f;
  color: #ffffff;
}
.rank-top-2 {
  background-color: #ff7d00;
  color: #ffffff;
}
.rank-top-3 {
  background-color: #fadc19;
  color: #8c5b00;
}
.rank-normal {
  background-color: #f2f3f5;
  color: #86909c;
}

.province-name {
  font-weight: 600;
  color: #1f2d3d;
  font-size: 13px;
}
.province-region {
  font-size: 12px;
  color: #86909c;
  margin-left: 4px;
}

.count-capsule {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 12px;
  background-color: #e8f3ff;
  color: #165dff;
  font-size: 12px;
  font-weight: 600;
}

.ratio-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ratio-progress-bg {
  flex: 1;
  height: 6px;
  background-color: #f2f3f5;
  border-radius: 3px;
  overflow: hidden;
}
.ratio-progress-bar {
  height: 100%;
  background-color: #f53f3f;
  border-radius: 3px;
  transition: width 0.3s ease;
}
.ratio-progress-bar.bar-blue {
  background-color: #165dff;
}
.ratio-text {
  font-size: 12px;
  font-weight: 600;
  color: #4e5969;
  width: 38px;
  text-align: right;
}

.node-tag {
  display: inline-block;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  font-weight: 500;
  color: #1d2129;
}

:deep(.selected-table-row) {
  background-color: #fef0f0 !important;
}
</style>
