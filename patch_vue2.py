import re

with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'r', encoding='utf-8') as f:
    content = f.read()

target = """  } else if (cmd === 'whitelist') {"""
replacement = """  } else if (cmd === 'clear_score') {
    try {
      await ElMessageBox.confirm(`确定要清空该用户 ${row.email} 的动态风控积分吗？`, '提示', {
        type: 'warning',
        confirmButtonText: '清空',
        cancelButtonText: '取消'
      });
      const securePath = getSecurePath();
      await api.post(`/${securePath}/stat/clearRiskScore`, { id: row.user_id });
      ElMessage.success('已清空用户的动态风控积分');
      fetchAnomalies();
    } catch (err) {
      if (err !== 'cancel') console.error(err);
    }
  } else if (cmd === 'whitelist') {"""

if target in content:
    content = content.replace(target, replacement)
    with open('E:\\GitHub\\v2board\\admin-panel\\src\\views\\SecurityAudit.vue', 'w', encoding='utf-8') as f:
        f.write(content)
    print("Done")
else:
    print("Target not found")
