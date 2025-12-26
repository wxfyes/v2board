/**
 * V2Board 管理后台 umi.js 客户端登录时间列注入脚本 v2
 * 用法: node inject_client_login.js
 */

const fs = require('fs');
const path = require('path');

const umiPath = path.join(__dirname, 'public', 'assets', 'admin', 'umi.js');

console.log('📖 开始读取 umi.js 文件...');
let content = fs.readFileSync(umiPath, 'utf8');
console.log(`📦 文件大小: ${(content.length / 1024 / 1024).toFixed(2)} MB`);

// 备份原文件
const backupPath = umiPath + '.backup_' + Date.now();
fs.writeFileSync(backupPath, content);
console.log(`💾 已备份到: ${backupPath}`);

let modifiedCount = 0;

// ============================================
// 修改 1: 在用户管理表格中插入"客户端登录时间"列
// 位置: 在 "到期时间" 列后面，"余额" 列前面 (约 70959 行)
// ============================================

// 精确定位: 找到 "}, {" 之间 title 为 "余额" 的结构
// 目标: 在 "到期时间" 渲染函数的闭合 "}" 之后插入新列

const tableColumnPattern = /(\}, \{\s*title: "\\u4f59\\u989d",\s*dataIndex: "balance")/;
if (tableColumnPattern.test(content)) {
    const newColumn = `}, {
                    title: "\\u5ba2\\u6237\\u7aef\\u767b\\u5f55\\u65f6\\u95f4",
                    dataIndex: "client_login_at",
                    key: "client_login_at",
                    sorter: !0,
                    render: e=>{
                        return e ? w()(1e3 * e).format("YYYY/MM/DD HH:mm") : "-"
                    }
                $1`;

    content = content.replace(tableColumnPattern, newColumn);
    console.log('✅ [1/2] 已插入"客户端登录时间"表格列');
    modifiedCount++;
} else {
    console.log('⚠️ [1/2] 未找到"余额"列定义模式');
}

// ============================================
// 修改 2: 在过滤器中插入"客户端登录时间"选项
// 位置: 在 "到期时间" 过滤器后面，"uuid" 过滤器前面 (约 71096 行)
// ============================================

const filterPattern = /(\}, \{\s*key: "uuid",\s*title: "UUID")/;
if (filterPattern.test(content)) {
    const newFilter = `}, {
                        key: "client_login_at",
                        title: "\\u5ba2\\u6237\\u7aef\\u767b\\u5f55\\u65f6\\u95f4",
                        condition: [">=", ">", "<", "<="],
                        type: "date"
                    $1`;

    content = content.replace(filterPattern, newFilter);
    console.log('✅ [2/2] 已插入"客户端登录时间"过滤条件');
    modifiedCount++;
} else {
    console.log('⚠️ [2/2] 未找到"UUID"过滤器定义模式');
}

// ============================================
// 保存修改
// ============================================
if (modifiedCount > 0) {
    fs.writeFileSync(umiPath, content);
    console.log(`\n🎉 修改完成！共修改了 ${modifiedCount} 处。`);
    console.log('📝 请完成以下步骤：');
    console.log('   1. 将修改后的 umi.js 上传到服务器');
    console.log('   2. 清除 Cloudflare 缓存（如有）');
    console.log('   3. 清除浏览器缓存后刷新管理后台');
} else {
    console.log('\n❌ 未能完成任何修改，可能文件结构与预期不符。');
}
