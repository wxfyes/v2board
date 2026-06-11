<template>
  <div class="knowledges-container">
    <el-card class="action-card" shadow="hover">
      <div class="flex-between align-center">
        <span class="action-text">知识库管理</span>
        <div class="flex-center gap-10">
          <el-button type="warning" icon="Sort" :loading="sortLoading" @click="handleSaveSort">
            保存排序
          </el-button>
          <el-button type="primary" icon="Plus" @click="openCreateDialog">添加文章</el-button>
        </div>
      </div>
    </el-card>

    <el-card class="table-card mt-20" shadow="hover">
      <el-table :data="knowledges" v-loading="loading" stripe style="width: 100%" :class="{'mobile-table': isMobile}">
        <el-table-column prop="id" label="ID" :width="isMobile ? '40' : '70'" align="center" />
        <!-- PC Title Column -->
        <el-table-column v-if="!isMobile" prop="title" label="标题" min-width="200" show-overflow-tooltip />
        
        <!-- Mobile Combined Column -->
        <el-table-column v-if="isMobile" label="文章标题" min-width="120">
          <template #default="scope">
            <div style="font-weight: 600; line-height: 1.2;">{{ scope.row.title }}</div>
            <span style="font-size: 10px; color: var(--el-text-color-secondary);">分类: {{ scope.row.category }}</span>
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" prop="category" label="分类" width="120" align="center">
          <template #default="scope">
            <el-tag type="info" size="small">{{ scope.row.category }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="show" label="显示" :width="isMobile ? '65' : '100'" align="center">
          <template #default="scope">
            <el-switch
              v-model="scope.row.show"
              :active-value="1"
              :inactive-value="0"
              @change="handleToggleShow(scope.row)"
              size="small"
            />
          </template>
        </el-table-column>
        <el-table-column v-if="!isMobile" label="最后更新" width="180">
          <template #default="scope">
            <span>{{ formatTime(scope.row.updated_at) }}</span>
          </template>
        </el-table-column>
        
        <!-- Sorting Column -->
        <el-table-column v-if="!isMobile" label="排序调整" width="150" align="center">
          <template #default="scope">
            <el-button-group>
              <el-button 
                type="info" 
                size="small" 
                icon="CaretTop" 
                :disabled="scope.$index === 0" 
                @click="moveRow(scope.$index, -1)"
              />
              <el-button 
                type="info" 
                size="small" 
                icon="CaretBottom" 
                :disabled="scope.$index === knowledges.length - 1" 
                @click="moveRow(scope.$index, 1)"
              />
            </el-button-group>
          </template>
        </el-table-column>

        <el-table-column label="操作" :width="isMobile ? '80' : '180'" :align="isMobile ? 'center' : 'right'">
          <template #default="scope">
            <el-button type="primary" link @click="openEditDialog(scope.row)" :style="isMobile ? 'margin-right: 2px; padding: 0;' : ''">编辑</el-button>
            <span v-if="isMobile" style="color: var(--el-border-color); font-size: 10px;">|</span>
            <el-button type="danger" link @click="handleDelete(scope.row)" :style="isMobile ? 'margin-left: 2px; padding: 0;' : ''">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- Dialog -->
    <el-dialog v-model="dialogVisible" :title="dialogTitle" :width="isMobile ? '95%' : '1050px'" :top="isMobile ? '2vh' : '6vh'">
      <el-form :model="form" :rules="rules" ref="formRef" :label-position="isMobile ? 'top' : 'right'" :label-width="isMobile ? undefined : '80px'">
        <el-form-item label="文章标题" prop="title">
          <el-input v-model="form.title" placeholder="如：如何在 Windows 上配置客户端" />
        </el-form-item>

        <el-row :gutter="20">
          <el-col :xs="24" :sm="12">
            <el-form-item label="文章分类" prop="category">
              <el-select
                v-model="form.category"
                filterable
                allow-create
                default-first-option
                placeholder="选择分类或手动输入"
                style="width: 100%"
              >
                <el-option v-for="cat in categories" :key="cat" :label="cat" :value="cat" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :xs="24" :sm="12">
            <el-form-item label="文章语言" prop="language">
              <el-select v-model="form.language" style="width: 100%">
                <el-option label="简体中文" value="zh-CN" />
                <el-option label="繁体中文" value="zh-TW" />
                <el-option label="English" value="en-US" />
                <el-option label="日本語" value="ja-JP" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>

        <el-form-item label="文章内容" prop="body">
          <div class="split-editor-container" :class="{ 'is-fullscreen': isFullscreen, 'is-mobile': isMobile }">
            <!-- Mobile Toggle Tab -->
            <div v-if="isMobile" class="mobile-tab-bar">
              <el-radio-group v-model="activeTab" size="small" class="mobile-tab-group">
                <el-radio-button label="edit">编辑模式</el-radio-button>
                <el-radio-button label="preview">文章预览</el-radio-button>
              </el-radio-group>
            </div>

            <!-- Toolbar -->
            <div class="editor-toolbar">
              <template v-for="(item, i) in toolbarItems" :key="i">
                <div v-if="item.divider" class="toolbar-divider" />
                <el-tooltip v-else :content="item.label" placement="top" :enterable="false" :disabled="isMobile">
                  <button type="button" class="toolbar-btn" @click="item.action">
                    <span v-if="item.iconHtml" v-html="item.iconHtml"></span>
                    <el-icon v-else><component :is="item.iconName" /></el-icon>
                  </button>
                </el-tooltip>
              </template>
            </div>

            <div class="editor-main-layout">
              <div class="editor-pane" v-show="!isMobile || activeTab === 'edit'">
                <div class="pane-title">编辑 Markdown</div>
                <el-input
                  type="textarea"
                  ref="textareaRef"
                  v-model="form.body"
                  placeholder="支持 Markdown 语法内容..."
                  class="code-textarea"
                />
              </div>
              <div class="preview-pane" v-show="!isMobile || activeTab === 'preview'">
                <div class="pane-title">实时预览</div>
                <div class="markdown-preview-body" v-html="renderedMarkdown"></div>
              </div>
            </div>
          </div>
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
import { ref, reactive, onMounted, onUnmounted, computed, watch, nextTick } from 'vue';
import { getSecurePath } from '../api';
import api from '../api';
import { ElMessage, ElMessageBox } from 'element-plus';
import { marked } from 'marked';

const debouncedBody = ref('');
let debounceTimer = null;

const isMobile = ref(window.innerWidth <= 768);
const activeTab = ref('edit');

const updateMobileStatus = () => {
  isMobile.value = window.innerWidth <= 768;
};

// Watch form.body and update debouncedBody with a 200ms delay to eliminate keyboard input lag
watch(() => form.body, (newVal) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    debouncedBody.value = newVal || '';
  }, 200);
});

const renderedMarkdown = computed(() => {
  if (!debouncedBody.value) return '<div class="preview-placeholder">无内容预览</div>';
  try {
    let html = marked.parse(debouncedBody.value);
    // 渲染 <!--access start--> 和 <!--access end--> 标签
    html = html.replace(/&lt;!--access start--&gt;|<!--access start-->/gi, '<div class="preview-access-block"><div class="access-header"><span class="el-tag el-tag--warning el-tag--small">付费/权限订阅可见内容</span></div><div class="access-content">');
    html = html.replace(/&lt;!--access end--&gt;|<!--access end-->/gi, '</div></div>');
    return html;
  } catch (e) {
    return `<div class="preview-placeholder">解析出错: ${e.message}</div>`;
  }
});

const textareaRef = ref(null);
const isFullscreen = ref(false);

const insertText = (before, after = '') => {
  const textarea = textareaRef.value?.$el.querySelector('textarea') || textareaRef.value;
  if (!textarea) return;

  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const text = form.body || '';
  const selected = text.substring(start, end);
  const replacement = before + selected + after;

  form.body = text.substring(0, start) + replacement + text.substring(end);

  setTimeout(() => {
    textarea.focus();
    textarea.setSelectionRange(start + before.length, start + before.length + selected.length);
  }, 50);
};

const confirmClear = () => {
  ElMessageBox.confirm('确定要清空编辑器内容吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定清空',
    cancelButtonText: '取消'
  }).then(() => {
    form.body = '';
  }).catch(() => {});
};

const showHelp = () => {
  ElMessageBox.alert(
    `<h3>常用 Markdown 语法与快捷方式说明</h3>
    <ul style="padding-left: 20px; line-height: 1.8; margin-top: 10px;">
      <li><b>一级/二级标题</b>: <code># 标题名称</code> 或 <code>## 标题名称</code></li>
      <li><b>加粗文本</b>: <code>**粗体文字**</code></li>
      <li><b>斜体文本</b>: <code>*斜体文字*</code></li>
      <li><b>下划线/删除线</b>: <code>&lt;u&gt;下划线&lt;/u&gt;</code> 或 <code>~~删除线~~</code></li>
      <li><b>添加链接/图片</b>: <code>[链接名称](地址)</code> 或 <code>![图片名称](图片地址)</code></li>
      <li><b>付费可见标签</b> (独有语法):<br>在付费可见内容的开头加上 <code>&lt;!--access start--&gt;</code>，结尾加上 <code>&lt;!--access end--&gt;</code></li>
    </ul>`,
    '编辑器使用指南',
    { dangerouslyUseHTMLString: true }
  );
};

const history = ref([]);
const historyIndex = ref(-1);
let isRestoringHistory = false;

watch(() => form.body, (newVal) => {
  if (isRestoringHistory) return;
  if (history.value[historyIndex.value] === newVal) return;
  
  history.value = history.value.slice(0, historyIndex.value + 1);
  history.value.push(newVal || '');
  historyIndex.value = history.value.length - 1;
});

const initHistory = (val) => {
  history.value = [val || ''];
  historyIndex.value = 0;
  debouncedBody.value = val || '';
};

const triggerUndo = () => {
  if (historyIndex.value > 0) {
    isRestoringHistory = true;
    historyIndex.value--;
    form.body = history.value[historyIndex.value];
    nextTick(() => { isRestoringHistory = false; });
  }
};

const triggerRedo = () => {
  if (historyIndex.value < history.value.length - 1) {
    isRestoringHistory = true;
    historyIndex.value++;
    form.body = history.value[historyIndex.value];
    nextTick(() => { isRestoringHistory = false; });
  }
};

const toggleFullscreen = () => {
  isFullscreen.value = !isFullscreen.value;
};

const handleKeyDown = (e) => {
  if (e.key === 'Escape' && isFullscreen.value) {
    isFullscreen.value = false;
  }
};

const toolbarItems = [
  { label: '标题', action: () => insertText('## '), iconHtml: '<span class="toolbar-text-icon">H</span>' },
  { label: '加粗', action: () => insertText('**', '**'), iconHtml: '<span class="toolbar-text-icon" style="font-weight: bold;">B</span>' },
  { label: '斜体', action: () => insertText('*', '*'), iconHtml: '<span class="toolbar-text-icon" style="font-style: italic;">I</span>' },
  { label: '下划线', action: () => insertText('<u>', '</u>'), iconHtml: '<span class="toolbar-text-icon" style="text-decoration: underline;">U</span>' },
  { label: '删除线', action: () => insertText('~~', '~~'), iconHtml: '<span class="toolbar-text-icon" style="text-decoration: line-through;">S</span>' },
  { divider: true },
  { label: '无序列表', action: () => insertText('- '), iconName: 'List' },
  { label: '有序列表', action: () => insertText('1. '), iconHtml: '<span class="toolbar-text-icon">1.</span>' },
  { label: '引用', action: () => insertText('> '), iconName: 'ChatLineSquare' },
  { divider: true },
  { label: '代码块', action: () => insertText('```\n', '\n```'), iconName: 'Cpu' },
  { label: '表格', action: () => insertText('| Header | Header |\n| ------ | ------ |\n| Content | Content |\n'), iconName: 'Grid' },
  { label: '图片', action: () => insertText('![alt](', ')'), iconName: 'Picture' },
  { label: '链接', action: () => insertText('[text](', ')'), iconName: 'Link' },
  { label: '清空内容', action: () => confirmClear(), iconName: 'Delete' },
  { divider: true },
  { label: '撤销 (Ctrl+Z)', action: () => triggerUndo(), iconName: 'Back' },
  { label: '重做 (Ctrl+Y)', action: () => triggerRedo(), iconName: 'Right' },
  { divider: true },
  { label: '使用帮助', action: () => showHelp(), iconName: 'Notebook' },
  { label: '全屏模式 (Esc退出)', action: () => toggleFullscreen(), iconName: 'FullScreen' }
];

const loading = ref(false);
const sortLoading = ref(false);
const submitLoading = ref(false);
const dialogVisible = ref(false);
const dialogTitle = ref('添加文章');
const isEdit = ref(false);

const knowledges = ref([]);
const categories = ref([]);
const formRef = ref(null);
const form = reactive({
  id: null,
  title: '',
  category: '',
  language: 'zh-CN',
  body: ''
});

const rules = {
  title: [{ required: true, message: '请输入文章标题', trigger: 'blur' }],
  category: [{ required: true, message: '分类不能为空', trigger: 'change' }],
  language: [{ required: true, message: '请选择文章语言', trigger: 'change' }],
  body: [{ required: true, message: '文章内容不能为空', trigger: 'blur' }]
};

const formatTime = (ts) => {
  if (!ts) return '-';
  return new Date(ts * 1000).toLocaleString();
};

const fetchCategories = async () => {
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/knowledge/getCategory`);
    if (res.data) {
      categories.value = res.data;
    }
  } catch (err) {
    console.error(err);
  }
};

const fetchKnowledges = async () => {
  loading.value = true;
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/knowledge/fetch`);
    if (res.data) {
      knowledges.value = res.data;
    }
  } catch (err) {
    console.error(err);
  } finally {
    loading.value = false;
  }
};

const moveRow = (idx, direction) => {
  const targetIdx = idx + direction;
  if (targetIdx < 0 || targetIdx >= knowledges.value.length) return;
  const temp = knowledges.value[idx];
  knowledges.value[idx] = knowledges.value[targetIdx];
  knowledges.value[targetIdx] = temp;
};

const handleSaveSort = async () => {
  sortLoading.value = true;
  try {
    const securePath = getSecurePath();
    const ids = knowledges.value.map(x => x.id);
    await api.post(`/${securePath}/knowledge/sort`, { knowledge_ids: ids });
    ElMessage.success('排序保存成功');
    fetchKnowledges();
  } catch (err) {
    ElMessage.error(err.message || '排序保存失败');
  } finally {
    sortLoading.value = false;
  }
};

const openCreateDialog = () => {
  isEdit.value = false;
  dialogTitle.value = '添加文章';
  form.id = null;
  form.title = '';
  form.category = categories.value.length > 0 ? categories.value[0] : '';
  form.language = 'zh-CN';
  form.body = '';
  initHistory('');
  dialogVisible.value = true;
};

const openEditDialog = async (row) => {
  isEdit.value = true;
  dialogTitle.value = '编辑文章';
  form.id = row.id;
  
  // Fetch detailed body
  try {
    const securePath = getSecurePath();
    const res = await api.get(`/${securePath}/knowledge/fetch`, { params: { id: row.id } });
    if (res.data) {
      form.title = res.data.title;
      form.category = res.data.category;
      form.language = res.data.language || 'zh-CN';
      form.body = res.data.body || '';
      initHistory(res.data.body || '');
      dialogVisible.value = true;
    }
  } catch (err) {
    ElMessage.error('获取文章详情失败');
  }
};

const handleToggleShow = async (row) => {
  try {
    const securePath = getSecurePath();
    await api.post(`/${securePath}/knowledge/show`, { id: row.id });
    ElMessage.success('启用状态更新成功');
  } catch (err) {
    console.error(err);
    row.show = row.show ? 0 : 1;
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
        title: form.title,
        category: form.category,
        language: form.language,
        body: form.body
      };
      if (isEdit.value) {
        payload.id = form.id;
      }
      await api.post(`/${securePath}/knowledge/save`, payload);
      ElMessage.success(isEdit.value ? '编辑文章成功' : '添加文章成功');
      dialogVisible.value = false;
      fetchKnowledges();
      fetchCategories();
    } catch (err) {
      ElMessage.error(err.message || '保存失败');
    } finally {
      submitLoading.value = false;
    }
  });
};

const handleDelete = (row) => {
  ElMessageBox.confirm('确定要删除该文章吗？', '提示', {
    type: 'warning',
    confirmButtonText: '确定删除',
    cancelButtonText: '取消'
  }).then(async () => {
    try {
      const securePath = getSecurePath();
      await api.post(`/${securePath}/knowledge/drop`, { id: row.id });
      ElMessage.success('删除文章成功');
      fetchKnowledges();
    } catch (err) {
      ElMessage.error(err.message || '删除失败');
    }
  }).catch(() => {});
};

onMounted(() => {
  fetchCategories();
  fetchKnowledges();
  window.addEventListener('keydown', handleKeyDown);
  window.addEventListener('resize', updateMobileStatus);
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
  window.removeEventListener('resize', updateMobileStatus);
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
.mt-20 {
  margin-top: 20px;
}
.gap-10 {
  gap: 10px;
}
.code-textarea :deep(.el-textarea__inner) {
  font-family: 'Courier New', Courier, monospace;
  font-size: 13px;
  height: 382px;
  resize: none;
  border: none;
  background-color: transparent;
  padding: 0;
}

.code-textarea :deep(.el-textarea__inner:focus) {
  box-shadow: none;
}

.split-editor-container {
  display: flex;
  flex-direction: column;
  width: 100%;
}

.editor-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  background-color: var(--el-fill-color-light);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px 8px 0 0;
  padding: 6px 10px;
  width: 100%;
  box-sizing: border-box;
}

.toolbar-btn {
  background: transparent;
  border: none;
  border-radius: 4px;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--el-text-color-regular);
  transition: all 0.2s;
  padding: 0;
}

.toolbar-btn:hover {
  background-color: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.toolbar-divider {
  width: 1px;
  height: 16px;
  background-color: var(--el-border-color-light);
  margin: 0 6px;
}

.toolbar-text-icon {
  font-size: 13px;
  font-weight: bold;
}

.editor-main-layout {
  display: flex;
  gap: 20px;
  width: 100%;
  border: 1px solid var(--el-border-color-light);
  border-top: none;
  border-radius: 0 0 8px 8px;
  padding: 15px;
  box-sizing: border-box;
  background-color: var(--el-bg-color-overlay);
}

.editor-pane, .preview-pane {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.editor-pane {
  border-right: 1px solid var(--el-border-color-extra-light);
  padding-right: 20px;
}

.pane-title {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-bottom: 8px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.markdown-preview-body {
  border: none;
  padding: 0;
  height: 382px;
  overflow-y: auto;
  background-color: transparent;
  box-sizing: border-box;
}

/* Fullscreen editor styling */
.split-editor-container.is-fullscreen {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  z-index: 9999;
  background-color: var(--el-bg-color);
  padding: 20px;
  box-sizing: border-box;
}

.split-editor-container.is-fullscreen .editor-toolbar {
  border-radius: 8px 8px 0 0;
}

.split-editor-container.is-fullscreen .editor-main-layout {
  flex: 1;
  height: calc(100vh - 100px);
  border-radius: 0 0 8px 8px;
}

.split-editor-container.is-fullscreen .code-textarea :deep(.el-textarea__inner) {
  height: calc(100vh - 170px) !important;
}

.split-editor-container.is-fullscreen .markdown-preview-body {
  height: calc(100vh - 170px) !important;
}

/* Style markdown contents */
.markdown-preview-body :deep(h1),
.markdown-preview-body :deep(h2),
.markdown-preview-body :deep(h3) {
  margin-top: 0;
  margin-bottom: 12px;
  color: var(--el-text-color-primary);
  font-weight: 600;
}

.markdown-preview-body :deep(h1) { font-size: 1.4em; }
.markdown-preview-body :deep(h2) { font-size: 1.2em; }
.markdown-preview-body :deep(h3) { font-size: 1.1em; }

.markdown-preview-body :deep(p) {
  margin-top: 0;
  margin-bottom: 12px;
  line-height: 1.6;
  color: var(--el-text-color-regular);
}

.markdown-preview-body :deep(a) {
  color: var(--el-color-primary);
  text-decoration: none;
}
.markdown-preview-body :deep(a:hover) {
  text-decoration: underline;
}

.markdown-preview-body :deep(img) {
  max-width: 100%;
  border-radius: 6px;
}

.markdown-preview-body :deep(code) {
  font-family: monospace;
  background-color: var(--el-fill-color-light);
  padding: 2px 4px;
  border-radius: 4px;
  font-size: 0.9em;
}

.markdown-preview-body :deep(pre) {
  background-color: var(--el-fill-color-light);
  padding: 10px;
  border-radius: 6px;
  overflow-x: auto;
}

.markdown-preview-body :deep(pre code) {
  background-color: transparent;
  padding: 0;
}

.preview-placeholder {
  color: var(--el-text-color-placeholder);
  text-align: center;
  margin-top: 80px;
  font-size: 14px;
}

/* Access / permission box styling */
.markdown-preview-body :deep(.preview-access-block) {
  border: 1px dashed var(--el-color-warning-light-3);
  background-color: var(--el-color-warning-light-9);
  border-radius: 8px;
  margin: 15px 0;
  padding: 12px;
  position: relative;
}

.markdown-preview-body :deep(.access-header) {
  margin-bottom: 8px;
  font-weight: bold;
}

.markdown-preview-body :deep(.access-content) {
  font-size: 13px;
  color: var(--el-text-color-regular);
}
/* Mobile styling updates */
.mobile-tab-bar {
  display: flex;
  justify-content: center;
  margin-bottom: 10px;
  width: 100%;
}

.mobile-tab-group {
  width: 100%;
  display: flex;
}

.mobile-tab-group :deep(.el-radio-button) {
  flex: 1;
}

.mobile-tab-group :deep(.el-radio-button__inner) {
  width: 100%;
  text-align: center;
}

@media (max-width: 768px) {
  /* Make the dialog width look generous and fit mobile screen nicely */
  :deep(.el-dialog) {
    width: 95% !important;
    max-width: 100vw !important;
    margin: 10px auto !important;
    top: 2vh !important;
  }
  
  :deep(.el-dialog__body) {
    padding: 10px 15px !important;
  }

  .editor-toolbar {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding: 8px;
    gap: 8px;
    border-radius: 8px 8px 0 0;
  }
  
  .editor-toolbar::-webkit-scrollbar {
    display: none;
  }
  
  .toolbar-btn {
    flex-shrink: 0;
  }

  .editor-main-layout {
    flex-direction: column;
    padding: 10px;
    border-radius: 0 0 8px 8px;
  }

  .editor-pane {
    border-right: none;
    padding-right: 0;
  }

  .code-textarea :deep(.el-textarea__inner) {
    height: 280px !important;
  }

  .markdown-preview-body {
    height: 280px !important;
  }
  
  .split-editor-container.is-fullscreen .editor-main-layout {
    height: calc(100vh - 150px) !important;
  }
  
  .split-editor-container.is-fullscreen .code-textarea :deep(.el-textarea__inner),
  .split-editor-container.is-fullscreen .markdown-preview-body {
    height: calc(100vh - 210px) !important;
  }
}
:deep(.mobile-table) {
  font-size: 12px;
}
:deep(.mobile-table .el-table__cell) {
  padding: 6px 0 !important;
}
:deep(.mobile-table .cell) {
  padding-left: 4px !important;
  padding-right: 4px !important;
}
</style>
