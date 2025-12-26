/**
 * V2Board 管理后台 umi.js - 客户端历史悬浮提示功能
 * 用法: node inject_client_tooltip.js
 */

const fs = require('fs');
const path = require('path');

const umiPath = path.join(__dirname, 'public', 'assets', 'admin', 'umi.js');

console.log('📖 开始读取 umi.js 文件...');
let content = fs.readFileSync(umiPath, 'utf8');
console.log(`📦 文件大小: ${(content.length / 1024 / 1024).toFixed(2)} MB`);

// 备份原文件
const backupPath = umiPath + '.backup_tooltip_' + Date.now();
fs.writeFileSync(backupPath, content);
console.log(`💾 已备份到: ${backupPath}`);

let modifiedCount = 0;

// ============================================
// 修改: 将"客户端类型"列的 render 函数改为带 Tooltip 的版本
// ============================================

// 旧的渲染代码
const oldRender = `title: "\\u5ba2\\u6237\\u7aef\\u7c7b\\u578b",
                    dataIndex: "client_type",
                    key: "client_type",
                    render: e=>{
                        return e || "-"
                    }`;

// 新的渲染代码（带 Tooltip 悬浮提示）
const newRender = `title: "\\u5ba2\\u6237\\u7aef\\u7c7b\\u578b",
                    dataIndex: "client_type",
                    key: "client_type",
                    render: e=>{
                        if (!e || e === "-") return "-";
                        try {
                            var arr = typeof e === "string" ? JSON.parse(e) : e;
                            if (!Array.isArray(arr) || arr.length === 0) return e;
                            var latest = arr[0];
                            var latestText = latest.type || "未知";
                            if (arr.length === 1) return latestText;
                            var tooltipContent = arr.map(function(item) {
                                var time = item.time ? w()(1e3 * item.time).format("MM/DD HH:mm") : "-";
                                return item.type + " (" + time + ")";
                            }).join("\\n");
                            return g.a.createElement(f["a"], {
                                title: g.a.createElement("div", {style: {whiteSpace: "pre-line"}}, tooltipContent),
                                placement: "top"
                            }, g.a.createElement("span", {style: {cursor: "pointer", borderBottom: "1px dashed #999"}}, latestText + " +" + (arr.length - 1)))
                        } catch(err) {
                            return e;
                        }
                    }`;

if (content.includes(oldRender)) {
    content = content.replace(oldRender, newRender);
    console.log('✅ 已修改"客户端类型"列为带 Tooltip 的版本');
    modifiedCount++;
} else {
    console.log('⚠️ 未找到目标代码块，尝试模糊匹配...');

    // 尝试更宽松的匹配
    const pattern = /title: "\\u5ba2\\u6237\\u7aef\\u7c7b\\u578b",\s*dataIndex: "client_type",\s*key: "client_type",\s*render: e=>\{\s*return e \|\| "-"\s*\}/;

    if (pattern.test(content)) {
        content = content.replace(pattern, newRender);
        console.log('✅ 已通过模糊匹配修改"客户端类型"列');
        modifiedCount++;
    } else {
        console.log('❌ 无法找到匹配的代码块');
    }
}

// ============================================
// 保存修改
// ============================================
if (modifiedCount > 0) {
    fs.writeFileSync(umiPath, content);
    console.log(`\n🎉 修改完成！`);
    console.log('📝 效果：鼠标悬停在客户端类型上会显示历史记录列表');
} else {
    console.log('\n❌ 未能完成任何修改');
}
