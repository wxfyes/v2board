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
            <span>#{{ scope.row.user_id }}</span>
          </template>
        </el-table-column>

        <el-table-column label="邮箱 / 账号" min-width="180">
          <template #default="scope">
            <span>{{ scope.row.email || '未知用户' }}</span>
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

        <el-table-column label="拉取时间" width="180" align="center">
          <template #default="scope">
            <span>{{ formatTime(scope.row.created_at) }}</span>
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
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import api, { getSecurePath } from '../api';

const list = ref([]);
const total = ref(0);
const listLoading = ref(true);

const listQuery = reactive({
  current: 1,
  page_size: 20,
  user_id: undefined,
  ip: undefined,
  ua: undefined
});

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

const handleFilter = () => {
  listQuery.current = 1;
  getList();
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
</style>
