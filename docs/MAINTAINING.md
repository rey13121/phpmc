# 维护约定

面向后续改代码的人（含初学者）。

## 改之前

1. 看 `README.md` 功能列表与 `ROADMAP.md`，避免重复造轮子。
2. 看 `docs/ARCHITECTURE.md` 找该动的文件。
3. 相关历史：`docs/` 下专题笔记、`HANDOFF.md`（偏早期，以本目录与 CHANGELOG 为准）。

## 改的时候

1. **PHP 8**：保持 `declare(strict_types=1)`；新文件同样。
2. **不要提交** `world/`、`cache/`、`logs/`、玩家存档；`.gitignore` 已覆盖常见项。
3. **物品/方块 id** 以 1.8.9 数字 id 为准（见 wiki.vg / 本仓库既有常量）。
4. **合成**：有形状配方优先写进 `data/recipes-1.8.9.json` 或确保 `CraftingTrait` 能加载；镜像匹配已修过，改匹配逻辑要测对称配方。
5. **箱子内容** 只通过 `ChestStore` 读写；生成结构时放方块 54，loot 交给 `LootTables` + `seedStructureLoot` / 首次打开。
6. **死亡与伤害** 走 `damage()` → `killPlayer()` / `respawnPlayer()`，不要绕开。

## 改完之后

1. `php -l` 相关文件。
2. 能启动则 `./start.sh` 冒烟（进服、相关操作一次）。
3. **更新文档**（缺一不可就容易烂尾）：
   - 用户能感知的行为 → `CHANGELOG.md` 顶一条
   - 完成度变化 → `ROADMAP.md` 改状态
   - 功能列表变化 → `README.md`「现在已经能做什么」
   - 架构/新模块 → `docs/ARCHITECTURE.md` 一行
   - 版本号 → `VERSION`；需要发版则打 **新** git tag（保留旧 tag）
4. 推送：`main` + 新 tag；不要 force 删 `v0.2.0` 等历史标签。

## 优先级建议（与 ROADMAP 一致）

体验缺口（死亡、睡觉、食物、指令）> 世界生成可玩性 > 完整原版对齐（红石边角、online-mode、1:1 结构）。

## 安全

- 勿把 GitHub token 写进仓库或文档。
- `server.properties` 里不要放密钥；本服默认 offline-mode。
