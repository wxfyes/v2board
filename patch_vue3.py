import re

with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove from dropdown
dropdown_target = """<el-dropdown-item v-if="scope.row.type === 'dynamic_score'" command="clear_score" icon="Refresh">清空积分</el-dropdown-item>"""
content = content.replace(dropdown_target, '')

# 2. Add next to 封禁 button
btn_target = """            <el-button
              type="danger"
              size="small"
              plain
              :disabled="scope.row.banned === 1"
              @click="handleBanUser(scope.row)"
            >
              {{ scope.row.banned === 1 ? '已封禁' : '封禁' }}
            </el-button>"""

btn_replacement = """            <el-button
              type="danger"
              size="small"
              plain
              :disabled="scope.row.banned === 1"
              @click="handleBanUser(scope.row)"
            >
              {{ scope.row.banned === 1 ? '已封禁' : '封禁' }}
            </el-button>
            <el-button
              v-if="scope.row.type === 'dynamic_score'"
              type="primary"
              size="small"
              plain
              @click="handleAnomalyAction('clear_score', scope.row)"
            >
              清空积分
            </el-button>"""

content = content.replace(btn_target, btn_replacement)

with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'w', encoding='utf-8') as f:
    f.write(content)
print("Done")
