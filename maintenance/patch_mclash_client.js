const fs = require('fs');
const path = require('path');

// 递归查找并修改 Mclash 项目中的 UA
const mclashPath = 'E:/GitHub/Mclash';
const targetUA = 'ClashforWindows/0.19.15';
const newUA = 'Mclash/1.0.0 TianQueApp/1.0 ClashforWindows/0.19.15';
const chromeUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

function walk(dir) {
    if (!fs.existsSync(dir)) return;
    const list = fs.readdirSync(dir);
    list.forEach(file => {
        const fullPath = path.join(dir, file);
        const stat = fs.statSync(fullPath);
        if (stat.isDirectory()) {
            if (file !== '.git' && file !== 'node_modules' && file !== 'build') {
                walk(fullPath);
            }
        } else if (file.endsWith('.dart')) {
            let content = fs.readFileSync(fullPath, 'utf8');
            let changed = false;

            if (content.includes(targetUA) && !content.includes('Mclash/1.0.0')) {
                content = content.split(targetUA).join(newUA);
                changed = true;
            }

            if (content.includes(chromeUA) && !content.includes('Mclash/1.0.0')) {
                content = content.split(chromeUA).join(`Mclash/1.0.0 TianQueApp/1.0 ${chromeUA}`);
                changed = true;
            }

            // 特殊处理 v2board_service.dart 中的 customUserAgent 定义
            if (fullPath.includes('v2board_service.dart') && content.includes('const customUserAgent = \'TianQueApp')) {
                content = content.replace('const customUserAgent = \'TianQueApp', 'const customUserAgent = \'Mclash/1.0.0 TianQueApp');
                changed = true;
            }

            if (changed) {
                fs.writeFileSync(fullPath, content, 'utf8');
                console.log(`✅ 已更新 UA: ${fullPath}`);
            }
        }
    });
}

console.log('正在扫描并修复 Mclash 客户端 User-Agent...');
walk(mclashPath);
console.log('完成。');
