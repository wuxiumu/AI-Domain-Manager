<?php

/** OpenAPI and discovery documents contain contracts only, never deployment secrets. */
class ApiDescriptionService
{
    public const VERSION = '1.3.0';

    public static function discovery(): array
    {
        return ['name' => 'Asset Workspace', 'version' => self::VERSION, 'description' => '域名与 IP 资产大屏 + DNS 管理，AI 可读可操作',
            'openapi_url' => '/api/openapi.json', 'instructions_url' => '/llms.txt', 'ai_bootstrap_md' => '/api/ai/bootstrap.md',
            'authentication' => ['recommended' => 'bearer', 'header' => 'Authorization: Bearer <token>',
                'provisioning' => '管理员登录 → /settings → AI API → 创建密钥',
                'session_login' => '/api/login', 'session_captcha' => '/api/login/captcha', 'session_write_header' => 'X-CSRF-Token'],
            'scopes' => ApiTokenService::SCOPES,
            'entrypoints' => ['domain_screen' => '/api/domains/screen', 'domain_screen_md' => '/api/domains/screen.md', 'domain_screen_report' => '/api/domains/screen/report', 'ip_screen' => '/api/ips/screen', 'ip_screen_md' => '/api/ips/screen.md', 'domains' => '/api/domains', 'proposals' => '/api/proposals', 'accounts' => '/api/accounts'],
            'cache' => '列表优先读取文件快照；同步、检查与刷新 DNS 需要显式调用；DNS 写入会刷新缓存。',
            'providers' => array_column(SettingsService::providerSchema(), 'id')];
    }

    public static function instructions(): string
    {
        return <<<'TEXT'
# Asset Workspace API

Domain & IP asset wall display with DNS management. AI can read the inventory and operate DNS under scoped permission.

Discover operations and request schemas at GET /api/openapi.json.
Machine discovery: GET /.well-known/asset-workspace.json (alias /api/capabilities).
One-file onboarding: GET /api/ai/bootstrap.md bundles these instructions, the capability list,
the write-guardrail cheat-sheet and BOTH view snapshots (domains + IPs) as a single Markdown document.
All URLs are relative to this installation's origin.

Authentication:
- The administrator creates an expiring, scoped API token on the /settings page (or via POST /api/tokens).
- Send Authorization: Bearer <token> on each request. No cookies or CSRF header are needed with a valid token.
- Interactive password login requires GET /api/login/captcha first. Keep its cookie, display the returned image to a human,
  then POST /api/login with user, pass, captcha_id and captcha, plus the challenge csrf_token as X-CSRF-Token.
  The challenge expires in 120 seconds and is consumed by one attempt. Refresh it after every failure.
  Keep the successful session cookie and use the new csrf_token as X-CSRF-Token for subsequent writes.
  Password login and captcha fetching are IP rate limited (HTTP 429, Retry-After). Automation should use scoped API tokens.
- Passwords and cloud secrets cannot be read back. Configuration, credential changes and token creation require an administrator session.
- Tokens expire, can be revoked, and are invalidated when administrator login credentials change.

Workflow:
1. GET /api/domains/screen is the wall-display snapshot: unexpired domains with their subdomain A records (IPv4),
   per-record access status, expire dates and account grouping. Read-only cache snapshot, never triggers cloud I/O;
   generated_at and cache.saved_at mark the cache time.
2. GET /api/domains/screen.md is the same snapshot as one plain Markdown document (per-domain A records, access
   status and a CRUD playbook with exact endpoints, bodies and scopes) — feed it to an AI as a single file.
3. GET /api/domains/screen/report is the daily anomaly report: expiring soon, failed access checks, cold DNS cache,
   non-public A values, shared IPs; add ?format=markdown for raw text. scripts/generate-screen-report.php prints it
   for daily cron (--prompt wraps the analysis instructions, --digest prints the Markdown snapshot).
4. GET /api/ips/screen is the IP view of the wall display: public IPs aggregated from cached A records plus manually
   registered IPs (data: ip, sources, domains with access status, worst-of status, cached ipwho.is location). Read-only cache snapshot.
   GET /api/ips/screen.md is that IP view as one plain Markdown document — feed it to an AI as a single file.
   Manage manual IPs with assets:write: POST /api/ips {"ip":"192.0.2.10","port":443,"name":"...","note":"..."} (public IPs only, no network request), PUT/DELETE /api/ips/{id}.
   POST /api/ips/{ip}/location (checks:run) explicitly refreshes one IP's geolocation via ipwho.is; the result enters the cache and appears in the next snapshot read.
5. GET /api/domains for the full local ledger (including expired domains). provider means a cloud ACCOUNT ID, not a vendor name.
6. GET /api/domains/{provider}/{domain}/records for cached DNS records and their IDs.
7. Preferred write path — submit a proposal instead of writing DNS directly:
   POST /api/proposals with {"account":"<provider id>","domain":"example.com","action":"add|update|delete",
   "record_id":"<required for update/delete>","record":{"type":"A","name":"@","value":"192.0.2.1"},
   "rationale":"4-500 char reason","evidence":"optional report line or check reference"}. Scope proposals:write.
   The administrator approves or rejects (session API: POST /api/proposals/{id}/approve|reject).
   Approval reads the fresh cloud version, writes once and verifies by readback (same guards as manual edits).
   GET /api/proposals (proposals:write) tracks status: pending / executed / failed / rejected. Duplicate pending changes are rejected with 409.
8. Direct DNS editing (dns:write) stays available for trusted automation but the proposal queue is the recommended path:
   first read the record and show the before/after difference, then PUT /api/domains/{provider}/{domain}/records/{recordId}
   with expected_version from that record's version and only the fields to change. DELETE also requires expected_version in its JSON body.
   POST requires expected_revision from the list response's revision plus the new record fields.
   A fresh cloud read rejects stale versions with HTTP 409. HTTP 202 / verified:false means unconfirmed; do not retry writes automatically.
   Only verified:true confirms cloud readback, not worldwide DNS propagation.
   POST /api/domains/{provider}/{domain}/records/{recordId}/status (dns:write) pauses or resumes one record
   with {"paused":true|false,"expected_version":"..."} — the record is kept but stops resolving (Aliyun / Tencent only; GoDaddy returns an error).
   Paused records appear with a paused flag in the screen snapshots.
   POST /api/domains/{provider}/{domain}/records/access-check (checks:run) probes the hosts of the zone's records and refreshes access status;
   add {"record_ids":["<id>",...]} to probe only the selected records.
9. POST /api/sync explicitly refreshes the cloud domain inventory. Requires sync:run.
   POST /api/accounts/{id}/sync syncs one cloud account the same way; add {"refresh_dns":true} to also refetch cached DNS records for its unexpired domains (may take minutes).

MCP (Model Context Protocol):
- scripts/mcp-server.py exposes this API as MCP tools over stdio (zero-dependency): get_domain_screen,
  get_ip_screen, list_dns_records, get_daily_report, list_docs and propose_dns_change.
- Configure with WORKSPACE_URL (server origin) and WORKSPACE_API_TOKEN; the token's scopes gate every tool
  (reads need assets:read, propose_dns_change needs proposals:write and the server rejects calls without the scope).
- propose_dns_change validates required fields before submitting and never writes cloud DNS directly;
  the administrator approves or rejects each proposal.

Interpretation:
- Reads reuse file caches. Do not sync, refresh DNS, run checks or write DNS just to display a list.
- DNS-derived IPs may be CDN/proxy addresses. Manual registrations and DNS discovery have different meanings; an IP can be both.
- unchecked and stale results are not healthy results. Access checks request https://fqdn/ (verified TLS) and http://<ip>/ without following redirects; each record result carries https_status (ok = HTTPS with a valid certificate, in production; fail = no HTTPS or invalid certificate) and https_expires (certificate expiry date for renewal reminders).
- Treat HTTP errors and JSON ok:false as failures; some legacy cloud endpoints return ok:false with HTTP 200.
- Use URL-encoded path segments. Do not invent account, record or asset IDs.
- Never include passwords, cloud secrets or bearer tokens in a URL, a shared report, or logs.
TEXT;
    }

    /** AI 一次请求的自包含接入文档：使用说明 + 能力清单 + 写护栏速查 + 域名/IP 双视图快照。 */
    public static function bootstrapMarkdown(): string
    {
        $scopes = [];
        foreach (ApiTokenService::SCOPES as $scope => $desc) {
            $scopes[] = '| `' . $scope . '` | ' . $desc . ' |';
        }
        $lines = [
            '# Asset Workspace · AI Bootstrap（一次请求完成接入）',
            '',
            '本文档是自包含接入包：认证与工作流说明 + 能力清单 + 写护栏速查 + 域名/IP 双视图 Markdown 快照。',
            '读完本文即可完成「读台账 + 提交变更提案」全流程；所有 URL 相对本安装源；读取均为缓存快照，不会触发云请求。',
            '生成时间：' . date('Y-m-d H:i:s') . ' · 版本：' . self::VERSION . '。',
            '',
            '---',
            '',
            '## 第一部分 · 使用说明（与 GET /llms.txt 内容一致）',
            '',
            self::instructions(),
            '',
            '---',
            '',
            '## 第二部分 · 能力清单（与 GET /.well-known/asset-workspace.json 一致）',
            '',
            '```json',
            json_encode(self::discovery(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '```',
            '',
            '## 第三部分 · 权限范围（scope）',
            '',
            '| scope | 说明 |',
            '| --- | --- |',
            implode("\n", $scopes),
            '',
            '## 第四部分 · 写护栏速查（写操作前必读）',
            '',
            '1. 首选提案流（`proposals:write`）：POST /api/proposals 提交，管理员批准后才执行；重复待审提案返回 409。',
            '2. 直接写（`dns:write`）必须携带目标记录的 `expected_version`（新增用列表响应的 `expected_revision`）；',
            '   云端版本不符返回 **HTTP 409**（未写入，请刷新后重试）；',
            '   **HTTP 202 / `verified:false`** 表示结果未核实——不要自动重试，稍后刷新核对；',
            '   只有 **`verified:true`** 表示已回读云端核实（不等于全球 DNS 传播完成）。',
            '3. 每次写只改一条记录；同一区域先读后写；绝不自动重试写操作。',
            '4. 暂停 / 恢复用 POST .../records/{id}/status（`dns:write`，body {paused, expected_version}）；GoDaddy 不支持。',
            '',
            '---',
            '',
            '## 第五部分 · 域名视图快照（GET /api/domains/screen.md 的当前内容）',
            '',
            ScreenService::markdown(DATA_DIR, config('accounts')),
            '',
            '---',
            '',
            '## 第六部分 · IP 视图快照（GET /api/ips/screen.md 的当前内容）',
            '',
            IpScreenService::markdown(DATA_DIR, config('accounts')),
            '',
            '---',
            '',
            '*本文档由 GET /api/ai/bootstrap.md 生成（`assets:read`）；内容随缓存刷新而变化，AI 应以最新一次读取为准。*',
        ];
        return implode("\n", $lines) . "\n";
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
            if (in_array($id, ['createCloudAccount', 'createApiToken'], true)) { $op['responses']['201'] = $op['responses']['200']; unset($op['responses']['200']); }
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
        $add('POST', '/api/proposals/{id}/approve', 'approveProposal', '管理员批准提案：读取云端最新版本后按既有护栏执行 DNS 写入并回读核实', null,
            self::object(), [], 'session');
        $add('POST', '/api/proposals/{id}/reject', 'rejectProposal', '管理员拒绝提案，可附原因（最多 300 字）', null,
            self::object(['reason' => ['type' => 'string', 'maxLength' => 300]]), [], 'session');
        $checkin = self::object(['mood' => ['type' => 'string', 'enum' => ['great', 'good', 'calm', 'tired', 'low']],
            'text' => ['type' => 'string', 'maxLength' => 280]], ['mood', 'text']);
        $taskBody = self::object(['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'title' => ['type' => 'string', 'maxLength' => 120],
            'description' => ['type' => 'string', 'maxLength' => 2000], 'done' => $boolean], ['date', 'title']);
        $runBody = self::object(['counts' => self::object(array_combine(['domain', 'ip', 'subdomain'], array_fill(0, 3,
            self::object(['healthy' => $integer, 'unhealthy' => $integer], ['healthy', 'unhealthy']))), ['counts'])], ['counts']);
        $add('GET', '/api/calendar', 'getCalendar', '读取日历的打卡、待办与完整检查运行记录；仅管理员会话', null, null, ['month' => ['type' => 'string', 'pattern' => '^\d{4}-(0[0-9]|1[0-2])$', 'default' => '当月']], 'session');
        $add('PUT', '/api/calendar/checkins/{date}', 'saveCheckin', '保存当日心情与一句话打卡；仅管理员会话', null, $checkin, [], 'session');
        $add('DELETE', '/api/calendar/checkins/{date}', 'deleteCheckin', '删除指定日期的打卡记录；仅管理员会话', null, null, [], 'session');
        $add('POST', '/api/calendar/tasks', 'createTask', '新增检查待办；仅管理员会话', null, $taskBody, [], 'session');
        $add('PUT', '/api/calendar/tasks/{id}', 'updateTask', '更新检查待办；仅管理员会话', null, $taskBody, [], 'session');
        $add('DELETE', '/api/calendar/tasks/{id}', 'deleteTask', '删除检查待办；仅管理员会话', null, null, [], 'session');
        $add('POST', '/api/calendar/runs', 'addRun', '记录一次完整全局检查的分类统计；仅管理员会话', null, $runBody, [], 'session');
        $add('GET', '/api/domains', 'listDomains', '查询本地缓存域名台账（含已过期域名）', 'assets:read', null,
            ['provider' => $string, 'q' => $string, 'valid' => ['type' => 'string', 'enum' => ['0', '1']], 'sort' => ['type' => 'string', 'enum' => ['expire', 'expire_desc', 'domain', 'synced']], 'expiring' => $string, 'all' => ['type' => 'string', 'enum' => ['1']]]);
        $add('GET', '/api/domains/stats', 'getDomainStats', '读取域名统计', 'assets:read');
        $screenRecord = self::object(['id' => $string, 'value' => $string, 'ttl' => ['type' => ['integer', 'null']],
            'version' => ['type' => 'string', 'description' => '修改 / 删除该记录时作为 expected_version 提交'],
            'access' => ['type' => ['object', 'null'], 'description' => '最近一次后端访问检测结果：status http_code ms checked_at']]);
        $screenSubdomain = self::object(['name' => ['type' => 'string', 'description' => '相对主机记录，根记录为 @'],
            'fqdn' => $string, 'ips' => ['type' => 'array', 'items' => $string, 'description' => 'A 记录 IPv4 值'],
            'ttl' => ['type' => ['integer', 'null'], 'description' => '组内最小 TTL'],
            'records' => ['type' => 'array', 'items' => $screenRecord, 'description' => '组内 A 记录明细，供 DNS 修改 / 删除使用'],
            'access' => ['type' => ['string', 'null'], 'description' => '组级访问状态 ok / fail / null：任一记录检测通过即 ok']]);
        $screenDomain = self::object(['domain' => $string, 'provider' => ['type' => 'string', 'description' => '云账号 ID'],
            'provider_name' => $string, 'registrar' => $string,
            'expire_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD 或空字符串'],
            'days_left' => ['type' => ['integer', 'null'], 'description' => '剩余天数；null 表示未知到期，均不含已过期域名'],
            'synced_at' => $string, 'dns_cached' => $boolean, 'dns_snapshot_at' => ['type' => ['string', 'null']],
            'dns_revision' => ['type' => ['string', 'null'], 'description' => '解析区域当前 revision；批量添加记录时作为 expected_revision 提交，成功响应会返回新 revision'],
            'subdomain_count' => $integer, 'a_count' => $integer,
            'other_count' => ['type' => 'integer', 'description' => '非 A 记录条数，仅计数不展开'],
            'subdomains' => ['type' => 'array', 'items' => $screenSubdomain]], ['domain', 'provider', 'subdomains']);
        $add('GET', '/api/domains/screen', 'getDomainScreen', '大屏聚合：未过期域名及其子域名 A 记录（只读缓存快照，generated_at 与 cache.saved_at 即缓存时间）', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'cache' => ['type' => 'object'],
                'stats' => self::object(['domains' => $integer, 'subdomains' => $integer, 'a_records' => $integer,
                    'hidden' => self::object(['expired' => $integer, 'invalid' => $integer, 'orphaned' => $integer])], ['domains']),
                'accounts' => ['type' => 'array', 'items' => self::object(['id' => $string, 'name' => $string, 'provider' => $string,
                    'domains' => $integer, 'a_records' => $integer, 'subdomains' => $integer, 'dns_pending' => $integer,
                    'dns_snapshot_at' => ['type' => ['string', 'null']]], ['id', 'name'])],
                'domains' => ['type' => 'array', 'items' => $screenDomain]]));
        $add('GET', '/api/domains/screen.md', 'getDomainScreenMarkdown', '纯 Markdown 快照：域名、子域名 A 记录、访问状态与可执行的 CRUD 指引，一份文件可直接交给 AI 阅读与操作', 'assets:read', null, [], 'api',
            self::object(['markdown' => $string]));
        $add('GET', '/api/domains/screen/report', 'getDomainScreenReport', '每日异常报告（只读快照编排）：到期预警、访问异常、解析缺口、非公网记录与解读指引；?format=markdown 直出正文，适合 AI 每日分析', 'assets:read', null,
            ['format' => ['type' => 'string', 'enum' => ['markdown'], 'description' => 'markdown 时直接返回 text/markdown 正文']], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'summary' => ['type' => 'object'],
                'markdown' => $string, 'prompt' => ['type' => 'string', 'description' => '已内嵌报告与完整分析要求的提示词，可直接交给 AI'],
                'usage' => $string]));
        $add('POST', '/api/domains', 'createDomain', '添加本地域名台账，不购买域名', 'assets:write', $domain);
        $add('PUT', '/api/domains', 'updateDomain', '编辑本地域名台账，不修改云端 DNS', 'assets:write', $domain);
        $add('DELETE', '/api/domains', 'deleteDomain', '删除本地域名台账，不删除云端域名', 'assets:write', self::object(['provider' => $string, 'domain' => $string], ['provider', 'domain']));
        $ipScreenDomain = self::object(['provider' => $string, 'provider_name' => $string, 'domain' => $string,
            'fqdn' => $string, 'name' => $string, 'record_id' => $string,
            'access' => ['type' => ['string', 'null'], 'enum' => ['ok', 'fail', 'na', null], 'description' => '最近一次访问检查结果；null=未检查，未检查不等于正常'],
            'http_code' => ['type' => ['integer', 'null']], 'checked_at' => ['type' => ['string', 'null']]]);
        $ipScreenRow = self::object(['ip' => $string, 'manual' => $boolean, 'name' => ['type' => ['string', 'null']],
            'note' => ['type' => ['string', 'null']], 'port' => ['type' => ['integer', 'null']],
            'sources' => ['type' => 'array', 'items' => $string, 'description' => '指向此 IP 的云账号 ID 列表；手工登记含 manual'],
            'domain_count' => $integer, 'records' => $integer,
            'domains' => ['type' => 'array', 'items' => $ipScreenDomain],
            'status' => ['type' => ['string', 'null'], 'enum' => ['ok', 'fail', null], 'description' => '关联记录的最差访问结果；空=未检查，未检查不等于正常'],
            'access_ok' => $integer, 'access_fail' => $integer, 'checked_at' => ['type' => ['string', 'null']],
            'location' => ['type' => ['object', 'null'], 'description' => '最近一次 ipwho.is 归属地缓存，可能是 CDN 出口']],
            ['ip', 'manual', 'sources']);
        $ipAsset = self::object(['ip' => $string, 'port' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65535],
            'name' => $string, 'note' => $string], ['ip']);
        $add('GET', '/api/ips/screen', 'getIpScreen', 'IP 大屏聚合：DNS A 记录自动发现的公网 IP 与手工登记 IP 合并（只读缓存快照，含归属地缓存与访问状态）', 'assets:read', null, [], 'api',
            self::object(['ok' => $boolean, 'generated_at' => $string, 'cache' => ['type' => 'object'],
                'stats' => self::object(['ips' => $integer, 'manual' => $integer, 'auto' => $integer, 'countries' => $integer,
                    'records' => $integer, 'domains_linked' => $integer], ['ips']),
                'accounts' => ['type' => 'array', 'items' => self::object(['id' => $string, 'name' => $string, 'provider' => $string,
                    'ips' => $integer, 'records' => $integer], ['id', 'name'])],
                'ips' => ['type' => 'array', 'items' => $ipScreenRow]]));
        $add('GET', '/api/ips/screen.md', 'getIpScreenMarkdown', '纯 Markdown 快照：IP 清单、归属地、访问状态与关联域名，一份文件可直接交给 AI 阅读', 'assets:read', null, [], 'api',
            self::object(['markdown' => $string]));
        $add('POST', '/api/ips', 'createIpAsset', '手工登记一个公网 IP（拒绝私有/保留地址），不发起任何网络请求', 'assets:write', $ipAsset);
        $add('PUT', '/api/ips/{id}', 'updateIpAsset', '编辑手工登记 IP 的名称、备注、端口或地址', 'assets:write', $ipAsset);
        $add('DELETE', '/api/ips/{id}', 'deleteIpAsset', '删除手工登记 IP；DNS 自动发现不受影响', 'assets:write');
        $add('POST', '/api/ips/{ip}/location', 'refreshIpLocation', '显式刷新该 IP 的归属地（ipwho.is），结果进入缓存', 'checks:run');
        $add('POST', '/api/ips/check', 'checkIps', '服务端并发探测指定 IP 列表的访问状态（http://IP/，不跟随跳转），结果全局缓存并整合进 IP 视图访问状态', 'checks:run',
            self::object(['ips' => ['type' => 'array', 'items' => $string, 'maxItems' => 50, 'description' => '公网 IP 列表']], ['ips']));
        $add('POST', '/api/ips/browser-check', 'reportIpBrowserCheck', '上报大屏前端检查的 IP 访问结果（浏览器直连 http://IP/），全局缓存；结果整合进 IP 视图访问状态', 'checks:run',
            self::object(['results' => ['type' => 'object', 'description' => 'ip => {ok: 是否可达, ms?: 耗时毫秒}']], ['results']));
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
        $add('POST', $dnsPath . '/{recordId}/status', 'setDnsRecordStatus', '暂停 / 恢复单条解析记录（记录保留、停止或恢复解析；需 expected_version；阿里云与腾讯云支持，GoDaddy 不支持并返回错误）', 'dns:write',
            self::object(['paused' => $boolean, 'expected_version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']], ['paused', 'expected_version']));
        foreach ([[$dnsPath, 'post'], [$dnsPath . '/{recordId}', 'put'], [$dnsPath . '/{recordId}', 'delete']] as [$path, $method]) {
            $paths[$path][$method]['responses']['202'] = ['description' => '云端结果未确认；verified:false，保留变更编号并刷新核实，不能直接重试',
                'content' => ['application/json' => ['schema' => self::object(['ok' => $boolean, 'verified' => $boolean, 'status' => $string, 'change_id' => $string, 'message' => $string])]]];
        }
        $add('POST', $dnsPath . '/browser-check', 'reportBrowserCheck', '上报大屏前端检查结果（浏览器直连探测，按 FQDN），与解析快照版本对齐后全局缓存；结果整合进大屏访问状态与检查时间', 'checks:run',
            self::object(['revision' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'DNS 列表返回的区域 revision'],
                'results' => ['type' => 'object', 'description' => 'fqdn => {ok: 是否可达}']], ['revision', 'results']));
        $add('POST', $dnsPath . '/access-check', 'checkDnsHosts', '检查解析记录对应的主机访问状态；body.record_ids 可选（从记录列表读取），只探测指定记录，缺省探测该域名全部记录。每条记录结果含 https_status（ok=HTTPS 证书有效可达，已上线运营；fail=未启用 HTTPS 或证书无效）与 https_expires（证书到期日 Y-m-d，续期提醒），随 records 列表的 access 字段透出', 'checks:run',
            self::object(['record_ids' => ['type' => 'array', 'items' => $string, 'description' => '可选，只检查这些记录 ID；勾选范围检查场景使用']]));
        $add('POST', $dnsPath . '/https-check', 'checkHttps', 'HTTPS 专题检查：仅探测已通过 HTTP 访问检查的记录的 https://主机名（严格 TLS + 证书到期），结果就地合并；未通过 HTTP 检查的记录跳过并在响应 skipped_no_http 计数；body.record_ids 可选', 'checks:run',
            self::object(['record_ids' => ['type' => 'array', 'items' => $string, 'description' => '可选，只检查这些记录 ID']]));
        $add('GET', '/api/accounts', 'listCloudAccounts', '列出云账号标识和脱敏信息', 'assets:read', null, [], 'api', ['type' => 'array', 'items' => ['type' => 'object']]);
        $add('POST', '/api/accounts', 'createCloudAccount', '添加云账号配置', null, $account, [], 'session');
        $accountUpdate = $account; unset($accountUpdate['required'], $accountUpdate['oneOf']);
        $add('PUT', '/api/accounts/{id}', 'updateCloudAccount', '编辑云账号；密钥留空保留，enabled=false 停用', null, $accountUpdate, [], 'session');
        $add('DELETE', '/api/accounts/{id}', 'deleteCloudAccount', '删除云账号配置，保留资产与缓存', null, null, [], 'session');
        $add('POST', '/api/accounts/{id}/test', 'testCloudAccount', '测试云账号连接', 'sync:run');
        $add('POST', '/api/accounts/{id}/sync', 'syncCloudAccount', '同步指定云账号的域名台账；refresh_dns=true 时同时刷新该账号未过期域名的解析缓存（可能耗时数分钟）', 'sync:run',
            self::object(['refresh_dns' => $boolean]), [], 'api',
            self::object(['ok' => $boolean, 'account' => ['type' => 'object'],
                'domains' => self::object(['count' => $integer, 'new' => $integer, 'stale' => $integer, 'latency_ms' => $integer]),
                'dns' => ['type' => ['object', 'null'], 'description' => 'refresh_dns 时的解析缓存刷新结果；pending 为超预算未刷新的域名'],
                'latency_ms' => $integer]));
        $add('POST', '/api/test-all', 'testAllCloudAccounts', '测试全部云账号连接', 'sync:run', null, [], 'api', ['type' => 'array', 'items' => ['type' => 'object']]);
        $add('POST', '/api/sync', 'syncDomains', '显式同步云端域名列表', 'sync:run');
        $add('GET', '/api/sync/logs', 'getSyncLogs', '读取同步记录', 'assets:read', null, ['limit' => $integer]);
        $add('GET', '/api/settings', 'getWorkspaceSettings', '读取当前配置状态和可配置字段（不回显秘密）', null, null, [], 'session');
        $add('GET', '/api/dns/changes', 'listDnsChanges', '读取 DNS 变更记录（最近 50 条：原值、拟提交值、回读值、结果与操作者），供设置页与管理员核对', null, null, [], 'session');
        $add('GET', '/api/audit', 'listAuditLog', '读取操作审计记录（最近 100 条：已通过登录与权限校验的写入请求，不含请求正文与秘密）', null, null, [], 'session');
        $add('GET', '/api/docs', 'listDocs', '列出仓库文档白名单（README / AGENTS / CHANGELOG / tasks.md 与 docs/ 全部 Markdown）的路径、标题、大小与更新时间', null, null, [], 'session');
        $add('GET', '/api/docs/read', 'readDoc', '读取单篇白名单文档的 Markdown 正文；path 必须命中清单，越界路径返回 404', null, null,
            ['path' => ['type' => 'string', 'description' => '文档相对路径，如 CHANGELOG.md 或 docs/00-目录.md']], 'session');
        $docBody = self::object(['path' => ['type' => 'string', 'description' => 'docs/ 内以 .md 结尾的相对路径'],
            'content' => ['type' => 'string', 'description' => 'Markdown 正文，最大 2 MB']], ['path', 'content']);
        $add('POST', '/api/docs', 'createDoc', '在 docs/ 内新建 Markdown 文档（同名返回 409；父目录不存在时自动创建；仅管理员会话）', null, $docBody, [], 'session');
        $add('PUT', '/api/docs', 'saveDoc', '覆盖保存白名单文档（原子写入；根目录 README / AGENTS / CHANGELOG / tasks.md 可编辑；仅管理员会话）', null, $docBody, [], 'session');
        $add('DELETE', '/api/docs', 'deleteDoc', '删除 docs/ 内的 Markdown 文档（根目录白名单文件受保护，返回 403；仅管理员会话）', null,
            self::object(['path' => $string], ['path']), [], 'session');
        $add('GET', '/api/stats', 'getSystemStats', '系统概况：代码规模（app / public / scripts / tests 的文件数、行数、磁盘占用）、数据目录大小与 PHP 运行时内存（只读，仅管理员会话）', null, null, [], 'session');
        $add('PUT', '/api/settings/screen', 'updateScreenRefresh', '更新大屏刷新配置：是否自动刷新与轮询周期（60–3600 秒）', null,
            self::object(['auto_refresh' => $boolean, 'refresh_seconds' => ['type' => 'integer', 'minimum' => 60, 'maximum' => 3600]]), [], 'session');
        $add('PUT', '/api/settings/http', 'updateHttpSettings', '更新云商接口代理与超时', null,
            self::object(['proxy' => $secret, 'clear_proxy' => $boolean, 'timeout' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 120], 'connect_timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30]]), [], 'session');
        $add('PUT', '/api/auth/credentials', 'updateAdminCredentials', '修改管理员账号和密码；会话和旧 API 密钥失效', null,
            self::object(['current_password' => $secret, 'user' => $string, 'password' => $secret], ['current_password', 'user', 'password']), [], 'session');
        $add('GET', '/api/tokens', 'listApiTokens', '列出密钥元数据和可授权范围', null, null, [], 'session');
        $add('POST', '/api/tokens', 'createApiToken', '创建 API 密钥，仅此次返回明文；响应附带 ai_usage（认证头、bootstrap 地址与可直接复制的 curl 示例），可整段交给 AI 完成接入', null,
            self::object(['current_password' => $secret, 'name' => $string, 'scopes' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => array_keys(ApiTokenService::SCOPES)]],
                'expires_in_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 90]], ['name', 'current_password']), [], 'session');
        $add('DELETE', '/api/tokens/{id}', 'revokeApiToken', '撤销 API 密钥，立即停止后续调用', null, null, [], 'session');
        $add('GET', '/api/openapi.json', 'getOpenApi', '读取完整接口契约', null, null, [], 'public');
        $add('GET', '/api/capabilities', 'getCapabilities', '读取 AI 能力与认证说明', null, null, [], 'public');
        $add('GET', '/api/ai/bootstrap.md', 'getAiBootstrap', 'AI 一次请求完成接入的自包含 Markdown：使用说明 + 能力清单 + 写护栏速查 + 域名/IP 双视图当前快照', 'assets:read', null, [], 'api');
        $add('GET', '/api/env-check', 'checkRuntimeEnvironment', '读取环境自检（初始化后仅管理员会话）', null, null, [], 'session');
        return ['openapi' => '3.1.0', 'info' => ['title' => 'Asset Workspace API', 'version' => self::VERSION,
                'description' => '缓存优先的域名 / IP 资产大屏与 DNS 管理。使用 Bearer API 密钥或登录 Session；云配置与登录密码不能读回。'],
            'servers' => [['url' => '/']], 'paths' => $paths, 'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'aw_<64 hex>', 'description' => '在 /settings 创建，按 scopes 授权'],
                    'sessionCookie' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => session_name(), 'description' => '先获取图形验证码，再 POST /api/login；保留 Cookie，写请求另传 X-CSRF-Token']]]];
    }
}
