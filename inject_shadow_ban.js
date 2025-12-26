const fs = require('fs');
const umiPath = './public/assets/admin/umi.js';
let c = fs.readFileSync(umiPath, 'utf-8');

// 用正则匹配
const methodRegex = /(\}\)\s*\}\s*)delUser\(e\)\s*\{/;
const match = c.match(methodRegex);

if (match) {
    console.log('Found delUser, injecting...');

    const toggleMethod = `})
            }
            toggleShadowBan(e) {
                var t = this;
                var txt = e.shadow_ban ? "取消影子封禁" : "启用影子封禁";
                p["a"].confirm({
                    title: txt,
                    content: "确定对 " + e.email + " " + txt + "?",
                    onOk: function() {
                        fetch("/" + window.settings.secure_path + "/user/toggleShadowBan", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ id: e.id })
                        }).then(function(r) { return r.json(); }).then(function(d) { 
                            if(d.data) t.props.dispatch({ type: "user/fetch" }); 
                        });
                    },
                    okText: "确定",
                    cancelText: "取消"
                })
            }
            delUser(e) {`;

    c = c.replace(methodRegex, toggleMethod);
    console.log('Method injected');
}

// 菜单注入 - 找 TA的流量记录 后面
const menuRegex = /(TA\\u7684\\u6d41\\u91cf\\u8bb0\\u5f55"\)\)\), g\.a\.createElement\(c\["a"\]\.Item, null, g\.a\.createElement\("a", \{\s*onClick: \(\)=>this\.delUser\(t\))/;
if (c.match(menuRegex)) {
    c = c.replace(menuRegex, `TA\\u7684\\u6d41\\u91cf\\u8bb0\\u5f55"))), g.a.createElement(c["a"].Item, null, g.a.createElement("a", {
                                onClick: ()=>this.toggleShadowBan(t),
                                style: { color: t.shadow_ban ? "#52c41a" : "#faad14" }
                            }, g.a.createElement(u["a"], {
                                type: t.shadow_ban ? "eye" : "eye-invisible"
                            }), t.shadow_ban ? " 取消影子封禁" : " 影子封禁")), g.a.createElement(c["a"].Item, null, g.a.createElement("a", {
                                onClick: ()=>this.delUser(t)`);
    console.log('Menu injected');
}

fs.writeFileSync(umiPath, c);
console.log('Has toggle now:', c.includes('toggleShadowBan'));
