<?php
/** 单入口：路由分发（同时作为 php -S 的路由脚本，静态文件直接放行） */

$reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Execute only the front controller, including with the local PHP development router.
if ($reqPath === '/index.php') { $reqPath = '/'; }
$staticPath = realpath(__DIR__ . $reqPath);
$isApiPath = strpos($reqPath, '/api/') === 0;
$escapedPublicRoot = $staticPath !== false && strpos($staticPath, realpath(__DIR__) . DIRECTORY_SEPARATOR) !== 0 && $staticPath !== realpath(__DIR__);
$isPhpFile = $staticPath !== false && is_file($staticPath) && strtolower(pathinfo($staticPath, PATHINFO_EXTENSION)) === 'php';
if ((!$isApiPath && preg_match('/\.php(?:\/|$)/i', $reqPath)) || $isPhpFile || $escapedPublicRoot) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '页面不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Static files may only resolve inside public/; symlinks cannot escape the document root.
if (!$isApiPath && $reqPath !== '/' && $staticPath !== false && is_file($staticPath)) {
    return false;
}

require __DIR__ . '/../app/bootstrap.php';

$router = new Router();
$router->get('/api/openapi.json', [ApiDescriptionController::class, 'openapi']);
$router->get('/api/capabilities', [ApiDescriptionController::class, 'discovery']);
$router->get('/.well-known/asset-workspace.json', [ApiDescriptionController::class, 'discovery']);
$router->get('/llms.txt', [ApiDescriptionController::class, 'instructions']);
$router->get('/api/ai/bootstrap.md', [ApiDescriptionController::class, 'bootstrap']);

// 鉴权
$router->get('/api/dns/changes', [RecordController::class, 'changes']);
$router->get('/api/audit', [DocsController::class, 'audit']);
$router->get('/api/docs', [DocsController::class, 'index']);
$router->get('/api/docs/read', [DocsController::class, 'read']);
$router->get('/api/stats', [DocsController::class, 'stats']);
$router->post('/api/docs', [DocsController::class, 'create']);
$router->put('/api/docs', [DocsController::class, 'save']);
$router->delete('/api/docs', [DocsController::class, 'remove']);
$router->get('/api/login/captcha', [AuthController::class, 'captcha']);
$router->post('/api/login',  [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout']);
$router->get('/api/me',      [AuthController::class, 'me']);
$router->get('/api/setup/status', [SettingsController::class, 'status']);
$router->post('/api/setup', [SettingsController::class, 'setup']);
$router->get('/api/settings', [SettingsController::class, 'index']);
$router->put('/api/settings/http', [SettingsController::class, 'http']);
$router->put('/api/settings/screen', [SettingsController::class, 'screen']);
$router->put('/api/auth/credentials', [SettingsController::class, 'credentials']);
$router->get('/api/tokens', [SettingsController::class, 'tokens']);
$router->post('/api/tokens', [SettingsController::class, 'createToken']);
$router->delete('/api/tokens/{id}', [SettingsController::class, 'revokeToken']);

// AI DNS 变更提案：AI 密钥提交，管理员批准后才经 DnsChangeService 执行。
$router->get('/api/proposals', [ProposalController::class, 'index']);
$router->post('/api/proposals', [ProposalController::class, 'create']);
$router->post('/api/proposals/{id}/approve', [ProposalController::class, 'approve']);
$router->post('/api/proposals/{id}/reject', [ProposalController::class, 'reject']);

// 日历：打卡、待办与完整检查运行记录（独立 /calendar 页）。
$router->get('/api/calendar', [CalendarController::class, 'index']);
$router->put('/api/calendar/checkins/{date}', [CalendarController::class, 'saveCheckin']);
$router->delete('/api/calendar/checkins/{date}', [CalendarController::class, 'deleteCheckin']);
$router->post('/api/calendar/tasks', [CalendarController::class, 'createTask']);
$router->put('/api/calendar/tasks/{id}', [CalendarController::class, 'updateTask']);
$router->delete('/api/calendar/tasks/{id}', [CalendarController::class, 'deleteTask']);
$router->post('/api/calendar/runs', [CalendarController::class, 'addRun']);

// 账号与密钥测试
$router->get('/api/accounts',            [AccountController::class, 'index']);
$router->post('/api/accounts',           [AccountController::class, 'create']);
$router->put('/api/accounts/{id}',       [AccountController::class, 'update']);
$router->delete('/api/accounts/{id}',    [AccountController::class, 'delete']);
$router->post('/api/accounts/{id}/test', [AccountController::class, 'test']);
$router->post('/api/accounts/{id}/sync', [AccountController::class, 'sync']);
$router->post('/api/test-all',           [AccountController::class, 'testAll']);

// 域名同步与查询
$router->post('/api/sync',         [SyncController::class, 'sync']);
$router->get('/api/sync/logs',     [SyncController::class, 'logs']);
$router->get('/api/domains',       [DomainController::class, 'index']);
$router->get('/api/domains/screen',[ScreenController::class, 'index']);
$router->get('/api/domains/screen.md', [ScreenController::class, 'markdown']);
$router->get('/api/domains/screen/report', [ScreenController::class, 'report']);
$router->get('/api/domains/stats', [DomainController::class, 'stats']);
$router->post('/api/domains',      [DomainController::class, 'create']);
$router->put('/api/domains',       [DomainController::class, 'update']);
$router->delete('/api/domains',    [DomainController::class, 'delete']);

// IP 资产（大屏 IP 视图）：快照、手工登记 CRUD 与归属地刷新。
$router->get('/api/ips/screen',         [IpController::class, 'screen']);
$router->get('/api/ips/screen.md',      [IpController::class, 'markdown']);
$router->post('/api/ips',               [IpController::class, 'create']);
$router->put('/api/ips/{id}',           [IpController::class, 'update']);
$router->delete('/api/ips/{id}',        [IpController::class, 'delete']);
$router->post('/api/ips/{ip}/location', [IpController::class, 'refreshLocation']);
$router->post('/api/ips/check', [IpController::class, 'check']);
$router->post('/api/ips/browser-check', [IpController::class, 'browserCheck']);

// DNS 解析记录
$router->get('/api/domains/{provider}/{domain}/records',             [RecordController::class, 'list']);
$router->post('/api/domains/{provider}/{domain}/records',            [RecordController::class, 'add']);
$router->put('/api/domains/{provider}/{domain}/records/{recordId}',  [RecordController::class, 'update']);
$router->delete('/api/domains/{provider}/{domain}/records/{recordId}', [RecordController::class, 'delete']);
$router->post('/api/domains/{provider}/{domain}/records/{recordId}/status', [RecordController::class, 'status']);
$router->post('/api/domains/{provider}/{domain}/records/access-check', [RecordController::class, 'accessCheck']);
$router->post('/api/domains/{provider}/{domain}/records/browser-check', [RecordController::class, 'browserCheck']);

// 初始化后仅管理员会话可读取环境诊断。
$router->get('/env-check',     [EnvCheckController::class, 'page']);
$router->get('/api/env-check', [EnvCheckController::class, 'index']);

// 大屏展示（独立页面）：未过期域名与子域名 A 记录，数据走只读快照缓存。
$router->get('/screen', [ScreenController::class, 'page']);

// 独立管理页：设置（云账号 / API 密钥 / 通用）、日历（打卡 / 待办）与文档库（浏览 / 编辑）。
$router->get('/settings', [SettingsController::class, 'page']);
$router->get('/calendar', [CalendarController::class, 'page']);
$router->get('/docs', [DocsController::class, 'page']);

$path = $reqPath;
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
