<template>
  <div class="servers-container">
    <!-- Action Bar -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <span class="action-text">节点管理</span>
        <div class="flex-center gap-10">
          <el-button type="warning" icon="Sort" :loading="saveSortLoading" @click="handleSaveSort">
            保存排序
          </el-button>
          <el-dropdown trigger="click" @command="handleCreateCommand">
            <el-button type="primary" icon="Plus">
              添加节点<el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item v-for="(label, type) in nodeTypes" :key="type" :command="type" v-show="type !== 'all'">
                  {{ label }} 节点
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </div>
    </el-card>

    <!-- Tabs by node type -->
    <el-tabs v-model="activeTab" class="mt-20 node-tabs" @tab-change="handleTabChange">
      <el-tab-pane v-for="(label, type) in nodeTypes" :key="type" :label="label" :name="type">
        <el-card class="table-card" shadow="hover">
          <!-- PC Table View -->
          <el-table v-if="!isMobile" :data="getPaginatedNodes(type)" v-loading="loading" stripe style="width: 100%">
            <el-table-column label="排序" width="55" align="center">
              <template #default="scope">
                <el-tooltip content="按住鼠标拖动可直接调整节点顺序" placement="top">
                  <div 
                    class="drag-handle" 
                    draggable="true" 
                    @dragstart="handleDragStart(scope.$index)"
                    @dragover.prevent="handleDragOver(scope.$index)"
                    @dragend="handleDragEnd"
                  >
                    <el-icon :size="16"><Rank /></el-icon>
                  </div>
                </el-tooltip>
              </template>
            </el-table-column>
            <el-table-column label="ID" width="110" align="center">
              <template #default="scope">
                <span v-if="scope.row.parent_id" :class="['node-id-badge', 'child', scope.row.type || type]">
                  {{ scope.row.id }} =&gt; {{ scope.row.parent_id }}
                </span>
                <span v-else :class="['node-id-badge', scope.row.type || type]">
                  {{ scope.row.id }}
                </span>
              </template>
            </el-table-column>
            <el-table-column prop="show" label="状态" width="100" align="center">
              <template #default="scope">
                <el-switch
                  v-model="scope.row.show"
                  :active-value="1"
                  :inactive-value="0"
                  @change="(val) => handleToggleShow(scope.row, scope.row.type || type, val)"
                />
              </template>
            </el-table-column>
            
            <el-table-column v-if="type === 'all'" prop="type" label="协议类型" width="120" align="center">
              <template #default="scope">
                <el-tag type="info" size="small" effect="plain">
                  {{ nodeTypes[scope.row.type] || scope.row.type }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column prop="name" label="节点名称" min-width="150">
              <template #default="scope">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <span class="status-dot" :class="getNodeStatusClass(scope.row)" :title="getNodeStatusTitle(scope.row)"></span>
                  <span>{{ scope.row.name }}</span>
                </div>
              </template>
            </el-table-column>
            
            <el-table-column prop="host" label="地址:端口" min-width="180" show-overflow-tooltip>
              <template #default="scope">
                <code>{{ scope.row.host }}:{{ scope.row.port }}</code>
              </template>
            </el-table-column>

            <el-table-column prop="rate" label="流量倍率" width="100" align="center">
              <template #default="scope">
                <el-tag :type="scope.row.rate > 1 ? 'warning' : 'success'" size="small" effect="dark">
                  {{ scope.row.rate }}x
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column prop="online" label="在线人数" width="100" align="center">
              <template #default="scope">
                <span class="online-badge" :style="{ color: scope.row.online > 0 ? 'var(--el-color-success)' : 'inherit', fontWeight: scope.row.online > 0 ? '600' : 'normal' }">
                  {{ scope.row.online || 0 }} 人
                </span>
              </template>
            </el-table-column>

            <el-table-column prop="group_id" label="权限组" min-width="150">
              <template #default="scope">
                <el-tag v-for="gId in scope.row.group_id" :key="gId" size="small" class="mr-5">
                  {{ getGroupName(gId) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="220" align="right">
              <template #default="scope">
                <el-button type="primary" link @click="openEditDialog(scope.row, scope.row.type || type)">编辑</el-button>
                <el-button type="success" link @click="handleCopy(scope.row, scope.row.type || type)">复制</el-button>
                <el-button type="danger" link @click="handleDelete(scope.row, scope.row.type || type)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <!-- Mobile Card List View -->
          <div v-else class="mobile-node-list" v-loading="loading">
            <div v-if="getPaginatedNodes(type).length === 0" class="empty-placeholder">
              <el-empty description="暂无节点" />
            </div>
            <div v-else v-for="(node, index) in getPaginatedNodes(type)" :key="node.id" class="mobile-node-card">
              <div class="card-header flex-between">
                <span class="node-id-name" style="display: flex; align-items: center;">
                  <span :class="['node-id-badge', node.type || type]" style="font-size: 11px; padding: 2px 5px;">#{{ node.id }}<template v-if="node.parent_id"> =&gt; {{ node.parent_id }}</template></span>
                  <span class="status-dot" :class="getNodeStatusClass(node)" :title="getNodeStatusTitle(node)" style="margin: 0 6px 0 4px;"></span>
                  <span class="node-name">{{ node.name }}</span>
                </span>
                <el-switch
                  v-model="node.show"
                  :active-value="1"
                  :inactive-value="0"
                  @change="(val) => handleToggleShow(node, node.type || type, val)"
                  size="small"
                />
              </div>
              <div class="card-body">
                <div class="body-row flex-between">
                  <div class="badges-group flex-center gap-5">
                    <el-tag type="info" size="small" effect="plain">
                      {{ nodeTypes[node.type] || node.type }}
                    </el-tag>
                    <el-tag :type="node.rate > 1 ? 'warning' : 'success'" size="small" effect="dark">
                      {{ node.rate }}x
                    </el-tag>
                    <span class="online-text">
                      在线: {{ node.online || 0 }} 人
                    </span>
                  </div>
                  <div class="node-groups">
                    <el-tag v-for="gId in node.group_id" :key="gId" size="small" class="mr-5">
                      {{ getGroupName(gId) }}
                    </el-tag>
                  </div>
                </div>
                <div class="body-row node-address">
                  <el-icon class="mr-5"><Connection /></el-icon>
                  <code>{{ node.host }}:{{ node.port }}</code>
                </div>
              </div>
              <div class="card-actions flex-between">
                <div 
                  class="drag-handle-mobile flex-center"
                  style="cursor: grab; touch-action: none; padding: 6px 10px; border-radius: 6px; background-color: var(--el-fill-color-light);"
                  @touchstart="handleTouchStart($event, index)"
                  @touchmove="handleTouchMove($event, index)"
                  @touchend="handleTouchEnd"
                >
                  <el-icon :size="16" class="mr-5"><Rank /></el-icon>
                  <span class="sort-text">拖动排序: {{ node.sort || 0 }}</span>
                </div>
                <div class="action-buttons flex-center gap-15">
                  <el-button type="primary" link size="small" @click="openEditDialog(node, node.type || type)">编辑</el-button>
                  <el-button type="success" link size="small" @click="handleCopy(node, node.type || type)">复制</el-button>
                  <el-button type="danger" link size="small" @click="handleDelete(node, node.type || type)">删除</el-button>
                </div>
              </div>
            </div>
          </div>

          <div class="pagination flex-between mt-20" v-if="getNodeListTotal(type) > nodePageSize">
            <span class="pagination-info">共 {{ getNodeListTotal(type) }} 个节点</span>
            <el-pagination
              v-model:current-page="nodeCurrentPage"
              v-model:page-size="nodePageSize"
              :page-sizes="[50, 100, 150, 200]"
              layout="sizes, prev, pager, next"
              :total="getNodeListTotal(type)"
            />
          </div>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- Node Form Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '750px'" :top="isMobile ? '2vh' : '6vh'">
      <el-scrollbar max-height="72vh">
        <el-form :model="form" :rules="rules" ref="formRef" label-width="130px" style="padding-right: 20px;">
          <!-- 基础设置 -->
          <div class="section-title">基础配置</div>
          <el-form-item label="节点名称" prop="name">
            <el-input v-model="form.name" placeholder="请输入节点名称，如 香港 01 [BGP]" />
          </el-form-item>

          <el-row :gutter="20">
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="流量倍率" prop="rate">
                <el-input-number v-model="form.rate" :min="0" :precision="2" :step="0.1" style="width: 100%" />
              </el-form-item>
            </el-col>
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="分配权限组" prop="group_id">
                <el-select v-model="form.group_id" multiple collapse-tags placeholder="请选择权限组" style="width: 100%">
                  <el-option v-for="g in groupList" :key="g.id" :label="g.name" :value="String(g.id)" />
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>

          <el-form-item label="节点地址" prop="host">
            <el-input v-model="form.host" placeholder="例如 hk1.node.com 或 12.34.56.78" />
          </el-form-item>

          <el-row :gutter="20">
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="连接端口" prop="port">
                <el-input v-model="form.port" placeholder="例如 443 或 20000-30000" />
              </el-form-item>
            </el-col>
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="服务端口" prop="server_port">
                <el-input-number v-model="form.server_port" :min="1" :max="65535" :controls="false" style="width: 100%" />
              </el-form-item>
            </el-col>
          </el-row>

          <el-row :gutter="20">
            <el-col :span="12" :xs="24" :sm="12">
              <el-form-item label="父节点" prop="parent_id">
                <el-select v-model="form.parent_id" placeholder="无单播中转（无父节点）" clearable style="width: 100%">
                  <el-option label="无" :value="0" />
                  <el-option 
                    v-for="s in sameTypeServers" 
                    :key="s.id" 
                    :label="s.name" 
                    :value="s.id" 
                    :disabled="s.id === form.id"
                  />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="12" :xs="24" :sm="12">
              <!-- V2node: 显示协议类型；其他节点: 显示路由组 -->
              <template v-if="activeType === 'v2node'">
                <el-form-item label="协议类型">
                  <el-select v-model="form.v2node_protocol" style="width: 100%" @change="handleV2nodeProtocolChange">
                    <el-option label="AnyTLS" value="anytls" />
                    <el-option label="Hysteria2" value="hysteria2" />
                    <el-option label="Mieru" value="mieru" />
                    <el-option label="Shadowsocks" value="shadowsocks" />
                    <el-option label="Trojan" value="trojan" />
                    <el-option label="Tuic" value="tuic" />
                    <el-option label="VLess" value="vless" />
                    <el-option label="VMess" value="vmess" />
                  </el-select>
                </el-form-item>
              </template>
              <template v-else>
                <el-form-item label="路由组" prop="route_id">
                  <el-select v-model="form.route_id" multiple collapse-tags placeholder="选择分流路由组" style="width: 100%">
                    <el-option v-for="r in routeList" :key="r.id" :label="r.remarks" :value="r.id" />
                  </el-select>
                </el-form-item>
              </template>
            </el-col>
          </el-row>

          <el-form-item label="标签设置" prop="tags">
            <el-select v-model="form.tags" multiple filterable allow-create default-first-option placeholder="请输入标签并回车" style="width: 100%">
              <el-option v-for="t in tagOptions" :key="t" :label="t" :value="t" />
            </el-select>
          </el-form-item>

          <!-- 协议特定设置 -->
          <div class="section-title mt-20">协议配置 ({{ nodeTypes[activeType] }})</div>

          <!-- Shadowsocks Options -->
          <template v-if="activeType === 'shadowsocks' || (activeType === 'v2node' && form.v2node_protocol === 'shadowsocks')">
            <el-form-item label="加密方式" prop="cipher">
              <el-select v-model="form.cipher" style="width: 100%">
                <el-option label="aes-256-gcm" value="aes-256-gcm" />
                <el-option label="aes-128-gcm" value="aes-128-gcm" />
                <el-option label="chacha20-ietf-poly1305" value="chacha20-ietf-poly1305" />
                <el-option label="2022-blake3-aes-128-gcm" value="2022-blake3-aes-128-gcm" />
                <el-option label="2022-blake3-aes-256-gcm" value="2022-blake3-aes-256-gcm" />
              </el-select>
            </el-form-item>
            <el-form-item label="混淆协议" prop="obfs">
              <el-select v-model="form.obfs" placeholder="不启用混淆" clearable style="width: 100%">
                <el-option label="无混淆" :value="null" />
                <el-option label="HTTP 混淆" value="http" />
              </el-select>
            </el-form-item>
            <template v-if="form.obfs === 'http'">
              <el-form-item label="混淆 Host" prop="obfs_settings_host">
                <el-input v-model="form.obfs_settings_host" placeholder="例如: static.xx.com" />
              </el-form-item>
              <el-form-item label="混淆 Path" prop="obfs_settings_path">
                <el-input v-model="form.obfs_settings_path" placeholder="例如: /index.html" />
              </el-form-item>
            </template>
          </template>

          <!-- VMess / Vless Visual Forms -->
          <template v-if="activeType === 'vless' || activeType === 'vmess' || (activeType === 'v2node' && (form.v2node_protocol === 'vmess' || form.v2node_protocol === 'vless'))">
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="传输协议" prop="network">
                  <el-select v-model="form.network" @change="handleNetworkChange" style="width: 100%">
                    <el-option label="TCP" value="tcp" />
                    <el-option label="WebSocket (WS)" value="ws" />
                    <el-option label="gRPC" value="grpc" />
                    <el-option label="KCP" value="kcp" />
                    <el-option label="QUIC" value="quic" />
                    <el-option label="HTTPUpgrade" value="httpupgrade" />
                    <el-option label="xhttp" value="xhttp" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="安全性 (TLS)" prop="tls">
                  <el-select v-model="form.tls" style="width: 100%">
                    <el-option label="无安全性" :value="0" />
                    <el-option label="TLS" :value="1" />
                    <el-option label="Reality" :value="2" v-if="activeType === 'vless' || (activeType === 'v2node' && form.v2node_protocol === 'vless')" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20" v-if="activeType === 'vless' || (activeType === 'v2node' && form.v2node_protocol === 'vless')">
              <el-col :span="12" :xs="24" :sm="12" v-if="form.network === 'tcp'">
                <el-form-item label="XTLS流控算法" prop="flow">
                  <el-select v-model="form.flow" clearable placeholder="无流控" style="width: 100%">
                    <el-option label="无" :value="null" />
                    <el-option label="xtls-rprx-vision" value="xtls-rprx-vision" />
                    <el-option label="自研混淆 (mom-private)" value="mom-private" />
                    <el-option label="自研混淆+Vision (mom-vision)" value="mom-vision" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="加密方式" prop="encryption">
                  <el-select v-model="form.encryption" style="width: 100%">
                    <el-option label="无加密 (none)" value="none" />
                    <el-option label="mlkem768x25519plus" value="mlkem768x25519plus" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item label="VMess加密" v-if="activeType === 'vmess' || (activeType === 'v2node' && form.v2node_protocol === 'vmess')" prop="vmess_security">
              <el-select v-model="form.vmess_security" style="width: 100%">
                <el-option label="Auto" value="auto" />
                <el-option label="AES-128-GCM" value="aes-128-gcm" />
                <el-option label="CHACHA20-POLY1305" value="chacha20-poly1305" />
                <el-option label="None" value="none" />
              </el-select>
            </el-form-item>

            <!-- Advanced tabs mimicking React/UmiJS child drawers -->
            <el-tabs type="border-card" class="mt-15 advanced-json-tabs">
              <!-- TLS Settings Tab -->
              <el-tab-pane label="安全性配置 (tls_settings)" v-if="form.tls > 0">
                <div class="flex-between align-center mb-15">
                  <span class="sub-section-title">编辑安全性配置</span>
                  <el-checkbox v-model="form.edit_tls_raw">编辑原始 JSON</el-checkbox>
                </div>
                
                <template v-if="form.edit_tls_raw">
                  <el-input type="textarea" :rows="8" v-model="form.tls_settings_raw_str" placeholder="{}" class="code-textarea" @input="syncTlsSettingsFromRaw" />
                </template>
                <template v-else>
                  <el-form-item label="Server Name (SNI)">
                    <el-input v-model="form.tls_settings.server_name" :placeholder="form.tls === 2 ? 'REALITY必填，与后端保持一致' : '证书验证SNI域名，留空使用默认'" @input="syncTlsSettingsToRaw" />
                  </el-form-item>

                  <!-- TLS 1.0 specific (Only V2node supports certificate mode auto-generation) -->
                  <template v-if="form.tls === 1 && activeType === 'v2node'">
                    <el-form-item label="证书模式">
                      <el-select v-model="form.tls_settings.cert_mode" style="width: 100%" @change="syncTlsSettingsToRaw">
                        <el-option label="自签名 (self)" value="self" />
                        <el-option label="HTTP 申请 (http)" value="http" />
                        <el-option label="DNS 申请 (dns)" value="dns" />
                        <el-option label="无证书/关闭 TLS (none)" value="none" />
                      </el-select>
                    </el-form-item>

                    <template v-if="form.tls_settings.cert_mode === 'dns'">
                      <el-form-item label="DNS提供商">
                        <el-input v-model="form.tls_settings.provider" placeholder="例如 cloudflare" @input="syncTlsSettingsToRaw" />
                      </el-form-item>
                      <el-form-item label="DNS env">
                        <el-input v-model="form.tls_settings.dns_env" placeholder="例如 CF_DNS_API_TOKEN=xxxx" @input="syncTlsSettingsToRaw" />
                      </el-form-item>
                    </template>

                    <template v-if="form.tls_settings.cert_mode !== 'none'">
                      <el-form-item label="证书公钥路径">
                        <el-input v-model="form.tls_settings.cert_file" placeholder="留空在 /etc/v2node/ 目录自动生成" @input="syncTlsSettingsToRaw" />
                      </el-form-item>
                      <el-form-item label="证书私钥路径">
                        <el-input v-model="form.tls_settings.key_file" placeholder="留空在 /etc/v2node/ 目录自动生成" @input="syncTlsSettingsToRaw" />
                      </el-form-item>
                    </template>

                    <el-form-item label="Reject Unknown SNI">
                      <el-switch v-model="form.tls_settings.reject_unknown_sni" :active-value="1" :inactive-value="0" @change="syncTlsSettingsToRaw" />
                    </el-form-item>
                  </template>

                  <!-- Reality 2.0 specific -->
                  <template v-if="form.tls === 2">
                    <el-form-item label="Server Address">
                      <el-input v-model="form.tls_settings.dest" placeholder="REALITY目标地址，默认使用SNI" @input="syncTlsSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="Server Port">
                      <el-input v-model="form.tls_settings.server_port" placeholder="REALITY目标端口，默认443" @input="syncTlsSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="Proxy Protocol">
                      <el-select v-model="form.tls_settings.xver" style="width: 100%" @change="syncTlsSettingsToRaw">
                        <el-option label="0" :value="0" />
                        <el-option label="1" :value="1" />
                        <el-option label="2" :value="2" />
                      </el-select>
                    </el-form-item>
                    <el-form-item label="Private Key">
                      <el-input v-model="form.tls_settings.private_key" placeholder="留空后端自动生成" @input="syncTlsSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="Public Key">
                      <el-input v-model="form.tls_settings.public_key" placeholder="留空后端自动生成" @input="syncTlsSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="ShortId">
                      <el-input v-model="form.tls_settings.short_id" placeholder="留空后端自动生成" @input="syncTlsSettingsToRaw" />
                    </el-form-item>
                  </template>

                  <el-form-item label="FingerPrint">
                    <el-select v-model="form.tls_settings.fingerprint" placeholder="TLS指纹默认Chrome" style="width: 100%" @change="syncTlsSettingsToRaw">
                      <el-option label="Chrome" value="chrome" />
                      <el-option label="Firefox" value="firefox" />
                      <el-option label="Safari" value="safari" />
                      <el-option label="iOS" value="ios" />
                      <el-option label="Android" value="android" />
                      <el-option label="Edge" value="edge" />
                      <el-option label="360" value="360" />
                      <el-option label="QQ" value="qq" />
                    </el-select>
                  </el-form-item>

                  <el-form-item label="Allow Insecure">
                    <el-switch v-model="form.tls_settings.allow_insecure" :active-value="1" :inactive-value="0" @change="syncTlsSettingsToRaw" />
                  </el-form-item>
                </template>
              </el-tab-pane>

              <!-- Transport Settings Tab -->
              <el-tab-pane label="传输配置 (network_settings)">
                <div class="flex-between align-center mb-15">
                  <span class="sub-section-title">编辑传输协议配置</span>
                  <el-checkbox v-model="form.edit_network_raw">编辑原始 JSON</el-checkbox>
                </div>

                <template v-if="form.edit_network_raw">
                  <el-input type="textarea" :rows="8" v-model="form.network_settings_raw_str" placeholder="[]" class="code-textarea" @input="syncNetworkSettingsFromRaw" />
                </template>
                <template v-else>
                  <!-- Form fields matching network -->
                  <template v-if="form.network === 'ws'">
                    <el-form-item label="WebSocket 路径">
                      <el-input v-model="form.network_settings.path" placeholder="/" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="WebSocket 主机">
                      <el-input v-model="network_settings_host" placeholder="例如 static.xx.com (可留空)" />
                    </el-form-item>
                  </template>

                  <template v-else-if="form.network === 'grpc'">
                    <el-form-item label="gRPC 服务名">
                      <el-input v-model="form.network_settings.serviceName" placeholder="GunService" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                  </template>

                  <template v-else-if="form.network === 'httpupgrade'">
                    <el-form-item label="HTTPUpgrade 路径">
                      <el-input v-model="form.network_settings.path" placeholder="/" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="HTTPUpgrade 主机">
                      <el-input v-model="form.network_settings.host" placeholder="例如 static.xx.com (可留空)" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                  </template>

                  <template v-else-if="form.network === 'xhttp'">
                    <el-form-item label="xhttp 路径">
                      <el-input v-model="form.network_settings.path" placeholder="/" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="xhttp 主机">
                      <el-input v-model="form.network_settings.host" placeholder="例如 static.xx.com (可留空)" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="xhttp 模式">
                      <el-select v-model="form.network_settings.mode" style="width: 100%" @change="syncNetworkSettingsToRaw">
                        <el-option label="auto" value="auto" />
                        <el-option label="packet" value="packet" />
                        <el-option label="stream" value="stream" />
                      </el-select>
                    </el-form-item>
                  </template>

                  <template v-else-if="form.network === 'tcp'">
                    <el-form-item label="HTTP 混淆路径">
                      <el-input v-model="tcp_path_shortcut" placeholder="例如 / (选填)" />
                    </el-form-item>
                    <el-form-item label="HTTP 混淆主机">
                      <el-input v-model="tcp_host_shortcut" placeholder="例如 www.baidu.com (选填)" />
                    </el-form-item>
                  </template>

                  <template v-else-if="form.network === 'kcp'">
                    <el-form-item label="混淆类型 (type)">
                      <el-input v-model="form.network_settings.header.type" placeholder="none" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                    <el-form-item label="KCP Seed">
                      <el-input v-model="form.network_settings.seed" placeholder="加密混淆因子" @input="syncNetworkSettingsToRaw" />
                    </el-form-item>
                  </template>

                  <template v-else>
                    <div class="text-muted p-10 font-13">此传输协议无专属表单，请勾选“编辑原始 JSON”以定义高级规则。</div>
                  </template>
                </template>
              </el-tab-pane>

              <!-- Encryption Settings Tab (Vless only) -->
              <el-tab-pane label="加密配置 (encryption_settings)" v-if="(activeType === 'vless' || (activeType === 'v2node' && form.v2node_protocol === 'vless')) && form.encryption !== 'none'">
                <div class="flex-between align-center mb-15">
                  <span class="sub-section-title">编辑加密配置</span>
                  <el-checkbox v-model="form.edit_encryption_raw">编辑原始 JSON</el-checkbox>
                </div>

                <template v-if="form.edit_encryption_raw">
                  <el-input type="textarea" :rows="8" v-model="form.encryption_settings_raw_str" placeholder="[]" class="code-textarea" @input="syncEncryptionSettingsFromRaw" />
                </template>
                <template v-else>
                  <el-form-item label="加密模式 (mode)">
                    <el-select v-model="form.encryption_settings.mode" style="width: 100%" @change="syncEncryptionSettingsToRaw">
                      <el-option label="native" value="native" />
                      <el-option label="xorpub" value="xorpub" />
                      <el-option label="random" value="random" />
                    </el-select>
                  </el-form-item>
                  <el-form-item label="RTT 握手模式">
                    <el-select v-model="form.encryption_settings.rtt" style="width: 100%" @change="syncEncryptionSettingsToRaw">
                      <el-option label="0-RTT (0rtt)" value="0rtt" />
                      <el-option label="1-RTT (1rtt)" value="1rtt" />
                    </el-select>
                  </el-form-item>
                  <el-form-item label="Ticket 有效时间" v-if="form.encryption_settings.rtt === '0rtt'">
                    <el-input v-model="form.encryption_settings.ticket" placeholder="600s" @input="syncEncryptionSettingsToRaw" />
                  </el-form-item>
                  <el-form-item label="Server Padding">
                    <el-input v-model="form.encryption_settings.server_padding" placeholder="留空使用默认" @input="syncEncryptionSettingsToRaw" />
                  </el-form-item>
                  <el-form-item label="Client Padding">
                    <el-input v-model="form.encryption_settings.client_padding" placeholder="留空使用默认" @input="syncEncryptionSettingsToRaw" />
                  </el-form-item>
                  <el-form-item label="Private Key">
                    <el-input v-model="form.encryption_settings.private_key" placeholder="留空后端自动生成" @input="syncEncryptionSettingsToRaw" />
                  </el-form-item>
                  <el-form-item label="Password">
                    <el-input v-model="form.encryption_settings.password" placeholder="留空后端自动生成" @input="syncEncryptionSettingsToRaw" />
                  </el-form-item>
                </template>
              </el-tab-pane>

              <!-- VMess Extra JSON parameters -->
              <template v-if="activeType === 'vmess' || (activeType === 'v2node' && form.v2node_protocol === 'vmess')">
                <el-tab-pane label="DNS 配置 (dnsSettings)">
                  <el-input type="textarea" :rows="8" v-model="form.dnsSettings_str" placeholder="{}" class="code-textarea" />
                </el-tab-pane>
                <el-tab-pane label="规则配置 (ruleSettings)">
                  <el-input type="textarea" :rows="8" v-model="form.ruleSettings_str" placeholder="{}" class="code-textarea" />
                </el-tab-pane>
              </template>
            </el-tabs>
          </template>

          <!-- Trojan Options -->
          <template v-if="activeType === 'trojan' || (activeType === 'v2node' && form.v2node_protocol === 'trojan')">
            <el-form-item label="SNI/域名" prop="server_name">
              <el-input v-model="form.server_name" placeholder="请输入 SNI / Server Name" />
            </el-form-item>
            <el-form-item label="允许不安全证书" prop="allow_insecure">
              <el-switch v-model="form.allow_insecure" :active-value="1" :inactive-value="0" />
            </el-form-item>
          </template>

          <!-- Hysteria Options -->
          <template v-if="activeType === 'hysteria' || (activeType === 'v2node' && form.v2node_protocol === 'hysteria2')">
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="Hysteria 版本" prop="version">
                  <el-select v-model="form.version" style="width: 100%">
                    <el-option label="Hysteria 1" :value="1" />
                    <el-option label="Hysteria 2" :value="2" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="允许不安全" prop="insecure">
                  <el-switch v-model="form.insecure" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="上行带宽 (Mbps)" prop="up_mbps">
                  <el-input-number v-model="form.up_mbps" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="下行带宽 (Mbps)" prop="down_mbps">
                  <el-input-number v-model="form.down_mbps" :min="0" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="SNI / ServerName" prop="server_name">
              <el-input v-model="form.server_name" placeholder="节点证书 SNI" />
            </el-form-item>
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="混淆协议 (obfs)" prop="obfs">
                  <el-select v-model="form.obfs" placeholder="不启用混淆" clearable style="width: 100%">
                    <el-option label="不启用混淆" value="" />
                    <el-option label="salamander" value="salamander" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="混淆密码" prop="obfs_password">
                  <el-input v-model="form.obfs_password" placeholder="留空则自动生成" />
                </el-form-item>
              </el-col>
            </el-row>
          </template>

          <!-- Tuic Options -->
          <template v-if="activeType === 'tuic' || (activeType === 'v2node' && form.v2node_protocol === 'tuic')">
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="SNI / ServerName" prop="server_name">
                  <el-input v-model="form.server_name" placeholder="请输入 SNI" />
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="允许不安全" prop="insecure">
                  <el-switch v-model="form.insecure" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="关闭 SNI" prop="disable_sni">
                  <el-switch v-model="form.disable_sni" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="0-RTT 握手" prop="zero_rtt_handshake">
                  <el-switch v-model="form.zero_rtt_handshake" :active-value="1" :inactive-value="0" />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="UDP 转发模式" prop="udp_relay_mode">
                  <el-select v-model="form.udp_relay_mode" placeholder="默认 (native)" style="width: 100%">
                    <el-option label="native" value="native" />
                    <el-option label="quic" value="quic" />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="拥塞控制" prop="congestion_control">
                  <el-select v-model="form.congestion_control" placeholder="默认 (bbr)" style="width: 100%">
                    <el-option label="bbr" value="bbr" />
                    <el-option label="cubic" value="cubic" />
                    <el-option label="new_reno" value="new_reno" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
          </template>

          <!-- AnyTLS Options -->
          <template v-if="activeType === 'anytls'">
            <el-form-item label="SNI / 域名" prop="server_name">
              <el-input v-model="form.server_name" placeholder="请输入 SNI / Server Name" />
            </el-form-item>
            <el-form-item label="允许不安全证书" prop="insecure">
              <el-switch v-model="form.insecure" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="填充方案" prop="anytls_custom_str">
              <el-input type="textarea" :rows="8" v-model="form.anytls_custom_str" placeholder="请输入 JSON 数组，如：[&quot;stop=8&quot;, &quot;0=30-30&quot;]" class="code-textarea" />
            </el-form-item>
          </template>

          <!-- V2node Options: 监听地址 + 路由组（协议类型已移至基础配置区） -->
          <template v-if="activeType === 'v2node'">
            <el-row :gutter="20">
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="监听地址">
                  <el-input v-model="form.listen_ip" placeholder="默认 0.0.0.0" />
                </el-form-item>
              </el-col>
              <el-col :span="12" :xs="24" :sm="12">
                <el-form-item label="路由组">
                  <el-select v-model="form.route_id" multiple collapse-tags placeholder="选择分流路由组" style="width: 100%">
                    <el-option v-for="r in routeList" :key="r.id" :label="r.remarks" :value="r.id" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
          </template>

          <!-- V2node AnyTLS (raw JSON since padding_scheme is complex) -->
          <template v-if="activeType === 'v2node' && form.v2node_protocol === 'anytls'">
            <el-form-item label="TLS SNI">
              <el-input v-model="form.server_name" placeholder="证书验证SNI域名" />
            </el-form-item>
            <el-form-item label="允许不安全">
              <el-switch v-model="form.allow_insecure" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="填充方案" prop="anytls_custom_str">
              <el-input type="textarea" :rows="8" v-model="form.anytls_custom_str" placeholder="请输入 JSON 数组，如：[&quot;stop=8&quot;, &quot;0=30-30&quot;]" class="code-textarea" />
            </el-form-item>
          </template>

          <!-- V2node Mieru -->
          <template v-if="activeType === 'v2node' && form.v2node_protocol === 'mieru'">
            <el-form-item label="端口范围" prop="tls_settings.port_range">
              <el-input v-model="form.tls_settings.port_range" placeholder="例如：10000-10050" />
            </el-form-item>
            <el-form-item label="传输协议">
              <el-select v-model="form.tls_settings.transport" placeholder="请选择传输协议" style="width: 100%">
                <el-option label="TCP" value="TCP" />
                <el-option label="UDP" value="UDP" />
              </el-select>
            </el-form-item>
          </template>

          <el-form-item label="上架状态" class="mt-15">
            <el-radio-group v-model="form.show">
              <el-radio :label="1">启用显示</el-radio>
              <el-radio :label="0">下架隐藏</el-radio>
            </el-radio-group>
          </el-form-item>
        </el-form>
      </el-scrollbar>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmit">确定</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, computed } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';

const isMobile = ref(false);
const checkMobile = () => {
  isMobile.value = window.innerWidth <= 768;
};

const loading = ref(false);
const submitLoading = ref(false);
const dialogVisible = ref(false);
const isEdit = ref(false);
const dialogTitle = ref('添加节点');

const activeTab = ref('all');
const activeType = ref('shadowsocks');

const nodeTypes = {
  all: '所有',
  shadowsocks: 'Shadowsocks',
  vmess: 'Vmess',
  trojan: 'Trojan',
  vless: 'Vless',
  hysteria: 'Hysteria',
  tuic: 'Tuic',
  anytls: 'AnyTLS',
  v2node: 'V2node'
};

const nodeLists = reactive({
  shadowsocks: [],
  vmess: [],
  trojan: [],
  vless: [],
  hysteria: [],
  tuic: [],
  anytls: [],
  v2node: [],
});

const allNodes = computed(() => {
  const list = [];
  Object.keys(nodeLists).forEach(type => {
    nodeLists[type].forEach(node => {
      list.push({ ...node, type });
    });
  });
  return list.sort((a, b) => (a.sort || 0) - (b.sort || 0) || a.id - b.id);
});

const DEFAULT_PADDING_SCHEME = JSON.stringify([
  "stop=8",
  "0=30-30",
  "1=100-400",
  "2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000",
  "3=9-9,500-1000",
  "4=500-1000",
  "5=500-1000",
  "6=500-1000",
  "7=500-1000"
], null, 2);

const groupList = ref([]);
const routeList = ref([]);
const tagOptions = ref(['香港', '日本', '新加坡', '美国', '台湾', '优化', 'BGP', 'IPLC', 'IEPL']);

// Prepopulated templates for transport protocol configs
const networkTemplates = {
  tcp: [],
  ws: {
    path: "/",
    headers: {
      Host: "xtls.github.io"
    }
  },
  grpc: {
    serviceName: "GunService"
  },
  kcp: {
    header: {
      type: "none"
    },
    seed: ""
  },
  quic: {
    security: "none",
    key: "",
    header: {
      type: "none"
    }
  },
  httpupgrade: {
    path: "/",
    host: "xtls.github.io"
  },
  xhttp: {
    path: "/",
    host: "xtls.github.io",
    mode: "auto",
    extra: {}
  }
};

const formRef = ref(null);
const form = reactive({
  id: null,
  name: '',
  rate: 1.0,
  group_id: [],
  host: '',
  port: 10000,
  server_port: 10000,
  parent_id: 0,
  route_id: [],
  tags: [],
  show: 1,
  
  // Shadowsocks specific
  cipher: 'aes-256-gcm',
  obfs: null,
  obfs_settings_host: '',
  obfs_settings_path: '',
  
  // Transport protocol toggle & camelCase fields
  network: 'tcp',
  tls: 0,
  vmess_security: 'none',

  // GUI configurations and mappings
  edit_tls_raw: false,
  tls_settings: {
    server_name: '',
    cert_mode: 'self',
    provider: '',
    dns_env: '',
    cert_file: '',
    key_file: '',
    dest: '',
    server_port: '443',
    xver: 0,
    private_key: '',
    public_key: '',
    short_id: '',
    fingerprint: 'chrome',
    reject_unknown_sni: 0,
    allow_insecure: 0,
    port_range: '',
    transport: 'TCP'
  },
  tls_settings_raw_str: '{}',

  edit_network_raw: false,
  network_settings: [],
  network_settings_raw_str: '[]',

  edit_encryption_raw: false,
  encryption_settings: {
    mode: 'native',
    rtt: '0rtt',
    ticket: '600s',
    server_padding: '',
    client_padding: '',
    private_key: '',
    password: ''
  },
  encryption_settings_raw_str: '[]',

  // Other advanced text strings
  dnsSettings_str: '{}',
  ruleSettings_str: '{}',

  // Vless specific
  flow: null,
  encryption: 'none',
  
  // Trojan specific
  server_name: '',
  allow_insecure: 0,
  
  // Hysteria specific
  version: 2,
  up_mbps: 100,
  down_mbps: 100,
  obfs: '',
  obfs_password: '',
  insecure: 0,
  
  // Tuic specific
  disable_sni: 0,
  udp_relay_mode: 'native',
  zero_rtt_handshake: 1,
  congestion_control: 'bbr',

  // Custom configurations (for AnyTLS)
  anytls_custom_str: DEFAULT_PADDING_SCHEME,

  // V2node specific
  listen_ip: '0.0.0.0',
  v2node_protocol: 'vmess'
});

const rules = {
  name: [{ required: true, message: '请输入节点名称', trigger: 'blur' }],
  host: [{ required: true, message: '请输入节点地址', trigger: 'blur' }],
  port: [{ required: true, message: '请输入连接端口', trigger: 'blur' }],
  server_port: [{ required: true, message: '请输入服务端口', trigger: 'blur' }],
  group_id: [{ type: 'array', required: true, message: '请选择至少一个权限组', trigger: 'change' }],
};

const sameTypeServers = computed(() => {
  return (nodeLists[activeType.value] || []).filter(s => s.id !== form.id);
});

// Computed properties for shortcuts & nested structures in network settings
const network_settings_host = computed({
  get() {
    if (!form.network_settings || Array.isArray(form.network_settings)) return '';
    return form.network_settings.headers?.Host || form.network_settings.host || '';
  },
  set(val) {
    if (!form.network_settings || Array.isArray(form.network_settings)) form.network_settings = {};
    if (!form.network_settings.headers) form.network_settings.headers = {};
    form.network_settings.headers.Host = val;
    form.network_settings.host = val;
    syncNetworkSettingsToRaw();
  }
});

const tcp_path_shortcut = computed({
  get() {
    if (!form.network_settings || Array.isArray(form.network_settings)) return '';
    return form.network_settings.header?.request?.path?.[0] || '';
  },
  set(val) {
    if (!form.network_settings || Array.isArray(form.network_settings)) form.network_settings = {};
    if (val) {
      if (!form.network_settings.header) form.network_settings.header = {};
      if (!form.network_settings.header.request) form.network_settings.header.request = {};
      form.network_settings.header.type = 'http';
      form.network_settings.header.request.path = [val];
      if (!form.network_settings.header.response) form.network_settings.header.response = {};
    } else if (form.network_settings.header?.request?.path) {
      delete form.network_settings.header.request.path;
    }
    syncNetworkSettingsToRaw();
  }
});

const tcp_host_shortcut = computed({
  get() {
    if (!form.network_settings || Array.isArray(form.network_settings)) return '';
    return form.network_settings.header?.request?.headers?.Host?.[0] || '';
  },
  set(val) {
    if (!form.network_settings || Array.isArray(form.network_settings)) form.network_settings = {};
    if (val) {
      if (!form.network_settings.header) form.network_settings.header = {};
      if (!form.network_settings.header.request) form.network_settings.header.request = {};
      if (!form.network_settings.header.request.headers) form.network_settings.header.request.headers = {};
      form.network_settings.header.type = 'http';
      form.network_settings.header.request.headers.Host = [val];
      if (!form.network_settings.header.response) form.network_settings.header.response = {};
    } else if (form.network_settings.header?.request?.headers?.Host) {
      delete form.network_settings.header.request.headers.Host;
    }
    syncNetworkSettingsToRaw();
  }
});

const getGroupName = (id) => {
  const g = groupList.value.find(item => Number(item.id) === Number(id));
  return g ? g.name : `组 ${id}`;
};

// Local pagination logic
const nodePageSize = ref(50);
const nodeCurrentPage = ref(1);

const getNodeListTotal = (type) => {
  const list = type === 'all' ? allNodes.value : (nodeLists[type] || []);
  return list.length;
};

const getPaginatedNodes = (type) => {
  const list = type === 'all' ? allNodes.value : (nodeLists[type] || []);
  const start = (nodeCurrentPage.value - 1) * nodePageSize.value;
  const end = start + nodePageSize.value;
  return list.slice(start, end);
};

// Drag and drop sorting logic
const dragIndex = ref(-1);

const handleDragStart = (index) => {
  const absoluteIndex = (nodeCurrentPage.value - 1) * nodePageSize.value + index;
  dragIndex.value = absoluteIndex;
};

const handleDragOver = (index) => {
  const absoluteIndex = (nodeCurrentPage.value - 1) * nodePageSize.value + index;
  if (dragIndex.value === -1 || dragIndex.value === absoluteIndex) return;
  swapNodes(dragIndex.value, absoluteIndex);
  dragIndex.value = absoluteIndex;
};

const handleDragEnd = () => {
  dragIndex.value = -1;
};

// Touch drag sorting logic for Mobile
const touchStartIndex = ref(-1);
const touchStartY = ref(0);

const handleTouchStart = (event, index) => {
  const absoluteIndex = (nodeCurrentPage.value - 1) * nodePageSize.value + index;
  touchStartIndex.value = absoluteIndex;
  touchStartY.value = event.touches[0].clientY;
};

const handleTouchMove = (event, index) => {
  if (touchStartIndex.value === -1) return;
  const currentY = event.touches[0].clientY;
  const diffY = currentY - touchStartY.value;
  
  const step = 80; // card height threshold
  if (Math.abs(diffY) > step) {
    const direction = diffY > 0 ? 1 : -1;
    const targetAbsoluteIndex = touchStartIndex.value + direction;
    
    const totalNodes = getNodeListTotal(activeTab.value);
    if (targetAbsoluteIndex >= 0 && targetAbsoluteIndex < totalNodes) {
      swapNodes(touchStartIndex.value, targetAbsoluteIndex);
      
      touchStartIndex.value = targetAbsoluteIndex;
      touchStartY.value = currentY;
    }
  }
};

const handleTouchEnd = () => {
  touchStartIndex.value = -1;
};

const swapNodes = (fromIndex, toIndex) => {
  if (fromIndex < 0 || toIndex < 0) return;
  
  if (activeTab.value === 'all') {
    const list = [...allNodes.value];
    const temp = list[fromIndex];
    list.splice(fromIndex, 1, list[toIndex]);
    list.splice(toIndex, 1, temp);
    
    // Assign sequential sort values to original lists
    list.forEach((node, idx) => {
      const orig = nodeLists[node.type].find(n => n.id === node.id);
      if (orig) {
        orig.sort = idx;
      }
    });
  } else {
    const list = nodeLists[activeTab.value];
    const temp = list[fromIndex];
    list.splice(fromIndex, 1, list[toIndex]);
    list.splice(toIndex, 1, temp);
    
    // Assign sequential sort values to current list
    list.forEach((node, idx) => {
      node.sort = idx;
    });
  }
};

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

const fetchRoutes = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/route/fetch`);
    if (res.data) {
      routeList.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchNodes = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/manage/getNodes`);
    if (res.data) {
      Object.keys(nodeLists).forEach(k => {
        nodeLists[k] = [];
      });
      res.data.forEach(node => {
        const type = node.type || 'vmess';
        if (nodeLists[type] !== undefined) {
          nodeLists[type].push(node);
        }
      });
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleTabChange = (name) => {
  if (name !== 'all') {
    activeType.value = name;
  }
  nodeCurrentPage.value = 1; // Reset local page when switching tabs
};

const saveSortLoading = ref(false);
const handleSaveSort = async () => {
  saveSortLoading.value = true;
  try {
    const payload = {};
    // Gather all types
    Object.keys(nodeLists).forEach(type => {
      payload[type] = {};
      nodeLists[type].forEach(node => {
        payload[type][node.id] = node.sort || 0;
      });
    });

    const securePath = getSecurePath();
    await api.post(`/${securePath}/server/manage/sort`, payload);
    ElMessage.success('排序保存成功');
    fetchNodes();
  } catch (err) {
    console.error(err);
    ElMessage.error('排序保存失败');
  } finally {
    saveSortLoading.value = false;
  }
};

// Sync helpers
const syncTlsSettingsToRaw = () => {
  form.tls_settings_raw_str = JSON.stringify(form.tls_settings, null, 2);
};

const syncTlsSettingsFromRaw = () => {
  try {
    const parsed = JSON.parse(form.tls_settings_raw_str || '{}');
    Object.assign(form.tls_settings, parsed);
  } catch (e) {}
};

const syncNetworkSettingsToRaw = () => {
  form.network_settings_raw_str = JSON.stringify(form.network_settings, null, 2);
};

const syncNetworkSettingsFromRaw = () => {
  try {
    form.network_settings = JSON.parse(form.network_settings_raw_str || '[]');
  } catch (e) {}
};

const syncEncryptionSettingsToRaw = () => {
  form.encryption_settings_raw_str = JSON.stringify(form.encryption_settings, null, 2);
};

const syncEncryptionSettingsFromRaw = () => {
  try {
    const parsed = JSON.parse(form.encryption_settings_raw_str || '[]');
    if (!Array.isArray(parsed)) Object.assign(form.encryption_settings, parsed);
  } catch (e) {}
};

const handleNetworkChange = (newVal) => {
  const template = networkTemplates[newVal] || {};
  form.network_settings = JSON.parse(JSON.stringify(template));
  syncNetworkSettingsToRaw();
};

const pruneEmpty = (value) => {
  if (Array.isArray(value)) {
    return value.map(pruneEmpty).filter(item => item !== undefined && item !== null && item !== '');
  }
  if (value && typeof value === 'object') {
    const result = {};
    Object.keys(value).forEach((key) => {
      const cleaned = pruneEmpty(value[key]);
      if (
        cleaned !== undefined &&
        cleaned !== null &&
        cleaned !== '' &&
        !(Array.isArray(cleaned) && cleaned.length === 0) &&
        !(typeof cleaned === 'object' && !Array.isArray(cleaned) && Object.keys(cleaned).length === 0)
      ) {
        result[key] = cleaned;
      }
    });
    return result;
  }
  return value;
};

const normalizeNetworkSettings = (network, settings = {}, emptyValue = []) => {
  const cleaned = pruneEmpty(JSON.parse(JSON.stringify(settings || {})));
  if (network !== 'tcp') return cleaned;

  const path = cleaned.header?.request?.path?.[0];
  const host = cleaned.header?.request?.headers?.Host?.[0];
  if (!path && !host) return emptyValue;

  const tcpSettings = {
    header: {
      type: 'http',
      request: {},
      response: {}
    }
  };
  if (path) tcpSettings.header.request.path = [path];
  if (host) tcpSettings.header.request.headers = { Host: [host] };
  return tcpSettings;
};

// Build tls_settings object from form fields (shared between vless/vmess/v2node submit)
const buildTlsSettings = () => {
  if (form.tls === 1) {
    // Standard vmess/vless only needs server_name, fingerprint, allow_insecure
    if (activeType.value !== 'v2node') {
      return {
        server_name: form.tls_settings.server_name,
        fingerprint: form.tls_settings.fingerprint,
        allow_insecure: String(form.tls_settings.allow_insecure)
      };
    }
    // V2node supports visual certificate auto-generation
    const s = {
      server_name: form.tls_settings.server_name,
      cert_mode: form.tls_settings.cert_mode,
      fingerprint: form.tls_settings.fingerprint,
      allow_insecure: String(form.tls_settings.allow_insecure),
      reject_unknown_sni: String(form.tls_settings.reject_unknown_sni)
    };
    if (form.tls_settings.cert_mode === 'dns') {
      s.provider = form.tls_settings.provider;
      s.dns_env = form.tls_settings.dns_env;
    }
    if (form.tls_settings.cert_mode !== 'none') {
      if (form.tls_settings.cert_file) s.cert_file = form.tls_settings.cert_file;
      if (form.tls_settings.key_file) s.key_file = form.tls_settings.key_file;
    }
    return s;
  } else if (form.tls === 2) {
    const s = {
      server_name: form.tls_settings.server_name,
      dest: form.tls_settings.dest,
      server_port: form.tls_settings.server_port || '443',
      xver: String(form.tls_settings.xver),
      fingerprint: form.tls_settings.fingerprint,
      allow_insecure: String(form.tls_settings.allow_insecure)
    };
    if (form.tls_settings.private_key) s.private_key = form.tls_settings.private_key;
    if (form.tls_settings.public_key) s.public_key = form.tls_settings.public_key;
    if (form.tls_settings.short_id) s.short_id = form.tls_settings.short_id;
    return s;
  }
  return {};
};

// Reset sub-protocol fields when switching v2node protocol
const handleV2nodeProtocolChange = (proto) => {
  form.tls = ['hysteria2', 'trojan', 'tuic', 'anytls'].includes(proto) ? 1 : 0;
  form.network = 'tcp';
  form.network_settings = [];
  form.network_settings_raw_str = '[]';
  form.tls_settings = { server_name: '', cert_mode: 'self', provider: '', dns_env: '', cert_file: '', key_file: '', dest: '', server_port: '443', xver: 0, private_key: '', public_key: '', short_id: '', fingerprint: 'chrome', reject_unknown_sni: 0, allow_insecure: 0, port_range: '', transport: 'TCP' };
  form.tls_settings_raw_str = '{}';
  form.server_name = '';
  form.insecure = 0;
  form.allow_insecure = 0;
};

const handleToggleShow = async (row, type, val) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/server/${type}/update`, {
      id: row.id,
      show: val
    });
    ElMessage.success('状态更新成功');
  } catch (err) {
    console.error(err);
    row.show = val === 1 ? 0 : 1;
  }
};

const handleCreateCommand = (type) => {
  isEdit.value = false;
  activeType.value = type;
  dialogTitle.value = `添加 ${nodeTypes[type]} 节点`;
  
  // Reset form
  form.id = null;
  form.name = '';
  form.rate = 1.0;
  form.group_id = groupList.value.length > 0 ? [String(groupList.value[0].id)] : [];
  form.host = '';
  form.port = 10000;
  form.server_port = 10000;
  form.parent_id = 0;
  form.route_id = [];
  form.tags = [];
  form.show = 1;
  
  form.cipher = 'aes-256-gcm';
  form.obfs = null;
  form.obfs_settings_host = '';
  form.obfs_settings_path = '';
  
  form.network = 'tcp';
  form.tls = 0;
  form.vmess_security = 'none';

  form.edit_tls_raw = false;
  form.tls_settings = {
    server_name: '',
    cert_mode: 'self',
    provider: '',
    dns_env: '',
    cert_file: '',
    key_file: '',
    dest: '',
    server_port: '443',
    xver: 0,
    private_key: '',
    public_key: '',
    short_id: '',
    fingerprint: 'chrome',
    reject_unknown_sni: 0,
    allow_insecure: 0,
    port_range: '',
    transport: 'TCP'
  };
  form.tls_settings_raw_str = '{}';

  form.edit_network_raw = false;
  form.network_settings = [];
  form.network_settings_raw_str = '[]';

  form.edit_encryption_raw = false;
  form.encryption_settings = {
    mode: 'native',
    rtt: '0rtt',
    ticket: '600s',
    server_padding: '',
    client_padding: '',
    private_key: '',
    password: ''
  };
  form.encryption_settings_raw_str = '[]';

  form.dnsSettings_str = '{}';
  form.ruleSettings_str = '{}';
  
  form.flow = null;
  form.encryption = 'none';
  
  form.server_name = '';
  form.allow_insecure = 0;
  
  form.version = 2;
  form.up_mbps = 100;
  form.down_mbps = 100;
  form.obfs = '';
  form.obfs_password = '';
  form.insecure = 0;
  
  form.disable_sni = 0;
  form.udp_relay_mode = 'native';
  form.zero_rtt_handshake = 1;
  form.congestion_control = 'bbr';

  form.anytls_custom_str = DEFAULT_PADDING_SCHEME;
  form.listen_ip = '0.0.0.0';
  form.v2node_protocol = 'vmess';
  
  dialogVisible.value = true;
};

const openEditDialog = (row, type) => {
  isEdit.value = true;
  activeType.value = type;
  dialogTitle.value = `编辑 ${nodeTypes[type]} 节点`;
  
  form.id = row.id;
  form.name = row.name;
  form.rate = row.rate;
  form.group_id = (row.group_id || []).map(id => String(id));
  form.host = row.host;
  form.port = row.port;
  form.server_port = row.server_port;
  form.parent_id = row.parent_id || 0;
  form.route_id = row.route_id || [];
  form.tags = row.tags || [];
  form.show = row.show;

  form.edit_tls_raw = false;
  form.edit_network_raw = false;
  form.edit_encryption_raw = false;
  
  if (type === 'shadowsocks') {
    form.cipher = row.cipher || 'aes-256-gcm';
    form.obfs = row.obfs || null;
    form.obfs_settings_host = row.obfs_settings?.host || '';
    form.obfs_settings_path = row.obfs_settings?.path || '';
  } else if (type === 'vmess') {
    form.network = row.network || 'tcp';
    form.tls = row.tls || 0;
    form.vmess_security = row.networkSettings?.security || 'none';

    const tlsSettings = row.tlsSettings || {};
    form.tls_settings = {
      server_name: tlsSettings.server_name || '',
      cert_mode: tlsSettings.cert_mode || 'self',
      provider: tlsSettings.provider || '',
      dns_env: tlsSettings.dns_env || '',
      cert_file: tlsSettings.cert_file || '',
      key_file: tlsSettings.key_file || '',
      dest: tlsSettings.dest || '',
      server_port: tlsSettings.server_port || '443',
      xver: tlsSettings.xver || 0,
      private_key: tlsSettings.private_key || '',
      public_key: tlsSettings.public_key || '',
      short_id: tlsSettings.short_id || '',
      fingerprint: tlsSettings.fingerprint || 'chrome',
      reject_unknown_sni: Number(tlsSettings.reject_unknown_sni) || 0,
      allow_insecure: Number(tlsSettings.allow_insecure) || 0
    };
    form.tls_settings_raw_str = JSON.stringify(tlsSettings, null, 2);

    const networkSettings = row.networkSettings || {};
    form.network_settings = JSON.parse(JSON.stringify(networkSettings));
    form.network_settings_raw_str = JSON.stringify(networkSettings, null, 2);

    form.dnsSettings_str = JSON.stringify(row.dnsSettings || {}, null, 2);
    form.ruleSettings_str = JSON.stringify(row.ruleSettings || {}, null, 2);
  } else if (type === 'vless') {
    form.tls = row.tls || 0;
    form.network = row.network || 'tcp';
    form.flow = row.flow || null;
    form.encryption = row.encryption || 'none';

    const tlsSettings = row.tls_settings || {};
    form.tls_settings = {
      server_name: tlsSettings.server_name || '',
      cert_mode: tlsSettings.cert_mode || 'self',
      provider: tlsSettings.provider || '',
      dns_env: tlsSettings.dns_env || '',
      cert_file: tlsSettings.cert_file || '',
      key_file: tlsSettings.key_file || '',
      dest: tlsSettings.dest || '',
      server_port: tlsSettings.server_port || '443',
      xver: tlsSettings.xver || 0,
      private_key: tlsSettings.private_key || '',
      public_key: tlsSettings.public_key || '',
      short_id: tlsSettings.short_id || '',
      fingerprint: tlsSettings.fingerprint || 'chrome',
      reject_unknown_sni: Number(tlsSettings.reject_unknown_sni) || 0,
      allow_insecure: Number(tlsSettings.allow_insecure) || 0
    };
    form.tls_settings_raw_str = JSON.stringify(tlsSettings, null, 2);

    const networkSettings = row.network_settings || [];
    form.network_settings = JSON.parse(JSON.stringify(networkSettings));
    form.network_settings_raw_str = JSON.stringify(networkSettings, null, 2);

    const encryptionSettings = row.encryption_settings || [];
    form.encryption_settings = {
      mode: encryptionSettings.mode || 'native',
      rtt: encryptionSettings.rtt || '0rtt',
      ticket: encryptionSettings.ticket || '600s',
      server_padding: encryptionSettings.server_padding || '',
      client_padding: encryptionSettings.client_padding || '',
      private_key: encryptionSettings.private_key || '',
      password: encryptionSettings.password || ''
    };
    form.encryption_settings_raw_str = JSON.stringify(encryptionSettings, null, 2);
  } else if (type === 'trojan') {
    form.network = row.network || 'tcp';
    form.network_settings = JSON.parse(JSON.stringify(row.network_settings || []));
    form.network_settings_raw_str = JSON.stringify(row.network_settings || [], null, 2);
    form.server_name = row.server_name || '';
    form.allow_insecure = row.allow_insecure || 0;
  } else if (type === 'hysteria') {
    form.version = row.version || 2;
    form.up_mbps = row.up_mbps || 0;
    form.down_mbps = row.down_mbps || 0;
    form.obfs = row.obfs || '';
    form.obfs_password = row.obfs_password || '';
    form.server_name = row.server_name || '';
    form.insecure = row.insecure || 0;
  } else if (type === 'tuic') {
    form.server_name = row.server_name || '';
    form.insecure = row.insecure || 0;
    form.disable_sni = row.disable_sni || 0;
    form.udp_relay_mode = row.udp_relay_mode || 'native';
    form.zero_rtt_handshake = row.zero_rtt_handshake || 0;
    form.congestion_control = row.congestion_control || 'bbr';
  } else if (type === 'anytls') {
    form.server_name = row.server_name || '';
    form.insecure = row.insecure || 0;
    const paddingScheme = row.padding_scheme || [];
    form.anytls_custom_str = typeof paddingScheme === 'string' ? paddingScheme : JSON.stringify(paddingScheme, null, 2);




  } else if (type === 'v2node') {
    form.listen_ip = row.listen_ip || '0.0.0.0';
    const proto = row.protocol || 'vmess';
    form.v2node_protocol = proto;
    // Reuse existing sub-protocol form fields
    if (proto === 'shadowsocks') {
      form.cipher = row.cipher || 'aes-256-gcm';
    } else if (proto === 'vmess') {
      form.network = row.network || 'tcp';
      form.tls = row.tls || 0;
      form.vmess_security = row.network_settings?.security || 'none';
      const tls = row.tls_settings || {};
      form.tls_settings = { server_name: tls.server_name || '', cert_mode: tls.cert_mode || 'self', provider: tls.provider || '', dns_env: tls.dns_env || '', cert_file: tls.cert_file || '', key_file: tls.key_file || '', dest: tls.dest || '', server_port: tls.server_port || '443', xver: tls.xver || 0, private_key: tls.private_key || '', public_key: tls.public_key || '', short_id: tls.short_id || '', fingerprint: tls.fingerprint || 'chrome', reject_unknown_sni: Number(tls.reject_unknown_sni) || 0, allow_insecure: Number(tls.allow_insecure) || 0 };
      form.tls_settings_raw_str = JSON.stringify(tls, null, 2);
      const ns = row.network_settings || [];
      form.network_settings = JSON.parse(JSON.stringify(ns));
      form.network_settings_raw_str = JSON.stringify(ns, null, 2);
    } else if (proto === 'vless') {
      form.tls = row.tls || 0;
      form.network = row.network || 'tcp';
      form.flow = row.flow || null;
      form.encryption = row.encryption || 'none';
      const tls = row.tls_settings || {};
      form.tls_settings = { server_name: tls.server_name || '', cert_mode: tls.cert_mode || 'self', provider: tls.provider || '', dns_env: tls.dns_env || '', cert_file: tls.cert_file || '', key_file: tls.key_file || '', dest: tls.dest || '', server_port: tls.server_port || '443', xver: tls.xver || 0, private_key: tls.private_key || '', public_key: tls.public_key || '', short_id: tls.short_id || '', fingerprint: tls.fingerprint || 'chrome', reject_unknown_sni: Number(tls.reject_unknown_sni) || 0, allow_insecure: Number(tls.allow_insecure) || 0 };
      form.tls_settings_raw_str = JSON.stringify(tls, null, 2);
      const ns = row.network_settings || [];
      form.network_settings = JSON.parse(JSON.stringify(ns));
      form.network_settings_raw_str = JSON.stringify(ns, null, 2);
    } else if (proto === 'trojan') {
      form.server_name = row.server_name || '';
      form.allow_insecure = row.allow_insecure || 0;
    } else if (proto === 'hysteria2') {
      form.up_mbps = row.up_mbps || 0;
      form.down_mbps = row.down_mbps || 0;
      form.obfs = row.obfs || '';
      form.obfs_password = row.obfs_password || '';
      const tls = row.tls_settings || {};
      form.server_name = tls.server_name || '';
      form.insecure = tls.insecure || 0;
    } else if (proto === 'tuic') {
      const tls = row.tls_settings || {};
      form.server_name = tls.server_name || '';
      form.insecure = tls.insecure || 0;
      form.disable_sni = row.disable_sni || 0;
      form.udp_relay_mode = row.udp_relay_mode || 'native';
      form.zero_rtt_handshake = row.zero_rtt_handshake || 0;
      form.congestion_control = row.congestion_control || 'bbr';
    } else if (proto === 'anytls') {
      const tls = row.tls_settings || {};
      form.server_name = tls.server_name || '';
      form.allow_insecure = tls.insecure || 0;
      const paddingScheme = row.padding_scheme || [];
      form.anytls_custom_str = typeof paddingScheme === 'string' ? paddingScheme : JSON.stringify(paddingScheme, null, 2);
    } else if (proto === 'mieru') {
      const tls = row.tls_settings || {};
      form.tls_settings = {
        port_range: tls.port_range || '',
        transport: tls.transport || 'TCP',
        server_name: '', cert_mode: 'self', provider: '', dns_env: '', cert_file: '', key_file: '', dest: '', server_port: '443', xver: 0, private_key: '', public_key: '', short_id: '', fingerprint: 'chrome', reject_unknown_sni: 0, allow_insecure: 0
      };
    }
  }

  dialogVisible.value = true;
};

const parseJSON = (str, fieldName) => {
  try {
    return JSON.parse(str || '{}');
  } catch (e) {
    throw new Error(`${fieldName} 的 JSON 格式不正确`);
  }
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      
      const payload = {
        name: form.name,
        rate: form.rate,
        group_id: form.group_id.map(id => String(id)),
        host: form.host,
        port: form.port,
        server_port: form.server_port,
        parent_id: form.parent_id || null,
        route_id: form.route_id || null,
        tags: form.tags || null,
        show: form.show,
      };

      if (isEdit.value) {
        payload.id = form.id;
      }
      
      if (activeType.value === 'shadowsocks') {
        payload.cipher = form.cipher;
        payload.obfs = form.obfs;
        if (form.obfs === 'http') {
          payload.obfs_settings = {
            host: form.obfs_settings_host,
            path: form.obfs_settings_path
          };
        }
      } else if (activeType.value === 'vmess' || activeType.value === 'vless') {
        // Compile TLS Settings
        let finalTlsSettings = {};
        if (form.tls > 0) {
          if (form.edit_tls_raw) {
            finalTlsSettings = parseJSON(form.tls_settings_raw_str, '安全性配置');
          } else {
            finalTlsSettings = buildTlsSettings();
          }
        }

        // Compile Network Settings
        let finalNetworkSettings = {};
        if (form.edit_network_raw) {
          finalNetworkSettings = parseJSON(form.network_settings_raw_str, '传输配置');
        } else {
          finalNetworkSettings = JSON.parse(JSON.stringify(form.network_settings));
        }
        finalNetworkSettings = normalizeNetworkSettings(form.network, finalNetworkSettings, activeType.value === 'vmess' ? {} : []);

        if (activeType.value === 'vmess') {
          payload.network = form.network;
          payload.tls = form.tls;
          finalNetworkSettings.security = form.vmess_security;
          
          payload.tlsSettings = form.tls > 0 ? finalTlsSettings : null;
          payload.networkSettings = finalNetworkSettings;
          payload.dnsSettings = parseJSON(form.dnsSettings_str, 'DNS 配置');
          payload.ruleSettings = parseJSON(form.ruleSettings_str, '规则配置');
        } else if (activeType.value === 'vless') {
          payload.tls = form.tls;
          payload.network = form.network;
          payload.flow = form.network === 'tcp' ? form.flow : null;
          payload.encryption = form.encryption;
          
          // Compile Encryption Settings (Vless only)
          let finalEncryptionSettings = {};
          if (form.encryption !== 'none') {
            if (form.edit_encryption_raw) {
              finalEncryptionSettings = parseJSON(form.encryption_settings_raw_str, '加密配置');
            } else {
              finalEncryptionSettings = {
                mode: form.encryption_settings.mode,
                rtt: form.encryption_settings.rtt,
                server_padding: form.encryption_settings.server_padding || null,
                client_padding: form.encryption_settings.client_padding || null,
                private_key: form.encryption_settings.private_key || null,
                password: form.encryption_settings.password || null
              };
              if (form.encryption_settings.rtt === '0rtt') {
                finalEncryptionSettings.ticket = form.encryption_settings.ticket;
              }
            }
          }

          payload.tls_settings = form.tls > 0 ? finalTlsSettings : null;
          payload.network_settings = finalNetworkSettings;
          payload.encryption_settings = form.encryption !== 'none' ? finalEncryptionSettings : [];
        }
      } else if (activeType.value === 'trojan') {
        payload.network = form.network || 'tcp';
        payload.network_settings = normalizeNetworkSettings(payload.network, form.edit_network_raw ? parseJSON(form.network_settings_raw_str, '传输配置') : form.network_settings, []);
        payload.server_name = form.server_name;
        payload.allow_insecure = form.allow_insecure;
      } else if (activeType.value === 'hysteria') {
        payload.version = form.version;
        payload.up_mbps = form.up_mbps;
        payload.down_mbps = form.down_mbps;
        payload.server_name = form.server_name;
        payload.insecure = form.insecure;
        if (form.obfs) {
          payload.obfs = form.obfs;
          if (form.obfs_password) {
            payload.obfs_password = form.obfs_password;
          }
        }
      } else if (activeType.value === 'tuic') {
        payload.server_name = form.server_name;
        payload.insecure = form.insecure;
        payload.disable_sni = form.disable_sni;
        payload.udp_relay_mode = form.udp_relay_mode;
        payload.zero_rtt_handshake = form.zero_rtt_handshake;
        payload.congestion_control = form.congestion_control;
      } else if (activeType.value === 'anytls') {
        payload.server_name = form.server_name;
        payload.insecure = form.insecure;
        try {
          JSON.parse(form.anytls_custom_str);
        } catch (e) {
          throw new Error('填充方案 JSON 格式不正确');
        }
        payload.padding_scheme = form.anytls_custom_str;

      } else if (activeType.value === 'v2node') {
        payload.listen_ip = form.listen_ip || '0.0.0.0';
        payload.protocol = form.v2node_protocol;
        // Defaults to satisfy V2nodeController validation
        payload.tls = 0;
        payload.network = 'tcp';
        payload.disable_sni = 0;
        payload.zero_rtt_handshake = 0;
        const proto = form.v2node_protocol;
        if (proto === 'shadowsocks') {
          payload.cipher = form.cipher;
        } else if (proto === 'vmess') {
          payload.network = form.network;
          payload.tls = form.tls;
          const ns = form.edit_network_raw ? parseJSON(form.network_settings_raw_str, '传输配置') : JSON.parse(JSON.stringify(form.network_settings));
          const normalizedNs = normalizeNetworkSettings(form.network, ns, []);
          normalizedNs.security = form.vmess_security;
          payload.network_settings = normalizedNs;
          if (form.tls > 0) {
            payload.tls_settings = form.edit_tls_raw ? parseJSON(form.tls_settings_raw_str, '安全性配置') : buildTlsSettings();
          }
        } else if (proto === 'vless') {
          payload.tls = form.tls;
          payload.network = form.network;
          payload.flow = form.network === 'tcp' ? form.flow : null;
          payload.encryption = form.encryption;
          payload.network_settings = normalizeNetworkSettings(
            form.network,
            form.edit_network_raw ? parseJSON(form.network_settings_raw_str, '传输配置') : form.network_settings,
            []
          );
          if (form.tls > 0) {
            payload.tls_settings = form.edit_tls_raw ? parseJSON(form.tls_settings_raw_str, '安全性配置') : buildTlsSettings();
          }
        } else if (proto === 'trojan') {
          payload.tls = 1;
          payload.network = 'tcp';
          payload.network_settings = [];
          payload.server_name = form.server_name;
          payload.allow_insecure = form.allow_insecure;
        } else if (proto === 'hysteria2') {
          payload.tls = 1;
          payload.up_mbps = form.up_mbps;
          payload.down_mbps = form.down_mbps;
          payload.tls_settings = { server_name: form.server_name, insecure: form.insecure };
          if (form.obfs) {
            payload.obfs = form.obfs;
            if (form.obfs_password) payload.obfs_password = form.obfs_password;
          }
        } else if (proto === 'tuic') {
          payload.tls = 1;
          payload.disable_sni = form.disable_sni;
          payload.udp_relay_mode = form.udp_relay_mode;
          payload.zero_rtt_handshake = form.zero_rtt_handshake;
          payload.congestion_control = form.congestion_control;
          payload.tls_settings = { server_name: form.server_name, insecure: form.insecure };
        } else if (proto === 'anytls') {
          payload.tls = 1;
          payload.tls_settings = { server_name: form.server_name, insecure: form.allow_insecure };
          try {
            JSON.parse(form.anytls_custom_str);
          } catch (e) {
            throw new Error('填充方案 JSON 格式不正确');
          }
          payload.padding_scheme = form.anytls_custom_str;
        } else if (proto === 'mieru') {
          payload.tls = 0;
          payload.tls_settings = {
            port_range: form.tls_settings.port_range,
            transport: form.tls_settings.transport || 'TCP'
          };
        }
      }

      await api.post(`/${securePath}/server/${activeType.value}/save`, payload);
      ElMessage.success(isEdit.value ? '保存节点成功' : '创建节点成功');
      dialogVisible.value = false;
      fetchNodes();
    } catch (err) {
      ElMessage.error(err.message || '保存失败');
      console.error(err);
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleCopy = async (row, type) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/server/${type}/copy`, { id: row.id });
    ElMessage.success('复制节点成功');
    fetchNodes();
  } catch (err) {
    console.error(err);
  }
};

const handleDelete = (row, type) => {
  ElMessageBox.confirm('确定要永久删除该节点吗？此操作无法撤销！', '警告', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/server/${type}/drop`, { id: row.id });
    ElMessage.success('节点删除成功');
    fetchNodes();
  }).catch(() => {});
};

const formatTimeAgo = (timestamp) => {
  if (!timestamp) return '从未连接';
  const now = Math.floor(Date.now() / 1000);
  let diff = now - Number(timestamp);
  if (diff < 0) diff = 0;
  
  if (diff < 60) {
    return `${diff} 秒前`;
  } else if (diff < 3600) {
    return `${Math.floor(diff / 60)} 分钟前`;
  } else if (diff < 86400) {
    return `${Math.floor(diff / 3600)} 小时前`;
  } else {
    return `${Math.floor(diff / 86400)} 天前`;
  }
};

const getNodeStatusClass = (row) => {
  const status = row.available_status !== undefined ? Number(row.available_status) : 0;
  if (status === 2) return 'online'; // 正常在线 (绿色/蓝色)
  if (status === 1) return 'warning'; // 警告（无人/无流量上报）
  return 'offline'; // 离线
};

const getNodeStatusTitle = (row) => {
  const status = row.available_status !== undefined ? Number(row.available_status) : 0;
  const timeStr = row.last_check_at ? formatTimeAgo(row.last_check_at) : '从未连接';
  
  if (status === 2) return `在线 (最后上报: ${timeStr})`;
  if (status === 1) return `在线但无流量 (最后上报: ${timeStr})`;
  return `离线 (最后上报: ${timeStr})`;
};

onMounted(() => {
  checkMobile();
  window.addEventListener('resize', checkMobile);
  fetchGroups();
  fetchRoutes();
  fetchNodes();
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', checkMobile);
});
</script>

<style scoped>
.action-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.action-text {
  font-size: 15px;
  font-weight: 600;
}

.table-card {
  border-radius: 16px;
  border: 1px solid var(--el-border-color-light);
}

.node-tabs :deep(.el-tabs__item) {
  font-weight: 600;
  font-size: 14px;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--el-text-color-primary);
  margin-bottom: 15px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
}

.sub-section-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--el-text-color-regular);
}

.advanced-json-tabs {
  border-radius: 8px;
  overflow: hidden;
}

.code-textarea :deep(.el-textarea__inner) {
  font-family: 'Courier New', Courier, monospace;
  font-size: 13px;
  background-color: var(--el-fill-color-blank);
  color: var(--el-text-color-primary);
}

.mr-5 {
  margin-right: 5px;
}

.mt-20 {
  margin-top: 20px;
}

.mt-15 {
  margin-top: 15px;
}

.mb-10 {
  margin-bottom: 10px;
}

.mb-15 {
  margin-bottom: 15px;
}

.gap-10 {
  gap: 10px;
}

.font-13 {
  font-size: 13px;
}

.text-muted {
  color: var(--el-text-color-secondary);
}

.p-10 {
  padding: 10px;
}

.drag-handle {
  cursor: grab;
  color: var(--el-text-color-secondary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  border-radius: 4px;
  transition: all 0.2s ease;
}
.drag-handle:active {
  cursor: grabbing;
  color: var(--el-color-primary);
  background-color: var(--el-fill-color-light);
}
.drag-handle:hover {
  color: var(--el-color-primary);
  background-color: var(--el-fill-color-lighter);
}

/* Mobile Node Card Styles */
.mobile-node-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.mobile-node-card {
  background-color: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 12px;
  padding: 14px 16px;
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
}

.mobile-node-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
  border-color: var(--el-color-primary-light-7);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px dashed var(--el-border-color-lighter);
  padding-bottom: 10px;
  margin-bottom: 10px;
}

.node-id-name {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 80%;
}

.node-id {
  font-family: monospace;
  font-weight: bold;
  color: var(--el-text-color-secondary);
  background-color: var(--el-fill-color-light);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
  flex-shrink: 0;
}

.node-name {
  font-weight: 600;
  font-size: 14px;
  color: var(--el-text-color-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.card-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.body-row {
  display: flex;
  align-items: center;
}

.badges-group {
  flex-wrap: wrap;
}

.online-text {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.node-groups {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.node-address {
  font-size: 13px;
  color: var(--el-text-color-regular);
  background-color: var(--el-fill-color-blank);
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid var(--el-border-color-extra-light);
  display: flex;
  align-items: center;
}

.node-address code {
  word-break: break-all;
  white-space: pre-wrap;
  font-family: monospace;
}

.card-actions {
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid var(--el-border-color-extra-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.drag-handle-mobile {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  display: flex;
  align-items: center;
}

.sort-text {
  font-size: 12px;
}

.empty-placeholder {
  padding: 40px 0;
  text-align: center;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
  flex-shrink: 0;
}

.status-dot.online {
  background-color: var(--el-color-success);
  box-shadow: 0 0 6px var(--el-color-success);
}

.status-dot.warning {
  background-color: var(--el-color-warning);
  box-shadow: 0 0 6px var(--el-color-warning);
}

.status-dot.offline {
  background-color: var(--el-color-danger);
  box-shadow: 0 0 6px var(--el-color-danger);
}

.node-id-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 3px 8px;
  background-color: var(--el-color-success-light-8);
  color: var(--el-color-success);
  border: 1px solid var(--el-color-success-light-5);
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  font-family: var(--el-font-family-mono, monospace);
  line-height: 1;
}

.node-id-badge.child {
  background-color: var(--el-color-success);
  color: #fff;
  border-color: var(--el-color-success);
}
/* Color theme mappings for node protocols */
.node-id-badge.v2node {
  background-color: #ffeef0;
  color: #ff0000;
  border-color: #ffd1d6;
}
.node-id-badge.v2node.child {
  background-color: #ff0000;
  color: #fff;
  border-color: #ff0000;
}

.node-id-badge.shadowsocks {
  background-color: #e8f7ed;
  color: #2e7d32;
  border-color: #c8e6c9;
}
.node-id-badge.shadowsocks.child {
  background-color: #2e7d32;
  color: #fff;
  border-color: #2e7d32;
}

.node-id-badge.vmess {
  background-color: #fbe4ec;
  color: #c2185b;
  border-color: #f8bbd0;
}
.node-id-badge.vmess.child {
  background-color: #c2185b;
  color: #fff;
  border-color: #c2185b;
}

.node-id-badge.trojan {
  background-color: #fef7e0;
  color: #b78103;
  border-color: #ffe0b2;
}
.node-id-badge.trojan.child {
  background-color: #b78103;
  color: #fff;
  border-color: #b78103;
}

.node-id-badge.hysteria {
  background-color: #e0e0e0;
  color: #212121;
  border-color: #bdbdbd;
}
.node-id-badge.hysteria.child {
  background-color: #212121;
  color: #fff;
  border-color: #212121;
}

.node-id-badge.tuic {
  background-color: #f3e5f5;
  color: #7b1fa2;
  border-color: #e1bee7;
}
.node-id-badge.tuic.child {
  background-color: #7b1fa2;
  color: #fff;
  border-color: #7b1fa2;
}

.node-id-badge.vless {
  background-color: #e3f2fd;
  color: #1565c0;
  border-color: #bbdefb;
}
.node-id-badge.vless.child {
  background-color: #1565c0;
  color: #fff;
  border-color: #1565c0;
}

.node-id-badge.anytls {
  background-color: #fff3e0;
  color: #ef6c00;
  border-color: #ffe0b2;
}
.node-id-badge.anytls.child {
  background-color: #ef6c00;
  color: #fff;
  border-color: #ef6c00;
}
</style>
