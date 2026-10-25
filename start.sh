#!/bin/bash
# 域名管家启动脚本
cd "$(dirname "$0")"
php scripts/setup-key.php --if-needed || exit 1
echo "域名管家已启动: http://localhost:8080"
exec php -S 0.0.0.0:8080 -t public public/index.php
