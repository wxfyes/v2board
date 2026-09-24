import re

with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Pagination HTML
table_end = r"      </el-table>"
pagination_html = """      </el-table>
      <div style="margin-top: 15px; display: flex; justify-content: flex-end;">
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="filteredAnomaliesList.length"
        />
      </div>"""
content = content.replace(table_end, pagination_html, 1)

# 2. Add watch import
content = content.replace("import { ref, reactive, onMounted, computed } from 'vue';", "import { ref, reactive, onMounted, computed, watch } from 'vue';")

# 3. Add state and pagination computed
state_target = "const anomaliesLoading = ref(false);"
state_replacement = """const anomaliesLoading = ref(false);
const currentPage = ref(1);
const pageSize = ref(20);

const paginatedAnomaliesList = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value;
  return filteredAnomaliesList.value.slice(start, start + pageSize.value);
});"""
content = content.replace(state_target, state_replacement)

# 4. Bind paginated data
content = content.replace('<el-table :data="filteredAnomaliesList"', '<el-table :data="paginatedAnomaliesList"')

# 5. Add Clear Score Button
button_target = """              <el-button
                v-if="scope.row.type === 'suspected'"
                type="info"
                link
                size="small"
                @click="handleIgnoreAnomaly(scope.row)"
              >
                忽略
              </el-button>"""
clear_score_btn = """              <el-button
                v-if="scope.row.type === 'suspected'"
                type="info"
                link
                size="small"
                @click="handleIgnoreAnomaly(scope.row)"
              >
                忽略
              </el-button>
              <el-button
                v-if="scope.row.type === 'dynamic_score'"
                type="primary"
                plain
                size="small"
                @click="handleClearScore(scope.row)"
              >
                清空积分
              </el-button>"""
content = content.replace(button_target, clear_score_btn)

# 6. Add handleClearScore method
method_target = "const handleIgnoreAnomaly = async (row) => {"
method_html = """const handleClearScore = async (row) => {
    try {
      await ElMessageBox.confirm(`确定要清空用户 ${row.email} 的动态风控积分吗？`, '提示', {
        type: 'warning'
      });
      const securePath = getSecurePath();
      await api.post(`/${securePath}/stat/clearRiskScore`, { id: row.user_id });
      ElMessage.success('已清空该用户动态积分');
      fetchAnomalies();
    } catch (err) {
      if (err !== 'cancel') console.error(err);
    }
  };

  const handleIgnoreAnomaly = async (row) => {"""
content = content.replace(method_target, method_html)

# 7. Add Watcher
search_target = "const anomaliesFilterType = ref('all');"
search_replacement = "const anomaliesFilterType = ref('all');\n  watch([anomaliesSearch, anomaliesFilterType], () => { currentPage.value = 1; });"
content = content.replace(search_target, search_replacement)

# Remove useless settings and ip association
content = content.replace('<el-button type="warning" plain size="small" icon="Connection" @click="openIpAssociationDialog">IP 关联分析</el-button>', '')
content = content.replace('<el-button type="primary" plain size="small" icon="Setting" @click="openSettingsDialog">审计规则 & 白名单</el-button>', '')

with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'w', encoding='utf-8') as f:
    f.write(content)
