/**
 * V2Board 管理后台 umi.js 客户端类型列注入脚本
 * 用法: node inject_client_type.js
 */

const fs = require('fs');
const path = require('path');

const umiPath = path.join(__dirname, 'public', 'assets', 'admin', 'umi.js');

console.log('📖 开始读取 umi.js 文件...');
let content = fs.readFileSync(umiPath, 'utf8');
console.log(`📦 文件大小: ${(content.length / 1024 / 1024).toFixed(2)} MB`);

// 检查是否已经添加过 client_type
if (content.includes('client_type')) {
    console.log('⚠️ client_type 已存在，跳过修改');
    process.exit(0);
}

// 备份原文件
const backupPath = umiPath + '.backup_clienttype_' + Date.now();
fs.writeFileSync(backupPath, content);
console.log(`💾 已备份到: ${backupPath}`);

let modifiedCount = 0;

// ============================================
// 修改 1: 在用户管理表格中插入"客户端类型"列
// 位置: 在 "客户端登录时间" 列后面
// ============================================

const tableColumnPattern = /(}, \{\s*title: "\\u4f59\\u989d",\s*dataIndex: "balance")/;
if (tableColumnPattern.test(content)) {
    const newColumn = `}, {
                    title: "\\u5ba2\\u6237\\u7aef\\u7c7b\\u578b",
                    dataIndex: "client_type",
                    key: "client_type",
                    render: e=>{
                        return e || "-"
                    }
                $1`;

    content = content.replace(tableColumnPattern, newColumn);
    console.log('✅ [1/2] 已插入"客户端类型"表格列');
    modifiedCount++;
} else {
    console.log('⚠️ [1/2] 未找到"余额"列定义模式');
}

// ============================================
// 修改 2: 在过滤器中插入"客户端类型"选项
// 位置: 在 "客户端登录时间" 过滤器后面
// ============================================

const filterPattern = /(}, \{\s*key: "uuid",\s*title: "UUID")/;
if (filterPattern.test(content)) {
    const newFilter = `}, {
                        key: "client_type",
                        title: "\\u5ba2\\u6237\\u7aef\\u7c7b\\u578b",
                        condition: ["\\u6a21\\u7cca"]
                    $1`;

    content = content.replace(filterPattern, newFilter);
    console.log('✅ [2/2] 已插入"客户端类型"过滤条件');
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
} else {
    console.log('\n❌ 未能完成任何修改');
}
