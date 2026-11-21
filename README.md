# phpmcs

用 **纯 PHP** 写的 Minecraft **Java 1.8.9** 服务端（协议 47）。

- 单进程 + `stream_select` 事件循环
- **零扩展依赖**（不需要 sockets / 特殊 pecl）
- 直接读写 Anvil 存档（`.mca`）

**当前正式版：v1.0.0**（见根目录 [`VERSION`](VERSION)）。变更见 [`CHANGELOG.md`](CHANGELOG.md)，规划见 [`ROADMAP.md`](ROADMAP.md)。

---

## 快速开始（新手）

### 环境

- PHP **8.0+**（推荐 8.1～8.3）
- 命令行可执行 `php`

```bash
php -v
```

### 启动

**Linux / macOS：**

```bash
chmod +x start.sh
./start.sh
# 或：./start.sh 25565 "我的 PHP 服" world
```

**Windows：** 双击 `start.bat`，或 `.\start.ps1`

**直接 PHP：**

```bash
php bin/server.php 25565 "PHP 写的 1.8.9" world
```

CLI 参数优先于 `server.properties`。改配置后需重启。

### 客户端

官方 **Minecraft 1.8.9**，离线模式，连接 `127.0.0.1:25565`（或服务器 IP）。

---

## 现在已经能做什么

- 离线登录、聊天、Tab、皮肤；多人可见与装备同步
- 挖/放方块、掉落与拾取、Anvil 存档
- 箱子 / 末影箱 / 工作台 / 熔炉 / 附魔台 / 铁砧 / 酿造（基础）
- JSON 合成表（`data/recipes-1.8.9.json`）
- 生存：生命、饥饿与饱和、经验；**死亡掉落与死亡界面**；盔甲减伤与耐久
- 附魔效果（工具/武器/护甲常见项）
- 生物 AI（追击、苦力怕爆炸、骷髅射箭等）、刷怪笼
- 村庄（含铁匠铺等）、地牢、废弃矿道与结构箱战利品
- 下界传送门；床（全员睡觉跳白天）
- 指令：`/gm` `/spawn` `/tp` `/give` `/clear` `/kill` `/timeset` `/save` `/help` …

未完成与简化项见 [`ROADMAP.md`](ROADMAP.md) 与 [`docs/FEATURES.md`](docs/FEATURES.md)。

---

## 文档索引（维护必看）

| 文档 | 内容 |
|------|------|
| [CHANGELOG.md](CHANGELOG.md) | 版本变更记录 |
| [ROADMAP.md](ROADMAP.md) | 已规划未完成 |
| [docs/FEATURES.md](docs/FEATURES.md) | 功能对照表 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 代码地图与踩坑点 |
| [docs/MAINTAINING.md](docs/MAINTAINING.md) | 改代码/发版约定 |
| [HANDOFF.md](HANDOFF.md) | 早期交接笔记（部分过时，以 docs + CHANGELOG 为准） |
| [docs/](docs/) | 专题：协议、流体、饱食度、群系等 |

---

## 目录结构（简）

```
bin/server.php      入口
src/                服务端与 Trait
src/World/          存档、地形、结构、战利品
data/               配方等数据
docs/               维护文档
start.sh            Linux 启动
server.properties   配置
```

---

## 许可证与来源

基于社区项目演进；提交历史见 GitHub 标签 `v0.2.0` 起。
