<template>
  <div class="users-container">
    <!-- Action Bar & Filter -->
    <el-card class="filter-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <div class="filter-left flex-center flex-wrap gap-10">
          <el-input
            v-model="searchQuery"
            placeholder="搜索邮箱..."
            prefix-icon="Search"
            clearable
            style="width: 240px"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          />
          
          <el-select v-model="filterPlan" placeholder="订阅计划" clearable style="width: 160px" @change="handleSearch">
            <el-option label="所有计划" value="" />
            <el-option label="无订阅" value="null" />
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>

          <el-select v-model="filterStatus" placeholder="状态" clearable style="width: 120px" @change="handleSearch">
            <el-option label="所有状态" value="" />
            <el-option label="正常" value="0" />
            <el-option label="已封禁" value="1" />
          </el-select>

          <el-button type="primary" @click="handleSearch">筛选</el-button>
          <el-button type="info" plain @click="advancedFilterVisible = true">高级筛选</el-button>
        </div>

        <div class="filter-right flex-center gap-10">
          <el-button type="success" icon="Plus" @click="openCreateDialog">添加用户</el-button>
        </div>
      </div>

      <div v-if="useAdvancedFilter" class="flex-center mt-10 filter-tags gap-5" style="justify-content: flex-start; flex-wrap: wrap;">
        <span class="font-12" style="color: var(--el-text-color-secondary)">已启用高级筛选：</span>
        <el-tag 
          v-for="(f, idx) in advancedFilters" 
          :key="idx" 
          closable 
          size="small" 
          @close="handleRemoveFilterCondition(idx)"
        >
          {{ filterKeys[f.key] }} {{ f.condition }} {{ f.key === 'plan_id' ? getPlanName(f.value) : f.value }}
        </el-tag>
        <el-button type="danger" link size="small" @click="handleClearAdvancedFilter">清除筛选</el-button>
      </div>
    </el-card>

    <!-- Filter Indicator for Invites -->
    <el-card v-if="filterInviteByEmail" class="filter-indicator-card mt-20" shadow="never">
      <div class="flex-between">
        <div class="flex-center" style="gap: 8px;">
          <el-icon><User /></el-icon>
          <span>正在查看用户 <strong style="color: var(--el-color-primary);">{{ filterInviteByEmail }}</strong> 的邀请人列表</span>
        </div>
        <el-button type="danger" size="small" icon="Close" @click="clearInviteFilter">清除过滤</el-button>
      </div>
    </el-card>

    <!-- User Table -->
    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="users" v-loading="loading" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" align="center" />
        
        <el-table-column prop="email" label="邮箱" min-width="180">
          <template #default="scope">
            <el-tooltip placement="top" :enterable="true">
              <template #content>
                <div class="client-tooltip-content" style="min-width: 220px;">
                  <div style="font-weight: bold; margin-bottom: 6px; border-bottom: 1px solid rgba(255, 255, 255, 0.15); padding-bottom: 4px;">👥 登录与在线状态</div>
                  <div style="margin-bottom: 4px;">
                    在线状态: 
                    <span :style="{ color: scope.row.alive_ip > 0 ? '#67C23A' : '#909399', fontWeight: 'bold' }">
                      {{ scope.row.alive_ip > 0 ? '在线 (' + scope.row.alive_ip + ' 个设备)' : '离线' }}
                    </span>
                  </div>
                  <div style="margin-bottom: 4px;">
                    登录 IP: 
                    <code 
                      @click="copyToClipboard(scope.row.last_login_ip)"
                      style="background: rgba(0,0,0,0.35); padding: 2px 6px; border-radius: 4px; font-family: monospace; cursor: pointer; color: #409EFF; text-decoration: underline;"
                      title="点击复制 IP"
                    >
                      {{ scope.row.last_login_ip }}
                    </code>
                  </div>
                  <div style="margin-bottom: 4px;">IP 位置: <span>{{ scope.row.last_login_location }}</span></div>
                  <div>最后登录: <span>{{ scope.row.last_login_time }}</span></div>
                </div>
              </template>
              <div class="email-cell flex-center" style="justify-content: flex-start; gap: 6px; cursor: help;">
                <span :class="['status-dot', scope.row.alive_ip > 0 ? 'online' : 'offline']">●</span>
                <span>{{ scope.row.email }}</span>
              </div>
            </el-tooltip>
          </template>
        </el-table-column>
        
        <el-table-column prop="banned" label="状态" width="100" align="center">
          <template #default="scope">
            <el-tag v-if="scope.row.in_honeypot === 1" type="warning" size="small">
              蜜罐接管
            </el-tag>
            <el-tag v-else :type="scope.row.banned ? 'danger' : 'success'" size="small">
              {{ scope.row.banned ? '已封禁' : '正常' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="plan_name" label="订阅" width="120" show-overflow-tooltip>
          <template #default="scope">
            <el-tag :type="scope.row.plan_name ? 'primary' : 'info'" size="small">
              {{ scope.row.plan_name || '无订阅' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column prop="group_id" label="权限组" width="120" show-overflow-tooltip>
          <template #default="scope">
            <el-tag v-if="scope.row.group_id" type="warning" size="small">
              {{ getGroupName(scope.row.group_id) }}
            </el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>

        <el-table-column label="已用(G)" width="100" align="right">
          <template #default="scope">
            <span :style="getTrafficStyle(scope.row.u + scope.row.d, scope.row.transfer_enable)">
              {{ ((scope.row.u + scope.row.d) / 1073741824).toFixed(2) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="流量(G)" width="100" align="right">
          <template #default="scope">
            <span>{{ (scope.row.transfer_enable / 1073741824).toFixed(2) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="设备数" width="90" align="center">
          <template #default="scope">
            <span>{{ scope.row.alive_ip || 0 }} / {{ scope.row.device_limit !== null && scope.row.device_limit !== undefined ? scope.row.device_limit : '∞' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="到期时间" width="150">
          <template #default="scope">
            <span :class="{ 'text-danger': isExpired(scope.row.expired_at) }">
              {{ formatTime(scope.row.expired_at) }}
            </span>
          </template>
        </el-table-column>


        <el-table-column label="客户端类型" width="180">
          <template #default="scope">
            <div v-if="parseClientHistory(scope.row.client_type).length > 0" class="client-type-text">
              <span>{{ getClientTypeMain(scope.row.client_type) }}</span>
              <el-tooltip placement="top" effect="dark" raw-content>
                <template #content>
                  <div class="client-tooltip-content">
                    <div v-for="(log, idx) in parseClientHistory(scope.row.client_type)" :key="idx" class="client-tooltip-item">
                      <span style="color: var(--el-color-primary)">{{ formatTime(log.time) }}</span><br/>
                      <span>{{ log.type }} (IP: {{ log.ip }})</span><br/>
                      <span style="color: var(--el-text-color-secondary); font-size: 11px">{{ log.ua }}</span>
                    </div>
                  </div>
                </template>
                <span v-if="parseClientHistory(scope.row.client_type).length > 1" class="client-more-tag">
                  +{{ parseClientHistory(scope.row.client_type).length - 1 }}
                </span>
              </el-tooltip>
            </div>
            <span v-else>-</span>
          </template>
        </el-table-column>

        <el-table-column label="余额" width="90" align="right">
          <template #default="scope">
            <span>{{ ((scope.row.balance || 0) / 100).toFixed(2) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="佣金" width="90" align="right">
          <template #default="scope">
            <span>{{ ((scope.row.commission_balance || 0) / 100).toFixed(2) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="加入时间" width="160">
          <template #default="scope">
            <span>{{ formatTime(scope.row.created_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="80" align="right" fixed="right">
          <template #default="scope">
            <el-dropdown trigger="click" @command="(cmd) => handleMoreCommand(cmd, scope.row)">
              <el-button type="primary" link>
                操作<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="edit" icon="Edit">编辑</el-dropdown-item>
                  <el-dropdown-item command="assign" icon="Plus">分配订单</el-dropdown-item>
                  <el-dropdown-item command="copy" icon="CopyDocument">复制订阅URL</el-dropdown-item>
                  <el-dropdown-item command="reset" icon="Refresh">重置UUID及订阅URL</el-dropdown-item>
                  <el-dropdown-item command="orders" icon="Tickets">TA的订单</el-dropdown-item>
                  <el-dropdown-item command="invites" icon="User">TA的邀请</el-dropdown-item>
                  <el-dropdown-item command="invite_records" icon="Share">TA的邀请记录</el-dropdown-item>
                  <el-dropdown-item command="traffic_logs" icon="Histogram">TA的流量记录</el-dropdown-item>
                  <el-dropdown-item command="toggleHoneypot" :icon="scope.row.in_honeypot === 1 ? 'CircleCheck' : 'Warning'" :style="{ color: scope.row.in_honeypot === 1 ? 'var(--el-color-success)' : 'var(--el-color-warning)' }">
                    {{ scope.row.in_honeypot === 1 ? '移出安全蜜罐' : '加入安全蜜罐' }}
                  </el-dropdown-item>
                  <el-dropdown-item command="delete" divided icon="Delete" style="color: var(--el-color-danger)">删除用户</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination flex-between mt-20">
        <span class="pagination-info">共 {{ total }} 条记录</span>
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          layout="sizes, prev, pager, next"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <!-- Create User Dialog -->
    <el-dialog v-model="createVisible" title="创建用户" :width="isMobile ? '95%' : '500px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="createForm" :rules="createRules" ref="createFormRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '90px'">
        <el-form-item label="邮箱前缀" prop="email_prefix">
          <el-input v-model="createForm.email_prefix" placeholder="例如 user" />
        </el-form-item>
        <el-form-item label="邮箱后缀" prop="email_suffix">
          <el-input v-model="createForm.email_suffix" placeholder="例如 qq.com" />
        </el-form-item>
        <el-form-item label="登录密码" prop="password">
          <el-input v-model="createForm.password" placeholder="留空默认和邮箱相同" show-password />
        </el-form-item>
        <el-form-item label="订阅计划" prop="plan_id">
          <el-select v-model="createForm.plan_id" placeholder="选择计划" style="width: 100%">
            <el-option label="不绑定订阅" :value="null" />
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="createForm.expired_at"
            type="datetime"
            placeholder="选择过期时间，留空长期有效"
            style="width: 100%"
            value-format="X"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="createVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleCreateUser">确定</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Edit User Dialog -->
    <el-dialog v-model="editVisible" title="编辑用户" :width="isMobile ? '95%' : '550px'" :top="isMobile ? '2vh' : '8vh'">
      <el-form :model="editForm" ref="editFormRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '100px'" v-if="editForm.id">
        <el-tabs v-model="activeTab">
          <!-- Basic Profile Info -->
          <el-tab-pane label="基本资料" name="profile">
            <el-form-item label="用户邮箱" required>
              <el-input v-model="editForm.email" />
            </el-form-item>
            <el-form-item label="登录密码">
              <el-input v-model="editForm.password" placeholder="留空表示不修改" show-password />
            </el-form-item>
            <el-form-item label="余额 (元)">
              <el-input-number v-model="editForm.balance" :precision="2" :step="10" style="width: 150px" />
            </el-form-item>
            <el-form-item label="佣金 (元)">
              <el-input-number v-model="editForm.commission_balance" :precision="2" :step="10" style="width: 150px" />
            </el-form-item>
          </el-tab-pane>

          <!-- Traffic and Plan config -->
          <el-tab-pane label="订阅 & 流量" name="subscription">
            <el-form-item label="订阅计划">
              <el-select v-model="editForm.plan_id" placeholder="选择计划" style="width: 100%">
                <el-option label="无订阅" :value="null" />
                <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="总流量 (GB)">
              <el-input-number v-model="editForm.transfer_enable_gb" :min="0" :step="10" style="width: 150px" />
            </el-form-item>
            <el-form-item label="已用上行 (GB)">
              <el-input-number v-model="editForm.u_gb" :min="0" :precision="2" style="width: 150px" />
            </el-form-item>
            <el-form-item label="已用下行 (GB)">
              <el-input-number v-model="editForm.d_gb" :min="0" :precision="2" style="width: 150px" />
            </el-form-item>
            <el-form-item label="到期时间">
              <el-date-picker
                v-model="editForm.expired_at"
                type="datetime"
                placeholder="选择过期时间，留空长期有效"
                style="width: 100%"
                value-format="X"
              />
            </el-form-item>
          </el-tab-pane>

          <!-- Settings config -->
          <el-tab-pane label="其它设置" name="settings">
            <el-form-item label="设备数限制">
              <el-input-number v-model="editForm.device_limit" :min="0" :step="1" placeholder="留空或0不限制" style="width: 150px" />
            </el-form-item>
            <el-form-item label="端口速度限制 (Mbps)">
              <el-input-number v-model="editForm.speed_limit" :min="0" :step="10" placeholder="留空不限制" style="width: 150px" />
            </el-form-item>
            <el-form-item label="账号状态">
              <el-radio-group v-model="editForm.banned">
                <el-radio :label="0">正常</el-radio>
                <el-radio :label="1">封禁</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="管理员权限">
              <el-radio-group v-model="editForm.is_admin">
                <el-radio :label="0">否</el-radio>
                <el-radio :label="1">是</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="员工权限">
              <el-radio-group v-model="editForm.is_staff">
                <el-radio :label="0">否</el-radio>
                <el-radio :label="1">是</el-radio>
              </el-radio-group>
            </el-form-item>
          </el-tab-pane>
        </el-tabs>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="editVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleUpdateUser">保存</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Advanced Filter Dialog -->
    <el-dialog v-model="advancedFilterVisible" title="高级筛选" :width="isMobile ? '95%' : '700px'" :top="isMobile ? '2vh' : '8vh'">
      <el-table :data="advancedFilters" style="width: 100%">
        <el-table-column label="字段" width="180">
          <template #default="scope">
            <el-select v-model="scope.row.key" placeholder="选择字段" style="width: 100%">
              <el-option v-for="(label, key) in filterKeys" :key="key" :label="label" :value="key" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="条件" width="130">
          <template #default="scope">
            <el-select v-model="scope.row.condition" placeholder="选择条件" style="width: 100%">
              <el-option v-for="c in filterConditions" :key="c.value" :label="c.label" :value="c.value" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="数值">
          <template #default="scope">
            <!-- plan_id input -->
            <el-select v-if="scope.row.key === 'plan_id'" v-model="scope.row.value" placeholder="选择计划" style="width: 100%">
              <el-option label="无订阅" value="null" />
              <el-option v-for="p in plans" :key="p.id" :label="p.name" :value="p.id" />
            </el-select>
            <!-- banned/is_admin input -->
            <el-select v-else-if="scope.row.key === 'banned' || scope.row.key === 'is_admin'" v-model="scope.row.value" placeholder="请选择" style="width: 100%">
              <el-option label="是 / 封禁" :value="1" />
              <el-option label="否 / 正常" :value="0" />
            </el-select>
            <!-- in_honeypot input -->
            <el-select v-else-if="scope.row.key === 'in_honeypot'" v-model="scope.row.value" placeholder="请选择" style="width: 100%">
              <el-option label="是" :value="1" />
              <el-option label="否" :value="0" />
            </el-select>
            <!-- Date/Time input -->
            <el-date-picker
              v-else-if="scope.row.key === 'expired_at' || scope.row.key === 'client_login_at'"
              v-model="scope.row.value"
              type="datetime"
              placeholder="选择日期时间"
              style="width: 100%"
              value-format="X"
            />
            <!-- Standard text input -->
            <el-input v-else v-model="scope.row.value" placeholder="请输入数值" style="width: 100%" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="scope">
            <el-button type="danger" icon="Delete" circle size="small" @click="handleRemoveFilterCondition(scope.$index)" />
          </template>
        </el-table-column>
      </el-table>
      <div class="mt-15" style="margin-top: 15px">
        <el-button type="primary" plain icon="Plus" @click="handleAddFilterCondition">添加筛选条件</el-button>
      </div>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="advancedFilterVisible = false">取消</el-button>
          <el-button type="primary" @click="handleApplyAdvancedFilter">确定筛选</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Assign Order Dialog -->
    <el-dialog v-model="assignVisible" title="分配订阅订单" :width="isMobile ? '95%' : '550px'">
      <el-form :model="assignForm" :rules="assignRules" ref="assignFormRef" :label-width="isMobile ? undefined : '100px'">
        <el-form-item label="用户邮箱">
          <el-input v-model="assignForm.email" disabled />
        </el-form-item>

        <el-form-item label="选择订阅" prop="plan_id">
          <el-select v-model="assignForm.plan_id" placeholder="选择计划" style="width: 100%" @change="handlePlanChange">
            <el-option v-for="plan in plans" :key="plan.id" :label="plan.name" :value="plan.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="账单周期" prop="period">
          <el-select v-model="assignForm.period" placeholder="选择购买周期" style="width: 100%">
            <el-option label="月付" value="month_price" />
            <el-option label="季付" value="quarter_price" />
            <el-option label="半年付" value="half_year_price" />
            <el-option label="年付" value="year_price" />
            <el-option label="两年付" value="two_year_price" />
            <el-option label="三年付" value="three_year_price" />
            <el-option label="一次性" value="onetime_price" />
            <el-option label="流量重置包" value="reset_price" />
          </el-select>
        </el-form-item>

        <el-form-item label="订单金额 (元)" prop="total_amount">
          <el-input-number v-model="assignForm.total_amount" :precision="2" :min="0" style="width: 180px" />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="assignVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleAssignSubmit">分配并激活</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- User Traffic Logs Dialog -->
    <el-dialog v-model="trafficVisible" title="TA的流量明细记录" :width="isMobile ? '95%' : '650px'">
      <el-table :data="trafficLogs" v-loading="trafficLoading" stripe style="width: 100%" max-height="400px">
        <el-table-column prop="record_at" label="记录时间" width="180">
          <template #default="scope">
            {{ formatTime(scope.row.record_at) }}
          </template>
        </el-table-column>
        <el-table-column prop="u" label="上行流量" width="120">
          <template #default="scope">
            {{ formatTraffic(scope.row.u) }}
          </template>
        </el-table-column>
        <el-table-column prop="d" label="下行流量" width="120">
          <template #default="scope">
            {{ formatTraffic(scope.row.d) }}
          </template>
        </el-table-column>
        <el-table-column label="小计" min-width="120">
          <template #default="scope">
            {{ formatTraffic(scope.row.u + scope.row.d) }}
          </template>
        </el-table-column>
      </el-table>
      <div class="pagination flex-between mt-20">
        <span class="pagination-info">共 {{ trafficTotal }} 条记录</span>
        <el-pagination
          v-model:current-page="trafficPage"
          v-model:page-size="trafficPageSize"
          layout="prev, pager, next"
          :total="trafficTotal"
          @current-change="handleTrafficPageChange"
        />
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();

const copyToClipboard = (text) => {
  if (!text || text === '无登录记录' || text === '未知') {
    ElMessage.warning('没有有效的 IP 可以复制');
    return;
  }
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => {
      ElMessage.success('IP 已成功复制到剪贴板: ' + text);
    }).catch(() => {
      fallbackCopy(text);
    });
  } else {
    fallbackCopy(text);
  }
};

const fallbackCopy = (text) => {
  const input = document.createElement('textarea');
  input.value = text;
  document.body.appendChild(input);
  input.select();
  document.execCommand('copy');
  document.body.removeChild(input);
  ElMessage.success('IP 已成功复制到剪贴板: ' + text);
};

const loading = ref(false);
const submitLoading = ref(false);
const users = ref([]);
const total = ref(0);
const currentPage = ref(1);
const pageSize = ref(10);

const searchQuery = ref('');
const filterPlan = ref('');
const filterStatus = ref('');
const plans = ref([]);

const useAdvancedFilter = ref(false);
const advancedFilterVisible = ref(false);
const advancedFilters = ref([
  { key: 'email', condition: '模糊', value: '' }
]);

const filterKeys = {
  email: '邮箱',
  id: '用户ID',
  plan_id: '订阅计划',
  transfer_enable: '总流量限制 (GB)',
  d: '已用下行流量 (GB)',
  expired_at: '到期时间',
  client_login_at: '客户端登录时间',
  client_type: '客户端类型',
  device_limit: '设备数限制',
  uuid: 'UUID',
  token: '订阅Token',
  invite_by_email: '邀请人邮箱',
  invite_user_id: '邀请人ID',
  banned: '封禁状态',
  remarks: '备注',
  is_admin: '是否管理员',
  in_honeypot: '是否蜜罐'
};

const filterConditions = [
  { value: '模糊', label: '模糊' },
  { value: '=', label: '=' },
  { value: '>', label: '>' },
  { value: '<', label: '<' },
  { value: '>=', label: '>=' },
  { value: '<=', label: '<=' },
  { value: '!=', label: '!=' }
];

const createVisible = ref(false);
const createFormRef = ref(null);
const createForm = reactive({
  email_prefix: '',
  email_suffix: 'qq.com',
  password: '',
  plan_id: null,
  expired_at: null,
});

const createRules = {
  email_prefix: [{ required: true, message: '请输入邮箱前缀', trigger: 'blur' }],
  email_suffix: [{ required: true, message: '请输入邮箱后缀', trigger: 'blur' }],
};

const editVisible = ref(false);
const editFormRef = ref(null);
const activeTab = ref('profile');
const editForm = reactive({
  id: null,
  email: '',
  password: '',
  balance: 0,
  commission_balance: 0,
  plan_id: null,
  transfer_enable_gb: 0,
  u_gb: 0,
  d_gb: 0,
  expired_at: null,
  device_limit: 0,
  speed_limit: 0,
  banned: 0,
  is_admin: 0,
  is_staff: 0,
});

const router = useRouter();

// Invite filter state
const filterInviteByEmail = ref('');

const clearInviteFilter = () => {
  filterInviteByEmail.value = '';
  currentPage.value = 1;
  fetchUsers();
};

// Assign order state
const assignVisible = ref(false);
const assignFormRef = ref(null);
const assignForm = reactive({
  email: '',
  plan_id: null,
  period: 'month_price',
  total_amount: 0,
});
const assignRules = {
  plan_id: [{ required: true, message: '请选择订阅计划', trigger: 'change' }],
  period: [{ required: true, message: '请选择账单周期', trigger: 'change' }],
  total_amount: [{ required: true, message: '请输入订单金额', trigger: 'blur' }],
};

const openAssignDialog = (row) => {
  assignForm.email = row.email;
  assignForm.plan_id = null;
  assignForm.period = 'month_price';
  assignForm.total_amount = 0;
  assignVisible.value = true;
};

const handlePlanChange = (planId) => {
  const plan = plans.value.find(p => p.id === planId);
  if (plan && plan.month_price) {
    assignForm.total_amount = plan.month_price / 100;
  }
};

const handleAssignSubmit = async () => {
  if (!assignFormRef.value) return;
  await assignFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const payload = {
        email: assignForm.email,
        plan_id: assignForm.plan_id,
        period: assignForm.period,
        total_amount: Math.round(assignForm.total_amount * 100),
      };

      const res = await api.post(`/${securePath}/order/assign`, payload);
      if (res.data) {
        await api.post(`/${securePath}/order/paid`, { trade_no: res.data });
        ElMessage.success('分配并开通订阅成功！');
      }
      assignVisible.value = false;
      fetchUsers();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

// User Traffic Logs state
const trafficVisible = ref(false);
const trafficLoading = ref(false);
const trafficLogs = ref([]);
const trafficTotal = ref(0);
const trafficPage = ref(1);
const trafficPageSize = ref(10);
const trafficUserId = ref(null);

const openTrafficDialog = (row) => {
  trafficUserId.value = row.id;
  trafficPage.value = 1;
  trafficVisible.value = true;
  fetchTrafficLogs();
};

const fetchTrafficLogs = async () => {
  if (!trafficUserId.value) return;
  trafficLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getStatUser`, {
      params: {
        user_id: trafficUserId.value,
        current: trafficPage.value,
        pageSize: trafficPageSize.value,
      }
    });
    if (res.data) {
      trafficLogs.value = res.data;
      trafficTotal.value = res.total;
    }
  } catch (err) {
    console.error(err);
  } finally {
    trafficLoading.value = false;
  }
};

const handleTrafficPageChange = (val) => {
  trafficPage.value = val;
  fetchTrafficLogs();
};

const getTrafficStyle = (used, total) => {
  if (used === 0) {
    return { color: 'var(--el-text-color-placeholder)' }; // 没用流量显示灰色
  }
  if (total > 0 && used >= total) {
    return { color: 'var(--el-color-danger)', fontWeight: 'bold' }; // 用完显示红色
  }
  return { color: 'var(--el-color-primary)' }; // 没超显示蓝色
};

// Helper formatting functions
const formatTraffic = (bytes) => {
  if (bytes === null || bytes === undefined) return '不限';
  if (bytes === 0) return '0 GB';
  const g = 1073741824; // 1024^3
  if (bytes >= 1099511627776) { // 1TB
    return (bytes / 1099511627776).toFixed(2) + ' TB';
  }
  return (bytes / g).toFixed(2) + ' GB';
};

const calculatePercentage = (used, total) => {
  if (!total) return 0;
  const pct = (used / total) * 100;
  return Math.min(parseFloat(pct.toFixed(1)), 100);
};

const getProgressStatus = (used, total) => {
  if (!total) return 'success';
  const pct = used / total;
  if (pct >= 0.9) return 'exception';
  if (pct >= 0.75) return 'warning';
  return 'success';
};

const formatTime = (timestamp) => {
  if (!timestamp) return '长期有效';
  const date = new Date(timestamp * 1000);
  return date.toLocaleString();
};

const isExpired = (timestamp) => {
  if (!timestamp) return false;
  return timestamp < Date.now() / 1000;
};

const getPlanName = (id) => {
  if (id === 'null') return '无订阅';
  const plan = plans.value.find(p => p.id == id);
  return plan ? plan.name : id;
};

// Fetch plans for dropdowns
const fetchPlans = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/plan/fetch`);
    if (res.data) {
      plans.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const groupList = ref([]);
const fetchGroups = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/group/fetch`);
    if (res.data) {
      groupList.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const getGroupName = (id) => {
  if (!id) return '-';
  const g = groupList.value.find(item => Number(item.id) === Number(id));
  return g ? g.name : `组 ${id}`;
};

const parseClientHistory = (jsonStr) => {
  if (!jsonStr) return [];
  try {
    const arr = JSON.parse(jsonStr);
    return Array.isArray(arr) ? arr : [];
  } catch (e) {
    return [];
  }
};

const getClientTypeMain = (jsonStr) => {
  const history = parseClientHistory(jsonStr);
  if (history.length === 0) return '-';
  return history[0].type || '未知';
};

// Fetch user data with search filters
const fetchUsers = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    
    // Construct filters
    const filter = [];
    if (useAdvancedFilter.value) {
      advancedFilters.value.forEach(f => {
        if (f.value !== undefined && f.value !== null && f.value !== '') {
          let val = f.value;
          if (f.key === 'expired_at' || f.key === 'client_login_at') {
            if (typeof val === 'string' || typeof val === 'number') {
              const num = Number(val);
              if (num > 1000000000000) { // millisecond
                val = Math.floor(num / 1000);
              }
            } else if (val instanceof Date) {
              val = Math.floor(val.getTime() / 1000);
            }
          }
          filter.push({ key: f.key, condition: f.condition, value: val });
        }
      });
    } else {
      if (searchQuery.value) {
        filter.push({ key: 'email', condition: '模糊', value: searchQuery.value });
      }
      if (filterPlan.value) {
        filter.push({ key: 'plan_id', condition: '=', value: filterPlan.value });
      }
      if (filterStatus.value !== '') {
        filter.push({ key: 'banned', condition: '=', value: filterStatus.value });
      }
      if (filterInviteByEmail.value) {
        filter.push({ key: 'invite_by_email', condition: '=', value: filterInviteByEmail.value });
      }
    }

    const res = await api.get(`/${securePath}/user/fetch`, {
      params: {
        current: currentPage.value,
        pageSize: pageSize.value,
        filter: filter,
      }
    });

    if (res.data) {
      users.value = res.data;
      total.value = res.total;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleAddFilterCondition = () => {
  advancedFilters.value.push({ key: 'email', condition: '模糊', value: '' });
};

const handleRemoveFilterCondition = (idx) => {
  advancedFilters.value.splice(idx, 1);
  if (advancedFilters.value.length === 0) {
    handleAddFilterCondition();
  }
};

const handleApplyAdvancedFilter = () => {
  useAdvancedFilter.value = true;
  advancedFilterVisible.value = false;
  currentPage.value = 1;
  fetchUsers();
};

const handleClearAdvancedFilter = () => {
  useAdvancedFilter.value = false;
  advancedFilters.value = [{ key: 'email', condition: '模糊', value: '' }];
  currentPage.value = 1;
  fetchUsers();
};

const handleSearch = () => {
  currentPage.value = 1;
  fetchUsers();
};

const handleSizeChange = (val) => {
  pageSize.value = val;
  fetchUsers();
};

const handleCurrentChange = (val) => {
  currentPage.value = val;
  fetchUsers();
};

// Create User dialog actions
const openCreateDialog = () => {
  createForm.email_prefix = '';
  createForm.password = '';
  createForm.plan_id = null;
  createForm.expired_at = null;
  createVisible.value = true;
};

const handleCreateUser = async () => {
  if (!createFormRef.value) return;
  await createFormRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      const payload = {
        email_prefix: createForm.email_prefix,
        email_suffix: createForm.email_suffix,
        plan_id: createForm.plan_id,
        expired_at: createForm.expired_at,
      };
      if (createForm.password) {
        payload.password = createForm.password;
      }
      
      await api.post(`/${securePath}/user/generate`, payload);
      ElMessage.success('创建用户成功');
      createVisible.value = false;
      fetchUsers();
    } catch (err) {
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

// Edit user actions
const openEditDialog = (row) => {
  activeTab.value = 'profile';
  
  // Map row fields into the form variables
  editForm.id = row.id;
  editForm.email = row.email;
  editForm.password = '';
  editForm.balance = row.balance / 100;
  editForm.commission_balance = row.commission_balance / 100;
  editForm.plan_id = row.plan_id;
  editForm.transfer_enable_gb = row.transfer_enable / 1073741824;
  editForm.u_gb = row.u / 1073741824;
  editForm.d_gb = row.d / 1073741824;
  editForm.expired_at = row.expired_at ? String(row.expired_at) : null;
  editForm.device_limit = row.device_limit || 0;
  editForm.speed_limit = row.speed_limit || 0;
  editForm.banned = row.banned;
  editForm.is_admin = row.is_admin !== undefined ? Number(row.is_admin) : 0;
  editForm.is_staff = row.is_staff !== undefined ? Number(row.is_staff) : 0;
  
  editVisible.value = true;
};

const handleUpdateUser = async () => {
  submitLoading.value = true;
  try {
    const securePath = getSecurePath();
    
    const parseIntegerOrNull = (val) => {
      if (val === null || val === undefined || val === '') return null;
      const parsed = parseInt(val);
      return isNaN(parsed) ? null : parsed;
    };

    const payload = {
      id: editForm.id,
      email: editForm.email,
      balance: Math.round(editForm.balance * 100),
      commission_balance: Math.round(editForm.commission_balance * 100),
      plan_id: editForm.plan_id,
      transfer_enable: Math.round(editForm.transfer_enable_gb * 1073741824),
      u: Math.round(editForm.u_gb * 1073741824),
      d: Math.round(editForm.d_gb * 1073741824),
      expired_at: parseIntegerOrNull(editForm.expired_at),
      device_limit: parseIntegerOrNull(editForm.device_limit),
      speed_limit: parseIntegerOrNull(editForm.speed_limit),
      banned: editForm.banned,
      is_admin: editForm.is_admin,
      is_staff: editForm.is_staff,
    };
    
    if (editForm.password) {
      payload.password = editForm.password;
    }
    
    await api.post(`/${securePath}/user/update`, payload);
    ElMessage.success('保存用户信息成功');
    editVisible.value = false;
    fetchUsers();
  } catch (err) {
    console.error(err);
  } finally {
    submitLoading.value = false;
  }
};

// Dropdown commands
const handleMoreCommand = async (command, row) => {
  const securePath = getSecurePath();
  
  if (command === 'edit') {
    openEditDialog(row);
  } else if (command === 'assign') {
    openAssignDialog(row);
  } else if (command === 'orders') {
    router.push({ name: 'Orders', query: { email: row.email } });
  } else if (command === 'invites') {
    filterInviteByEmail.value = row.email;
    currentPage.value = 1;
    fetchUsers();
  } else if (command === 'invite_records') {
    router.push({ name: 'Orders', query: { invite_user_id: row.id, invite_user_email: row.email } });
  } else if (command === 'traffic_logs') {
    openTrafficDialog(row);
  } else if (command === 'reset') {
    ElMessageBox.confirm('确定要重置该用户的重置订阅密钥和连接 Token 吗？重置后，该用户现有的客户端配置将失效，需要重新导入！', '提示', {
      type: 'warning',
      confirmButtonText: '确定重置',
      cancelButtonText: '取消'
    }).then(async () => {
      await api.post(`/${securePath}/user/resetSecret`, { id: row.id });
      ElMessage.success('密钥重置成功，请让用户重新导入订阅');
      fetchUsers();
    }).catch(() => {});
    
  } else if (command === 'copy') {
    if (row.subscribe_url) {
      navigator.clipboard.writeText(row.subscribe_url);
      ElMessage.success('订阅链接已复制到剪贴板');
    }
    
  } else if (command === 'toggleHoneypot') {
    const actionText = row.in_honeypot === 1 ? '移出蜜罐' : '加入蜜罐';
    ElMessageBox.confirm(`确定要将该用户 ${row.email} ${actionText}吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    }).then(async () => {
      await api.post(`/${securePath}/user/toggleHoneypot`, { id: row.id });
      ElMessage.success(`${actionText}成功`);
      fetchUsers();
    }).catch(() => {});

  } else if (command === 'delete') {
    ElMessageBox.confirm('确定要永久删除该用户吗？删除后该用户的所有订单、工单、推广关联等数据都将被清理！此操作无法撤销！', '高危操作', {
      type: 'error',
      confirmButtonText: '确认删除',
      cancelButtonText: '取消',
    }).then(async () => {
      await api.post(`/${securePath}/user/delUser`, { id: row.id });
      ElMessage.success('删除用户成功');
      fetchUsers();
    }).catch(() => {});
  }
};

onMounted(() => {
  fetchPlans();
  fetchGroups();
  fetchUsers();
});
</script>

<style scoped>
.filter-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.table-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.traffic-progress {
  max-width: 250px;
}

.traffic-text {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  margin-bottom: 4px;
}

.text-danger {
  color: var(--el-color-danger);
  font-weight: 500;
}

.online-badge {
  transform: translateY(-2px);
}

.pagination-info {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.mt-20 {
  margin-top: 20px;
}

.flex-wrap {
  flex-wrap: wrap;
}

.gap-10 {
  gap: 10px;
}

.status-dot {
  display: inline-block;
  font-size: 14px;
  line-height: 1;
  vertical-align: middle;
  margin-right: 4px;
  transition: all 0.3s ease;
}
.status-dot.online {
  color: #67C23A;
  text-shadow: 0 0 6px #67C23A, 0 0 10px #67C23A;
  animation: status-pulse 1.8s infinite ease-in-out;
}
.status-dot.offline {
  color: var(--el-color-info);
  opacity: 0.5;
}

@keyframes status-pulse {
  0% {
    opacity: 0.7;
    transform: scale(0.95);
  }
  50% {
    opacity: 1;
    transform: scale(1.15);
  }
  100% {
    opacity: 0.7;
    transform: scale(0.95);
  }
}

.client-type-text {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.client-more-tag {
  border-bottom: 1px dotted var(--el-text-color-secondary);
  cursor: help;
  color: var(--el-text-color-secondary);
  font-size: 11px;
}
.client-tooltip-content {
  padding: 4px;
  font-size: 12px;
  line-height: 1.5;
}
.client-tooltip-item {
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  padding: 4px 0;
}
.client-tooltip-item:last-child {
  border-bottom: none;
}

.filter-indicator-card {
  border-radius: 16px;
  border: 1px solid var(--el-color-primary-light-8);
  background-color: var(--el-color-primary-light-9);
  padding: 0px 10px;
}
</style>
