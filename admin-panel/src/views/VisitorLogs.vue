<template>
  <div class="app-container">
    <el-card class="box-card">
      <template #header>
        <div class="card-header">
          <span>🌐 网站访客访问记录</span>
          <span class="sub-title">自动聚合独立 IP 访问环境与频次 · 定期自动清理</span>
        </div>
      </template>

      <!-- 搜索筛选栏 -->
      <div class="filter-container">
        <el-input
          v-model="listQuery.ip"
          placeholder="IP 地址"
          style="width: 170px; margin-right: 10px; margin-bottom: 8px;"
          clearable
          @keyup.enter="handleFilter"
        />
        <el-input
          v-model="listQuery.location"
          placeholder="地区 / 城市"
          style="width: 150px; margin-right: 10px; margin-bottom: 8px;"
          clearable
          @keyup.enter="handleFilter"
        />
        <el-select
          v-model="listQuery.device"
          placeholder="设备类型"
          clearable
          style="width: 130px; margin-right: 10px; margin-bottom: 8px;"
          @change="handleFilter"
        >
          <el-option label="Desktop" value="Desktop" />
          <el-option label="Mobile" value="Mobile" />
          <el-option label="Tablet" value="Tablet" />
        </el-select>

        <div style="display: inline-flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
          <el-button type="primary" icon="Search" @click="handleFilter">
            查询
          </el-button>
          <el-button icon="Refresh" @click="resetFilter">
            重置
          </el-button>
          <el-popconfirm
            title="确认要清空全部访客访问日志吗？此操作不可恢复！"
            confirm-button-text="确认清空"
            cancel-button-text="取消"
            confirm-button-type="danger"
            @confirm="handleClearAll"
          >
            <template #reference>
              <el-button type="danger" icon="Delete">
                清空日志
              </el-button>
            </template>
          </el-popconfirm>
        </div>
      </div>

      <!-- 移动端卡片流 -->
      <div v-if="isMobile" v-loading="listLoading" class="mobile-card-list">
        <el-card
          v-for="item in list"
          :key="item.id"
          class="mobile-log-card"
          shadow="hover"
        >
          <div class="card-head">
            <span class="ip-link" @click="copyIp(item.ip)">{{ item.ip }}</span>
            <el-tag size="small" type="primary">{{ item.visit_count || 1 }} 次访问</el-tag>
          </div>

          <div class="card-row">
            <span v-html="renderFlagIcon(item.country_code, item.country)"></span>
            <span class="loc-text">{{ item.city || item.province || item.country || '未知' }}</span>
          </div>

          <div class="card-meta">
            <span class="meta-item">
              <span v-html="renderBrowserIcon(item.browser)"></span>
              {{ item.browser || 'Chrome' }}
            </span>
            <span class="meta-item">
              <span v-html="renderOsIcon(item.os)"></span>
              {{ item.os || 'Windows' }}
            </span>
            <span class="meta-item">
              <span v-html="renderDeviceIcon(item.device)"></span>
              {{ item.device || 'Desktop' }}
            </span>
          </div>

          <div class="card-foot">
            <span class="time-text">{{ formatTime(item.updated_at) }}</span>
            <el-button
              v-if="item.is_banned === 1"
              size="small"
              type="success"
              plain
              @click="handleUnbanIp(item.ip)"
            >
              已封禁
            </el-button>
            <el-button
              v-else
              size="small"
              type="danger"
              plain
              @click="handleBanIp(item.ip)"
            >
              封禁IP
            </el-button>
          </div>
        </el-card>

        <el-empty v-if="list.length === 0 && !listLoading" description="暂无访客记录" />
      </div>

      <!-- 桌面端表格 (100% 还原图一) -->
      <el-table
        v-else
        v-loading="listLoading"
        :data="list"
        style="width: 100%; margin-top: 10px;"
      >
        <el-table-column label="IP" min-width="160">
          <template #default="{ row }">
            <span class="ip-link" title="点击复制 IP" @click="copyIp(row.ip)">
              {{ row.ip }}
            </span>
          </template>
        </el-table-column>

        <el-table-column prop="visit_count" label="访问次数" width="100" align="left">
          <template #default="{ row }">
            <span style="font-size: 14px; font-weight: 500; color: #333;">
              {{ row.visit_count || 1 }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="位置" min-width="160">
          <template #default="{ row }">
            <div style="display: flex; align-items: center;">
              <span v-html="renderFlagIcon(row.country_code, row.country)"></span>
              <span>{{ row.city || row.province || row.country || '未知' }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="浏览器" min-width="130">
          <template #default="{ row }">
            <div style="display: flex; align-items: center;">
              <span v-html="renderBrowserIcon(row.browser)"></span>
              <span style="margin-left: 6px;">{{ row.browser || 'Chrome' }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="操作系统" min-width="130">
          <template #default="{ row }">
            <div style="display: flex; align-items: center;">
              <span v-html="renderOsIcon(row.os)"></span>
              <span style="margin-left: 6px;">{{ row.os || 'Windows' }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="设备" min-width="120">
          <template #default="{ row }">
            <div style="display: flex; align-items: center;">
              <span v-html="renderDeviceIcon(row.device)"></span>
              <span style="margin-left: 6px;">{{ row.device || 'Desktop' }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="最近访问" min-width="170">
          <template #default="{ row }">
            <el-tooltip :content="row.last_url ? `最后访问: ${row.last_url}` : '无路径记录'" placement="top">
              <span style="font-size: 12px; color: #888;">{{ formatTime(row.updated_at) }}</span>
            </el-tooltip>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="100" align="center">
          <template #default="{ row }">
            <el-button
              v-if="row.is_banned === 1"
              size="small"
              type="success"
              plain
              @click="handleUnbanIp(row.ip)"
            >
              已封禁
            </el-button>
            <el-button
              v-else
              size="small"
              type="danger"
              plain
              @click="handleBanIp(row.ip)"
            >
              封禁
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页栏 -->
      <div class="pagination-container">
        <el-pagination
          v-model:current-page="listQuery.current"
          v-model:page-size="listQuery.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          :layout="isMobile ? 'prev, pager, next' : 'total, sizes, prev, pager, next, jumper'"
          background
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount } from 'vue';
import api, { getSecurePath } from '../api';
import { ElMessage } from 'element-plus';

const listLoading = ref(false);
const list = ref([]);
const total = ref(0);
const isMobile = ref(false);

const listQuery = reactive({
  current: 1,
  pageSize: 20,
  ip: '',
  location: '',
  device: '',
});

const checkMobile = () => {
  isMobile.value = window.innerWidth <= 768;
};

onMounted(() => {
  checkMobile();
  window.addEventListener('resize', checkMobile);
  fetchList();
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', checkMobile);
});

const fetchList = async () => {
  listLoading.value = true;
  try {
    const securePath = getSecurePath();
    const params = {
      current: listQuery.current,
      page_size: listQuery.pageSize,
    };
    if (listQuery.ip) params.ip = listQuery.ip;
    if (listQuery.location) params.location = listQuery.location;
    if (listQuery.device) params.device = listQuery.device;

    const res = await api.get(`/${securePath}/system/getVisitorLog`, { params });
    if (res && res.data) {
      list.value = res.data.data || res.data || [];
      total.value = res.data.total || res.total || 0;
    }
  } catch (error) {
    ElMessage.error(error.message || '获取访客记录失败');
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  listQuery.current = 1;
  fetchList();
};

const resetFilter = () => {
  listQuery.ip = '';
  listQuery.location = '';
  listQuery.device = '';
  listQuery.current = 1;
  fetchList();
};

const handleClearAll = async () => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/system/clearVisitorLog`);
    ElMessage.success('访客记录已清空');
    fetchList();
  } catch (error) {
    ElMessage.error(error.message || '清空失败');
  }
};

const handleBanIp = async (ip) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/banIp`, { ip });
    ElMessage.success(`已封禁 IP: ${ip}`);
    fetchList();
  } catch (error) {
    ElMessage.error(error.message || '操作失败');
  }
};

const handleUnbanIp = async (ip) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeBanIp`, { ip });
    ElMessage.success(`已解封 IP: ${ip}`);
    fetchList();
  } catch (error) {
    ElMessage.error(error.message || '操作失败');
  }
};

const copyIp = (ip) => {
  navigator.clipboard.writeText(ip).then(() => {
    ElMessage.success(`已复制 IP: ${ip}`);
  }).catch(() => {
    ElMessage.info(ip);
  });
};

const formatTime = (timestamp) => {
  if (!timestamp) return '-';
  const d = new Date(timestamp * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
};

// 渲染长方形标准国旗矢量素材 (SVG)
const renderFlagIcon = (code = '', country = '') => {
  const c = (code || '').toUpperCase();
  const name = country || '';

  // 1. 中国五星红旗 (CN)
  if (c === 'CN' || name.includes('中国')) {
    return `<svg width="22" height="15" viewBox="0 0 900 600" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <path fill="#de2910" d="M0 0h900v600H0z"/>
      <polygon fill="#ffde00" points="150,50 179,141 255,86 197,166 273,221 179,221 150,300 121,221 27,221 103,166 45,86 121,141"/>
      <polygon fill="#ffde00" points="300,50 310,79 335,62 316,88 341,105 311,105 301,130 292,105 262,105 287,88 268,62 293,79"/>
      <polygon fill="#ffde00" points="360,100 370,129 395,112 376,138 401,155 371,155 361,180 352,155 322,155 347,138 328,112 353,129"/>
      <polygon fill="#ffde00" points="360,200 370,229 395,212 376,238 401,255 371,255 361,280 352,255 322,255 347,238 328,212 353,229"/>
      <polygon fill="#ffde00" points="300,250 310,279 335,262 316,288 341,305 311,305 301,330 292,305 262,305 287,288 268,262 293,279"/>
    </svg>`;
  }

  // 2. 朝鲜国旗 (KP)
  if (c === 'KP' || name.includes('朝鲜') || name.includes('North Korea')) {
    return `<svg width="22" height="15" viewBox="0 0 72 36" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="36" fill="#024FA2"/>
      <rect y="6" width="72" height="24" fill="#fff"/>
      <rect y="8" width="72" height="20" fill="#ED1C27"/>
      <circle cx="24" cy="18" r="8" fill="#fff"/>
      <polygon points="24,10.5 26.2,15 31,15.5 27.5,19 28.5,23.8 24,21.5 19.5,23.8 20.5,19 17,15.5 21.8,15" fill="#ED1C27"/>
    </svg>`;
  }

  // 3. 美国星条旗 (US)
  if (c === 'US' || name.includes('United States') || name.includes('美国')) {
    return `<svg width="22" height="15" viewBox="0 0 741 390" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="741" height="390" fill="#B22234"/>
      <path d="M0,45H741M0,105H741M0,165H741M0,225H741M0,285H741M0,345H741" stroke="#fff" stroke-width="30"/>
      <rect width="296" height="210" fill="#3C3B6E"/>
      <circle cx="50" cy="50" r="14" fill="#fff"/>
      <circle cx="150" cy="50" r="14" fill="#fff"/>
      <circle cx="250" cy="50" r="14" fill="#fff"/>
      <circle cx="100" cy="105" r="14" fill="#fff"/>
      <circle cx="200" cy="105" r="14" fill="#fff"/>
      <circle cx="50" cy="160" r="14" fill="#fff"/>
      <circle cx="150" cy="160" r="14" fill="#fff"/>
      <circle cx="250" cy="160" r="14" fill="#fff"/>
    </svg>`;
  }

  // 4. 韩国太极旗 (KR)
  if (c === 'KR' || name.includes('Korea') || name.includes('韩国')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#fff"/>
      <path d="M36,12 A12,12 0 0,0 36,36 A6,6 0 0,0 36,24 A6,6 0 0,1 36,12" fill="#C60C30"/>
      <path d="M36,12 A12,12 0 0,1 36,36 A6,6 0 0,1 36,24 A6,6 0 0,0 36,12" fill="#003478"/>
      <rect x="12" y="14" width="3" height="8" fill="#000"/>
      <rect x="17" y="14" width="3" height="8" fill="#000"/>
      <rect x="52" y="26" width="3" height="8" fill="#000"/>
      <rect x="57" y="26" width="3" height="8" fill="#000"/>
    </svg>`;
  }

  // 5. 中国香港旗 (HK)
  if (c === 'HK' || name.includes('香港')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#DE2910"/>
      <circle cx="36" cy="24" r="8" fill="#fff"/>
      <circle cx="36" cy="24" r="5" fill="#DE2910"/>
    </svg>`;
  }

  // 6. 中国台湾旗 (TW)
  if (c === 'TW' || name.includes('台湾')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#FE0000"/>
      <rect width="36" height="24" fill="#000095"/>
      <circle cx="18" cy="12" r="7" fill="#fff"/>
      <circle cx="18" cy="12" r="5" fill="#000095"/>
    </svg>`;
  }

  // 7. 日本国旗 (JP)
  if (c === 'JP' || name.includes('日本')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#fff"/>
      <circle cx="36" cy="24" r="11" fill="#BC002D"/>
    </svg>`;
  }

  // 8. 英国米字旗 (GB)
  if (c === 'GB' || c === 'UK' || name.includes('英国')) {
    return `<svg width="22" height="15" viewBox="0 0 72 36" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="36" fill="#012169"/>
      <path d="M0,0 L72,36 M72,0 L0,36" stroke="#fff" stroke-width="6"/>
      <path d="M0,0 L72,36 M72,0 L0,36" stroke="#C8102E" stroke-width="2"/>
      <path d="M36,0 V36 M0,18 H72" stroke="#fff" stroke-width="10"/>
      <path d="M36,0 V36 M0,18 H72" stroke="#C8102E" stroke-width="6"/>
    </svg>`;
  }

  // 9. 德国国旗 (DE)
  if (c === 'DE' || name.includes('德国')) {
    return `<svg width="22" height="15" viewBox="0 0 72 45" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="15" fill="#000"/>
      <rect y="15" width="72" height="15" fill="#DD0000"/>
      <rect y="30" width="72" height="15" fill="#FFCE00"/>
    </svg>`;
  }

  // 10. 俄罗斯国旗 (RU)
  if (c === 'RU' || name.includes('俄罗斯')) {
    return `<svg width="22" height="15" viewBox="0 0 72 45" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="15" fill="#fff"/>
      <rect y="15" width="72" height="15" fill="#0039A6"/>
      <rect y="30" width="72" height="15" fill="#D52B1E"/>
    </svg>`;
  }

  // 11. 新加坡国旗 (SG)
  if (c === 'SG' || name.includes('新加坡')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="24" fill="#ED2939"/>
      <rect y="24" width="72" height="24" fill="#fff"/>
      <circle cx="18" cy="12" r="6" fill="#fff"/>
      <circle cx="20" cy="12" r="5" fill="#ED2939"/>
    </svg>`;
  }

  // 12. 法国国旗 (FR)
  if (c === 'FR' || name.includes('法国') || name.includes('France')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="24" height="48" fill="#002654"/>
      <rect x="24" width="24" height="48" fill="#fff"/>
      <rect x="48" width="24" height="48" fill="#ED2939"/>
    </svg>`;
  }

  // 13. 挪威国旗 (NO)
  if (c === 'NO' || name.includes('挪威') || name.includes('Norway')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#BA0C2F"/>
      <path d="M0,24 H72 M24,0 V48" stroke="#fff" stroke-width="12"/>
      <path d="M0,24 H72 M24,0 V48" stroke="#00205B" stroke-width="6"/>
    </svg>`;
  }

  // 14. 荷兰国旗 (NL)
  if (c === 'NL' || name.includes('荷兰') || name.includes('Netherlands')) {
    return `<svg width="22" height="15" viewBox="0 0 72 45" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="15" fill="#AE1C28"/>
      <rect y="15" width="72" height="15" fill="#fff"/>
      <rect y="30" width="72" height="15" fill="#21468B"/>
    </svg>`;
  }

  // 15. 加拿大国旗 (CA)
  if (c === 'CA' || name.includes('加拿大') || name.includes('Canada')) {
    return `<svg width="22" height="15" viewBox="0 0 72 36" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="18" height="36" fill="#FF0000"/>
      <rect x="18" width="36" height="36" fill="#fff"/>
      <rect x="54" width="18" height="36" fill="#FF0000"/>
      <polygon points="36,8 39,18 48,15 42,22 45,28 36,25 27,28 30,22 24,15 33,18" fill="#FF0000"/>
    </svg>`;
  }

  // 16. 澳大利亚国旗 (AU)
  if (c === 'AU' || name.includes('澳大利亚') || name.includes('Australia')) {
    return `<svg width="22" height="15" viewBox="0 0 72 36" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="36" fill="#012169"/>
      <rect width="36" height="18" fill="#00247D"/>
      <path d="M0,0 L36,18 M36,0 L0,18" stroke="#fff" stroke-width="3"/>
      <path d="M18,0 V18 M0,9 H36" stroke="#fff" stroke-width="5"/>
      <path d="M18,0 V18 M0,9 H36" stroke="#CF142B" stroke-width="3"/>
      <circle cx="18" cy="27" r="3" fill="#fff"/>
      <circle cx="54" cy="18" r="2" fill="#fff"/>
    </svg>`;
  }

  // 17. 意大利国旗 (IT)
  if (c === 'IT' || name.includes('意大利') || name.includes('Italy')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="24" height="48" fill="#009246"/>
      <rect x="24" width="24" height="48" fill="#fff"/>
      <rect x="48" width="24" height="48" fill="#CE2B37"/>
    </svg>`;
  }

  // 18. 西班牙国旗 (ES)
  if (c === 'ES' || name.includes('西班牙') || name.includes('Spain')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="12" fill="#AA151B"/>
      <rect y="12" width="72" height="24" fill="#F1BF00"/>
      <rect y="36" width="72" height="12" fill="#AA151B"/>
    </svg>`;
  }

  // 19. 中国澳门特区旗 (MO)
  if (c === 'MO' || name.includes('澳门')) {
    return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
      <rect width="72" height="48" fill="#007A5E"/>
      <circle cx="36" cy="24" r="8" fill="#fff"/>
      <circle cx="36" cy="24" r="5" fill="#007A5E"/>
    </svg>`;
  }

  // 兜底通用国际蓝色旗帜
  return `<svg width="22" height="15" viewBox="0 0 72 48" style="vertical-align: middle; margin-right: 6px; border-radius: 2px; box-shadow: 0 0 1px rgba(0,0,0,0.35);">
    <rect width="72" height="48" fill="#1890ff"/>
    <circle cx="36" cy="24" r="14" fill="none" stroke="#fff" stroke-width="2.5"/>
    <ellipse cx="36" cy="24" rx="7" ry="14" fill="none" stroke="#fff" stroke-width="2"/>
    <line x1="22" y1="24" x2="50" y2="24" stroke="#fff" stroke-width="2"/>
  </svg>`;
};

// 渲染浏览器彩色 SVG
const renderBrowserIcon = (name = '') => {
  const n = (name || '').toLowerCase();
  if (n.includes('chrome')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <circle cx="16" cy="16" r="14" fill="#fff"/>
      <path d="M16 2A14 14 0 0 0 3.8 9l6.5 11.3A7 7 0 0 1 16 9h13.9A14 14 0 0 0 16 2z" fill="#EA4335"/>
      <path d="M29.9 9H16a7 7 0 0 0-6.1 3.5L3.8 23A14 14 0 0 0 16 30a14 14 0 0 0 12.1-7l-6-10.4A7 7 0 0 1 29.9 9z" fill="#FBBC05"/>
      <path d="M16 30a14 14 0 0 0 12.1-7l-6.5-11.3a7 7 0 0 1-5.6 11.3v-6a7 7 0 0 1-6.1-3.5L3.8 23A14 14 0 0 0 16 30z" fill="#34A853"/>
      <circle cx="16" cy="16" r="5.5" fill="#4285F4"/>
    </svg>`;
  }
  if (n.includes('edge')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <path d="M28.5 18.5C28.2 24.5 23 29.5 16.5 29.5 8.5 29.5 2 23 2 15S8.5 2 16.5 2c3.5 0 6.5 1.5 8.5 4-3.5 0-7 2-8.5 5.5-1.5 3.5 0 7 3.5 8.5 3.5 1.5 7.5.5 8.5-1.5z" fill="#0078D7"/>
      <path d="M16.5 29.5c6.5 0 11.7-5 12-11 0-3-2-5.5-5.5-5.5-4 0-7 3-7 7 0 5.5 4 9.5 9.5 9.5z" fill="#00BCF2"/>
      <path d="M14 19c-3.5 0-6-2.5-6-6 0-4.5 3.5-8 8.5-8 3.5 0 6 2 7 4.5-2.5-1-5.5-1-8 1.5-2 2-2.5 5-1.5 8z" fill="#25B96E"/>
    </svg>`;
  }
  if (n.includes('safari')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <circle cx="16" cy="16" r="14" fill="#006CFF"/>
      <circle cx="16" cy="16" r="12" fill="#fff"/>
      <circle cx="16" cy="16" r="11" fill="#006CFF"/>
      <polygon points="16,6 18.5,13.5 26,16 18.5,18.5 16,26 13.5,18.5 6,16 13.5,13.5" fill="#FF2638"/>
      <polygon points="16,16 18.5,13.5 26,16" fill="#fff"/>
      <polygon points="16,16 13.5,18.5 6,16" fill="#fff"/>
    </svg>`;
  }
  if (n.includes('firefox')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <circle cx="16" cy="16" r="14" fill="#FF7139"/>
      <circle cx="15" cy="15" r="9" fill="#802090"/>
      <path d="M26 12c-1.5-3-4-5-7-5 2 1.5 3 4 2 6-1 2-3 3-5 2s-3-3-2-5c-3 1-5 4-5 8 0 6 5 10 11 10s10-4 10-10c0-2-.5-4-1.5-6z" fill="#FF9400"/>
    </svg>`;
  }
  return `<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align: middle;">
    <circle cx="12" cy="12" r="10" fill="none" stroke="#888" stroke-width="2"/>
    <ellipse cx="12" cy="12" rx="4" ry="10" fill="none" stroke="#888" stroke-width="1.5"/>
  </svg>`;
};

// 渲染操作系统 SVG
const renderOsIcon = (name = '') => {
  const n = (name || '').toLowerCase();
  if (n.includes('win')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <path d="M2 5.5l11-1.5v11H2V5.5zm13-1.8L29 2v13H15V3.7zM2 17h11v11l-11-1.5V17zm13 0h14v13l-14-2V17z" fill="#0078D7"/>
    </svg>`;
  }
  if (n.includes('android')) {
    return `<svg width="18" height="18" viewBox="0 0 32 32" style="vertical-align: middle;">
      <path d="M9 13v10c0 .6.4 1 1 1h2v4a1.5 1.5 0 0 0 3 0v-4h2v4a1.5 1.5 0 0 0 3 0v-4h2c.6 0 1-.4 1-1V13H9z" fill="#78C257"/>
      <path d="M10.5 11h11a5.5 5.5 0 0 0-11 0z" fill="#78C257"/>
    </svg>`;
  }
  if (n.includes('mac') || n.includes('ios')) {
    const isDark = n.includes('ios');
    return `<svg width="18" height="18" viewBox="0 0 170 170" style="vertical-align: middle;">
      <path d="M150.37 130.25c-2.45 5.66-5.35 10.87-8.71 15.66-4.58 6.53-8.33 11.05-11.22 13.56-4.48 4.12-9.28 6.23-14.42 6.35-3.69 0-8.14-1.05-13.32-3.18-5.19-2.12-9.97-3.17-14.34-3.17-4.58 0-9.49 1.05-14.75 3.17-5.26 2.13-9.5 3.24-12.74 3.35-4.35.13-9.16-1.9-14.42-6.08-3.69-3.04-7.67-7.81-11.96-14.34-7.5-11.39-13.3-24.62-17.4-39.69-4.1-15.07-6.15-28.77-6.15-41.11 0-14.35 3.42-26.47 10.26-36.35 6.84-9.88 15.63-14.93 26.36-15.15 4.36 0 9.28 1.15 14.75 3.44 5.48 2.29 9.39 3.51 11.75 3.65 2.12 0 6.15-1.33 12.08-3.98 5.94-2.65 11.25-3.86 15.93-3.65 11.83.64 21.32 4.96 28.46 12.96-10.45 6.34-15.54 15.08-15.28 26.23.27 8.92 3.66 16.34 10.18 22.28 6.52 5.94 14.19 9.3 23 10.08-2.12 6.52-4.77 13.04-7.96 19.57zM119.22 31.84c0-7.39 2.65-14.18 7.96-20.37 5.3-6.19 11.85-10.02 19.64-11.47.27 1.09.41 2.18.41 3.26 0 7.39-2.73 14.38-8.2 20.97-5.46 6.59-12.06 10.37-19.81 11.33v-3.72z" fill="${isDark ? '#000' : '#495057'}"/>
    </svg>`;
  }
  return `<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align: middle;">
    <rect x="3" y="3" width="18" height="18" rx="3" fill="#6c757d"/>
    <text x="12" y="16" font-size="11" fill="#fff" text-anchor="middle" font-weight="bold">OS</text>
  </svg>`;
};

// 渲染设备 SVG
const renderDeviceIcon = (type = 'Desktop') => {
  const t = (type || '').toLowerCase();
  if (t === 'mobile') {
    return `<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align: middle;">
      <rect x="6" y="2" width="12" height="20" rx="3" fill="#1890ff"/>
      <rect x="8" y="5" width="8" height="12" rx="1" fill="#fff"/>
      <circle cx="12" cy="19" r="1" fill="#fff"/>
    </svg>`;
  }
  if (t === 'tablet') {
    return `<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align: middle;">
      <rect x="4" y="2" width="16" height="20" rx="2" fill="#13c2c2"/>
      <rect x="6" y="4" width="12" height="14" rx="1" fill="#fff"/>
    </svg>`;
  }
  return `<svg width="18" height="18" viewBox="0 0 24 24" style="vertical-align: middle;">
    <rect x="3" y="3" width="18" height="12" rx="2" fill="#1890ff"/>
    <rect x="5" y="5" width="14" height="8" rx="1" fill="#fff"/>
    <path d="M9 18h6v2H9z" fill="#1890ff"/>
    <line x1="12" y1="15" x2="12" y2="18" stroke="#1890ff" stroke-width="2"/>
  </svg>`;
};
</script>

<style scoped>
.app-container {
  padding: 20px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-weight: 600;
  font-size: 16px;
}
.sub-title {
  font-size: 12px;
  color: #999;
  font-weight: normal;
}
.filter-container {
  margin-bottom: 12px;
}
.ip-link {
  color: #2b8a3e;
  font-weight: 500;
  cursor: pointer;
  font-size: 14px;
}
.ip-link:hover {
  text-decoration: underline;
}
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
.mobile-card-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.mobile-log-card {
  border-radius: 8px;
  background: #fafafa;
}
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.card-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  margin-bottom: 6px;
}
.card-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: #666;
  margin-bottom: 8px;
  flex-wrap: wrap;
}
.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.card-foot {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid #eee;
  padding-top: 8px;
}
.time-text {
  font-size: 11px;
  color: #999;
}
@media (max-width: 768px) {
  .app-container {
    padding: 10px;
  }
}
</style>
