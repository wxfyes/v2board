const fs = require('fs');

const filePath = 'public/assets/admin/umi.js';
let content = fs.readFileSync(filePath, 'utf8');

// 备份原文件
const backupPath = `public/assets/admin/umi.js.backup_dashboard_${Date.now()}`;
fs.writeFileSync(backupPath, content);
console.log(`备份已保存到: ${backupPath}`);

// 找到 "实时注册" 后面的代码，添加新的统计卡片
// 原代码结构：
// }, "\u5b9e\u65f6\u6ce8\u518c"), l.a.createElement("div", {
//     className: "display-4 text-black font-w300 mb-2"
// }, t.day_register_total ? t.day_register_total : "0")))))),

// 查找目标位置
const searchPattern = `}, t.day_register_total ? t.day_register_total : "0")))))),`;

if (!content.includes(searchPattern)) {
    console.log('未找到目标代码，尝试其他模式...');
    process.exit(1);
}

// 构建新的统计卡片代码（今日总流量 + 有效订阅）
const newStatsCode = `}, t.day_register_total ? t.day_register_total : "0")), l.a.createElement("div", {
                    className: "pr-4 pr-sm-5 pl-0 pl-sm-3 "
                }, l.a.createElement("i", {
                    className: "fa fa-cloud-download-alt fa-2x text-gray-light float-right"
                }), l.a.createElement("div", {
                    className: "text-muted mb-1",
                    style: { width: '120px' }
                }, "\\u4eca\\u65e5\\u603b\\u6d41\\u91cf"), l.a.createElement("div", {
                    className: "display-4 text-black font-w300 mb-2"
                }, t.today_traffic ? (t.today_traffic / 1073741824).toFixed(2) + " GB" : "0 GB")), l.a.createElement("div", {
                    className: "pr-4 pr-sm-5 pl-0 pl-sm-3 "
                }, l.a.createElement("i", {
                    className: "fa fa-user-check fa-2x text-gray-light float-right"
                }), l.a.createElement("div", {
                    className: "text-muted mb-1",
                    style: { width: '120px' }
                }, "\\u6709\\u6548\\u8ba2\\u9605"), l.a.createElement("div", {
                    className: "display-4 text-black font-w300 mb-2"
                }, t.valid_subscribe_count ? t.valid_subscribe_count : "0")))))),`;

content = content.replace(searchPattern, newStatsCode);

fs.writeFileSync(filePath, content);
console.log('✅ 已添加"今日总流量"和"有效订阅"统计卡片！');
