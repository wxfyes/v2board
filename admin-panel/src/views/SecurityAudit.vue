<template>
  <div class="security-audit-container">
    <!-- Summary Cards -->
    <el-row :gutter="16" class="mb-20">
      <el-col :xs="24" :sm="8">
        <el-card shadow="hover" class="stat-card">
          <div class="flex-between">
            <div>
              <div class="stat-title">待处理高风险拦截</div>
              <div class="stat-value text-danger">{{ flaggedCount }}</div>
            </div>
            <div class="stat-icon-wrapper bg-danger-light text-danger">
              <el-icon><Warning /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="8">
        <el-card shadow="hover" class="stat-card">
          <div class="flex-between">
            <div>
              <div class="stat-title">疑似工具拉取记录</div>
              <div class="stat-value text-warning">{{ suspectedCount }}</div>
            </div>
            <div class="stat-icon-wrapper bg-warning-light text-warning">
              <el-icon><Odometer /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="8">
        <el-card shadow="hover" class="stat-card">
          <div class="flex-between">
            <div>
              <div class="stat-title">已启用白名单/蜜罐</div>
              <div class="stat-value text-primary">
                {{ whitelistList.length }} <span class="stat-unit">白</span> / {{ honeypotCount }} <span class="stat-unit">蜜</span>
              </div>
            </div>
            <div class="stat-icon-wrapper bg-primary-light text-primary">
              <el-icon><Lock /></el-icon>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Main Audit List -->
    <el-card class="rank-card anomalies-card" shadow="hover">
      <template #header>
        <div class="flex-between flex-wrap gap-10">
          <div>
            <span class="rank-title-text">{{ systemName ? systemName + '订阅安全审计中心' : '订阅安全审计中心' }}</span>
            <div class="rank-subtitle-text">对多 IP 扩散分享、高频测活、命令行客户端等进行精细化审查与蜜罐重定向管理</div>
          </div>
          <div class="flex-end gap-10">
            <el-button type="success" plain size="small" icon="Cpu" @click="openCustomAuditDialog">自定义特征探测</el-button>
            <el-button type="warning" plain size="small" icon="Connection" @click="openIpAssociationDialog">IP 关联分析</el-button>
            <el-button type="primary" plain size="small" icon="Setting" @click="openSettingsDialog">审计规则 & 白名单</el-button>
            <el-button type="danger" plain size="small" icon="Delete" :disabled="flaggedCount === 0" @click="handleClearAllAnomalies">一键忽略全部预警</el-button>
            <el-button type="primary" size="small" icon="Refresh" :loading="anomaliesLoading" @click="fetchAnomalies">刷新</el-button>
          </div>
        </div>
      </template>

      <!-- Filter Toolbar -->
      <div class="flex-between flex-wrap gap-10 toolbar-wrapper">
        <div class="flex-start gap-10 flex-wrap">
          <el-input
            v-model="anomaliesSearch"
            placeholder="搜索邮箱或用户 ID..."
            size="small"
            clearable
            style="width: 220px;"
            prefix-icon="Search"
          />
          <el-select v-model="anomaliesFilterType" placeholder="威胁等级与状态" size="small" style="width: 180px;">
            <el-option label="全部记录" value="all" />
            <el-option label="仅看审计拦截 (高风险)" value="flagged" />
            <el-option label="仅看疑似工具 (低风险)" value="suspected" />
            <el-option label="仅看已接管蜜罐" value="honeypot" />
          </el-select>
        </div>
        <div style="font-size: 13px; color: var(--el-text-color-secondary);">
          共筛选出 <strong>{{ filteredAnomaliesList.length }}</strong> 条审计数据
        </div>
      </div>

      <el-table :data="filteredAnomaliesList" v-loading="anomaliesLoading" stripe style="width: 100%">
        <el-table-column type="expand">
          <template #default="props">
            <div class="anomaly-history-detail">
              <h4 class="history-title">
                <el-icon><Odometer /></el-icon> 最近 5 次拉取详细审计轨迹
              </h4>
              <el-timeline v-if="props.row.history && props.row.history.length > 0">
                <el-timeline-item
                  v-for="(h, hIdx) in props.row.history"
                  :key="hIdx"
                  :timestamp="formatTime(h.time)"
                  placement="top"
                  :type="h.ua && (h.ua.toLowerCase().includes('curl') || h.ua.toLowerCase().includes('wget')) ? 'warning' : 'primary'"
                >
                  <el-card shadow="none" class="history-item-card">
                    <div style="font-size: 13px; line-height: 1.6;">
                      <div style="margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                        <span><strong>拉取 IP:</strong> <code class="font-mono">{{ h.ip }}</code><span v-if="h.location" style="color: var(--el-text-color-secondary); font-size: 12px; margin-left: 6px;">({{ h.location }})</span></span>
                        <el-button
                          v-if="h.ip"
                          type="danger"
                          link
                          size="small"
                          style="padding: 0; height: auto;"
                          @click="handleQuickBanIp(h.ip)"
                        >
                          (一键封禁此 IP)
                        </el-button>
                        <span v-if="h.ua && h.ua.toLowerCase().includes('curl')" style="color: var(--el-color-warning); font-size: 12px; display: inline-flex; align-items: center; gap: 2px;">
                          <el-icon><InfoFilled /></el-icon> (该 IP 使用 curl 拉取，多为 OpenWrt 等路由器设备)
                        </span>
                      </div>
                      <div style="margin-bottom: 4px;"><strong>拉取类型:</strong> <el-tag size="small" type="info">{{ h.type }}</el-tag></div>
                      <div><strong>客户端 User-Agent:</strong> <code class="font-mono text-muted">{{ h.ua }}</code></div>
                    </div>
                  </el-card>
                </el-timeline-item>
              </el-timeline>
              <div v-else class="text-center text-muted" style="padding: 10px 0;">暂无历史拉取记录</div>
            </div>
          </template>
        </el-table-column>
        
        <el-table-column label="ID" width="80" align="center">
          <template #default="scope">
            <el-button type="primary" link style="font-weight: bold;" @click="showUserDetail(scope.row.user_id)">
              {{ scope.row.user_id }}
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="邮箱" min-width="180" show-overflow-tooltip>
          <template #default="scope">
            <el-button type="primary" link @click="showUserDetail(scope.row.user_id)">
              {{ scope.row.email }}
            </el-button>
          </template>
        </el-table-column>
        
        <el-table-column label="审计时间" width="160">
          <template #default="scope">
            <span>{{ formatTime(scope.row.flagged_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="风险评估" width="130">
          <template #default="scope">
            <el-tag :type="scope.row.risk_level === 'high' ? 'danger' : 'warning'" size="small">
              {{ scope.row.risk_level === 'high' ? '审计拦截 (高)' : '疑似工具 (低)' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="判定原委" min-width="260">
          <template #default="scope">
            <div v-for="(reason, rIdx) in scope.row.reasons" :key="rIdx" style="margin-bottom: 4px; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap;">
              <el-tag :type="scope.row.risk_level === 'high' ? 'danger' : 'warning'" size="small" style="white-space: normal; height: auto; padding: 4px 8px; line-height: 1.4;">
                {{ reason }}
              </el-tag>
              <el-tooltip
                v-if="reason.toLowerCase().includes('curl')"
                content="提示: curl 请求极有可能是 OpenWrt 软路由插件正常拉取，请结合下方的拉取 IP 记录（是否有多 IP 扩散分享）进行确认，不要误封正常用户。"
                placement="top"
                effect="dark"
              >
                <el-icon style="color: var(--el-color-warning); cursor: help; font-size: 14px;"><QuestionFilled /></el-icon>
              </el-tooltip>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="蜜罐状态" width="120" align="center">
          <template #default="scope">
            <el-tag :type="scope.row.in_honeypot === 1 ? 'warning' : 'info'" size="small">
              {{ scope.row.in_honeypot === 1 ? '蜜罐接管中' : '未接管' }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="280" align="right" fixed="right">
          <template #default="scope">
            <el-button
              :type="scope.row.in_honeypot === 1 ? 'success' : 'warning'"
              size="small"
              plain
              @click="handleToggleHoneypot(scope.row)"
            >
              {{ scope.row.in_honeypot === 1 ? '解除蜜罐' : '一键蜜罐' }}
            </el-button>
            <el-button
              type="danger"
              size="small"
              plain
              :disabled="scope.row.banned === 1"
              @click="handleBanUser(scope.row)"
            >
              {{ scope.row.banned === 1 ? '已封禁' : '封禁' }}
            </el-button>
            
            <el-dropdown trigger="click" @command="(cmd) => handleAnomalyAction(cmd, scope.row)" style="margin-left: 10px;">
              <el-button size="small" plain>
                更多<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item v-if="scope.row.type === 'flagged'" command="ignore" icon="CircleClose">忽略预警</el-dropdown-item>
                  <el-dropdown-item command="whitelist" icon="Checked">加入白名单</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Audit Settings & Whitelist Dialog -->
    <el-dialog v-model="settingsDialogVisible" :title="systemName ? systemName + '订阅审计与白名单设置' : '订阅审计与白名单设置'" width="600px" destroy-on-close>
      <el-tabs v-model="settingsActiveTab">
        <el-tab-pane label="审计参数规则" name="rules">
          <el-form :model="settingsForm" label-width="180px" style="padding-top: 15px;">
            <el-form-item label="24h独立IP阈值">
              <el-input-number v-model="settingsForm.ip_limit" :min="1" :max="100" />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                同一个订阅 24 小时内独立拉取 IP 达到该数值后，会被自动判定并拦截预警（默认：10 个 IP）。
              </div>
            </el-form-item>
            <el-form-item label="命令行/客户端 UA 审计">
              <el-switch v-model="settingsForm.audit_ua_enabled" />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                是否对使用指定的客户端 UA 拉取订阅的行为进行检测和审计。如果关闭，前述工具拉取将不触发拦截。
              </div>
            </el-form-item>
            <el-form-item label="UA 审计关键字" v-if="settingsForm.audit_ua_enabled">
              <el-input
                type="textarea"
                v-model="settingsForm.audit_ua_keywords"
                :rows="6"
                placeholder="每行输入一个 UA 关键字，例如：&#10;curl&#10;ClashMetaForAndroid/733"
              />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                当拉取订阅的 User-Agent 包含以上关键字时将被标记为异常，支持新增、修改或删除，每行一个，不区分大小写。
              </div>
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane label="拦截与蜜罐防御" name="honeypot_settings">
          <el-form :model="settingsForm" label-width="160px" style="padding-top: 15px;">
            <el-form-item label="拦截防探测策略">
              <el-radio-group v-model="settingsForm.banned_strategy">
                <el-radio value="bait" style="margin-right: 15px;">诱饵模式</el-radio>
                <el-radio value="redirect">重定向模式</el-radio>
              </el-radio-group>
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                <strong>诱饵模式</strong>返回错误/伪装节点信息；<strong>重定向模式</strong>直接 302 重定向到目标网址。
              </div>
            </el-form-item>
            
            <el-form-item label="诱捕/重定向订阅地址">
              <el-input v-model="settingsForm.banned_redirect_url" placeholder="如: https://sub.deadairport.com/link/..." clearable />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                <strong>诱饵模式下</strong>：作为您下发的诱饵订阅的原始数据源（如死机厂订阅），系统会在后台拉取并进行敏感词过滤净化后返回给客户端；<br>
                <strong>重定向模式下</strong>：客户端拉取订阅时将直接 302 重定向跳转到此网址（由第三方下发，无法进行敏感词过滤）。
              </div>
            </el-form-item>

            <el-form-item label="拦截过滤敏感词">
              <el-input v-model="settingsForm.banned_keywords" placeholder="本站域名或简称，用英文逗号分隔" clearable />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                在诱饵下发的假配置中，自动清除或混淆敏感词，防止被第三方或测活反查到源站域名。
              </div>
            </el-form-item>

            <el-form-item label="敏感词替换为">
              <el-input v-model="settingsForm.replace_keyword_to" placeholder="如: 精品线路" clearable />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                过滤掉上述敏感词后，将会把敏感字眼替换成该文本。
              </div>
            </el-form-item>

            <el-form-item label="转换器防封脱敏">
              <el-switch v-model="settingsForm.subconverter_enable" />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                当检测到通过第三方转换器（如 Subconverter）更新时，进行订阅脱敏以隐藏核心主站。
              </div>
            </el-form-item>

            <el-form-item v-if="settingsForm.subconverter_enable" label="订阅转换 API 地址">
              <el-input v-model="settingsForm.subconverter_url" placeholder="默认: https://api.wcc.best/sub" clearable />
              <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; line-height: 1.2;">
                <span style="font-size: 11px; color: var(--el-text-color-secondary);">常用快捷填入:</span>
                <el-tag 
                  size="small" 
                  effect="plain" 
                  style="cursor: pointer; user-select: none;"
                  @click="settingsForm.subconverter_url = 'https://api.bianyuan.xyz/sub'"
                >
                  边缘转换
                </el-tag>
                <el-tag 
                  size="small" 
                  effect="plain" 
                  style="cursor: pointer; user-select: none;"
                  @click="settingsForm.subconverter_url = 'https://sub.fyacg.com/sub'"
                >
                  肥羊转换
                </el-tag>
                <el-tag 
                  size="small" 
                  effect="plain" 
                  style="cursor: pointer; user-select: none;"
                  @click="settingsForm.subconverter_url = 'https://sub.xeton.dev/sub'"
                >
                  ACL4SSR 转换
                </el-tag>
                <el-tag 
                  size="small" 
                  effect="plain" 
                  style="cursor: pointer; user-select: none;"
                  @click="settingsForm.subconverter_url = 'https://api.wcc.best/sub'"
                >
                  核心转换
                </el-tag>
                <el-tag 
                  size="small" 
                  effect="plain" 
                  style="cursor: pointer; user-select: none;"
                  @click="settingsForm.subconverter_url = 'https://sub.dler.io/sub'"
                >
                  Dler 转换
                </el-tag>
              </div>
            </el-form-item>

            <el-form-item label="防封随机流量包">
              <el-switch v-model="settingsForm.banned_traffic_enable" />
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; line-height: 1.4;">
                是否在假拦截配置里生成随机大额虚假已用/剩余流量包以诱骗敌方画像。
              </div>
            </el-form-item>

            <el-form-item v-if="settingsForm.banned_traffic_enable" label="生成虚假流量范围">
              <div style="display: flex; align-items: center; gap: 8px;">
                <el-input-number v-model="settingsForm.banned_traffic_min" :min="1" placeholder="最小(GB)" style="width: 110px;" />
                <span>至</span>
                <el-input-number v-model="settingsForm.banned_traffic_max" :min="2" placeholder="最大(GB)" style="width: 110px;" />
                <span>GB</span>
              </div>
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane label="白名单管理" name="whitelist">
          <div style="padding-top: 10px;">
            <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 12px; line-height: 1.4;">
              以下用户邮箱或用户 ID 不会被系统定时审计扫描或预警。可以直接添加，也可以点击右侧移除。
            </div>
            
            <div class="flex-between gap-10 mb-15">
              <el-input
                v-model="newWhitelistIdentity"
                placeholder="请输入要加白的用户邮箱或用户 ID"
                size="small"
                style="width: 380px;"
                clearable
              />
              <el-button type="primary" size="small" @click="addWhitelistDirectly">添加白名单</el-button>
            </div>

            <el-table :data="whitelistList" stripe size="small" max-height="250px" style="width: 100%;">
              <el-table-column label="白名单标识 (ID/邮箱)" min-width="250">
                <template #default="scope">
                  <code class="font-mono">{{ scope.row }}</code>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80" align="right">
                <template #default="scope">
                  <el-button type="danger" link size="small" @click="removeWhitelistDirectly(scope.row)">移除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </el-tab-pane>
        <el-tab-pane label="IP黑名单管理" name="ip_blacklist">
          <div style="padding-top: 10px;">
            <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 12px; line-height: 1.4;">
              以下 IP 在请求订阅拉取时会被直接拦截并返回 403。您可以手动输入 IP 增加封禁，也可对已封禁的 IP 点击解封。
            </div>
            
            <div class="flex-between gap-10 mb-15" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
              <el-input
                v-model="newBannedIp"
                placeholder="请输入要封禁 the IP 地址 (IPv4/IPv6)"
                size="small"
                style="width: 380px;"
                clearable
              />
              <el-button type="primary" size="small" @click="addBannedIpDirectly">添加封禁</el-button>
            </div>

            <el-table :data="bannedIpsList" stripe size="small" max-height="250px" style="width: 100%;">
              <el-table-column label="封禁 IP" min-width="250">
                <template #default="scope">
                  <code class="font-mono">{{ scope.row }}</code>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80" align="right">
                <template #default="scope">
                  <el-button type="danger" link size="small" @click="removeBannedIpDirectly(scope.row)">解封</el-button>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </el-tab-pane>
        <el-tab-pane label="节点IP免审白名单" name="ip_ignore">
          <div style="padding-top: 10px;">
            <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 12px; line-height: 1.4;">
              将您本站<strong>节点的公网 IP 地址或网段（支持 CIDR 格式如 45.125.12.0/24）</strong>在此添加。用户连接这些节点拉取订阅时所产生的 IP 请求历史将被忽略，不再记录和计算审计画像，从而彻底消除节点代理更新订阅带来的异地或扩散误报。
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 10px;">
              <el-input
                v-model="newIgnoreIp"
                placeholder="请输入要忽略的节点 IP 或网段 (例如: 45.125.12.64 或 45.125.12.0/24)"
                size="small"
                style="width: 380px;"
                clearable
              />
              <el-button type="primary" size="small" @click="addIgnoreIpDirectly">添加免审</el-button>
            </div>

            <el-table :data="ignoreIpsList" stripe size="small" max-height="250px" style="width: 100%;">
              <el-table-column label="免审 IP/网段" min-width="250">
                <template #default="scope">
                  <code class="font-mono">{{ scope.row }}</code>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="80" align="right">
                <template #default="scope">
                  <el-button type="danger" link size="small" @click="removeIgnoreIpDirectly(scope.row)">移除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </el-tab-pane>
      </el-tabs>
      <template #footer>
        <span class="dialog-footer">
          <el-button size="small" @click="settingsDialogVisible = false">取消</el-button>
          <el-button size="small" type="primary" :loading="saveSettingsLoading" @click="saveAuditSettings">保存修改</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- IP Association Dialog -->
    <el-dialog v-model="ipAssociationVisible" title="多账号共用 IP 关联分析雷达" width="900px" destroy-on-close>
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 15px; line-height: 1.5;">
        分析所有用户的客户端拉取历史，抓取并呈现在近期内，<strong>有 2 个及以上不同账号共同使用过</strong>的 IP 地址。这通常可以高效识别一人多号或内鬼测活探测。
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

    <!-- Custom Audit Radar Dialog -->
    <el-dialog v-model="customAuditVisible" title="天阙安全审计 - 自定义特征探测雷达" width="950px" destroy-on-close>
      <div style="font-size: 13px; color: var(--el-text-color-secondary); margin-bottom: 15px; line-height: 1.5;">
        在这里您可以自由设定用户的拉取行为特征。点击开始探测后，雷达会从全局用户库中即时扫描并筛选出符合条件的异常账号。
      </div>

      <!-- Filters Form -->
      <el-card shadow="never" style="margin-bottom: 15px; background: var(--el-fill-color-blank); border-color: var(--el-border-color-lighter);">
        <el-form :inline="true" size="small" :model="customAuditForm" style="margin-bottom: -15px;">
          <el-form-item label="ID 大于">
            <el-input-number v-model="customAuditForm.id_min" :min="0" style="width: 100px;" />
          </el-form-item>
          <el-form-item label="UA 包含">
            <el-input v-model="customAuditForm.ua_keyword" placeholder="如 clash-verge/733" style="width: 150px;" clearable />
          </el-form-item>
          <el-form-item label="审计时间">
            <el-select v-model="customAuditForm.time_range" style="width: 100px;">
              <el-option label="24 小时" :value="86400" />
              <el-option label="36 小时" :value="129600" />
              <el-option label="48 小时" :value="172800" />
              <el-option label="72 小时" :value="259200" />
            </el-select>
          </el-form-item>
          <el-form-item label="跨省数 >=">
            <el-input-number v-model="customAuditForm.province_count" :min="0" :max="34" style="width: 75px;" />
          </el-form-item>
          <el-form-item label="仅限机房IP">
            <el-switch v-model="customAuditForm.only_idc" />
          </el-form-item>
          <el-form-item label="已用流量 <=">
            <el-input-number v-model="customAuditForm.max_traffic" :min="0" style="width: 100px;" controls-position="right" />
            <span style="margin-left: 3px; color: var(--el-text-color-secondary);">M (0不限)</span>
          </el-form-item>
          <el-form-item style="margin-left: 10px;">
            <el-button type="success" icon="Cpu" :loading="customAuditLoading" @click="runCustomAuditScan">开始探测扫描</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <!-- Results Table -->
      <el-table
        v-loading="customAuditLoading"
        :data="customAuditResults"
        stripe
        size="small"
        max-height="400px"
        style="width: 100%;"
        :row-class-name="tableRowClassName"
        @selection-change="handleCustomAuditSelectionChange"
      >
        <el-table-column type="selection" width="55" />
        <el-table-column label="用户 ID" width="90" prop="user_id" sortable />
        <el-table-column label="用户邮箱" min-width="180">
          <template #default="scope">
            <span style="font-weight: 500;">{{ scope.row.email }}</span>
          </template>
        </el-table-column>
        <el-table-column label="账号状态" width="80">
          <template #default="scope">
            <el-tag :type="scope.row.banned === 1 ? 'danger' : 'success'" size="small">
              {{ scope.row.banned === 1 ? '已封禁' : '正常' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="蜜罐状态" width="80">
          <template #default="scope">
            <el-tag :type="scope.row.in_honeypot === 1 ? 'warning' : 'info'" size="small" effect="plain">
              {{ scope.row.in_honeypot === 1 ? '接管中' : '未接管' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="探测诊断 / 排除原因" min-width="260">
          <template #default="scope">
            <span v-if="scope.row.match_status === 'matched'" style="color: var(--el-color-success); font-weight: bold; display: flex; align-items: center; gap: 4px;">
              <el-icon><Cpu /></el-icon> 🟢 完全吻合特征
            </span>
            <span v-else style="color: var(--el-text-color-secondary); font-size: 12px; font-style: italic;">
              {{ scope.row.exclude_reason }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="活跃 IP数" width="85" prop="ip_count" align="center" sortable />
        <el-table-column label="跨省数" width="70" prop="province_count" align="center" sortable />
        <el-table-column label="拉取省份" min-width="100">
          <template #default="scope">
            <span style="font-size: 12px; color: var(--el-text-color-regular);">
              {{ scope.row.provinces.join(', ') || '无' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="机房IP" width="70" prop="idc_count" align="center" sortable />
        <el-table-column label="操作" width="100" align="right" fixed="right">
          <template #default="scope">
            <el-button
              v-if="scope.row.in_honeypot === 0"
              type="warning"
              size="small"
              plain
              @click="handleCustomHoneypot([scope.row.user_id])"
            >
              放入蜜罐
            </el-button>
            <el-button
              v-else
              type="info"
              size="small"
              disabled
            >
              已接管
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- Selection operations -->
      <div v-if="customAuditSelection.length > 0" style="margin-top: 15px; display: flex; align-items: center; justify-content: space-between;">
        <span style="font-size: 13px; color: var(--el-text-color-regular);">
          已选中 <strong>{{ customAuditSelection.length }}</strong> 个账号
        </span>
        <el-button
          type="warning"
          icon="Connection"
          size="small"
          @click="handleCustomHoneypot(customAuditSelection.map(item => item.user_id))"
        >
          批量拖入蜜罐
        </el-button>
      </div>

      <template #footer>
        <span class="dialog-footer">
          <el-button size="small" @click="customAuditVisible = false">关闭</el-button>
        </span>
      </template>
    </el-dialog>

    <!-- User Detail / Hologram Dialog -->
    <el-dialog v-model="userDetailVisible" title="用户全息档案" width="600px" destroy-on-close>
      <div v-loading="userDetailLoading">
        <div v-if="userDetailData" class="user-detail-card">
          <!-- Header Profile -->
          <div class="user-profile-header mb-15 flex-start" style="display: flex; align-items: center; gap: 12px; border-bottom: 1px solid var(--el-border-color-lighter); padding-bottom: 15px; margin-bottom: 15px;">
            <el-avatar :size="50" style="background-color: var(--el-color-primary-light-9); color: var(--el-color-primary);">
              <el-icon :size="24"><User /></el-icon>
            </el-avatar>
            <div>
              <div style="font-size: 16px; font-weight: bold; color: var(--el-text-color-primary);">
                {{ userDetailData.email }}
              </div>
              <div style="font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px;">
                用户 ID: <code class="font-mono" style="background: var(--el-fill-color); padding: 2px 6px; border-radius: 4px;">{{ userDetailData.id }}</code> | 注册于: {{ formatTime(userDetailData.created_at) }}
              </div>
            </div>
          </div>

          <!-- Status Tags -->
          <div class="mb-15" style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap;">
            <el-tag :type="userDetailData.banned === 1 ? 'danger' : 'success'" effect="dark">
              {{ userDetailData.banned === 1 ? '封禁状态: 已封禁' : '账号状态: 正常' }}
            </el-tag>
            <el-tag :type="userDetailData.in_honeypot === 1 ? 'warning' : 'info'" effect="dark">
              {{ userDetailData.in_honeypot === 1 ? '蜜罐接管中' : '未接管蜜罐' }}
            </el-tag>
            <el-tag type="primary" effect="plain" v-if="userDetailData.plan_name">
              套餐: {{ userDetailData.plan_name }}
            </el-tag>
            <el-tag type="info" effect="plain" v-else>
              无订阅套餐
            </el-tag>
          </div>

          <!-- Traffic Progress -->
          <el-card shadow="never" class="mb-15" style="background-color: var(--el-fill-color-light); border: none; margin-bottom: 15px;">
            <template #header>
              <div class="flex-between" style="display: flex; justify-content: space-between; font-size: 13px; font-weight: bold; border-bottom: 1px solid var(--el-border-color-lighter); padding-bottom: 8px; margin-bottom: 10px;">
                <span>📊 流量使用概览</span>
                <span style="color: var(--el-text-color-secondary);">
                  {{ formatTraffic(userDetailData.u + userDetailData.d) }} / {{ formatTraffic(userDetailData.transfer_enable) }}
                </span>
              </div>
            </template>
            <div>
              <el-progress 
                :percentage="calculatePercentage(userDetailData.u + userDetailData.d, userDetailData.transfer_enable)" 
                :status="getProgressStatus(userDetailData.u + userDetailData.d, userDetailData.transfer_enable)"
                :stroke-width="12"
                striped
                striped-flow
              />
              <div class="flex-between mt-10" style="display: flex; justify-content: space-between; font-size: 11px; color: var(--el-text-color-secondary); margin-top: 10px;">
                <span>上行: {{ formatTraffic(userDetailData.u) }} | 下行: {{ formatTraffic(userDetailData.d) }}</span>
                <span>剩余流量: <strong :style="{ color: userDetailData.transfer_enable - (userDetailData.u + userDetailData.d) > 0 ? 'var(--el-color-success)' : 'var(--el-color-danger)' }">{{ formatTraffic(Math.max(0, userDetailData.transfer_enable - (userDetailData.u + userDetailData.d))) }}</strong></span>
              </div>
            </div>
          </el-card>

          <!-- Detail Descriptions -->
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="到期时间" :span="2">
              <span :class="{ 'text-danger': isExpired(userDetailData.expired_at) }" style="font-weight: 500;">
                {{ userDetailData.expired_at ? formatTime(userDetailData.expired_at) : '长期有效' }}
                <span v-if="isExpired(userDetailData.expired_at)" style="font-size: 11px; margin-left: 6px; color: var(--el-color-danger);">(已过期)</span>
                <span v-else-if="userDetailData.expired_at" style="font-size: 11px; color: var(--el-color-success); margin-left: 6px;">
                  (余 {{ Math.ceil((userDetailData.expired_at - Date.now()/1000) / 86400) }} 天)
                </span>
              </span>
            </el-descriptions-item>
            <el-descriptions-item label="账户余额">
              {{ ((userDetailData.balance || 0) / 100).toFixed(2) }} 元
            </el-descriptions-item>
            <el-descriptions-item label="推广佣金">
              {{ ((userDetailData.commission_balance || 0) / 100).toFixed(2) }} 元
            </el-descriptions-item>
            <el-descriptions-item label="设备限制">
              {{ userDetailData.device_limit !== null && userDetailData.device_limit !== undefined ? userDetailData.device_limit + ' 台' : '无限制' }}
            </el-descriptions-item>
            <el-descriptions-item label="速率限制">
              {{ userDetailData.speed_limit ? userDetailData.speed_limit + ' Mbps' : '无限制' }}
            </el-descriptions-item>
          </el-descriptions>
        </div>
      </div>
      <template #footer>
        <div class="flex-between" style="display: flex; justify-content: space-between; align-items: center;">
          <div class="flex-start" style="display: flex; gap: 8px;">
            <el-button type="primary" plain size="small" icon="Tickets" @click="goToUserOrders">TA的订单</el-button>
            <el-button type="warning" plain size="small" icon="User" @click="goToUserManage">在用户管理中编辑</el-button>
          </div>
          <el-button size="small" @click="userDetailVisible = false">关闭</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';

const router = useRouter();
const systemName = computed(() => {
  return window.settings?.title || '';
});
const anomaliesRawList = ref([]);
const anomaliesSearch = ref('');
const anomaliesFilterType = ref('all');
const anomaliesLoading = ref(false);

const filteredAnomaliesList = computed(() => {
  let list = anomaliesRawList.value;
  
  if (anomaliesSearch.value.trim()) {
    const q = anomaliesSearch.value.trim().toLowerCase();
    list = list.filter(item => 
      item.email.toLowerCase().includes(q) || 
      String(item.user_id).includes(q)
    );
  }
  
  if (anomaliesFilterType.value === 'flagged') {
    list = list.filter(item => item.type === 'flagged');
  } else if (anomaliesFilterType.value === 'suspected') {
    list = list.filter(item => item.type === 'suspected');
  } else if (anomaliesFilterType.value === 'honeypot') {
    list = list.filter(item => item.in_honeypot === 1);
  }
  
  return list;
});

// Summary numbers
const flaggedCount = computed(() => {
  return anomaliesRawList.value.filter(item => item.type === 'flagged').length;
});

const suspectedCount = computed(() => {
  return anomaliesRawList.value.filter(item => item.type === 'suspected').length;
});

const honeypotCount = computed(() => {
  return anomaliesRawList.value.filter(item => item.in_honeypot === 1).length;
});

const whitelistList = ref([]);
const bannedIpsList = ref([]);
const newBannedIp = ref('');
const ignoreIpsList = ref([]);
const newIgnoreIp = ref('');
const settingsDialogVisible = ref(false);
const ipAssociationVisible = ref(false);
const ipAssociationLoading = ref(false);
const ipAssociationList = ref([]);
const settingsActiveTab = ref('rules');
const newWhitelistIdentity = ref('');
const saveSettingsLoading = ref(false);

const customAuditVisible = ref(false);
const customAuditLoading = ref(false);
const customAuditResults = ref([]);
const customAuditSelection = ref([]);
const customAuditForm = reactive({
  id_min: 10000,
  ua_keyword: 'clash-verge/733',
  province_count: 5,
  only_idc: false,
  time_range: 86400,
  max_traffic: 1024
});

const settingsForm = reactive({
  ip_limit: 10,
  audit_ua_enabled: true,
  audit_ua_keywords: '',
  banned_strategy: 'bait',
  banned_redirect_url: '',
  subconverter_enable: true,
  subconverter_url: 'https://api.wcc.best/sub',
  banned_keywords: '',
  replace_keyword_to: '精品线路',
  banned_traffic_enable: false,
  banned_traffic_min: 100,
  banned_traffic_max: 300
});

const formatTime = (timestamp) => {
  if (!timestamp) return '无记录';
  const date = new Date(timestamp * 1000);
  return date.toLocaleString();
};

const fetchAnomalies = async () => {
  anomaliesLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getSubscriptionAnomalies`);
    if (res.data) {
      anomaliesRawList.value = res.data.list || [];
      whitelistList.value = res.data.whitelist || [];
      bannedIpsList.value = res.data.banned_ips || [];
      ignoreIpsList.value = res.data.ignore_ips || [];
      if (res.data.config) {
        settingsForm.ip_limit = res.data.config.ip_limit || 10;
        settingsForm.audit_ua_enabled = res.data.config.audit_ua_enabled !== false;
        if (Array.isArray(res.data.config.audit_ua_keywords)) {
          settingsForm.audit_ua_keywords = res.data.config.audit_ua_keywords.join('\n');
        } else {
          settingsForm.audit_ua_keywords = '';
        }
        settingsForm.banned_strategy = res.data.config.banned_strategy || 'bait';
        settingsForm.banned_redirect_url = res.data.config.banned_redirect_url || '';
        settingsForm.subconverter_enable = res.data.config.subconverter_enable !== false;
        settingsForm.subconverter_url = res.data.config.subconverter_url || 'https://api.wcc.best/sub';
        settingsForm.banned_keywords = res.data.config.banned_keywords || '';
        settingsForm.replace_keyword_to = res.data.config.replace_keyword_to || '精品线路';
        settingsForm.banned_traffic_enable = !!res.data.config.banned_traffic_enable;
        settingsForm.banned_traffic_min = res.data.config.banned_traffic_min || 100;
        settingsForm.banned_traffic_max = res.data.config.banned_traffic_max || 300;
      }
    }
  } catch (err) {
    console.error(err);
  } finally {
    anomaliesLoading.value = false;
  }
};

const openSettingsDialog = () => {
  settingsActiveTab.value = 'rules';
  newWhitelistIdentity.value = '';
  settingsDialogVisible.value = true;
};

const saveAuditSettings = async () => {
  saveSettingsLoading.value = true;
  try {
    const securePath = getSecurePath();
    const keywordsArray = settingsForm.audit_ua_keywords
      .split('\n')
      .map(k => k.trim())
      .filter(k => k.length > 0);

    await api.post(`/${securePath}/stat/saveSubscriptionAuditSettings`, {
      ip_limit: settingsForm.ip_limit,
      audit_ua_enabled: settingsForm.audit_ua_enabled,
      audit_ua_keywords: keywordsArray,
      banned_strategy: settingsForm.banned_strategy,
      banned_redirect_url: settingsForm.banned_redirect_url,
      subconverter_enable: settingsForm.subconverter_enable,
      subconverter_url: settingsForm.subconverter_url,
      banned_keywords: settingsForm.banned_keywords,
      replace_keyword_to: settingsForm.replace_keyword_to,
      banned_traffic_enable: settingsForm.banned_traffic_enable,
      banned_traffic_min: settingsForm.banned_traffic_min,
      banned_traffic_max: settingsForm.banned_traffic_max
    });
    ElMessage.success('审计规则已成功更新');
    settingsDialogVisible.value = false;
    fetchAnomalies();
  } catch (err) {
    console.error(err);
  } finally {
    saveSettingsLoading.value = false;
  }
};

const addWhitelistDirectly = async () => {
  const identity = newWhitelistIdentity.value.trim();
  if (!identity) {
    ElMessage.warning('请输入用户邮箱或用户 ID');
    return;
  }
  try {
    const securePath = getSecurePath();
    const isId = /^\d+$/.test(identity);
    if (isId) {
      await api.post(`/${securePath}/stat/whitelistUser`, { id: parseInt(identity) });
    } else {
      await api.post(`/${securePath}/stat/whitelistUser`, { identity });
    }
    ElMessage.success(`已将 ${identity} 成功加入白名单`);
    newWhitelistIdentity.value = '';
    fetchAnomalies();
  } catch (err) {
    console.error(err);
  }
};

const removeWhitelistDirectly = async (identity) => {
  try {
    await ElMessageBox.confirm(`确定要将白名单标识 ${identity} 移除吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeWhitelistUser`, { identity });
    ElMessage.success('白名单移除成功');
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') {
      console.error(err);
    }
  }
};

const handleQuickBanIp = async (ip) => {
  try {
    await ElMessageBox.confirm(`确定要封禁该 IP 地址 ${ip} 吗？(封禁后该 IP 将无法拉取本站任何订阅链接)`, '警告', {
      type: 'warning',
      confirmButtonText: '确定封禁',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/banIp`, { ip });
    ElMessage.success(`IP ${ip} 封禁成功`);
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') console.error(err);
  }
};

const addBannedIpDirectly = async () => {
  const ip = newBannedIp.value.trim();
  if (!ip) {
    ElMessage.warning('请输入要封禁的 IP 地址');
    return;
  }
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/banIp`, { ip });
    ElMessage.success(`已成功封禁 IP: ${ip}`);
    newBannedIp.value = '';
    fetchAnomalies();
  } catch (err) {
    console.error(err);
  }
};

const removeBannedIpDirectly = async (ip) => {
  try {
    await ElMessageBox.confirm(`确定要解封 IP ${ip} 吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeBanIp`, { ip });
    ElMessage.success(`IP ${ip} 解封成功`);
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') console.error(err);
  }
};

const addIgnoreIpDirectly = async () => {
  const ipVal = newIgnoreIp.value.trim();
  if (!ipVal) {
    ElMessage.warning('请输入要忽略的 IP 或网段');
    return;
  }
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/addIgnoreIp`, { ip: ipVal });
    ElMessage.success('添加节点免审 IP 成功');
    newIgnoreIp.value = '';
    fetchAnomalies();
  } catch (err) {
    console.error(err);
  }
};

const removeIgnoreIpDirectly = async (ip) => {
  try {
    await ElMessageBox.confirm(`确定要移除免审 IP/网段 ${ip} 吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeIgnoreIp`, { ip });
    ElMessage.success('已移除免审 IP/网段');
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') console.error(err);
  }
};

const openIpAssociationDialog = () => {
  ipAssociationVisible.value = true;
  fetchIpAssociationAnalysis();
};

const fetchIpAssociationAnalysis = async () => {
  ipAssociationLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/stat/getIpAssociationAnalysis`);
    if (res.data) {
      ipAssociationList.value = res.data || [];
    }
  } catch (err) {
    console.error(err);
  } finally {
    ipAssociationLoading.value = false;
  }
};

const banAssociatedIp = async (ip) => {
  try {
    await ElMessageBox.confirm(`确认要封禁共用 IP ${ip} 吗？封禁后该 IP 将无法拉取任何订阅。`, '警告', {
      type: 'warning',
      confirmButtonText: '确定封禁',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/banIp`, { ip });
    ElMessage.success(`IP ${ip} 封禁成功`);
    fetchIpAssociationAnalysis();
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') console.error(err);
  }
};

const unbanAssociatedIp = async (ip) => {
  try {
    await ElMessageBox.confirm(`确定要解封 IP ${ip} 吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/removeBanIp`, { ip });
    ElMessage.success(`IP ${ip} 解封成功`);
    fetchIpAssociationAnalysis();
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') console.error(err);
  }
};

const handleClearAllAnomalies = async () => {
  try {
    await ElMessageBox.confirm('确定要忽略全部待处理的审计预警吗？此操作将清除所有当前的警报记录。', '警告', {
      type: 'warning',
      confirmButtonText: '确定忽略全部',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/stat/clearAllAnomalies`);
    ElMessage.success('已成功清空所有审计记录');
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') {
      console.error(err);
    }
  }
};

const handleAnomalyAction = async (cmd, row) => {
  const securePath = getSecurePath();
  if (cmd === 'ignore') {
    try {
      await ElMessageBox.confirm(`确定要忽略此条对用户 ${row.email} 的审计拦截吗？(忽略后该条记录将从列表消失，但若其再次触发审计检测仍会重新生成报警)`, '提示', {
        type: 'warning',
        confirmButtonText: '忽略',
        cancelButtonText: '取消'
      });
      await api.post(`/${securePath}/stat/ignoreAnomaly`, { id: row.user_id });
      ElMessage.success('已成功忽略此条审计记录');
      fetchAnomalies();
    } catch (err) {
      if (err !== 'cancel') console.error(err);
    }
  } else if (cmd === 'whitelist') {
    try {
      await ElMessageBox.confirm(`确定要将用户 ${row.email} 加入白名单吗？(加入后系统将不再对其执行任何订阅拉取的安全审计)`, '提示', {
        type: 'warning',
        confirmButtonText: '确定加白',
        cancelButtonText: '取消'
      });
      await api.post(`/${securePath}/stat/whitelistUser`, { id: row.user_id });
      ElMessage.success('用户已被成功加入白名单，审计记录已清除');
      fetchAnomalies();
    } catch (err) {
      if (err !== 'cancel') console.error(err);
    }
  }
};

const handleToggleHoneypot = async (row) => {
  const actionText = row.in_honeypot === 1 ? '移出蜜罐' : '加入蜜罐';
  try {
    await ElMessageBox.confirm(`确定要将该用户 ${row.email} ${actionText}吗？`, '提示', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/user/toggleHoneypot`, { id: row.user_id });
    ElMessage.success(`${actionText}成功`);
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') {
      console.error(err);
    }
  }
};

const handleBanUser = async (row) => {
  try {
    await ElMessageBox.confirm(`确定要封禁用户 ${row.email} 吗？`, '警告', {
      type: 'error',
      confirmButtonText: '确定封禁',
      cancelButtonText: '取消'
    });
    const securePath = getSecurePath();
    await api.post(`/${securePath}/user/ban`, {
      filter: [
        { key: 'id', condition: '=', value: row.user_id }
      ]
    });
    ElMessage.success(`封禁用户 ${row.email} 成功`);
    fetchAnomalies();
  } catch (err) {
    if (err !== 'cancel') {
      console.error(err);
    }
  }
};

// --- 用户全息档案 (详情查看) 相关状态与方法 ---
const userDetailVisible = ref(false);
const userDetailLoading = ref(false);
const userDetailData = ref(null);

const showUserDetail = async (userId) => {
  userDetailLoading.value = true;
  userDetailVisible.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/user/fetch`, {
      params: {
        filter: [
          { key: 'id', condition: '=', value: userId }
        ]
      }
    });
    if (res.data && res.data.length > 0) {
      userDetailData.value = res.data[0];
    } else {
      ElMessage.error('获取用户信息失败或该用户已被删除');
      userDetailVisible.value = false;
    }
  } catch (err) {
    console.error(err);
    ElMessage.error('获取用户信息接口出错');
    userDetailVisible.value = false;
  } finally {
    userDetailLoading.value = false;
  }
};

const goToUserOrders = () => {
  if (userDetailData.value) {
    router.push({ name: 'Orders', query: { email: userDetailData.value.email } });
    userDetailVisible.value = false;
  }
};

const goToUserManage = () => {
  if (userDetailData.value) {
    router.push({ name: 'Users', query: { email: userDetailData.value.email } });
    userDetailVisible.value = false;
  }
};

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

const tableRowClassName = ({ row }) => {
  if (row.match_status === 'excluded') {
    return 'excluded-row';
  }
  return '';
};

const openCustomAuditDialog = () => {
  customAuditResults.value = [];
  customAuditSelection.value = [];
  customAuditVisible.value = true;
};

const runCustomAuditScan = async () => {
  customAuditLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.post(`/${securePath}/stat/customAuditScan`, {
      id_min: customAuditForm.id_min,
      ua_keyword: customAuditForm.ua_keyword,
      province_count: customAuditForm.province_count,
      only_idc: customAuditForm.only_idc,
      time_range: customAuditForm.time_range,
      max_traffic: customAuditForm.max_traffic
    });
    if (res.data) {
      // 由于 api 拦截器已执行 return response.data，此处 res 即为后端返回的根 JSON 对象
      const rawResults = res.data || [];
      customAuditResults.value = [...rawResults].sort((a, b) => {
        if (a.match_status === 'matched' && b.match_status !== 'matched') return -1;
        if (a.match_status !== 'matched' && b.match_status === 'matched') return 1;
        return b.user_id - a.user_id;
      });
      const matchedCount = customAuditResults.value.filter(r => r.match_status === 'matched').length;
      
      const dbg = res.debug || {};
      console.log('Radar Debug Info:', dbg);
      
      ElMessage({
        type: 'success',
        message: `探测完成：完全吻合 ${matchedCount} 个，初筛命中 ${dbg.users_count || 0} 个（后台接收 UA: "${dbg.ua_keyword || ''}"，ID: ${dbg.id_min || 0}）`,
        duration: 8000,
        showClose: true
      });
    }
  } catch (err) {
    console.error(err);
    ElMessage.error(err.response?.data?.message || '探测执行失败');
  } finally {
    customAuditLoading.value = false;
  }
};

const handleCustomAuditSelectionChange = (val) => {
  customAuditSelection.value = val;
};

const handleCustomHoneypot = async (userIds) => {
  if (!userIds || userIds.length === 0) return;
  
  try {
    await ElMessageBox.confirm(
      `确定要将选中的 ${userIds.length} 个账号拖入蜜罐中接管吗？`,
      '批量蜜罐接管确认',
      {
        confirmButtonText: '确定接管',
        cancelButtonText: '取消',
        type: 'warning'
      }
    );
  } catch (cancel) {
    return;
  }
  
  customAuditLoading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.post(`/${securePath}/stat/customAuditHoneypot`, {
      user_ids: userIds
    });
    if (res.data && res.data.status === 'success') {
      ElMessage.success(res.data.message || '接管成功');
      
      // 更新这些用户在 customAuditResults 里的蜜罐状态
      customAuditResults.value.forEach(item => {
        if (userIds.includes(item.user_id)) {
          item.in_honeypot = 1;
        }
      });
      
      // 清空勾选
      customAuditSelection.value = [];
      
      // 刷新主列表
      fetchAnomalies();
    } else {
      ElMessage.error(res.data?.message || '接管失败');
    }
  } catch (err) {
    console.error(err);
    ElMessage.error('网络请求失败');
  } finally {
    customAuditLoading.value = false;
  }
};

onMounted(() => {
  fetchAnomalies();
});
</script>

<style scoped>
.security-audit-container {
  padding: 0 4px;
}

.stat-card {
  border-radius: 12px;
  border: none;
  background: var(--el-bg-color);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
}

.stat-title {
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-bottom: 6px;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
}

.stat-unit {
  font-size: 12px;
  font-weight: normal;
  color: var(--el-text-color-secondary);
}

.stat-icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.bg-danger-light {
  background: rgba(245, 108, 108, 0.12);
}

.bg-warning-light {
  background: rgba(230, 162, 60, 0.12);
}

.bg-primary-light {
  background: rgba(64, 158, 255, 0.12);
}

.text-danger {
  color: var(--el-color-danger);
}

.text-warning {
  color: var(--el-color-warning);
}

.text-primary {
  color: var(--el-color-primary);
}

.toolbar-wrapper {
  padding: 12px 16px;
  background: var(--el-fill-color-blank);
  border-bottom: 1px solid var(--el-border-color-light);
  border-radius: 8px 8px 0 0;
}

.anomaly-history-detail {
  padding: 15px 25px;
}

.history-title {
  margin: 0 0 12px 0;
  color: var(--el-text-color-primary);
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.history-item-card {
  background: var(--el-fill-color-light);
  border: none;
  margin-bottom: 5px;
  border-radius: 8px;
}

.text-muted {
  color: var(--el-text-color-secondary);
}

.text-center {
  text-align: center;
}

:deep(.el-table .excluded-row) {
  opacity: 0.55;
  background-color: var(--el-fill-color-light) !important;
}
</style>
