# 代码维护工具包

如果你执行了 `git pull upstream master` 或者更新了客户端源码，导致自定义修改丢失，请按需运行以下脚本。

## 1. 恢复管理员后台仪表盘显示
**适用场景**：拉取更新后，后台不再显示“有效订阅”和“今日流量”。
**命令**：
```bash
node maintenance/restore_admin_ui.js
```

## 2. 修复 Mclash 客户端识别
**适用场景**：Mclash 源码被上游覆盖，导致编译后的客户端在后台显示为 "Clash" 或 "天阙"。
**命令**：
```bash
node maintenance/patch_mclash_client.js
```

## 注意事项
1. 请确保你已安装 Node.js。
2. 建议在每次拉取上游更新前，先提交（git commit）你当前的后端 PHP 修改。如果发生冲突，手动合并即可。
3. 后端 PHP 逻辑（`StatController.php` 和 `ClientController.php`）建议通过 Git 分支管理，这比脚本更可靠。
