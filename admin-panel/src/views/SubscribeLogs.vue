<template>
  <div class="app-container">
    <el-card class="box-card">
      <template #header>
        <div class="card-header">
          <span>🛡️ 订阅拉取雷达 (无限制监控)</span>
        </div>
      </template>

      <!-- 搜索栏 -->
      <div class="filter-container">
        <el-input v-model="listQuery.user_id" placeholder="User ID" style="width: 150px; margin-right: 10px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-input v-model="listQuery.ip" placeholder="IP 地址" style="width: 200px; margin-right: 10px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-input v-model="listQuery.ua" placeholder="User-Agent 关键词" style="width: 250px; margin-right: 10px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-button type="primary" class="filter-item" icon="Search" @click="handleFilter">
          查询
        </el-button>
        <el-button class="filter-item" icon="Refresh" @click="resetFilter">
          重置
        </el-button>
        <el-button class="filter-item" type="warning" icon="Trophy" @click="getTopUsers">
          今日拉取排行
        </el-button>
        <el-button class="filter-item" type="danger" icon="Connection" @click="openIpAssociationDialog" style="margin-left: 10px;">
          IP 关联分析
        </el-button>
      </div>

      <!-- 表格 -->
      <el-table
        v-loading="listLoading"
        :data="list"
        border
        fit
        highlight-current-row
        style="width: 100%; margin-top: 20px;"
      >
        <el-table-column label="User ID" width="100" align="center">
          <template #default="scope">
            <span class="clickable-link" @click="showUserDetail(scope.row.user_id)">#{{ scope.row.user_id }}</span>
          </template>
        </el-table-column>

        <el-table-column label="邮箱 / 账号" min-width="180">
          <template #default="scope">
            <span class="clickable-link" @click="showUserDetail(scope.row.user_id)">{{ scope.row.email || '未知用户' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="识别客户端" width="150" align="center">
          <template #default="scope">
            <el-tag type="info">{{ scope.row.type || '未知' }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="IP 地址" width="160" align="center">
          <template #default="scope">
            <span style="font-family: monospace;">{{ scope.row.ip }}</span>
          </template>
        </el-table-column>

        <el-table-column label="归属地" min-width="180">
          <template #default="scope">
            <el-tag type="info" effect="plain" v-if="scope.row.location">{{ scope.row.location }}</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>

        <el-table-column label="拉取时间" width="180" align="center">
          <template #default="scope">
            <span>{{ formatTime(scope.row.created_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="今日频次" width="100" align="center">
          <template #default="scope">
            <el-tooltip content="点击查看该用户所有拉取记录" placement="top">
              <el-tag 
                :type="scope.row.today_count > 10 ? 'danger' : (scope.row.today_count > 5 ? 'warning' : 'success')" 
                effect="dark" 
                style="cursor: pointer; transition: transform 0.2s;" 
                @click="filterByUser(scope.row.user_id)"
                onmouseover="this.style.transform='scale(1.1)'"
                onmouseout="this.style.transform='scale(1)'"
              >
                {{ scope.row.today_count || 0 }} 次
              </el-tag>
            </el-tooltip>
          </template>
        </el-table-column>

        <el-table-column label="原始 User-Agent" min-width="250">
          <template #default="scope">
            <el-tooltip class="box-item" effect="dark" :content="scope.row.ua" placement="top-start">
              <div class="ua-text">{{ scope.row.ua }}</div>
            </el-tooltip>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <div class="pagination-container" style="margin-top: 20px; display: flex; justify-content: flex-end;">
        <el-pagination
          v-show="total > 0"
          :current-page="listQuery.current"
          :page-sizes="[10, 20, 50, 100]"
          :page-size="listQuery.page_size"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <UserDetailDialog v-model="detailVisible" :user-id="currentUserId" @change="getList" />

    <!-- IP Association Dialog -->
    <el-dialog v-model="ipAssociationVisible" title="多账号共用 IP 关联分析雷达 (订阅拉取)" width="900px" destroy-on-close>
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 15px; line-height: 1.5;">
        分析所有用户的客户端拉取历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同使用过</strong>的 IP 地址。
      </div>

      <el-table :data="ipAssociationList" v-loading="ipAssociationLoading" stripe size="small" max-height="450px" style="width: 100%;">
        <el-table-column label="共用 IP" min-width="240">
          <template #default="scope">
            <code class="font-mono" style="font-weight: bold;">{{ scope.row.ip }}</code>
            <div v-if="scope.row.location" style="font-size: 11px; color: var(--el-text-color-secondary); margin-top: 2px;">
              {{ scope.row.location }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="关联账号数" width="160">
          <template #default="scope">
            <span style="font-size: 13px;">
              <strong>{{ scope.row.associated_accounts_count }}</strong> 个账号
              <span v-if="scope.row.honeypot_accounts_count > 0" style="color: var(--el-color-warning); font-size: 12px;">
                ({{ scope.row.honeypot_accounts_count }} 蜜罐)
              </span>
            </span>
          </template>
        </el-table-column>
        <el-table-column label="共用账号列表" min-width="320">
          <template #default="scope">
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
              <el-tag
                v-for="u in scope.row.associated_users"
                :key="u.id"
                size="small"
                :type="u.in_honeypot === 1 ? 'warning' : 'success'"
              >
                {{ u.email }} ({{ u.id }})
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="总频次" width="80" align="center" prop="total_pulls" />
        <el-table-column label="最近拉取" width="150">
          <template #default="scope">
            <span style="font-size: 12px; color: var(--el-text-color-secondary);">
              {{ formatTime(scope.row.latest_time) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" align="right" fixed="right">
          <template #default="scope">
            <el-button
              v-if="scope.row.is_banned === 0"
              type="danger"
              size="small"
              plain
              @click="banAssociatedIp(scope.row.ip)"
            >
              封禁 IP
            </el-button>
            <el-button
              v-else
              type="info"
              size="small"
              plain
              @click="unbanAssociatedIp(scope.row.ip)"
            >
              已封锁
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <span class="dialog-footer">
          <el-button size="small" @click="ipAssociationVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import api, { getSecurePath } from '../api';
import UserDetailDialog from '../components/UserDetailDialog.vue';

const list = ref([]);
const total = ref(0);
const listLoading = ref(true);

const detailVisible = ref(false);
const currentUserId = ref(null);

const showUserDetail = (userId) => {
  currentUserId.value = userId;
  detailVisible.value = true;
};

const listQuery = reactive({
  current: 1,
  page_size: 20,
  user_id: undefined,
  ip: undefined,
  ua: undefined
});

const ipAssociationVisible = ref(false);
const ipAssociationLoading = ref(false);
const ipAssociationList = ref([]);

const openIpAssociationDialog = () => {
  ipAssociationVisible.value = true;
  fetchIpAssociation();
};

const fetchIpAssociation = async () => {
  ipAssociationLoading.value = true;
  try {
    const securePath = getSecurePath();
    const response = await api.get(`/${securePath}/stat/getIpAssociationAnalysis`);
    ipAssociationList.value = response.data || [];
  } catch (error) {
    ElMessage.error(error.message || '获取关联分析数据失败');
  } finally {
    ipAssociationLoading.value = false;
  }
};

const banAssociatedIp = async (ip) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/banIp`, { ip });
    ElMessage.success('IP 封禁成功');
    fetchIpAssociation();
  } catch (error) {
    ElMessage.error(error.message || '操作失败');
  }
};

const unbanAssociatedIp = async (ip) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeBanIp`, { ip });
    ElMessage.success('IP 已解封');
    fetchIpAssociation();
  } catch (error) {
    ElMessage.error(error.message || '操作失败');
  }
};

const getList = async () => {
  listLoading.value = true;
  try {
    const securePath = getSecurePath();
    const response = await api.get(`/${securePath}/system/getSubscribeLog`, {
      params: listQuery
    });
    
    if (response.data) {
      list.value = response.data;
      total.value = response.total;
    }
  } catch (error) {
    // api module already handles ElMessage.error, but we can keep a fallback
  } finally {
    listLoading.value = false;
  }
};

const getTopUsers = async () => {
  listLoading.value = true;
  try {
    const securePath = getSecurePath();
    const response = await api.get(`/${securePath}/system/getTopSubscribeUsers`);
    
    if (response.data) {
      list.value = response.data;
      total.value = response.total;
    }
  } catch (error) {
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  listQuery.current = 1;
  getList();
};

const filterByUser = (userId) => {
  listQuery.user_id = userId;
  handleFilter();
};

const resetFilter = () => {
  listQuery.user_id = undefined;
  listQuery.ip = undefined;
  listQuery.ua = undefined;
  handleFilter();
};

const handleSizeChange = (val) => {
  listQuery.page_size = val;
  getList();
};

const handleCurrentChange = (val) => {
  listQuery.current = val;
  getList();
};

const formatTime = (timestamp) => {
  if (!timestamp) return '-';
  const d = new Date(timestamp * 1000);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
};

onMounted(() => {
  getList();
});
</script>

<style scoped>
.filter-container {
  padding-bottom: 10px;
}
.ua-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
}
.card-header {
  font-weight: bold;
  color: var(--el-color-primary);
}
.clickable-link {
  color: var(--el-color-primary);
  cursor: pointer;
  text-decoration: underline;
}
.clickable-link:hover {
  opacity: 0.8;
}
</style>
