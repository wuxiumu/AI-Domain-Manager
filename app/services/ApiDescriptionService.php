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
            'entrypoints' => ['health' => '/api/health', 'operations' => '/api/operations', 'issues' => '/api/issues', 'report' => '/api/health/report', 'handover' => '/api/handover', 'proposals' => '/api/proposals', 'trends' => '/api/trends', 'metrics' => '/api/metrics', 'domains' => '/api/domains', 'accounts' => '/api/accounts'],
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
1. GET /api/handover is the takeover briefing: current summary counts, your granted scopes, next actions and the full playbook.
2. GET /api/issues is the triage list: every problem (P0 HTTP-unreachable, P1 stale, P2 expiring) with a stable id, evidence, dns_path and suggested actions. Accessibility is judged by HTTP only; HTTPS certificate follow-ups live in GET /api/https-review — the same list the administrator sees first on the dashboard. Triage it, record your conclusion in the asset note (assets:write) or a proposal rationale referencing the issue id, act via checks:run / proposals:write, then re-read this endpoint to confirm the issue disappeared.
3. GET /api/health for cached domains, IPs, health evidence, subdomains and relationship IDs.
   GET /api/operations lists domains without a successful latest HTTPS check and unhealthy domains with observed IPs. Unknown is not proof of no HTTPS; check timestamps and stale status.
   GET /api/health/report returns the same cached evidence compiled into one bounded Markdown report (failing domains, unconfirmed HTTPS, failing IPs, 30-day expiries, interpretation guide).
   Its prompt field embeds the report in a ready-to-run analysis prompt; paste it into a chat assistant or follow it yourself when summarizing for a human.
   Add ?format=markdown to download it as text/markdown. Read-only, never triggers checks or syncs; generated_at marks the snapshot time.
4. GET /api/domains for the domain inventory. provider means a cloud ACCOUNT ID, not a vendor name.
5. GET /api/domains/{provider}/{domain}/records for cached DNS records and their IDs.
6. Health checks require checks:run: POST /api/health/check with {"asset_ids":["<asset id>"]}; at most 5 IDs per request.
7. Preferred write path — submit a proposal instead of writing DNS directly:
   POST /api/proposals with {"account":"<provider id>","domain":"example.com","action":"add|update|delete",
   "record_id":"<required for update/delete>","record":{"type":"A","name":"@","value":"192.0.2.1"},
   "rationale":"4-500 char reason","evidence":"optional report line or check reference"}. Scope proposals:write.
   The administrator sees the proposal, the cached before-values and your rationale, then approves or rejects.
   Approval reads the fresh cloud version, writes once and verifies by readback (same guards as manual edits).
   GET /api/proposals (proposals:write) tracks status: pending / executed / failed / rejected. Duplicate pending changes are rejected with 409.
8. Direct DNS editing (dns:write) stays available for trusted automation but the proposal queue is the recommended path:
   first read the record and show the before/after difference, then PUT /api/domains/{provider}/{domain}/records/{recordId}
   with expected_version from that record's version and only the fields to change. DELETE also requires expected_version in its JSON body.
   POST requires expected_revision from the list response's revision plus the new record fields.
   A fresh cloud read rejects stale versions with HTTP 409. HTTP 202 / verified:false means unconfirmed; do not retry writes automatically.
   Only verified:true confirms cloud readback, not worldwide DNS propagation. DNS change history is administrator-session-only at GET /api/dns/changes.
9. POST /api/sync explicitly refreshes the cloud domain inventory. Requires sync:run.
10. GET /api/trends returns the 30-day daily check trend for human dashboards (checks, healthy/unhealthy, avg latency, full-run summaries).
   GET /api/metrics exposes the same state in Prometheus text format (asset_workspace_* series) so external monitoring platforms can scrape it with assets:read.

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
            'priority' => ['type' => 'integer', 'default' => 0], 'line' => ['type' => 'string', 'description' => '新增时省略使用云商默认；修改时省略保留当前线路'],
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
            'secret_id' => $secret, 'secret_key' => $secret, 'api_key' => $secret, 'api_secret' => $secret,
            'api_base_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'GoDaddy HTTPS API 基础地址，默认 https://api.godaddy.com']], ['name', 'provider']);
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
        $add('GET', '/api/health', 'getHealthSnapshot', '读取缓存健康看板、子域名和 IP 关系（列表不内嵌检查历史）', 'assets:read', null, [], 'api',
            self::object(['assets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Asset']], 'summary' => ['type' => 'object'], 'cache' => ['type' => 'object']]));
        $add('GET', '/api/health/history', 'getAssetHistory', '读取单资产最近 10 次检查历史；列表出口不内嵌历史，详情按需读取', 'assets:read', null,
            ['asset_id' => ['type' => 'string', 'description' => '资产 ID，从 /api/health 的 assets[].id 读取']], 'api',
            self::object(['ok' => $boolean, 'asset_id' => $string, 'history' => ['type' => 'array', 'items' => ['type' => 'object']]]));
        $operationItem = self::object(['id' => $string, 'domain' => $string, 'provider' => $string,
            'checked_at' => ['type' => ['string', 'null']], 'health_status' => $string,
            'https_status' => ['type' => 'string', 'enum' => ['healthy', 'unhealthy', 'unknown']],
            'resolved_ips' => ['type' => 'array', 'items' => $string], 'message' => $string]);
        $add('GET', '/api/operations', 'getOperations', '读取待运营域名和最近检查异常域名及解析 IP；未知或过时结果需重新检查', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string,
                'pending_domains' => ['type' => 'array', 'items' => $operationItem],
                'unhealthy_domains' => ['type' => 'array', 'items' => $operationItem]]));
        $add('GET', '/api/health/report', 'getHealthReport', '把缓存健康证据编译成可直接交给 AI 分析的 Markdown 报告；只读，不触发检查', 'assets:read', null,
            ['format' => ['type' => 'string', 'enum' => ['markdown'], 'description' => 'markdown 时直接返回 text/markdown 正文']], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'last_checked_at' => ['type' => ['string', 'null']],
                'summary' => ['type' => 'object'], 'markdown' => ['type' => 'string', 'description' => '完整 Markdown 报告正文'],
                'prompt' => ['type' => 'string', 'description' => '已内嵌报告的完整分析提示词，可直接交给聊天助手'],
                'usage' => $string]));
        $add('GET', '/api/handover', 'getHandover', 'AI 接管交接简报：当前统计、持有权限、下一步动作与操作序列；只读', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'scopes' => ['type' => 'array', 'items' => $string],
                'summary' => ['type' => 'object'], 'unhealthy_domains' => $integer, 'proposals' => ['type' => 'object'],
                'next_actions' => ['type' => 'array', 'items' => ['type' => 'object']], 'playbook' => ['type' => 'array', 'items' => ['type' => 'object']]]));
        $proposalBody = self::object(['account' => ['type' => 'string', 'description' => '云账号 ID，从域名列表的 provider 字段读取'],
            'domain' => ['type' => 'string', 'description' => '该账号台账中的主域名'],
            'action' => ['type' => 'string', 'enum' => ['add', 'update', 'delete']],
            'record_id' => ['type' => 'string', 'description' => 'update / delete 必填，从 DNS 记录列表读取'],
            'record' => self::object(['type' => ['type' => 'string', 'enum' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']],
                'name' => $string, 'value' => $string, 'ttl' => $integer, 'priority' => $integer], ['type', 'name', 'value']),
            'rationale' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 500, 'description' => '变更理由，供管理员审批'],
            'evidence' => ['type' => 'string', 'maxLength' => 300, 'description' => '可选：报告行或检查结果引用']], ['account', 'domain', 'action', 'rationale']);
        $add('POST', '/api/proposals', 'createProposal', '提交待审批的 DNS 变更提案；不直接写云端，管理员批准后才执行', 'proposals:write', $proposalBody, [], 'api',
            self::object(['ok' => $boolean, 'proposal' => ['type' => 'object']]));
        $add('GET', '/api/proposals', 'listProposals', '读取提案列表与状态统计', 'proposals:write', null, [], 'api',
            self::object(['ok' => $boolean, 'counts' => ['type' => 'object'], 'proposals' => ['type' => 'array', 'items' => ['type' => 'object']]]));
        $dailyItem = self::object(['date' => $string, 'checks' => $integer, 'healthy' => $integer, 'unhealthy' => $integer,
            'full_runs' => $integer, 'avg_latency_ms' => ['type' => ['integer', 'null']]]);
        $add('GET', '/api/https-review', 'listHttpsFollowups', '读取 HTTPS 跟进清单（证书未通过、HTTPS 未确认或证据过时的域名，items 含可直接访问的 url），独立于可达性问题；只读', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'counts' => ['type' => 'object'],
                'items' => ['type' => 'array', 'items' => ['type' => 'object']], 'usage' => $string]));
        $add('GET', '/api/trends', 'getTrends', '读取最近 30 天检查趋势（每日检查数、健康 / 异常、平均延迟），供图表展示；只读', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'days' => $integer,
                'daily' => ['type' => 'array', 'items' => $dailyItem],
                'summary' => ['type' => 'object'], 'current' => ['type' => 'object']]));
        $add('GET', '/api/metrics', 'getMetrics', '以 Prometheus 文本格式输出 asset_workspace_* 指标，供监控平台抓取；只读', 'assets:read');
        $issueItem = self::object(['id' => ['type' => 'string', 'description' => '稳定问题标识，可在备注与提案 rationale 中引用'],
            'severity' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']], 'type' => ['type' => 'string', 'enum' => ['unhealthy', 'stale', 'expiring']],
            'asset_id' => $string, 'kind' => ['type' => 'string', 'enum' => ['domain', 'ip']], 'name' => $string, 'provider' => $string,
            'summary' => $string, 'since' => ['type' => ['string', 'null']], 'due' => ['type' => ['string', 'null']],
            'ips' => ['type' => 'array', 'items' => $string],
            'url' => ['type' => ['string', 'null'], 'description' => '本次检查探测的目标 URL，可直接访问核对；IP 为 http://IP/'],
            'dns_path' => ['type' => ['string', 'null'], 'description' => '云托管域名的问题附带 DNS 记录读取路径，manual 为空'],
            'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'label' => ['type' => 'string']]]]]);
        $add('GET', '/api/issues', 'getIssues', '读取分级问题清单（P0 HTTP 异常 / P1 过时 / P2 到期；可达性只看 HTTP），与首页问题中心同源；只读', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'counts' => ['type' => 'object'],
                'issues' => ['type' => 'array', 'items' => $issueItem], 'truncated' => $boolean,
                'severity_labels' => ['type' => 'object'], 'usage' => $string]));
        $add('POST', '/api/proposals/{id}/approve', 'approveProposal', '管理员批准提案：读取云端最新版本后按既有护栏执行 DNS 写入并回读核实', null,
            self::object(), [], 'session');
        $add('POST', '/api/proposals/{id}/reject', 'rejectProposal', '管理员拒绝提案，可附原因（最多 300 字）', null,
            self::object(['reason' => ['type' => 'string', 'maxLength' => 300]]), [], 'session');
        $add('POST', '/api/health/check', 'checkHealth', '检查 1–5 项资产并保存结果', 'checks:run',
            self::object(['asset_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => $string]], ['asset_ids']));
        $add('POST', '/api/health/ips', 'createIpAsset', '登记公网 IP 检查目标', 'assets:write', $ip);
        $add('POST', '/api/health/ips/{id}/location', 'refreshIpLocation', '查询并缓存 IP 归属地；会将 IP 发送给 ipwho.is，失败保留旧结果', 'checks:run');
        $add('POST', '/api/health/ips/{id}/browser-probe', 'recordBrowserProbe', '记录管理员浏览器侧的 IP 探测结果：可达时计入健康状态并标注来源 browser（经管理员浏览器网络/系统代理）；不可达不改变记录。仅管理员会话', null,
            self::object(['reachable' => $boolean, 'ms' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 60000]], ['reachable']), [], 'session');
        $ipUpdate = self::object(['name' => $string, 'note' => $string, 'links' => $links, 'server_provider' => $string, 'configuration' => $string, 'expires_at' => $string]);
        $add('PUT', '/api/health/ips/{id}', 'updateIpAsset', '编辑 IP 资产资料', 'assets:write', $ipUpdate);
        $add('DELETE', '/api/health/ips/{id}', 'deleteIpAsset', '移除手工登记的 IP 目标', 'assets:write');
        $add('PUT', '/api/health/domains/{id}/bindings', 'saveDomainBindings', '保存域名与服务器的人工确认绑定', 'assets:write',
            self::object(['ip_ids' => ['type' => 'array', 'items' => $string]], ['ip_ids']));
        $checkin = self::object(['mood' => ['type' => 'string', 'enum' => ['great', 'good', 'calm', 'tired', 'low']],
            'text' => ['type' => 'string', 'maxLength' => 280]], ['mood', 'text']);
        $taskBody = self::object(['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'title' => ['type' => 'string', 'maxLength' => 120],
            'description' => ['type' => 'string', 'maxLength' => 2000], 'done' => $boolean], ['date', 'title']);
        $runBody = self::object(['counts' => self::object(array_combine(['domain', 'ip', 'subdomain'], array_fill(0, 3,
            self::object(['healthy' => $integer, 'unhealthy' => $integer], ['healthy', 'unhealthy']))), ['counts'])], ['counts']);
        $add('GET', '/api/calendar', 'getCalendar', '读取检查日历的打卡、待办、检查记录与资产概览；仅管理员会话', null, null, ['month' => ['type' => 'string', 'pattern' => '^\d{4}-(0[0-9]|1[0-2])$', 'default' => '当月']], 'session');
        $add('PUT', '/api/calendar/checkins/{date}', 'saveCheckin', '保存当日心情与一句话打卡；仅管理员会话', null, $checkin, [], 'session');
        $add('DELETE', '/api/calendar/checkins/{date}', 'deleteCheckin', '删除指定日期的打卡记录；仅管理员会话', null, null, [], 'session');
        $add('POST', '/api/calendar/tasks', 'createTask', '新增检查待办；仅管理员会话', null, $taskBody, [], 'session');
        $add('PUT', '/api/calendar/tasks/{id}', 'updateTask', '更新检查待办；仅管理员会话', null, $taskBody, [], 'session');
        $add('DELETE', '/api/calendar/tasks/{id}', 'deleteTask', '删除检查待办；仅管理员会话', null, null, [], 'session');
        $add('POST', '/api/calendar/runs', 'addRun', '记录一次完整全局检查的分类统计；仅管理员会话', null, $runBody, [], 'session');
        $add('GET', '/api/domains', 'listDomains', '查询本地缓存域名台账', 'assets:read', null,
            ['provider' => $string, 'q' => $string, 'valid' => ['type' => 'string', 'enum' => ['0', '1']], 'sort' => ['type' => 'string', 'enum' => ['expire', 'expire_desc', 'domain', 'synced']], 'expiring' => $string, 'access' => $string, 'all' => ['type' => 'string', 'enum' => ['1']]]);
        $add('GET', '/api/domains/stats', 'getDomainStats', '读取域名统计', 'assets:read');
        $add('POST', '/api/domains', 'createDomain', '添加本地域名台账，不购买域名', 'assets:write', $domain);
        $add('PUT', '/api/domains', 'updateDomain', '编辑本地域名台账，不修改云端 DNS', 'assets:write', $domain);
        $add('DELETE', '/api/domains', 'deleteDomain', '删除本地域名台账，不删除云端域名', 'assets:write', self::object(['provider' => $string, 'domain' => $string], ['provider', 'domain']));
        $dnsPath = '/api/domains/{provider}/{domain}/records';
        $add('GET', $dnsPath, 'listDnsRecords', '读取并缓存解析记录；refresh=1 显式刷新云端', 'dns:read', null, ['refresh' => ['type' => 'string', 'enum' => ['1']]]);
        $recordAdd = $record;
        $recordAdd['properties'] = clone $record['properties'];
        $recordAdd['properties']->expected_revision = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'DNS 列表返回的 revision；新增前核对整个区域'];
        $recordAdd['required'][] = 'expected_revision';
        $recordUpdate = $record;
        $recordUpdate['properties'] = clone $record['properties'];
        $recordUpdate['properties']->expected_version = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => '目标 DNS 记录的 version；服务端读取云端最新值后核对'];
        $recordUpdate['properties']->ttl = ['type' => 'integer', 'minimum' => 1, 'maximum' => 2147483647, 'description' => '省略保留当前 TTL'];
        $recordUpdate['properties']->priority = ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535, 'description' => '省略保留当前优先级'];
        $recordUpdate['required'] = ['expected_version'];
        $add('POST', $dnsPath, 'createDnsRecord', '按区域版本新增 DNS；只写一次并回读核实', 'dns:write', $recordAdd);
        $add('PUT', $dnsPath . '/{recordId}', 'updateDnsRecord', '核对旧版本后修改 DNS，保留未提交字段，回读核实', 'dns:write', $recordUpdate);
        $add('DELETE', $dnsPath . '/{recordId}', 'deleteDnsRecord', '核对旧版本后删除单条 DNS，保留同组其他记录，回读核实', 'dns:write',
            self::object(['expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']], ['expected_version']));
        foreach ([[$dnsPath, 'post'], [$dnsPath . '/{recordId}', 'put'], [$dnsPath . '/{recordId}', 'delete']] as [$path, $method]) {
            $paths[$path][$method]['responses']['202'] = ['description' => '云端结果未确认；verified:false，保留变更编号并刷新核实，不能直接重试',
                'content' => ['application/json' => ['schema' => self::object(['ok' => $boolean, 'verified' => $boolean, 'status' => $string, 'change_id' => $string, 'message' => $string])]]];
        }
        $add('GET', '/api/dns/changes', 'listDnsChanges', '管理员查看最近 50 条 DNS 原值、拟提交值、回读值及结果（保留 200 条）', null, null, [], 'session');
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
        $add('GET', '/api/access-check', 'getLegacyAccessResults', '读取历史访问检查结果：汇总计数与全部逐域结果（状态、HTTP 码、耗时、检测时间）', 'assets:read');
        $add('POST', '/api/access-check', 'runLegacyAccessCheck', '并发探测全部有效域名能否访问，结果与检测时间持久化并返回全量明细', 'checks:run');
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
        $add('GET', '/api/maintenance/cleanup', 'readCleanupOverview', '读取账号不可用（已删除或停用）的遗留域名记录分组与四类日志（检查历史、DNS 变更、操作记录、同步日志）的计数与最早时间', null, null, [], 'session');
        $add('POST', '/api/maintenance/cleanup/orphans', 'cleanupOrphanDomains', '清理账号不可用的域名记录：delete 删除台账行，manual 转为手工资产；两者都连带清除 DNS 缓存、健康观测与遗留检测数据。仅限已删除或已停用的账号', null,
            self::object(['provider' => $string, 'action' => ['type' => 'string', 'enum' => ['delete', 'manual']], 'domains' => ['type' => 'array', 'items' => $string, 'description' => '可选，缺省表示该账号全部域名']], ['provider', 'action']), [], 'session');
        $add('POST', '/api/maintenance/cleanup/logs', 'cleanupLogsByDimension', '按维度删除日志：store 为 check_events（按域名/时间/全部，保留最近健康状态）、dns_changes（按域名/全部）、audit（按时间/全部）或 sync_log（按类型/时间/全部）', null,
            self::object(['store' => ['type' => 'string', 'enum' => ['check_events', 'dns_changes', 'audit', 'sync_log']],
                'domain' => $string, 'provider' => $string, 'type' => $string,
                'before' => ['type' => 'string', 'description' => '仅删除早于此时间的条目，Y-m-d 或完整时间'],
                'all' => ['type' => 'boolean', 'description' => '清空该类日志全部条目']], ['store']), [], 'session');
        $taskItem = self::object(['id' => $string, 'type' => ['type' => 'string', 'enum' => ['health-check', 'access-check', 'sync']],
            'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'done', 'failed']],
            'progress' => ['type' => 'object'], 'result' => ['type' => ['object', 'null']], 'error' => ['type' => ['string', 'null']],
            'actor' => $string, 'created_at' => $string, 'started_at' => ['type' => ['string', 'null']], 'finished_at' => ['type' => ['string', 'null']]]);
        $add('GET', '/api/tasks', 'listBackgroundTasks', '后台任务队列：当前执行摘要与最近任务；执行由服务器 cron 的 worker（scripts/task-worker.php）逐个认领', null, null, [], 'session',
            self::object(['ok' => $boolean, 'active' => ['type' => ['object', 'null']], 'tasks' => ['type' => 'array', 'items' => $taskItem]]));
        $add('POST', '/api/tasks', 'enqueueBackgroundTask', '把长任务转入后台队列后立即返回；关闭浏览器也会继续执行。health-check=全量健康检查（可限定 kind=domain|ip，不含子域名）、access-check=域名访问检测、sync=域名同步', null,
            self::object(['type' => ['type' => 'string', 'enum' => ['health-check', 'access-check', 'sync']],
                'params' => self::object(['kind' => ['type' => 'string', 'enum' => ['domain', 'ip'], 'description' => '仅 health-check 支持；缺省检查域名与 IP']])]), [], 'session',
            self::object(['ok' => $boolean, 'task' => $taskItem]));
        $planItem = self::object(['id' => $string, 'title' => $string, 'detail' => $string,
            'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'source' => ['type' => 'string', 'enum' => ['agent', 'admin'], 'description' => 'agent=AI 协助提出，admin=工作台手动提出'],
            'status' => ['type' => 'string', 'enum' => ['proposed', 'approved', 'in_progress', 'done', 'rejected']],
            'review_note' => $string, 'result_note' => $string,
            'created_at' => $string, 'updated_at' => $string, 'reviewed_at' => ['type' => ['string', 'null']]]);
        $add('GET', '/api/plans', 'listImprovementPlans', '改进计划：功能点条目与审核状态，待审核优先排序；附带 AI 建议可用性与可选模型（不含密钥）', null, null, [], 'session',
            self::object(['ok' => $boolean, 'items' => ['type' => 'array', 'items' => $planItem],
                'ai' => self::object(['enabled' => $boolean, 'models' => ['type' => 'array', 'items' => $string], 'default' => $string])]));
        $add('POST', '/api/plans', 'createImprovementPlan', '新增改进计划条目（待审核）；同名待处理条目会返回 409', null,
            self::object(['title' => $string, 'detail' => $string, 'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']]], ['title']), [], 'session',
            self::object(['ok' => $boolean, 'item' => $planItem]));
        $add('PUT', '/api/plans/{id}', 'updateImprovementPlan', '编辑条目或推进执行状态：status 仅支持 proposed / in_progress / done（通过 / 驳回走审核接口）', null,
            self::object(['title' => $string, 'detail' => $string, 'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'status' => ['type' => 'string', 'enum' => ['proposed', 'in_progress', 'done']], 'result_note' => ['type' => 'string', 'description' => '执行结果说明，标记完成时填写']]), [], 'session',
            self::object(['ok' => $boolean, 'item' => $planItem]));
        $add('POST', '/api/plans/{id}/review', 'reviewImprovementPlan', '审核计划条目：decision=approve 通过 / reject 驳回（驳回必须附 note 原因）', null,
            self::object(['decision' => ['type' => 'string', 'enum' => ['approve', 'reject']], 'note' => ['type' => 'string', 'description' => '审核意见；驳回时必填']]), ['decision'], 'session',
            self::object(['ok' => $boolean, 'item' => $planItem]));
        $add('POST', '/api/plans/ai-suggest', 'suggestImprovementPlan', 'AI 优化建议：调用已配置的智谱 GLM（config/config.php 的 ai 节点）生成详情 Markdown 改写稿；仅返回建议文本不落盘，由管理员对比后决定是否采用', null,
            self::object(['title' => $string, 'detail' => $string,
                'model' => ['type' => 'string', 'description' => '可选，须在配置的 ai.models 列表内；缺省用 ai.model']], ['title']), [], 'session',
            self::object(['ok' => $boolean, 'suggestion' => $string, 'model' => $string]));
        $add('DELETE', '/api/plans/{id}', 'deleteImprovementPlan', '删除计划条目', null, null, [], 'session',
            self::object(['ok' => $boolean]));
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
