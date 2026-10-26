#!/bin/bash
# Local development only; production uses a Web server rooted at public/.
cd "$(dirname "$0")"
workspace_host="${WORKSPACE_HOST:-127.0.0.1}"
workspace_port="${WORKSPACE_PORT:-8080}"
echo "资产工作台（本地开发）: http://${workspace_host}:${workspace_port}"
exec php -S "${workspace_host}:${workspace_port}" -t public public/index.php
