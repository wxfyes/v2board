/**
 * 影子封禁功能注入脚本
 * 在 umi.js 中添加影子封禁按钮到用户操作菜单
 */

const fs = require('fs');
const path = require('path');

const umiPath = path.join(__dirname, 'public', 'assets', 'admin', 'umi.js');

// 备份原文件
const backupPath = umiPath + '.backup_shadowban_' + Date.now();
fs.copyFileSync(umiPath, backupPath);
console.log('备份完成:', backupPath);

let content = fs.readFileSync(umiPath, 'utf-8');

// 1. 添加 toggleShadowBan 方法（在 resetSecret 方法后面）
const resetSecretMethod = `resetSecret(e) {
                var t = this;
                p["a"].confirm({
                    title: "\\u91cd\\u7f6e\\u5b89\\u5168\\u4fe1\\u606f",
                    content: "\\u786e\\u5b9a\\u8981\\u91cd\\u7f6e".concat(e.email, "\\u7684\\u5b89\\u5168\\u4fe1\\u606f\\u5417\\uff1f"),
                    onOk() {
                        t.props.dispatch({
                            type: "user/resetSecret",
                            id: e.id
                        })
                    },
                    okText: "\\u786e\\u5b9a",
                    cancelText: "\\u53d6\\u6d88"
                })
            }`;

const toggleShadowBanMethod = `resetSecret(e) {
                var t = this;
                p["a"].confirm({
                    title: "\\u91cd\\u7f6e\\u5b89\\u5168\\u4fe1\\u606f",
                    content: "\\u786e\\u5b9a\\u8981\\u91cd\\u7f6e".concat(e.email, "\\u7684\\u5b89\\u5168\\u4fe1\\u606f\\u5417\\uff1f"),
                    onOk() {
                        t.props.dispatch({
                            type: "user/resetSecret",
                            id: e.id
                        })
                    },
                    okText: "\\u786e\\u5b9a",
                    cancelText: "\\u53d6\\u6d88"
                })
            }
            toggleShadowBan(e) {
                var t = this;
                var statusText = e.shadow_ban ? "\\u53d6\\u6d88\\u5f71\\u5b50\\u5c01\\u7981" : "\\u542f\\u7528\\u5f71\\u5b50\\u5c01\\u7981";
                p["a"].confirm({
                    title: statusText,
                    content: "\\u786e\\u5b9a\\u8981\\u5bf9 ".concat(e.email, " ").concat(statusText, "\\u5417\\uff1f\\u5f71\\u5b50\\u5c01\\u7981\\u4f1a\\u4e0b\\u53d1\\u5047\\u8ba2\\u9605\\uff0c\\u7528\\u6237\\u65e0\\u6cd5\\u611f\\u77e5\\u3002"),
                    onOk() {
                        fetch("/" + window.settings.secure_path + "/user/toggleShadowBan", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ id: e.id })
                        }).then(res => res.json()).then(data => {
                            if (data.data) {
                                t.props.dispatch({ type: "user/fetch" });
                                var msg = data.shadow_ban ? "\\u5df2\\u542f\\u7528\\u5f71\\u5b50\\u5c01\\u7981" : "\\u5df2\\u53d6\\u6d88\\u5f71\\u5b50\\u5c01\\u7981";
                                window.message && window.message.success(msg);
                            }
                        });
                    },
                    okText: "\\u786e\\u5b9a",
                    cancelText: "\\u53d6\\u6d88"
                })
            }`;

if (content.includes('toggleShadowBan')) {
    console.log('toggleShadowBan 方法已存在，跳过');
} else {
    content = content.replace(resetSecretMethod, toggleShadowBanMethod);
    console.log('添加 toggleShadowBan 方法');
}

// 2. 在下拉菜单中添加影子封禁按钮（在 "TA的流量记录" 后面，"删除用户" 前面）
// 找到这段代码: g.a.createElement(c["a"].Item, null, g.a.createElement("a", { onClick: ()=>this.delUser(t)
const menuItemBefore = `}), " TA\\u7684\\u6d41\\u91cf\\u8bb0\\u5f55"))), g.a.createElement(c["a"].Item, null, g.a.createElement("a", {
                                onClick: ()=>this.delUser(t)`;

const menuItemAfter = `}), " TA\\u7684\\u6d41\\u91cf\\u8bb0\\u5f55"))), g.a.createElement(c["a"].Item, null, g.a.createElement("a", {
                                onClick: ()=>this.toggleShadowBan(t),
                                style: { color: t.shadow_ban ? "#52c41a" : "#faad14" }
                            }, g.a.createElement(u["a"], {
                                type: t.shadow_ban ? "eye" : "eye-invisible"
                            }), t.shadow_ban ? " \\u53d6\\u6d88\\u5f71\\u5b50\\u5c01\\u7981" : " \\u5f71\\u5b50\\u5c01\\u7981")), g.a.createElement(c["a"].Item, null, g.a.createElement("a", {
                                onClick: ()=>this.delUser(t)`;

if (content.includes('toggleShadowBan(t)')) {
    console.log('菜单项已存在，跳过');
} else {
    content = content.replace(menuItemBefore, menuItemAfter);
    console.log('添加下拉菜单项');
}

// 写回文件
fs.writeFileSync(umiPath, content, 'utf-8');
console.log('注入完成！');
console.log('请刷新后台页面查看效果');
