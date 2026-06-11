<template>
  <div class="themes-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between">
        <span class="action-text">主题配置</span>
      </div>
    </el-card>

    <div class="themes-grid mt-20" v-loading="loading">
      <el-row :gutter="20">
        <el-col v-for="(theme, name) in themeList" :key="name" :span="8" :xs="24" :sm="12" :md="8">
          <el-card :class="['theme-card', { active: activeTheme === name }]" shadow="hover" :body-style="{ padding: '0px' }">
            <!-- Theme Preview Banner -->
            <div class="theme-banner flex-center">
              <el-icon class="banner-icon" :size="48"><Brush /></el-icon>
              <div v-if="activeTheme === name" class="active-badge">使用中</div>
            </div>

            <div class="theme-info-box">
              <div class="flex-between align-center">
                <span class="theme-name">{{ theme.name || name }}</span>
                <span class="theme-version">v{{ theme.version || '1.0.0' }}</span>
              </div>
              <p class="theme-desc text-muted">{{ theme.description || '暂无描述' }}</p>
              
              <div class="theme-meta mt-10">
                <span class="meta-item">作者: {{ theme.author || '系统' }}</span>
              </div>

              <div class="theme-actions flex-between mt-15">
                <el-button 
                  :type="activeTheme === name ? 'info' : 'primary'"
                  :disabled="activeTheme === name"
                  :loading="activateLoading === name"
                  size="small"
                  @click="handleActivateTheme(name)"
                >
                  {{ activeTheme === name ? '当前主题' : '启用主题' }}
                </el-button>
                
                <el-button 
                  type="primary" 
                  plain 
                  size="small" 
                  icon="Setting"
                  @click="handleConfigureTheme(name, theme)"
                >
                  配置参数
                </el-button>
              </div>
            </div>
          </el-card>
        </el-col>
      </el-row>
    </div>

    <!-- Configure Dialog -->
    <el-dialog v-model="dialogVisible" :title="'配置主题 - ' + selectedThemeName" :width="isMobile ? '95%' : '600px'">
      <el-scrollbar max-height="65vh">
        <el-form :model="themeConfigForm" ref="formRef" label-position="top" class="p-10">
          <div v-if="!selectedThemeSchema || selectedThemeSchema.length === 0" class="empty-tip">
            该主题没有可配置的参数
          </div>
          <template v-else>
            <el-form-item 
              v-for="field in selectedThemeSchema" 
              :key="field.field_name" 
              :label="field.label"
              :prop="field.field_name"
            >
              <!-- Input -->
              <el-input 
                v-if="field.type === 'input'" 
                v-model="themeConfigForm[field.field_name]" 
                :placeholder="field.placeholder" 
              />
              
              <!-- Textarea -->
              <el-input 
                v-else-if="field.type === 'textarea'" 
                type="textarea" 
                v-model="themeConfigForm[field.field_name]" 
                :placeholder="field.placeholder" 
                :rows="3" 
              />
              
              <!-- Select -->
              <el-select 
                v-else-if="field.type === 'select'" 
                v-model="themeConfigForm[field.field_name]" 
                style="width: 100%"
              >
                <el-option 
                  v-for="(label, value) in field.select_options" 
                  :key="value" 
                  :label="label" 
                  :value="value" 
                />
              </el-select>

              <!-- Switch -->
              <el-switch 
                v-else-if="field.type === 'switch'" 
                v-model="themeConfigForm[field.field_name]" 
                :active-value="1"
                :inactive-value="0"
              />

              <div class="form-tip" v-if="field.placeholder && field.type !== 'input' && field.type !== 'textarea'">
                {{ field.placeholder }}
              </div>
            </el-form-item>
          </template>
        </el-form>
      </el-scrollbar>
      <template #footer>
        <div class="flex-end gap-10">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="submitLoading" @click="handleSubmitConfig">保存配置</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import api, { getSecurePath } from '../api';
import { ElMessage } from 'element-plus';
import { useMobile } from '../utils/useMobile';

const { isMobile } = useMobile();
const loading = ref(false);
const submitLoading = ref(false);
const activateLoading = ref('');
const dialogVisible = ref(false);

const themeList = ref({});
const activeTheme = ref('default');
const selectedThemeName = ref('');
const selectedThemeSchema = ref([]);
const themeConfigForm = reactive({});

const fetchThemes = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/theme/getThemes`);
    if (res.data) {
      themeList.value = res.data.themes;
      activeTheme.value = res.data.active;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const handleActivateTheme = async (name) => {
  activateLoading.value = name;
  try {
    const securePath = getSecurePath();
    // V2board changes active theme by updating the system configuration frontend_theme
    await api.post(`/${securePath}/config/save`, {
      frontend_theme: name
    });
    activeTheme.value = name;
    ElMessage.success('成功切换并启用该主题');
  } catch (err) {
    console.error(err);
  } finally {
    activateLoading.value = '';
  }
};

const handleConfigureTheme = async (name, themeSchema) => {
  selectedThemeName.value = name;
  selectedThemeSchema.value = themeSchema.configs || [];
  
  // Reset form properties
  Object.keys(themeConfigForm).forEach(k => delete themeConfigForm[k]);
  
  // Pre-populate defaults
  selectedThemeSchema.value.forEach(field => {
    themeConfigForm[field.field_name] = '';
  });

  try {
    const securePath = getSecurePath();
    const res = await api.post(`/${securePath}/theme/getThemeConfig`, { name });
    if (res.data) {
      Object.keys(res.data).forEach(key => {
        themeConfigForm[key] = res.data[key];
      });
    }
  } catch (err) {
    console.error(err);
  }
  
  dialogVisible.value = true;
};

const handleSubmitConfig = async () => {
  submitLoading.value = true;
  try {
    // V2board expectations: saveThemeConfig expects config as a base64 encoded JSON string
    const jsonStr = JSON.stringify(themeConfigForm);
    // Safe UTF-8 Base64 encode
    const base64Str = btoa(encodeURIComponent(jsonStr).replace(/%([0-9A-F]{2})/g, (match, p1) => {
      return String.fromCharCode('0x' + p1);
    }));

    const securePath = getSecurePath();
    await api.post(`/${securePath}/theme/saveThemeConfig`, {
      name: selectedThemeName.value,
      config: base64Str
    });
    
    ElMessage.success('主题配置更新成功，缓存已重置！');
    dialogVisible.value = false;
  } catch (err) {
    console.error(err);
  } finally {
    submitLoading.value = false;
  }
};

onMounted(() => {
  fetchThemes();
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

.theme-card {
  border-radius: 16px;
  overflow: hidden;
  transition: all 0.3s ease;
  border: 1px solid var(--el-border-color-light);
  margin-bottom: 20px;
}

.theme-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
}

.theme-card.active {
  border-color: var(--el-color-primary);
  box-shadow: 0 4px 12px rgba(var(--el-color-primary-rgb), 0.1);
}

.theme-banner {
  height: 140px;
  background: linear-gradient(135deg, var(--el-color-primary-light-7), var(--el-color-primary-light-9));
  color: var(--el-color-primary);
  position: relative;
}

.theme-card.active .theme-banner {
  background: linear-gradient(135deg, var(--el-color-primary), var(--el-color-primary-light-3));
  color: white;
}

.active-badge {
  position: absolute;
  top: 12px;
  right: 12px;
  background-color: var(--el-color-success);
  color: white;
  padding: 4px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
}

.theme-info-box {
  padding: 16px;
}

.theme-name {
  font-weight: 700;
  font-size: 16px;
}

.theme-version {
  font-family: monospace;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.theme-desc {
  font-size: 12px;
  margin-top: 8px;
  height: 36px;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.theme-meta {
  font-size: 11px;
  color: var(--el-text-color-placeholder);
}

.form-tip {
  font-size: 11px;
  color: var(--el-text-color-secondary);
  line-height: 1.4;
  margin-top: 4px;
}

.empty-tip {
  padding: 30px 0;
  text-align: center;
  color: var(--el-text-color-placeholder);
}

.gap-10 {
  gap: 10px;
}

.mt-20 {
  margin-top: 20px;
}

.mt-15 {
  margin-top: 15px;
}

.mt-10 {
  margin-top: 10px;
}

.p-10 {
  padding: 10px;
}
</style>
