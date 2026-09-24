<template>
  <div class="app-container">
    <el-card class="box-card">
      <template #header>
        <div class="card-header">
          <span>📝 用户登录记录</span>
        </div>
      </template>

      <!-- 搜索栏 -->
      <div class="filter-container">
        <el-input v-model="listQuery.email" placeholder="邮箱" style="width: 200px; margin-right: 10px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-input v-model="listQuery.ip" placeholder="IP 地址" style="width: 200px; margin-right: 10px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-select v-model="listQuery.type" placeholder="登录状态" clearable style="width: 150px; margin-right: 10px;" class="filter-item" @change="handleFilter">
          <el-option label="成功" value="成功" />
          <el-option label="失败" value="失败" />
        </el-select>
        <el-button type="primary" class="filter-item" icon="Search" @click="handleFilter">
          查询
        </el-button>
        <el-button class="filter-item" icon="Refresh" @click="resetFilter">
          重置
        </el-button>
        <el-button class="filter-item" type="danger" icon="Connection" @click="openIpAssociationDialog" style="margin-left: 10px;">
          IP 关联分析
        </el-button>
      </div>

      <!-- 动态显隐列 (可选) -->
      <div style="margin-bottom: 15px;">
        <span style="font-size: 14px; margin-right: 10px;">显示表项:</span>
        <el-checkbox-group v-model="showColumns" style="display: inline-block;">
          <el-checkbox label="id">ID</el-checkbox>
          <el-checkbox label="email">邮箱 / 账号</el-checkbox>
          <el-checkbox label="ip">IP</el-checkbox>
          <el-checkbox label="location">归属地</el-checkbox>
          <el-checkbox label="time">时间</el-checkbox>
          <el-checkbox label="type">类型</el-checkbox>
          <el-checkbox label="ua">客户端 UA</el-checkbox>
        </el-checkbox-group>
      </div>

      <!-- 表格 -->
      <el-table
        v-loading="listLoading"
        :data="list"
        border
        fit
        highlight-current-row
        style="width: 100%;"
      >
        <el-table-column v-if="showColumns.includes('id')" label="ID" width="100" align="center">
          <template #default="scope">
            <span>{{ scope.row.id }}</span>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('email')" label="邮箱 / 账号" min-width="180">
          <template #default="scope">
            <span 
              class="clickable-link" 
              @click="scope.row.user_id ? showUserDetail(scope.row.user_id) : null"
            >
              {{ scope.row.email || '未知' }}
            </span>
            <el-tag size="small" type="info" v-if="scope.row.user_id" style="margin-left: 5px;">UID:{{ scope.row.user_id }}</el-tag>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('ip')" label="IP 地址" width="160" align="center">
          <template #default="scope">
            <span style="font-family: monospace;">{{ scope.row.ip }}</span>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('location')" label="归属地" min-width="180">
          <template #default="scope">
            <el-tag type="info" effect="plain" v-if="scope.row.location">{{ scope.row.location }}</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('time')" label="时间" width="180" align="center">
          <template #default="scope">
            <span>{{ formatTime(scope.row.created_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('type')" label="类型" width="150" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.type && scope.row.type.includes('成功') ? 'success' : 'danger'">
              {{ scope.row.type }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column v-if="showColumns.includes('ua')" label="客户端 UA" min-width="200">
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
    <el-dialog v-model="ipAssociationVisible" title="多账号共用 IP 关联分析雷达 (登录记录)" width="900px" destroy-on-close>
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 15px; line-height: 1.5;">
        分析系统内所有的用户登录历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同登录过</strong>的 IP 地址。
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
                :key="u.email"
                size="small"
                :type="u.in_honeypot === 1 ? 'warning' : 'success'"
              >
                {{ u.email }} <template v-if="u.id">({{ u.id }})</template>
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="总频次" width="80" align="center" prop="total_pulls" />
        <el-table-column label="最近登录" width="150">
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

const showColumns = ref(['id', 'email', 'ip', 'location', 'time', 'type']);

const listQuery = reactive({
  current: 1,
  page_size: 20,
  email: undefined,
  ip: undefined,
  type: undefined
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
    const response = await api.get(`/${securePath}/stat/getLoginIpAssociationAnalysis`);
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
    const response = await api.get(`/${securePath}/system/getLoginLog`, {
      params: listQuery
    });
    
    if (response.data) {
      list.value = response.data;
      total.value = response.total;
    }
  } catch (error) {
    // API 模块通常已拦截错误，无需额外处理
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  listQuery.current = 1;
  getList();
};

const resetFilter = () => {
  listQuery.email = undefined;
  listQuery.ip = undefined;
  listQuery.type = undefined;
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
