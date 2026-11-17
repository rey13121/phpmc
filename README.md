# phpmcs

用 **纯 PHP** 写的 Minecraft **Java 1.8.9** 服务端（协议 47）。

- 单进程 + `stream_select` 事件循环
- **零扩展依赖**（不需要 sockets / 特殊 pecl）
- 直接读写 Anvil 存档（`.mca`），单机也能打开

适合学习 Minecraft 协议、或在只有 PHP 的环境里跑一个轻量 1.8.9 服。

---

## 快速开始（适合新手）

### 1. 环境要求

- PHP **8.0+**（推荐 8.1 / 8.2 / 8.3）
- 命令行能执行 `php`
- 可选：Python 3（用来跑 `tools/` 下的协议测试脚本）

检查：

```bash
php -v
```

### 2. 启动服务端

**Linux / macOS：**

```bash
chmod +x start.sh
./start.sh
```

或指定参数：

```bash
./start.sh 25565 "我的 PHP 服" world
```

**Windows：**

双击 `start.bat`，或在 PowerShell 里：

```powershell
.\start.ps1
```

**直接用 PHP：**

```bash
php bin/server.php 25565 "PHP 写的 1.8.9" world
```

- 默认监听 `0.0.0.0:25565`
- 没有 `world/` 目录会自动按 Anvil 格式新建一份标准存档
- 改配置请编辑 `server.properties`（端口、MOTD、存档名、视距、模式等），改完需重启

### 3. 客户端连接

1. 启动官方 **Minecraft 1.8.9**（或支持 1.8.9 协议的启动器）
2. 多人游戏 → 直接连接 → 输入 `127.0.0.1`（本机）或服务器 IP
3. 离线模式，随便起名字即可

> 视距默认 3，公网带宽压力较小。想更远可在 `server.properties` 里改 `view-distance`（会更吃内存和流量）。

---

## 现在已经能做什么

- 离线登录、Keep-Alive、聊天
- 区块收发、移动、挖方块 / 放方块
- 掉落物与拾取、两个玩家互相看见
- 皮肤（走 Mojang 签名纹理）
- Anvil 存档读写
- 箱子（单箱 / 双箱、TileEntity 同步）
- 工作台 + 2×2 合成（木板、木棍、火把、箱子、熔炉、基础工具等）
- 近似原版地形（群系、河流、树、仙人掌、雪、甘蔗等）+ 部分洞穴 / 矿石 / 村庄相关代码
- 部分生物、流体、红石、熔炉、下界/末地、附魔等相关 Trait（完成度不一）
- 插件示例（`plugins/example.php`）
- 简单 WebSocket 支持（见 `src/WebSocket.php`）

常用命令：

```
/gm 0|1     生存 / 创造
/spawn
/who
/help
/block x y z
/save
/reload
```

---

## 还缺 / 不完善的地方

- 生存模式完整挖掘计时与正确掉落概率
- 完整生物 AI、刷怪、战斗
- 红石电路完整模拟
- 下界 / 末地完整体验
- 熔炉冶炼进度、盔甲穿戴、更多配方
- 区块 zlib 压缩（当前未压缩，流量偏大）
- 在线模式（加密 + 正版验证）
- 洞穴 / 矿脉 / 结构生成仍有接缝或不完整的可能

详细技术交接与已知坑见 `HANDOFF.md` 和 `CHEST_TE_ISSUE.md`。

---

## 测试脚本（不依赖真客户端）

```bash
python tools/play_test.py     # 登录 / 区块 / 聊天 / 两人互见
python tools/dig_test.py      # 挖放方块
python tools/drop_test.py     # 掉落物 + 拾取
python tools/action_test.py   # 丢物品、挥手
python tools/skin_test.py     # 皮肤属性
python tools/check_save.py    # 校验 .mca 和 level.dat
```

---

## 项目结构（简要）

| 路径 | 说明 |
|------|------|
| `bin/server.php` | 启动入口 |
| `src/Server.php` | 核心网络循环、玩家、包处理（很大） |
| `src/*Trait.php` | 功能模块（箱子、合成、熔炉、生物、红石……） |
| `src/World/` | 世界、区块、地形生成 |
| `src/Protocol.php` | 协议编解码 |
| `server.properties` | 配置 |
| `start.sh` / `start.bat` / `start.ps1` | 启动脚本 |
| `tools/` | 协议测试脚本 |
| `plugins/` | 简单插件示例 |
| `HANDOFF.md` | 详细开发交接笔记 |

---

## 1.8 协议两个容易踩的坑

1. **实体元数据**：一个字节 = 高 3 位类型 + 低 5 位索引（1.9 才拆成两个字节）。
2. **Spawn Object**：只有 `data > 0` 时才后面跟速度三个 short；写成 0 时物品会卡在出生点。

写错通常不报错，只是画面不对或客户端崩溃。

---

## 下一步可以怎么完善

1. **稳定性**：修箱子重进渲染、区块接缝、生存挖掘计时
2. **功能**：补更多合成配方、熔炉完整流程、简单生物 AI
3. **性能**：给区块数据加 zlib 压缩
4. **易用性**：简单 Web 状态页 / 管理接口、更好的日志与错误提示
5. **现代化**：拆分过大的 `Server.php`、补类型注解与简单单元测试

有问题或想优先做哪一块，直接说即可。
