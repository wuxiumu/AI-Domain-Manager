<?php

/** OpenAPI and discovery documents contain contracts only, never deployment secrets. */
class ApiDescriptionService
{
    public static function discovery(): array
    {
        return ['name' => 'Asset Workspace', 'version' => AssetSnapshot::VERSION, 'description' => '域名、子域名、IP 健康与 DNS 管理工作台',
            'openapi_url' => '/api/openapi.json', 'instructions_url' => '/llms.txt',
            'authentication' => ['recommended' => 'bearer', 'header' => 'Authorization: Bearer <token>',
                'provisioning' => '管理员登录 → 产品设置 → AI API → 创建密钥',
                'session_login' => '/api/login', 'session_captcha' => '/api/login/captcha', 'session_write_header' => 'X-CSRF-Token'],
            'scopes' => ApiTokenService::SCOPES,
            'entrypoints' => ['health' => '/api/health', 'domains' => '/api/domains', 'accounts' => '/api/accounts'],
            'cache' => '列表优先读取文件快照；同步、检查与刷新 DNS 需要显式调用；DNS 写入会刷新缓存。',
            'limits' => ['health_check_batch' => 5], 'providers' => array_column(SettingsService::providerSchema(), 'id')];
    }

    public static function instructions(): string
    {
        return <<<'TEXT'
# Asset Workspace API

Discover operations and request schemas at GET /api/openapi.json.
Machine discovery: GET /.well-known/asset-workspace.json (alias /api/capabilities).
All URLs are relative to this installation's origin.

Authentication:
- The administrator creates an expiring, scoped API token in Product Settings > AI API.
- Send Authorization: Bearer <token> on each request. No cookies or CSRF header are needed with a valid token.
- Interactive password login requires GET /api/login/captcha first. Keep its cookie, display the returned image to a human,
  then POST /api/login with user, pass, captcha_id and captcha, plus the challenge csrf_token as X-CSRF-Token.
  The challenge expires in 120 seconds and is consumed by one attempt. Refresh it after every failure.
  Keep the successful session cookie and use the new csrf_token as X-CSRF-Token for subsequent writes.
  Password login and captcha fetching are IP rate limited (HTTP 429, Retry-After). Automation should use scoped API tokens.
- Passwords and cloud secrets cannot be read back. Configuration, credential changes and token creation require an administrator session.
- Tokens expire, can be revoked, and are invalidated when administrator login credentials change.

Workflow:
1. GET /api/health for cached domains, IPs, health evidence, subdomains and relationship IDs.
2. GET /api/domains for the domain inventory. provider means a cloud ACCOUNT ID, not a vendor name.
3. GET /api/domains/{provider}/{domain}/records for cached DNS records and their IDs.
4. Health checks require checks:run: POST /api/health/check with {"asset_ids":["<asset id>"]}; at most 5 IDs per request.
5. To edit DNS, first read the record, then PUT /api/domains/{provider}/{domain}/records/{recordId}
   with type, name, value, ttl, priority and line. Preserve fields the user did not ask to change. Requires dns:write.
6. POST /api/sync explicitly refreshes the cloud domain inventory. Requires sync:run.

Interpretation:
- Reads reuse file caches. Do not sync, refresh DNS, run checks or write DNS just to display a list.
- DNS-derived IPs may be CDN/proxy addresses. Manual bindings and DNS observations have different meanings.
- unchecked and stale results are not healthy results. IP checks request http://IP/ and accept HTTP 2xx/3xx without following redirects; results and timestamps persist on the server.
- Treat HTTP errors and JSON ok:false as failures; some legacy cloud endpoints return ok:false with HTTP 200.
- Use URL-encoded path segments. Do not invent account, record or asset IDs.
- Never include passwords, cloud secrets or bearer tokens in a URL, a shared report, or logs.
TEXT;
    }

    private static function object(array $properties = [], array $required = []): array
    {
        $result = ['type' => 'object', 'properties' => (object) $properties];
        if ($required) { $result['required'] = $required; }
        return $result;
    }

    public static function openapi(): array
    {
        $string = ['type' => 'string'];
        $secret = ['type' => 'string', 'format' => 'password', 'writeOnly' => true];
        $boolean = ['type' => 'boolean'];
        $integer = ['type' => 'integer'];
        $record = self::object([
            'type' => ['type' => 'string', 'enum' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']],
            'name' => ['type' => 'string', 'description' => '相对主机记录，例如 @、www、api'],
            'value' => $string, 'ttl' => ['type' => 'integer', 'default' => 600],
            'priority' => ['type' => 'integer', 'default' => 0], 'line' => ['type' => 'string', 'default' => '默认'],
        ], ['type', 'name', 'value']);
        $domain = self::object(['provider' => ['type' => 'string', 'description' => '云账号 ID 或 manual'],
            'domain' => $string, 'status' => $string, 'expire_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD 或空字符串'],
            'registration_date' => $string, 'registrar' => $string, 'note' => $string, 'valid' => $boolean], ['provider', 'domain']);
        $links = ['type' => 'array', 'maxItems' => 30, 'items' => self::object(['name' => ['type' => 'string', 'maxLength' => 120], 'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'http(s):// URL']], ['name', 'url'])];
        $ip = self::object(['ip' => ['type' => 'string', 'description' => '公网 IPv4 或 IPv6 字面量'],
            'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535, 'default' => 443],
            'name' => $string, 'note' => $string, 'links' => $links], ['ip']);
        $account = self::object(['provider' => ['type' => 'string', 'enum' => ['aliyun', 'tencent', 'godaddy']],
            'name' => $string, 'note' => $string, 'enabled' => $boolean, 'access_key_id' => $secret, 'access_key_secret' => $secret,
            'secret_id' => $secret, 'secret_key' => $secret, 'api_key' => $secret, 'api_secret' => $secret], ['name', 'provider']);
        $account['oneOf'] = array_map(static function (array $provider): array {
            return ['properties' => ['provider' => ['const' => $provider['id']]], 'required' => array_column($provider['fields'], 'key')];
        }, SettingsService::providerSchema());
        $paths = [];
        $add = static function (string $method, string $path, string $id, string $summary, ?string $scope = null, ?array $body = null, array $requiredQuery = [], string $access = 'api', ?array $response = null) use (&$paths): void {
            $security = $access === 'public' ? [] : ($access === 'session' ? [['sessionCookie' => []]] : [['bearerAuth' => []], ['sessionCookie' => []]]);
            $op = ['operationId' => $id, 'summary' => $summary, 'security' => $security,
                'description' => ($scope ? 'Bearer 权限：' . $scope . '。' : '') . ($access === 'session' ? '仅管理员登录会话可调用。' : '')
                    . ($method !== 'GET' && $access !== 'public' ? '使用 Cookie 会话时必须传 X-CSRF-Token。' . ($access === 'api' ? '有效 Bearer Token 不需要 CSRF。' : '') : '')
                    . '请求失败时检查 HTTP 状态以及响应 ok:false。',
                'responses' => ['200' => ['description' => '成功（业务失败需检查 ok:false）', 'content' => ['application/json' => ['schema' => $response ?? ['type' => 'object']]]],
                    '401' => ['description' => '未登录或密钥无效'], '403' => ['description' => '权限不足或 CSRF 无效'],
                    '409' => ['description' => '状态冲突'], '422' => ['description' => '参数验证失败']]];
            if ($scope) { $op['x-required-scopes'] = [$scope]; }
            preg_match_all('/\{([^}]+)\}/', $path, $matches);
            foreach ($matches[1] as $name) {
                $op['parameters'][] = ['name' => $name, 'in' => 'path', 'required' => true,
                    'description' => $name === 'provider' ? '云账号 ID，例如 aliyun_1；从域名列表读取，不能填 aliyun' : '从列表响应取得的标识', 'schema' => ['type' => 'string']];
            }
            foreach ($requiredQuery as $name => $schema) { $op['parameters'][] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema]; }
            if ($method !== 'GET' && ($access !== 'public' || in_array($path, ['/api/setup', '/api/login'], true))) {
                $op['parameters'][] = ['name' => 'X-CSRF-Token', 'in' => 'header', 'required' => in_array($path, ['/api/setup', '/api/login'], true) || $access === 'session', 'schema' => ['type' => 'string'],
                    'description' => $path === '/api/login' ? '先 GET /api/login/captcha 获取，同时保留 Cookie' : ($path === '/api/setup' ? '先 GET /api/setup/status 获取，同时保留 Cookie' : ($access === 'session' ? '管理员 Cookie 会话写入时必填' : 'Cookie 会话写入时必填；Bearer 调用不需要'))];
            }
            if ($body !== null) { $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $body]]]; }
            if (in_array($id, ['createCloudAccount', 'createApiToken', 'createAssetBackup'], true)) { $op['responses']['201'] = $op['responses']['200']; unset($op['responses']['200']); }
            $paths[$path][strtolower($method)] = $op;
        };
        $add('GET', '/api/setup/status', 'getSetupStatus', '首次配置状态', null, null, [], 'public');
        $add('POST', '/api/setup', 'initializeWorkspace', '首次使用时设置管理员账号和密码，自动登录', null,
            self::object(['user' => $string, 'password' => $secret], ['user', 'password']), [], 'public');
        $add('GET', '/api/login/captcha', 'getLoginCaptcha', '获取 120 秒有效的一次性图形验证码；每 IP 每分钟最多 30 次', null, null, [], 'public',
            self::object(['ok' => $boolean, 'captcha_id' => $string, 'image' => ['type' => 'string', 'description' => 'SVG data URL，仅供人查看，不返回答案'], 'expires_in' => $integer, 'expires_at' => $integer, 'csrf_token' => $string]));
        $add('POST', '/api/login', 'login', '账号密码与验证码登录；每 IP 每分钟最多 10 次，5 分钟内失败 5 次锁定 5 分钟', null,
            self::object(['user' => $string, 'pass' => $secret, 'captcha_id' => $string, 'captcha' => $string], ['user', 'pass', 'captcha_id', 'captcha']), [], 'public',
            self::object(['ok' => $boolean, 'user' => $string, 'csrf_token' => $string]));
        foreach (['/api/login' => 'post', '/api/login/captcha' => 'get'] as $path => $method) {
            $paths[$path][$method]['responses']['429'] = ['description' => 'IP 请求过于频繁，按 Retry-After 等待后获取新验证码',
                'headers' => ['Retry-After' => ['schema' => $integer, 'description' => '剩余等待秒数']],
                'content' => ['application/json' => ['schema' => self::object(['ok' => $boolean, 'code' => $string, 'error' => $string, 'retry_after' => $integer])]]];
        }
        $add('GET', '/api/me', 'getIdentity', '读取当前登录身份');
        $add('POST', '/api/logout', 'logout', '退出管理员会话', null, null, [], 'session');
        $add('GET', '/api/health', 'getHealthSnapshot', '读取缓存健康看板、子域名和 IP 关系', 'assets:read', null, [], 'api',
            self::object(['assets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Asset']], 'summary' => ['type' => 'object'], 'cache' => ['type' => 'object']]));
        $add('POST', '/api/health/check', 'checkHealth', '检查 1–5 项资产并保存结果', 'checks:run',
            self::object(['asset_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $string]], ['asset_ids']));
        $add('POST', '/api/health/ips', 'createIpAsset', '登记公网 IP 检查目标', 'assets:write', $ip);
        $ipUpdate = self::object(['name' => $string, 'note' => $string, 'links' => $links, 'server_provider' => $string, 'expires_at' => $string]);
        $add('PUT', '/api/health/ips/{id}', 'updateIpAsset', '编辑 IP 资产资料', 'assets:write', $ipUpdate);
        $add('DELETE', '/api/health/ips/{id}', 'deleteIpAsset', '移除手工登记的 IP 目标', 'assets:write');
        $add('PUT', '/api/health/domains/{id}/bindings', 'saveDomainBindings', '保存域名与服务器的人工确认绑定', 'assets:write',
            self::object(['ip_ids' => ['type' => 'array', 'items' => $string]], ['ip_ids']));
        $add('GET', '/api/domains', 'listDomains', '查询本地缓存域名台账', 'assets:read', null,
            ['provider' => $string, 'q' => $string, 'valid' => ['type' => 'string', 'enum' => ['0', '1']], 'sort' => ['type' => 'string', 'enum' => ['expire', 'expire_desc', 'domain', 'synced']], 'expiring' => $string, 'access' => $string, 'all' => ['type' => 'string', 'enum' => ['1']]]);
        $add('GET', '/api/domains/stats', 'getDomainStats', '读取域名统计', 'assets:read');
        $add('POST', '/api/domains', 'createDomain', '添加本地域名台账，不购买域名', 'assets:write', $domain);
        $add('PUT', '/api/domains', 'updateDomain', '编辑本地域名台账，不修改云端 DNS', 'assets:write', $domain);
        $add('DELETE', '/api/domains', 'deleteDomain', '删除本地域名台账，不删除云端域名', 'assets:write', self::object(['provider' => $string, 'domain' => $string], ['provider', 'domain']));
        $dnsPath = '/api/domains/{provider}/{domain}/records';
        $add('GET', $dnsPath, 'listDnsRecords', '读取并缓存解析记录；refresh=1 显式刷新云端', 'dns:read', null, ['refresh' => ['type' => 'string', 'enum' => ['1']]]);
        $add('POST', $dnsPath, 'createDnsRecord', '新增云端 DNS 解析记录', 'dns:write', $record);
        $add('PUT', $dnsPath . '/{recordId}', 'updateDnsRecord', '修改云端解析，保留未请求修改的字段', 'dns:write', $record);
        $add('DELETE', $dnsPath . '/{recordId}', 'deleteDnsRecord', '删除云端 DNS 记录', 'dns:write');
        $add('POST', $dnsPath . '/access-check', 'checkDnsHosts', '检查解析记录对应的主机访问状态', 'checks:run');
        $add('GET', '/api/accounts', 'listCloudAccounts', '列出云账号标识和脱敏信息', 'assets:read', null, [], 'api', ['type' => 'array', 'items' => ['type' => 'object']]);
        $add('POST', '/api/accounts', 'createCloudAccount', '添加云账号配置', null, $account, [], 'session');
        $accountUpdate = $account; unset($accountUpdate['required'], $accountUpdate['oneOf']);
        $add('PUT', '/api/accounts/{id}', 'updateCloudAccount', '编辑云账号；密钥留空保留，enabled=false 停用', null, $accountUpdate, [], 'session');
        $add('DELETE', '/api/accounts/{id}', 'deleteCloudAccount', '删除云账号配置，保留资产与缓存', null, null, [], 'session');
        $add('POST', '/api/accounts/{id}/test', 'testCloudAccount', '测试云账号连接', 'sync:run');
        $add('POST', '/api/test-all', 'testAllCloudAccounts', '测试全部云账号连接', 'sync:run', null, [], 'api', ['type' => 'array', 'items' => ['type' => 'object']]);
        $add('POST', '/api/sync', 'syncDomains', '显式同步云端域名列表', 'sync:run');
        $add('GET', '/api/sync/logs', 'getSyncLogs', '读取同步记录', 'assets:read', null, ['limit' => $integer]);
        $add('GET', '/api/access-check', 'getLegacyAccessResults', '读取历史访问检查结果', 'assets:read');
        $add('POST', '/api/access-check', 'runLegacyAccessCheck', '运行域名访问检查', 'checks:run');
        $add('GET', '/api/settings', 'getWorkspaceSettings', '读取当前配置状态和可配置字段（不回显秘密）', null, null, [], 'session');
        $add('PUT', '/api/settings/http', 'updateHttpSettings', '更新云商接口代理与超时', null,
            self::object(['proxy' => $secret, 'clear_proxy' => $boolean, 'timeout' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 120], 'connect_timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30]]), [], 'session');
        $add('PUT', '/api/auth/credentials', 'updateAdminCredentials', '修改管理员账号和密码；会话和旧 API 密钥失效', null,
            self::object(['current_password' => $secret, 'user' => $string, 'password' => $secret], ['current_password', 'user', 'password']), [], 'session');
        $add('GET', '/api/tokens', 'listApiTokens', '列出密钥元数据和可授权范围', null, null, [], 'session');
        $add('POST', '/api/tokens', 'createApiToken', '创建 API 密钥，仅此次返回明文', null,
            self::object(['current_password' => $secret, 'name' => $string, 'scopes' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => array_keys(ApiTokenService::SCOPES)]],
                'expires_in_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 90]], ['name', 'current_password']), [], 'session');
        $add('DELETE', '/api/tokens/{id}', 'revokeApiToken', '撤销 API 密钥，立即停止后续调用', null, null, [], 'session');
        $add('GET', '/api/openapi.json', 'getOpenApi', '读取完整接口契约', null, null, [], 'public');
        $add('GET', '/api/capabilities', 'getCapabilities', '读取 AI 能力与认证说明', null, null, [], 'public');
        $add('GET', '/api/env-check', 'checkRuntimeEnvironment', '读取环境自检（初始化后仅管理员会话）', null, null, [], 'session');
        $add('GET', '/api/maintenance', 'readMaintenance', '读取版本、资产数量、备份目录、交付自检与最近操作记录', null, null, [], 'session');
        $add('POST', '/api/maintenance/backups', 'createAssetBackup', '创建不含凭据与检查缓存的服务器资产备份（最多 30 份）', null, self::object(), [], 'session');
        $add('GET', '/api/maintenance/backups/{id}', 'downloadAssetBackup', '下载私有资产备份内容与 SHA-256', null, null, [], 'session');
        $add('DELETE', '/api/maintenance/backups/{id}', 'deleteAssetBackup', '删除指定服务器备份', null, null, [], 'session');
        $add('GET', '/api/maintenance/export', 'exportAssets', '导出 JSON 迁移文件或 CSV 交接资料；返回 filename、content 与 sha256', null, null, ['format' => ['type' => 'string', 'enum' => ['json', 'csv'], 'default' => 'json']], 'session');
        $add('POST', '/api/maintenance/preview', 'previewAssetRestore', '预览全量替换；从 backup_id 或 content 指定一种来源，最大 5 MB，有效 15 分钟', null,
            self::object(['backup_id' => $string, 'content' => ['type' => 'string', 'description' => 'assets-v1 JSON 文件正文；不能与 backup_id 同时填写']]), [], 'session');
        $add('POST', '/api/maintenance/restore', 'restoreAssets', '验证密码与预览版本，自动留底后替换本地资产；不修改云端 DNS', null,
            self::object(['token' => $string, 'password' => $secret, 'confirmation' => ['type' => 'string', 'const' => '恢复资产']], ['token', 'password', 'confirmation']), [], 'session');
        return ['openapi' => '3.1.0', 'info' => ['title' => 'Asset Workspace API', 'version' => AssetSnapshot::VERSION,
                'description' => '缓存优先的域名 / IP 工作台。使用 Bearer API 密钥或登录 Session；云配置与登录密码不能读回。'],
            'servers' => [['url' => '/']], 'paths' => $paths, 'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'aw_<64 hex>', 'description' => '在产品设置创建，按 scopes 授权'],
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => session_name(), 'description' => '先获取图形验证码，再 POST /api/login；保留 Cookie，写请求另传 X-CSRF-Token']],
                'schemas' => ['Asset' => self::object(['id' => $string, 'kind' => ['type' => 'string', 'enum' => ['domain', 'ip']],
                    'name' => $string, 'provider' => $string, 'ip' => $string, 'ip_version' => ['type' => 'string', 'enum' => ['IPv4', 'IPv6']],
                    'port' => $integer, 'note' => $string, 'links' => $links, 'linked_ips' => ['type' => 'array', 'items' => $string], 'bound_ips' => ['type' => 'array', 'items' => $string],
                    'subdomain_count' => ['type' => ['integer', 'null']], 'subdomains' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'health' => self::object(['status' => ['type' => 'string', 'enum' => ['healthy', 'unhealthy', 'stale', 'unchecked']], 'message' => $string, 'checked_at' => ['type' => ['string', 'null']]])], ['id', 'kind', 'name', 'health'])]]];
    }
}
