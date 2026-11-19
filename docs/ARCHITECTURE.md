# 架构与代码地图（维护用）

协议：**Minecraft Java 1.8.9 / protocol 47**  
运行时：PHP 8+ CLI，单进程 `stream_select`，无 sockets 扩展依赖。

## 入口与加载

| 路径 | 作用 |
|------|------|
| `bin/server.php` | 入口：`require` 全部 `src/`，解析 CLI / `server.properties`，启动 `Server` |
| `start.sh` / `start.bat` / `start.ps1` | 启动脚本；**CLI 参数优先于 properties**（port / motd / world） |
| `server.properties` | 端口、MOTD、世界名、视距、模式等 |

改 PHP 后必须**重启进程**才会生效。

## 核心类

| 路径 | 作用 |
|------|------|
| `src/Server.php` | 事件循环、连接、Play 包分发、伤害/死亡、食物、指令、掉落物 |
| `src/Protocol.php` / 内嵌 Writer·Reader | 1.8 包编解码 |
| `src/World/AnvilWorld.php` | 区块读写、生成调度、玩家位置/背包 JSON + playerdata |
| `src/World/Chunk.php` | 单区块 section / 方块读写 |
| `src/World/TerrainGenerator.php` | 主世界地形；挂钩洞穴、矿石、村庄、地牢、矿道 |
| `src/World/ChestStore.php` | 箱子内容权威存储（`.phpmcs_chests.json`） |
| `src/World/LootTables.php` | 结构箱加权战利品 |

## Trait 功能模块（挂在 `Server` 上）

| Trait | 功能 |
|-------|------|
| `MobTrait` | 生物 AI、刷怪、掉落 |
| `CraftingTrait` | 2×2 / 3×3、JSON 配方 |
| `ChestTrait` / `EnderChestTrait` | 箱子窗口、懒加载战利品 |
| `EnchantTrait` | 附魔台与战斗附魔效果 |
| `FurnaceTrait` / `BrewingTrait` / `AnvilTrait` | 熔炉、酿造、铁砧 |
| `PortalTrait` / `EndTrait` | 下界 / 末地 |
| `FluidTrait` / `RedstoneTrait` / `BlockPhysicsTrait` | 流体、红石、物理 |
| `FarmingTrait` / `SpawnerTrait` | 作物、刷怪笼 |
| `ArrowTrait` | 箭 |

新增玩法优先**新 Trait 或扩现有 Trait**，避免把逻辑全塞进 `Server.php`。

## 数据与存档

- 世界目录：默认 `world/`（Anvil `region/*.mca` + `level.dat`）
- 玩家位置/背包：世界旁 JSON + `playerdata/`（服务端以 JSON 为正本）
- 箱子：`ChestStore` 文件；区块 TE 为辅助（数字 id，单机原版打开可能不完整）
- 配方：`data/recipes-1.8.9.json` 运行时加载

## 协议注意点（易踩坑）

1. **死亡**：`health=0` 同步后等客户端 `0x16` Client Status 再 `0x07` Respawn，不要直接跳重生。
2. **光标槽**：`Set Slot` 用 `windowId=-1, slot=-1`。
3. **装备可见**：`0x04` Entity Equipment（手持 + 盔甲）。
4. **区块**：改方块后对视距内玩家发 `Block Change`；结构箱 loot 在**新生成区块**或**首次打开空箱**时填充。
5. **维度**：下界/末地靠坐标带 + Respawn 维度字段，不是独立进程。

## 日志与调试

- 日志：`logs/server.log`（若配置）
- 指令：`/debug`、`/foodlog` 等开关
- 约定：新功能在关键分支加 `if ($this->debugLog)` 日志，便于排查

## 版本与发布

- `VERSION` 文件：当前版本号
- `CHANGELOG.md`：用户可见变更
- `ROADMAP.md`：已规划未完成
- Git 标签：`v0.2.0` 起保留历史，新版本只**新增**标签，不删旧标签
