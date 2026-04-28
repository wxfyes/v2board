const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, '../public/assets/admin/umi.js');
if (!fs.existsSync(filePath)) {
    console.error('错误: 未找到 public/assets/admin/umi.js，请确保在项目根目录运行。');
    process.exit(1);
}

let content = fs.readFileSync(filePath, 'utf8');

// 1. 定义注入点和代码
const target = '}, t.day_register_total ? t.day_register_total : "0"))';
const labelTraffic = "\\u4eca\\u65e5\\u6d41\\u91cf"; // 今日流量
const labelUser = "\\u6709\\u6548\\u8ba2\\u9605"; // 有效订阅

const blockTraffic = `l.a.createElement("div", {
    className: "pr-4 pr-sm-5 pl-0 pl-sm-3 "
}, l.a.createElement("i", {
    className: "fa fa-broadcast-tower fa-2x text-gray-light float-right"
}), l.a.createElement("div", {
    className: "text-muted mb-1",
    style: { width: '120px' }
}, "${labelTraffic}"), l.a.createElement("div", {
    className: "display-4 text-black font-w300 mb-2"
}, t.day_traffic ? (t.day_traffic / 1073741824).toFixed(2) + " GB" : "0.00 GB"))`;

const blockTotalUser = `l.a.createElement("div", {
    className: "pr-4 pr-sm-5 pl-0 pl-sm-3 "
}, l.a.createElement("i", {
    className: "fa fa-users fa-2x text-gray-light float-right"
}), l.a.createElement("div", {
    className: "text-muted mb-1",
    style: { width: '120px' }
}, "${labelUser}"), l.a.createElement("div", {
    className: "display-4 text-black font-w300 mb-2"
}, t.total_user ? t.total_user : "0"))`;

const replacement = target + ', ' + blockTraffic + ', ' + blockTotalUser;

// 2. 执行注入
if (content.includes(target)) {
    if (content.includes(labelTraffic)) {
        console.log('提示: 仪表盘统计项似乎已经存在，无需重复注入。');
    } else {
        const newContent = content.replace(target, replacement);
        fs.writeFileSync(filePath, newContent, 'utf8');
        console.log('✅ 成功恢复后台仪表盘显示逻辑 (有效订阅 + 今日流量)');
    }
} else {
    console.error('❌ 无法在 umi.js 中找到注入点，可能上游代码结构发生了重大变化。');
}
