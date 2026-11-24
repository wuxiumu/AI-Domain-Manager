#!/bin/bash
# 仅用于本地开发；生产环境请把 Web 站点根目录指向 public/（见 deploy/nginx.conf.example）。
cd "$(dirname "$0")"

# 默认端口：8080 常被各种开发服务与客户端连接占用，本地开发改用 18080。
default_host="127.0.0.1"
default_port="18080"

workspace_host="${WORKSPACE_HOST:-$default_host}"
workspace_port="${WORKSPACE_PORT:-$default_port}"
port_pinned=0
[ -n "$WORKSPACE_PORT" ] && port_pinned=1

assume_yes=0

usage() {
    cat <<EOF
用法: ./start.sh [选项]

本地开发启动资产工作台（PHP 内置服务器），启动前确认端口可用。

选项:
  -p, --port <端口>    直接指定端口（等价于 WORKSPACE_PORT，视为明确指定：被占用则报错不自动改）
      --host <地址>    监听地址（默认 127.0.0.1）
  -y, --yes            跳过交互确认（端口空闲直接启动；默认端口被占用时自动向后找空闲端口）
  -h, --help           显示本帮助

环境变量: WORKSPACE_HOST / WORKSPACE_PORT
EOF
}

while [ $# -gt 0 ]; do
    case "$1" in
        -p|--port)
            [ -n "$2" ] || { echo "选项 $1 需要一个端口值"; exit 2; }
            workspace_port="$2"; port_pinned=1; shift 2 ;;
        --port=*) workspace_port="${1#*=}"; port_pinned=1; shift ;;
        --host)
            [ -n "$2" ] || { echo "选项 $1 需要一个地址值"; exit 2; }
            workspace_host="$2"; shift 2 ;;
        --host=*) workspace_host="${1#*=}"; shift ;;
        -y|--yes) assume_yes=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "未知选项: $1"; echo; usage; exit 2 ;;
    esac
done

# ---------- 端口可用性判定 ----------
# 只判断「本机能否监听这个端口」。此前用 lsof -i :8080 会把微信等程序
# 连向别人 8080 的出站连接（ESTABLISHED）误判为端口被占用，这里必须避开。
port_is_free() {
    local host="$1" port="$2" reason=""
    case "$port" in ''|*[!0-9]*) return 2 ;; esac
    [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || return 2
    if command -v php >/dev/null 2>&1; then
        # 用同一个用户身份真实试绑定一次：这就是 php -S 即将做的事，最可靠
        reason=$(php -r '$s=@stream_socket_server("tcp://".$argv[1].":".$argv[2],$_ENV_NO,$m); if($s){exit(0);} echo $m; exit(1);' -- "$host" "$port" 2>/dev/null)
        [ $? -eq 0 ] && return 0
        [ -n "$reason" ] && return 1
        # 沙箱或权限限制导致无法判定时，退回只看本机 LISTEN 的行式匹配
    fi
    if command -v lsof >/dev/null 2>&1; then
        lsof -nP -a -p $$ -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1 && return 1
        lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | tail -n +2 | grep -Eq "[:.]${port}[[:space:]]|[:.]${port}$" && return 1
        return 0
    fi
    if command -v ss >/dev/null 2>&1; then
        ss -tlnH 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${port}$" && return 1
        return 0
    fi
    if command -v netstat >/dev/null 2>&1; then
        netstat -an 2>/dev/null | grep -Ei "tcp[46]?[[:space:]].*[.:]${port}[[:space:]]+.*LISTEN" >/dev/null 2>&1 && return 1
        return 0
    fi
    return 0    # 没有任何可用判据时不阻塞启动，交给 php -S 自己报错
}

first_free_port() {
    local host="$1" start="$2" probe port
    for probe in $(seq 0 20); do
        port=$((start + probe))
        [ "$port" -gt 65535 ] && break
        port_is_free "$host" "$port" >/dev/null 2>&1 && { printf '%s' "$port"; return 0; }
    done
    return 1
}

valid_port() {
    case "$1" in ''|*[!0-9]*) return 1 ;; esac
    [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

# ---------- 启动 ----------
echo "========================================="
echo "  资产工作台 · 本地开发启动器"
echo "========================================="

if ! port_is_free "$workspace_host" "$workspace_port"; then
    suggest=$(first_free_port "$workspace_host" "$workspace_port")
    echo ""
    echo "⚠️  端口 ${workspace_port} 本机不可监听（已有程序在听，或需要更高权限）"
    echo "   想确认是谁占用的：lsof -nP -iTCP:${workspace_port} -sTCP:LISTEN"
    if [ "$port_pinned" -eq 1 ]; then
        if [ -n "$suggest" ]; then
            echo "❌ 你明确指定了端口 ${workspace_port}，不做自动改动。可改用: --port ${suggest}"
        else
            echo "❌ 你明确指定了端口 ${workspace_port}，且附近没有空闲端口。"
        fi
        exit 1
    fi
    if [ "$assume_yes" -eq 1 ] || [ ! -t 0 ]; then
        if [ -z "$suggest" ]; then
            echo "❌ 找不到可用端口，请手动指定：./start.sh --port <端口>"
            exit 1
        fi
        echo "✅ 自动改用空闲端口: ${workspace_port} -> ${suggest}（用 --port 可固定）"
        workspace_port="$suggest"
    else
        echo "✅ 建议改用空闲端口: ${suggest}"
        workspace_port="$suggest"
    fi
fi

if [ "$assume_yes" -eq 1 ] || [ ! -t 0 ]; then
    echo ""
    echo "✅ 端口 ${workspace_port} 可用，直接启动"
else
    tries=0
    while : ; do
        printf '\n🔗 资产工作台地址: http://%s:%s\n' "$workspace_host" "$workspace_port"
        printf '   端口是否正确？直接回车确认，或输入新端口【默认 %s】: ' "$workspace_port"
        read -r input || break                      # 读不到输入（EOF）就用当前端口
        [ -z "$input" ] && break                    # 直接回车 = 接受
        [ "$input" = "$workspace_port" ] && break
        if ! valid_port "$input"; then
            echo "⚠️  端口需为 1~65535 的数字"
        elif ! port_is_free "$workspace_host" "$input"; then
            echo "⚠️  端口 $input 本机不可监听（已有程序在听，或需要更高权限）"
        else
            workspace_port="$input"; break
        fi
        tries=$((tries + 1))
        if [ "$tries" -ge 3 ]; then
            echo "↩️  连续三次没换成功，就按 http://${workspace_host}:${workspace_port} 启动"
            break
        fi
        echo "   再试一次，或直接回车接受上面的端口"
    done
fi

echo ""
echo "🚀 正在启动: http://${workspace_host}:${workspace_port}    (Ctrl+C 停止)"
echo "========================================="
exec php -S "${workspace_host}:${workspace_port}" -t public public/index.php
