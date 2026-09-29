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
        <el-input v-model="listQuery.email" placeholder="邮箱" style="width: 170px; margin-right: 10px; margin-bottom: 8px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-input v-model="listQuery.ip" placeholder="IP 地址" style="width: 170px; margin-right: 10px; margin-bottom: 8px;" class="filter-item" @keyup.enter="handleFilter" clearable />
        <el-select v-model="listQuery.type" placeholder="登录状态" clearable style="width: 130px; margin-right: 10px; margin-bottom: 8px;" class="filter-item" @change="handleFilter">
          <el-option label="成功" value="成功" />
          <el-option label="失败" value="失败" />
        </el-select>
        <div style="display: inline-flex; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
          <el-button type="primary" class="filter-item" icon="Search" @click="handleFilter">
            查询
          </el-button>
          <el-button class="filter-item" icon="Refresh" @click="resetFilter">
            重置
          </el-button>
          <el-button class="filter-item" type="danger" icon="Connection" @click="openIpAssociationDialog">
            IP 关联
          </el-button>
          <el-button class="filter-item" type="danger" icon="Monitor" @click="openDeviceAssociationDialog">
            设备雷达
          </el-button>
        </div>
      </div>

      <!-- 正在筛选单账号的醒目提示横幅 -->
      <div v-if="activeFilteredUser" class="filter-alert-banner">
        <span>🔍 正在查看账号 <strong>{{ activeFilteredUser.email || `UID:${activeFilteredUser.id}` }}</strong> 的全部登录记录 (共 {{ total }} 条)</span>
        <el-button type="primary" size="small" icon="CircleClose" @click="resetFilter">
          清除筛选 / 查看全部
        </el-button>
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
            <div class="user-info-wrap">
              <span class="user-email" @click="item.user_id ? showUserDetail(item.user_id) : null">
                {{ item.email || '未知' }}
              </span>
              <el-tag v-if="item.user_id" size="small" type="info" class="uid-tag">UID:{{ item.user_id }}</el-tag>
            </div>
            <el-tag
              :type="item.type && item.type.includes('成功') ? 'success' : 'danger'"
              size="small"
              class="type-tag"
            >
              {{ item.type || '未知' }}
            </el-tag>
          </div>

          <div class="card-row">
            <span>IP: <code class="ip-code">{{ item.ip }}</code></span>
            <span class="loc-text">{{ item.location || '-' }}</span>
          </div>

          <div class="card-row">
            <span class="time-text">{{ formatTime(item.created_at) }}</span>
            <span style="font-size: 11px; color: #bbb">#{{ item.id }}</span>
          </div>

          <div v-if="item.ua" class="card-ua">
            设备: {{ item.ua }}
          </div>

          <!-- 快捷操作区 -->
          <div class="card-actions">
            <el-button
              size="small"
              :type="activeFilteredUser?.email === item.email ? 'primary' : 'default'"
              @click="filterByAccount(item.email, item.user_id)"
            >
              {{ activeFilteredUser?.email === item.email ? '筛选中' : 'TA的全部登录' }}
            </el-button>
            <el-button
              v-if="item.user_id"
              size="small"
              type="primary"
              plain
              @click="openUserSubModal({ id: item.user_id, email: item.email })"
            >
              TA的拉取记录
            </el-button>
            <el-button
              v-if="item.user_id"
              size="small"
              @click="showUserDetail(item.user_id)"
            >
              用户档案
            </el-button>
          </div>
        </el-card>

        <el-empty v-if="list.length === 0 && !listLoading" description="暂无登录记录" />

        <div class="mobile-pagination">
          <el-pagination
            v-show="total > 0"
            :current-page="listQuery.current"
            :page-size="listQuery.page_size"
            layout="prev, pager, next"
            :total="total"
            @current-change="handleCurrentChange"
          />
        </div>
      </div>

      <!-- PC 端表格 -->
      <div v-else>
        <!-- 动态显隐列 -->
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

        <el-table
          v-loading="listLoading"
          :data="list"
          border
          fit
          highlight-current-row
          style="width: 100%;"
        >
          <el-table-column v-if="showColumns.includes('id')" label="ID" width="80" align="center">
            <template #default="scope">
              <span>{{ scope.row.id }}</span>
            </template>
          </el-table-column>

          <el-table-column v-if="showColumns.includes('email')" label="邮箱 / 账号" min-width="170">
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

          <el-table-column v-if="showColumns.includes('ip')" label="IP 地址" width="150" align="center">
            <template #default="scope">
              <span style="font-family: monospace;">{{ scope.row.ip }}</span>
            </template>
          </el-table-column>

          <el-table-column v-if="showColumns.includes('location')" label="归属地" min-width="160">
            <template #default="scope">
              <el-tag type="info" effect="plain" v-if="scope.row.location">{{ scope.row.location }}</el-tag>
              <span v-else>-</span>
            </template>
          </el-table-column>

          <el-table-column v-if="showColumns.includes('time')" label="时间" width="170" align="center">
            <template #default="scope">
              <span>{{ formatTime(scope.row.created_at) }}</span>
            </template>
          </el-table-column>

          <el-table-column v-if="showColumns.includes('type')" label="类型" width="120" align="center">
            <template #default="scope">
              <el-tag :type="scope.row.type && scope.row.type.includes('成功') ? 'success' : 'danger'">
                {{ scope.row.type }}
              </el-tag>
            </template>
          </el-table-column>

          <el-table-column v-if="showColumns.includes('ua')" label="客户端 UA" min-width="200">
            <template #default="scope">
              <el-tooltip effect="dark" :content="scope.row.ua" placement="top-start">
                <div class="ua-text">{{ scope.row.ua }}</div>
              </el-tooltip>
            </template>
          </el-table-column>

          <el-table-column label="快捷操作" width="220" align="center">
            <template #default="scope">
              <el-button
                size="small"
                :type="activeFilteredUser?.email === scope.row.email ? 'primary' : 'default'"
                link
                @click="filterByAccount(scope.row.email, scope.row.user_id)"
              >
                {{ activeFilteredUser?.email === scope.row.email ? '筛选中' : '全部登录' }}
              </el-button>
              <el-button
                v-if="scope.row.user_id"
                size="small"
                type="primary"
                link
                @click="openUserSubModal({ id: scope.row.user_id, email: scope.row.email })"
              >
                拉取记录
              </el-button>
              <el-button
                v-if="scope.row.user_id"
                size="small"
                type="info"
                link
                @click="showUserDetail(scope.row.user_id)"
              >
                档案
              </el-button>
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
      </div>
    </el-card>

    <UserDetailDialog v-model="detailVisible" :user-id="currentUserId" @change="getList" />

    <!-- 单账号订阅拉取记录快捷浮窗 -->
    <el-dialog
      v-model="userSubDialogVisible"
      :title="'🛡️ ' + (currentUserAccount?.email || '未知用户') + ' (UID:' + currentUserAccount?.id + ') 的订阅拉取 IP 记录'"
      :width="isMobile ? '95%' : '750px'"
      destroy-on-close
    >
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 12px;">
        查看该账号的历史客户端拉取 IP、归属地及识别客户端（共 <strong>{{ userSubTotal }}</strong> 条记录）：
      </div>

      <div v-if="isMobile" v-loading="userSubLoading" style="display: flex; flex-direction: column; gap: 8px; max-height: 400px; overflow-y: auto;">
        <div
          v-for="(sub, idx) in userSubList"
          :key="sub.id || idx"
          style="background: #fafafa; border: 1px solid #f0f0f0; border-radius: 6px; padding: 10px 12px; font-size: 12px;"
        >
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
            <span>
              <code style="background: #eee; padding: 2px 6px; border-radius: 4px; font-weight: bold;">{{ sub.ip }}</code>
              <span v-if="sub.location" style="margin-left: 6px; color: #888;">{{ sub.location }}</span>
            </span>
            <el-tag size="small" type="info">{{ sub.type || '未知' }}</el-tag>
          </div>
          <div style="color: #999; font-size: 11px;">
            {{ formatTime(sub.created_at) }}
          </div>
          <div v-if="sub.ua" style="color: #bbb; font-size: 10px; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            UA: {{ sub.ua }}
          </div>
        </div>
        <el-empty v-if="userSubList.length === 0 && !userSubLoading" description="暂无该账号拉取记录" />
      </div>

      <el-table v-else :data="userSubList" v-loading="userSubLoading" size="small" style="width: 100%" max-height="350px">
        <el-table-column label="拉取 IP" min-width="180">
          <template #default="scope">
            <code style="font-family: monospace; font-weight: bold;">{{ scope.row.ip }}</code>
            <div v-if="scope.row.location" style="font-size: 11px; color: #888;">{{ scope.row.location }}</div>
          </template>
        </el-table-column>
        <el-table-column label="客户端" width="110" align="center">
          <template #default="scope">
            <el-tag size="small" type="info">{{ scope.row.type || '未知' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="拉取时间" width="160" align="center">
          <template #default="scope">
            <span>{{ formatTime(scope.row.created_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="客户端 UA" min-width="180" show-overflow-tooltip>
          <template #default="scope">
            <span>{{ scope.row.ua }}</span>
          </template>
        </el-table-column>
      </el-table>

      <div style="display: flex; justify-content: center; margin-top: 12px;">
        <el-pagination
          v-show="userSubTotal > 0"
          :current-page="userSubPage"
          :page-size="10"
          layout="prev, pager, next"
          :total="userSubTotal"
          @current-change="handleUserSubPageChange"
        />
      </div>

      <template #footer>
        <span class="dialog-footer">
          <el-button size="small" @click="goToSubscribeLogs(currentUserAccount)">前往订阅雷达 &gt;</el-button>
          <el-button type="primary" size="small" @click="userSubDialogVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>

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
                :key="u.id"
                size="small"
                :type="u.in_honeypot === 1 ? 'warning' : 'success'"
              >
                {{ u.email }}
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="总频次" width="80" align="center" prop="total_logins" />
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

    <!-- Device Association Dialog -->
    <el-dialog v-model="deviceAssociationVisible" title="异常设备关联分析雷达 (物理机防作弊)" width="950px" destroy-on-close>
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 15px; line-height: 1.5;">
        分析登录记录，提取底层物理设备特征（设备 ID），抓出<strong>同一台物理设备登录了 2 个及以上不同账号，或高频使用了 2 个及以上不同 IP 地址</strong>的账号群！
      </div>

      <el-table :data="deviceAssociationList" v-loading="deviceAssociationLoading" stripe size="small" max-height="450px" style="width: 100%;">
        <el-table-column label="设备 ID (硬件特征)" min-width="210">
          <template #default="scope">
            <code class="font-mono" style="font-weight: bold; color: var(--el-color-danger);">{{ scope.row.device_id }}</code>
          </template>
        </el-table-column>
        <el-table-column label="关联账号数" width="110">
          <template #default="scope">
            <span style="font-size: 13px;">
              <strong :style="{ color: scope.row.associated_accounts_count >= 2 ? 'var(--el-color-danger)' : 'inherit' }">{{ scope.row.associated_accounts_count }}</strong> 账号
              <span v-if="scope.row.honeypot_accounts_count > 0" style="color: var(--el-color-warning); font-size: 12px;">
                ({{ scope.row.honeypot_accounts_count }}蜜罐)
              </span>
            </span>
          </template>
        </el-table-column>
        <el-table-column label="共用 IP 数" width="90">
          <template #default="scope">
            <span style="font-size: 13px;">
              <strong :style="{ color: scope.row.associated_ips_count >= 2 ? 'var(--el-color-danger)' : 'inherit' }">{{ scope.row.associated_ips_count }}</strong> IP
            </span>
          </template>
        </el-table-column>
        <el-table-column label="共用账号列表" min-width="210">
          <template #default="scope">
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
              <el-tag
                v-for="u in scope.row.associated_users"
                :key="u.id"
                size="small"
                :type="u.in_honeypot === 1 ? 'warning' : 'success'"
                @click="showUserDetail(u.id)"
                style="cursor: pointer;"
              >
                {{ u.email }}
              </el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="登录 IP 列表 (含归属地)" min-width="260">
          <template #default="scope">
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
              <el-tooltip
                v-for="(item, index) in (scope.row.associated_ips ? scope.row.associated_ips.slice(0, 5) : [])"
                :key="index"
                placement="top"
                :content="formatIpLocation(item) || '归属地未知'"
              >
                <el-tag size="small" type="info" style="cursor: help; display: inline-flex; align-items: center; gap: 4px;">
                  <span>{{ formatIpStr(item) }}</span>
                  <span 
                    v-if="formatIpLocation(item)" 
                    style="color: #67c23a; font-size: 11px; background: rgba(103,194,58,0.15); padding: 0 4px; border-radius: 2px;"
                  >
                    {{ formatIpLocationShort(item) }}
                  </span>
                </el-tag>
              </el-tooltip>

              <el-popover
                v-if="scope.row.associated_ips && scope.row.associated_ips.length > 5"
                placement="right"
                width="360"
                trigger="hover"
              >
                <template #reference>
                  <el-tag size="small" type="info" style="cursor: pointer;">
                    +{{ scope.row.associated_ips.length - 5 }}
                  </el-tag>
                </template>
                <div style="max-height: 280px; overflow-y: auto;">
                  <div style="font-weight: bold; margin-bottom: 8px; font-size: 13px;">该设备关联的全部 IP ({{ scope.row.associated_ips.length }} 个):</div>
                  <div v-for="(item, idx) in scope.row.associated_ips" :key="idx" style="margin-bottom: 6px; font-size: 12px; display: flex; justify-content: space-between; border-bottom: 1px solid #f0f0f0; padding-bottom: 4px;">
                    <code style="color: #409EFF;">{{ formatIpStr(item) }}</code>
                    <span style="color: #67c23a;">{{ formatIpLocation(item) || '归属地未知' }}</span>
                  </div>
                </div>
              </el-popover>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <span class="dialog-footer">
          <el-button size="small" @click="deviceAssociationVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import api, { getSecurePath } from '../api';
import UserDetailDialog from '../components/UserDetailDialog.vue';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();
const router = useRouter();

const list = ref([]);
const total = ref(0);
const listLoading = ref(true);

const detailVisible = ref(false);
const currentUserId = ref(null);

const showColumns = ref(['id', 'email', 'ip', 'location', 'time', 'type']);
const activeFilteredUser = ref(null);

const showUserDetail = (userId) => {
  currentUserId.value = userId;
  detailVisible.value = true;
};

const listQuery = reactive({
  current: 1,
  page_size: 20,
  email: undefined,
  user_id: undefined,
  ip: undefined,
  type: undefined
});

// 单账号订阅拉取记录快捷浮窗相关
const userSubDialogVisible = ref(false);
const currentUserAccount = ref(null);
const userSubList = ref([]);
const userSubTotal = ref(0);
const userSubLoading = ref(false);
const userSubPage = ref(1);

const openUserSubModal = (user) => {
  currentUserAccount.value = user;
  userSubPage.value = 1;
  userSubDialogVisible.value = true;
  fetchUserSubLogs(1);
};

const fetchUserSubLogs = async (page = 1) => {
  if (!currentUserAccount.value) return;
  userSubLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/system/getSubscribeLog`, {
      params: {
        user_id: currentUserAccount.value.id,
        current: page,
        page_size: 10
      }
    });
    userSubList.value = res.data || [];
    userSubTotal.value = res.total || 0;
  } catch (e) {
    ElMessage.error(e.message || '获取拉取记录失败');
  } finally {
    userSubLoading.value = false;
  }
};

const handleUserSubPageChange = (page) => {
  userSubPage.value = page;
  fetchUserSubLogs(page);
};

const goToSubscribeLogs = (user) => {
  userSubDialogVisible.value = false;
  router.push({
    path: '/system/subscribe-logs',
    query: { user_id: user?.id }
  });
};

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

const deviceAssociationVisible = ref(false);
const deviceAssociationLoading = ref(false);
const deviceAssociationList = ref([]);

const formatIpStr = (item) => (typeof item === 'string' ? item : (item?.ip || ''));
const formatIpLocation = (item) => (typeof item === 'object' && item && item.location && item.location !== '未知' ? item.location : '');
const formatIpLocationShort = (item) => {
  const loc = formatIpLocation(item);
  if (!loc) return '';
  const parts = loc.split('-').filter(p => p !== '中国' && p !== 'CN');
  return parts.slice(0, 2).join('·') || loc;
};

const openDeviceAssociationDialog = () => {
  deviceAssociationVisible.value = true;
  fetchDeviceAssociation();
};

const fetchDeviceAssociation = async () => {
  deviceAssociationLoading.value = true;
  try {
    const securePath = getSecurePath();
    const response = await api.get(`/${securePath}/stat/getLoginDeviceAssociationAnalysis`);
    deviceAssociationList.value = response.data || [];
  } catch (error) {
    ElMessage.error(error.message || '获取设备关联分析数据失败');
  } finally {
    deviceAssociationLoading.value = false;
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
  } finally {
    listLoading.value = false;
  }
};

const handleFilter = () => {
  if (listQuery.email || listQuery.user_id) {
    activeFilteredUser.value = { email: listQuery.email, id: listQuery.user_id };
  } else {
    activeFilteredUser.value = null;
  }
  listQuery.current = 1;
  getList();
};

const filterByAccount = (email, userId) => {
  listQuery.email = email || undefined;
  listQuery.user_id = userId ? String(userId) : undefined;
  activeFilteredUser.value = { email, id: userId };
  listQuery.current = 1;
  getList();
};

const resetFilter = () => {
  listQuery.email = undefined;
  listQuery.user_id = undefined;
  listQuery.ip = undefined;
  listQuery.type = undefined;
  activeFilteredUser.value = null;
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
  const urlParams = new URLSearchParams(window.location.search);
  const qEmail = urlParams.get('email');
  const qUserId = urlParams.get('user_id');
  if (qEmail || qUserId) {
    listQuery.email = qEmail || undefined;
    listQuery.user_id = qUserId || undefined;
    activeFilteredUser.value = { email: qEmail, id: qUserId };
  }
  getList();
});
</script>

<style scoped>
.filter-container {
  padding-bottom: 8px;
}
.filter-alert-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #e6f7ff;
  border: 1px solid #91d5ff;
  padding: 8px 12px;
  border-radius: 6px;
  margin-bottom: 14px;
  color: #0050b3;
  font-size: 13px;
  flex-wrap: wrap;
  gap: 8px;
}

/* 移动端卡片流 */
.mobile-card-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.mobile-log-card {
  border-radius: 10px;
  border: 1px solid var(--el-border-color-lighter);
  background: #fafafa;
}
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.user-info-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  flex: 1;
  margin-right: 8px;
}
.user-email {
  font-weight: 600;
  font-size: 14px;
  color: var(--el-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
}
.uid-tag {
  flex-shrink: 0;
  font-size: 11px;
}
.type-tag {
  font-weight: 500;
  flex-shrink: 0;
}
.card-row {
  font-size: 12px;
  color: #555;
  margin-bottom: 4px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.time-text, .loc-text {
  color: #888;
  font-size: 11px;
}
.ip-code {
  padding: 2px 5px;
  background: #eee;
  border-radius: 4px;
  font-family: monospace;
  font-size: 11px;
}
.card-ua {
  font-size: 11px;
  color: #999;
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px dashed #e8e8e8;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.card-actions {
  display: flex;
  gap: 6px;
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px solid #f0f0f0;
  justify-content: flex-end;
  flex-wrap: wrap;
}
.mobile-pagination {
  display: flex;
  justify-content: center;
  margin-top: 14px;
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
