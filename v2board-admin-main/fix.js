const fs = require('fs');

function fixFile(filePath, title) {
    let content = fs.readFileSync(filePath, 'utf-8');
    
    // Replace top AdminLayout
    const badTopRegex = /return \(\
\s*<AdminLayout title=".*?"\>
\s*(<div[^>]*>|<Card[^>]*>)/;
    if (badTopRegex.test(content)) {
        content = content.replace(badTopRegex, 'return (\n    <AdminLayout title="' + title + '">\n      ');
        console.log('Fixed top in ' + filePath);
    }
    
    // Replace bottom AdminLayout
    const badBottomRegex = /(<\/div>|<\/Card>)\
\s*<\/AdminLayout>\
\s*\);\
\}/;
    if (badBottomRegex.test(content)) {
        content = content.replace(badBottomRegex, '\n    </AdminLayout>\n  );\n}');
        console.log('Fixed bottom in ' + filePath);
    }
    
    fs.writeFileSync(filePath, content);
}

fixFile('src/pages/security-audit/SecurityAuditPage.tsx', '安全审计');
fixFile('src/pages/login-logs/LoginLogsPage.tsx', '登录记录');
