const fs = require('fs');

// Fix SecurityAuditPage.tsx
let f1 = 'src/pages/security-audit/SecurityAuditPage.tsx';
let c1 = fs.readFileSync(f1, 'utf-8');
c1 = c1.replace(/return \(\
\s*<AdminLayout title="安全审计">\
\s*<div style=\{\{ padding: "0 4px" \}\}>/, 'return (\n    <AdminLayout title="安全审计">\n      <div style={{ padding: "0 4px" }}>');
c1 = c1.replace(/<\/div>\
\s*<\/AdminLayout>\
\s*\);\
\}/, '      </div>\n    </AdminLayout>\n  );\n}');
fs.writeFileSync(f1, c1);

// Fix LoginLogsPage.tsx
let f2 = 'src/pages/login-logs/LoginLogsPage.tsx';
let c2 = fs.readFileSync(f2, 'utf-8');
c2 = c2.replace(/return \(\
\s*<AdminLayout title="登录记录">\
\s*<Card title="[^"]+" className="box-card">/, 'return (\n    <AdminLayout title="登录记录">\n      <Card title="📝 用户登录记录" className="box-card">');
c2 = c2.replace(/<\/Card>\
\s*<\/AdminLayout>\
\s*\);\
\}/, '      </Card>\n    </AdminLayout>\n  );\n}');
fs.writeFileSync(f2, c2);

console.log('Fixed both files');
