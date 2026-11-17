#!/usr/bin/env bash
# PHPMCS 简易启动脚本（Linux / macOS）
# 用法：./start.sh
# 或：  bash start.sh [端口] [MOTD] [存档目录]

set -e
cd "$(dirname "$0")"

# 默认值；命令行参数优先；没有参数时再读 server.properties
PORT=25565
MOTD="PHP 写的 1.8.9"
WORLD="world"

if [[ -f server.properties ]]; then
  while IFS='=' read -r key value || [[ -n "$key" ]]; do
    key="${key%%#*}"
    key=$(echo "$key" | xargs 2>/dev/null || true)
    value=$(echo "$value" | xargs 2>/dev/null || true)
    [[ -z "$key" ]] && continue
    case "$key" in
      server-port) [[ "$value" =~ ^[0-9]+$ ]] && PORT="$value" ;;
      motd) [[ -n "$value" ]] && MOTD="$value" ;;
      level-name) [[ -n "$value" ]] && WORLD="$value" ;;
    esac
  done < server.properties
fi

# 命令行参数覆盖配置文件
[[ -n "${1:-}" ]] && PORT="$1"
[[ -n "${2:-}" ]] && MOTD="$2"
[[ -n "${3:-}" ]] && WORLD="$3"

if ! command -v php >/dev/null 2>&1; then
  echo "错误：找不到 php 命令。请先安装 PHP 8.0+（推荐 8.1+）。"
  echo "  Ubuntu/Debian: sudo apt install php-cli"
  echo "  macOS: brew install php"
  exit 1
fi

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "使用 PHP $PHP_VER"
echo "启动 PHPMCS  端口=$PORT  MOTD=\"$MOTD\"  存档=$WORLD"
echo "按 Ctrl+C 停止服务端"
echo "----------------------------------------"

exec php bin/server.php "$PORT" "$MOTD" "$WORLD"
