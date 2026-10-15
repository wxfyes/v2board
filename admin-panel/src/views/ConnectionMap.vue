<template>
  <div class="connection-map-page">
    <!-- 顶部 4 项统计指标卡片 -->
    <el-row :gutter="16" class="stat-row">
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">{{ timeRange === 'realtime' ? '实时在线用户数' : `${stats.range_label || '时段'}活跃用户` }}</div>
          <div class="stat-value-box text-danger">
            <span class="stat-num">{{ stats.total_online_users || 0 }}</span>
            <span class="stat-unit">{{ timeRange === 'realtime' ? '位在线' : '位活跃' }}</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">{{ timeRange === 'realtime' ? '总活跃连接数 (IP)' : '覆盖活跃连接 / IP' }}</div>
          <div class="stat-value-box text-primary">
            <span class="stat-num">{{ stats.total_active_ips || 0 }}</span>
            <span class="stat-unit">{{ timeRange === 'realtime' ? '条心跳' : '个痕迹' }}</span>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="12" :md="6" class="stat-col">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-title">{{ timeRange === 'realtime' ? '当前覆盖省份数' : '历史覆盖省份数' }}</div>
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

    <!-- 异常跨省并发风控预警 Banner -->
    <el-alert
      v-if="(stats.multi_location_users || 0) > 0"
      type="warning"
      show-icon
      class="mb-16 risk-alert"
      :closable="false"
    >
      <template #title>
        <div class="risk-alert-content">
          <span>
            <strong>多地并发风控预警：</strong>检测到当前有 <b class="text-danger">{{ stats.multi_location_users }}</b> 位用户正在从多个不同省份并发连接，疑似合租共享账号或凭证泄漏。
          </span>
          <el-button type="danger" link size="small" @click="activeTab = 'details'; onlyMultiLocation = true;">
            立即查看涉事用户明细 →
          </el-button>
        </div>
      </template>
    </el-alert>

    <!-- 下方主区域：左侧地图 + 右侧统计排名 -->
    <el-row :gutter="16" class="main-content-row">
      <!-- 左侧地图看板 -->
      <el-col :xs="24" :lg="14" class="map-col">
        <el-card shadow="hover" class="map-card" :body-style="{ padding: '16px 20px 20px' }">
          <div class="map-toolbar">
            <div class="toolbar-left">
              <el-radio-group v-model="timeRange" size="default" class="scope-radio-group" @change="handleRangeChange">
                <el-radio-button label="realtime">⚡ 实时在线 (10分)</el-radio-button>
                <el-radio-button label="24h">🕒 今日活跃 (24h)</el-radio-button>
                <el-radio-button label="7d">📅 本周轨迹 (7天)</el-radio-button>
                <el-radio-button label="15d">🗓️ 半月全景 (15天)</el-radio-button>
              </el-radio-group>
            </div>
            <div class="toolbar-right">
              <span class="map-tip">
                <el-icon class="mr-4"><InfoFilled /></el-icon>
                提示：点击省份连接数可穿透查看用户清单
              </span>
              <el-select v-model="autoRefreshInterval" size="small" style="width: 110px;">
                <el-option :value="0" label="自动刷新: 关" />
                <el-option :value="30" label="每 30 秒" />
                <el-option :value="60" label="每 60 秒" />
                <el-option :value="120" label="每 2 分钟" />
              </el-select>
              <el-button
                size="small"
                circle
                icon="Refresh"
                :loading="loading"
                title="立即刷新数据"
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
                  <el-table-column label="省份 / 地区" min-width="120">
                    <template #default="{ row }">
                      <span class="province-name">{{ row.name }}</span>
                      <span v-if="row.region" class="province-region">({{ row.region }})</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="连接数 (点击查看)" width="115" align="center" sortable prop="value">
                    <template #default="{ row }">
                      <span
                        class="count-capsule clickable-capsule"
                        title="点击穿透查看实时在线用户明细"
                        @click.stop="openDrilldown(row.name)"
                      >
                        {{ row.value }} 人 <el-icon :size="10" style="margin-left: 2px;"><ArrowRight /></el-icon>
                      </span>
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
                <!-- 明细操作栏 -->
                <div class="detail-toolbar">
                  <div class="toolbar-filter">
                    <span class="filter-label">仅看跨省并发:</span>
                    <el-switch v-model="onlyMultiLocation" size="small" />
                    <el-tag v-if="onlyMultiLocation" type="danger" size="small" closable @close="onlyMultiLocation = false">
                      异常跨省 ({{ filteredDetails.length }})
                    </el-tag>
                  </div>
                  <el-button
                    size="small"
                    icon="Download"
                    @click="handleExportCsv(filteredDetails, `active_connections_${selectedProvince || 'all'}`)"
                  >
                    导出 CSV
                  </el-button>
                </div>

                <el-table :data="filteredDetails" style="width: 100%" height="470" size="small">
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
                  <el-table-column label="风控状态" width="110" align="center">
                    <template #default="{ row }">
                      <el-tooltip
                        v-if="row.is_multi_location"
                        effect="dark"
                        placement="top"
                      >
                        <template #content>
                          <div><b>⚠️ 跨省多地并发异常</b></div>
                          <div>同时在线省份：{{ (row.concurrent_provinces || []).join('、') }}</div>
                        </template>
                        <el-tag type="danger" size="small" style="cursor: pointer;">
                          ⚠️ 跨省并发
                        </el-tag>
                      </el-tooltip>
                      <el-tag v-else type="success" size="small">正常单省</el-tag>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-tab-pane>
          </el-tabs>
        </el-card>
      </el-col>
    </el-row>

    <!-- 省份实时在线/历史活跃用户下钻穿透弹窗 -->
    <el-dialog
      v-model="drilldownVisible"
      :title="'【' + drilldownProvince + '】' + (stats.range_label || '实时在线') + '用户清单'"
      width="840px"
      destroy-on-close
      append-to-body
    >
      <div class="drilldown-dialog-body">
        <div class="drilldown-summary-bar">
          <div class="summary-left">
            <el-tag type="danger" effect="plain">共 {{ provinceDetails.length }} 条连接</el-tag>
            <span class="user-count-text">
              独立用户：<b>{{ new Set(provinceDetails.map(d => d.user_id)).size }}</b> 位
            </span>
            <el-tag v-if="provinceMultiCount > 0" type="danger">
              ⚠️ 跨省并发风控：{{ provinceMultiCount }} 位
            </el-tag>
          </div>
          <div class="summary-right">
            <el-input
              v-model="drilldownSearch"
              placeholder="搜索 邮箱 / IP / 城市 / 节点"
              size="small"
              clearable
              style="width: 200px; margin-right: 8px;"
            />
            <el-button
              size="small"
              icon="Download"
              @click="handleExportCsv(provinceDetails, `province_${drilldownProvince}`)"
            >
              导出
            </el-button>
          </div>
        </div>

        <el-table :data="filteredProvinceDetails" size="small" style="width: 100%" height="400">
          <el-table-column label="用户 (UID / 邮箱)" min-width="160">
            <template #default="{ row }">
              <div class="email-row">
                <span class="email-text">{{ row.email }}</span>
                <el-button link size="small" icon="DocumentCopy" title="复制邮箱" @click="copyEmail(row.email)" />
              </div>
              <div class="uid-text">UID: {{ row.user_id }}</div>
            </template>
          </el-table-column>
          <el-table-column label="客户端 IP / 城市" min-width="140">
            <template #default="{ row }">
              <div style="font-family: monospace; font-weight: 500;">{{ row.ip }}</div>
              <div style="font-size: 11px; color: #909399;">
                {{ row.city || row.province }}{{ row.isp ? ` · ${row.isp}` : '' }}
              </div>
            </template>
          </el-table-column>
          <el-table-column label="连接节点" prop="node" min-width="120">
            <template #default="{ row }">
              <el-tag size="small" type="info">{{ row.node }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="最后活跃时间" min-width="150">
            <template #default="{ row }">
              <span style="font-size: 12px; color: #606266;">
                {{ row.last_active_at || (row.updated_at ? new Date(row.updated_at * 1000).toLocaleString('zh-CN', { hour12: false }) : '-') }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="风控状态" width="115" align="center">
            <template #default="{ row }">
              <el-tooltip
                v-if="row.is_multi_location"
                effect="dark"
                placement="top"
              >
                <template #content>
                  <div><b>⚠️ 跨省多地并发异常</b></div>
                  <div>同时在线省份：{{ (row.concurrent_provinces || []).join('、') }}</div>
                </template>
                <el-tag type="danger" size="small" style="cursor: pointer;">
                  ⚠️ 跨省并发
                </el-tag>
              </el-tooltip>
              <el-tag v-else type="success" size="small">正常单省</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import * as echarts from 'echarts';
import api from '../api';
import { ElMessage } from 'element-plus';
import { InfoFilled, Refresh, ArrowRight, Download, DocumentCopy } from '@element-plus/icons-vue';

// 响应式数据
const loading = ref(false);
const chartInitialized = ref(false);
const chartContainer = ref(null);
let chartInstance = null;

const timeRange = ref('realtime'); // 'realtime' | '24h' | '7d' | '15d'
const activeTab = ref('provinces'); // 'provinces' | 'regions' | 'details'
const selectedProvince = ref('');

const handleRangeChange = () => {
  selectedProvince.value = '';
  fetchData(false);
};

// 自动定时刷新 (0: 关, 30s, 60s, 120s)
const autoRefreshInterval = ref(0);
let autoRefreshTimer = null;

// 风控筛选与省份下钻穿透弹窗
const onlyMultiLocation = ref(false);
const drilldownVisible = ref(false);
const drilldownProvince = ref('');
const drilldownSearch = ref('');

const stats = ref({
  total_online_users: 0,
  total_active_ips: 0,
  covered_provinces: 0,
  overseas_or_lan: 0,
  multi_location_users: 0,
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
  let list = stats.value.details || [];
  if (selectedProvince.value) {
    list = list.filter((d) => d.province === selectedProvince.value);
  }
  if (onlyMultiLocation.value) {
    list = list.filter((d) => d.is_multi_location);
  }
  return list;
});

// 省份下钻明细
const provinceDetails = computed(() => {
  if (!drilldownProvince.value) return [];
  return (stats.value.details || []).filter((d) => d.province === drilldownProvince.value);
});

const filteredProvinceDetails = computed(() => {
  if (!drilldownSearch.value.trim()) return provinceDetails.value;
  const kw = drilldownSearch.value.trim().toLowerCase();
  return provinceDetails.value.filter(
    (d) =>
      d.email.toLowerCase().includes(kw) ||
      d.ip.includes(kw) ||
      (d.city && d.city.includes(kw)) ||
      (d.isp && d.isp.includes(kw)) ||
      (d.node && d.node.toLowerCase().includes(kw))
  );
});

const provinceMultiCount = computed(() => {
  return new Set(provinceDetails.value.filter((d) => d.is_multi_location).map((d) => d.user_id)).size;
});

// 打开省份下钻穿透弹窗
const openDrilldown = (pName) => {
  drilldownProvince.value = pName;
  drilldownSearch.value = '';
  drilldownVisible.value = true;
};

// 复制邮箱
const copyEmail = (email) => {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(email);
    ElMessage.success('邮箱已复制');
  }
};

// 导出 CSV
const handleExportCsv = (dataList, filename) => {
  if (!dataList || !dataList.length) {
    ElMessage.warning('当前无数据可导出');
    return;
  }
  const headers = ['用户ID', '邮箱', '客户端IP', '省份', '城市', '运营商', '节点', '风控状态', '并发省份'];
  const rows = dataList.map((d) => [
    d.user_id,
    `"${d.email.replace(/"/g, '""')}"`,
    d.ip,
    `"${d.province}"`,
    `"${d.city || ''}"`,
    `"${d.isp || ''}"`,
    `"${d.node.replace(/"/g, '""')}"`,
    d.is_multi_location ? '跨省多地并发' : '正常单省',
    `"${(d.concurrent_provinces || []).join(';')}"`,
  ]);
  const csvContent = '\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a')
  link.href = url;
  link.setAttribute('download', `${filename}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  ElMessage.success('已导出 CSV 报表');
};

// 监听自动刷新设置
watch(autoRefreshInterval, (val) => {
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
  if (val > 0) {
    autoRefreshTimer = setInterval(() => {
      fetchData(false);
    }, val * 1000);
  }
});

// 加载后台数据
const fetchData = async (forceRefresh = false) => {
  loading.value = true;
  try {
    const res = await api.get('/admin/stat/getConnectionMap', {
      params: { refresh: forceRefresh ? 1 : 0, time_range: timeRange.value },
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
        roam: window.innerWidth > 768,
        scaleLimit: { min: 1, max: 5 },
        zoom: window.innerWidth > 768 ? 1.35 : 1.2,
        top: 20,
        bottom: 20,
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


onMounted(async () => {
  await nextTick();
  await initChart();
  await fetchData();
  window.addEventListener('resize', handleResize);
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize);
  if (autoRefreshTimer) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
  }
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

.risk-alert {
  border-radius: 8px;
  margin-bottom: 16px;
  border: 1px solid #ffe58f;
}
.risk-alert-content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  flex-wrap: wrap;
  gap: 8px;
}
.clickable-capsule {
  cursor: pointer;
  border: 1px solid #b3d8ff;
  transition: all 0.2s;
  display: inline-flex;
  align-items: center;
}
.clickable-capsule:hover {
  background-color: #d9ecff;
}
.detail-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  flex-wrap: wrap;
  gap: 8px;
}
.toolbar-filter {
  display: flex;
  align-items: center;
  gap: 8px;
}
.filter-label {
  font-size: 12px;
  color: #606266;
}
.drilldown-dialog-body {
  padding: 4px 0;
}
.drilldown-summary-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  flex-wrap: wrap;
  gap: 8px;
}
.summary-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.summary-right {
  display: flex;
  align-items: center;
}
.user-count-text {
  font-size: 13px;
  color: #606266;
}
.email-row {
  display: flex;
  align-items: center;
  gap: 4px;
}
.email-text {
  font-weight: 500;
  color: #303133;
}
.uid-text {
  font-size: 11px;
  color: #909399;
}

@media screen and (max-width: 768px) {
  .echarts-map-wrapper {
    height: 360px !important;
  }
  .map-card, .rank-card {
    min-height: auto !important;
  }
  .map-toolbar {
    flex-direction: column;
    align-items: flex-start !important;
    gap: 8px;
  }
  .toolbar-right {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
