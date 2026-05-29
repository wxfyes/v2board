<template>
  <div class="servers-container">
    <!-- Action Bar -->
    <el-card class="action-card" shadow="hover">
      <div class="flex-between flex-wrap gap-10">
        <span class="action-text">节点管理</span>
        <div class="flex-center gap-10">
          <el-dropdown trigger="click" @command="handleCreateCommand">
            <el-button type="primary" icon="Plus">
              添加节点<el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="shadowsocks">Shadowsocks 节点</el-dropdown-item>
                <el-dropdown-item command="vmess">Vmess 节点</el-dropdown-item>
                <el-dropdown-item command="trojan">Trojan 节点</el-dropdown-item>
                <el-dropdown-item command="vless">Vless 节点</el-dropdown-item>
                <el-dropdown-item command="hysteria">Hysteria 节点</el-dropdown-item>
                <el-dropdown-item command="tuic">Tuic 节点</el-dropdown-item>
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
          <el-table :data="nodeLists[type] || []" v-loading="loading" stripe style="width: 100%">
            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column prop="name" label="节点名称" min-width="150" />
            
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

            <el-table-column prop="group_id" label="分配组" width="120">
              <template #default="scope">
                <el-tag v-for="g in scope.row.group_id" :key="g" size="small" class="mr-5">
                  组 {{ g }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column prop="show" label="状态" width="100" align="center">
              <template #default="scope">
                <el-switch
                  v-model="scope.row.show"
                  :active-value="1"
                  :inactive-value="0"
                  @change="(val) => handleToggleShow(scope.row, type, val)"
                />
              </template>
            </el-table-column>

            <el-table-column label="操作" width="220" align="right">
              <template #default="scope">
                <el-button type="primary" link @click="openEditDialog(scope.row, type)">编辑</el-button>
                <el-button type="success" link @click="handleCopy(scope.row, type)">复制</el-button>
                <el-button type="danger" link @click="handleDelete(scope.row, type)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- Node Form Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" width="600px">
      <el-form :model="form" :rules="rules" ref="formRef" label-width="110px">
        <el-form-item label="节点名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入节点名称，如 香港 01 [BGP]" />
        </el-form-item>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item label="流量倍率" prop="rate">
              <el-input-number v-model="form.rate" :min="0" :precision="2" :step="0.1" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="分配权限组" prop="group_string">
              <el-input v-model="form.group_string" placeholder="如 1,2" />
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="节点地址" prop="host">
          <el-input v-model="form.host" placeholder="例如 hk1.node.com 或 12.34.56.78" />
        </el-form-item>

        <el-row :gutter="20">
          <el-col :span="12">
            <el-form-item label="连接端口" prop="port">
              <el-input-number v-model="form.port" :min="1" :max="65535" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="服务端口" prop="server_port">
              <el-input-number v-model="form.server_port" :min="1" :max="65535" :controls="false" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>

        <!-- Shadowsocks Options -->
        <template v-if="activeType === 'shadowsocks'">
          <el-form-item label="加密方式" prop="cipher">
            <el-select v-model="form.cipher" style="width: 100%">
              <el-option label="aes-256-gcm" value="aes-256-gcm" />
              <el-option label="aes-128-gcm" value="aes-128-gcm" />
              <el-option label="chacha20-ietf-poly1305" value="chacha20-ietf-poly1305" />
              <el-option label="2022-blake3-aes-128-gcm" value="2022-blake3-aes-128-gcm" />
              <el-option label="2022-blake3-aes-256-gcm" value="2022-blake3-aes-256-gcm" />
            </el-select>
          </el-form-item>
          <el-form-item label="混淆设置">
            <el-select v-model="form.obfs" placeholder="不启用混淆" clearable style="width: 100%">
              <el-option label="HTTP 混淆" value="http" />
            </el-select>
          </el-form-item>
        </template>

        <!-- Vmess/Vless Options -->
        <template v-if="activeType === 'vmess' || activeType === 'vless'">
          <el-row :gutter="20">
            <el-col :span="12">
              <el-form-item label="传输协议" prop="network">
                <el-select v-model="form.network" style="width: 100%">
                  <el-option label="TCP" value="tcp" />
                  <el-option label="WebSocket (WS)" value="ws" />
                  <el-option label="gRPC" value="grpc" />
                  <el-option label="Hysteria" value="kcp" />
                  <el-option label="QUIC" value="quic" />
                </el-select>
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="TLS 加密">
                <el-switch v-model="form.tls" :active-value="1" :inactive-value="0" />
              </el-form-item>
            </el-col>
          </el-row>
        </template>

        <el-form-item label="上架状态">
          <el-radio-group v-model="form.show">
            <el-radio :label="1">启用显示</el-radio>
            <el-radio :label="0">下架隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
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
import { ref, reactive, onMounted } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';

const loading = ref(false);
const submitLoading = ref(false);
const dialogVisible = ref(false);
const isEdit = ref(false);
const dialogTitle = ref('添加节点');

const activeTab = ref('shadowsocks');
const activeType = ref('shadowsocks');

const nodeTypes = {
  shadowsocks: 'Shadowsocks',
  vmess: 'Vmess',
  trojan: 'Trojan',
  vless: 'Vless',
  hysteria: 'Hysteria',
  tuic: 'Tuic',
};

const nodeLists = reactive({
  shadowsocks: [],
  vmess: [],
  trojan: [],
  vless: [],
  hysteria: [],
  tuic: [],
});

const formRef = ref(null);
const form = reactive({
  id: null,
  name: '',
  rate: 1.0,
  group_string: '1',
  host: '',
  port: 443,
  server_port: 443,
  show: 1,
  
  // Shadowsocks specific
  cipher: 'aes-256-gcm',
  obfs: null,
  
  // Vmess / Vless specific
  network: 'tcp',
  tls: 0,
});

const rules = {
  name: [{ required: true, message: '请输入节点名称', trigger: 'blur' }],
  host: [{ required: true, message: '请输入节点地址', trigger: 'blur' }],
  port: [{ required: true, message: '请输入连接端口', trigger: 'blur' }],
  server_port: [{ required: true, message: '请输入服务端口', trigger: 'blur' }],
  group_string: [{ required: true, message: '请分配权限组', trigger: 'blur' }],
};

const fetchNodes = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/server/manage/getNodes`);
    if (res.data) {
      // Clear current lists
      Object.keys(nodeLists).forEach(k => {
        nodeLists[k] = [];
      });
      // Distribute nodes to lists
      res.data.forEach(node => {
        const type = node.type || 'vmess'; // Fallback
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
  activeType.value = name;
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
  
  // Reset form fields
  form.id = null;
  form.name = '';
  form.rate = 1.0;
  form.group_string = '1';
  form.host = '';
  form.port = 10000;
  form.server_port = 10000;
  form.show = 1;
  
  // Shadowsocks defaults
  form.cipher = 'aes-256-gcm';
  form.obfs = null;
  
  // Vmess defaults
  form.network = 'tcp';
  form.tls = 0;
  
  dialogVisible.value = true;
};

const openEditDialog = (row, type) => {
  isEdit.value = true;
  activeType.value = type;
  dialogTitle.value = `编辑 ${nodeTypes[type]} 节点`;
  
  // Map standard values
  form.id = row.id;
  form.name = row.name;
  form.rate = row.rate;
  form.group_string = row.group_id ? row.group_id.join(',') : '1';
  form.host = row.host;
  form.port = row.port;
  form.server_port = row.server_port;
  form.show = row.show;
  
  // Map specific values
  if (type === 'shadowsocks') {
    form.cipher = row.cipher || 'aes-256-gcm';
    form.obfs = row.obfs || null;
  } else if (type === 'vmess' || type === 'vless') {
    form.network = row.network || 'tcp';
    form.tls = row.tls || 0;
  }

  dialogVisible.value = true;
};

const handleSubmit = async () => {
  if (!formRef.value) return;
  await formRef.value.validate(async (valid) => {
    if (!valid) return;
    submitLoading.value = true;
    try {
      const securePath = getSecurePath();
      
      // Parse group string "1,2" into array [1, 2]
      const group_id = form.group_string.split(',').map(s => parseInt(s.trim())).filter(n => !isNaN(n));
      
      const payload = {
        name: form.name,
        rate: form.rate,
        group_id: group_id,
        host: form.host,
        port: form.port,
        server_port: form.server_port,
        show: form.show,
      };

      if (isEdit.value) {
        payload.id = form.id;
      }
      
      // Append specific params
      if (activeType.value === 'shadowsocks') {
        payload.cipher = form.cipher;
        if (form.obfs) {
          payload.obfs = form.obfs;
        }
      } else if (activeType.value === 'vmess' || activeType.value === 'vless') {
        payload.network = form.network;
        payload.tls = form.tls;
      }

      await api.post(`/${securePath}/server/${activeType.value}/save`, payload);
      ElMessage.success(isEdit.value ? '保存节点成功' : '创建节点成功');
      dialogVisible.value = false;
      fetchNodes();
    } catch (err) {
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

onMounted(() => {
  fetchNodes();
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

.form-tip {
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  line-height: 1.4;
  margin-top: 5px;
}

.mr-5 {
  margin-right: 5px;
}

.mt-20 {
  margin-top: 20px;
}

.gap-10 {
  gap: 10px;
}
</style>
