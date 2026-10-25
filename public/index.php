<?php
/** 单入口：路由分发（同时作为 php -S 的路由脚本，静态文件直接放行） */

$reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// 静态资源放行（php -S --router 模式）
if ($reqPath !== '/' && is_file(__DIR__ . $reqPath)) {
    return false;
}

require __DIR__ . '/../app/bootstrap.php';

$router = new Router();
$router->get('/api/openapi.json', [ApiDescriptionController::class, 'openapi']);
$router->get('/api/capabilities', [ApiDescriptionController::class, 'discovery']);
$router->get('/.well-known/asset-workspace.json', [ApiDescriptionController::class, 'discovery']);
$router->get('/llms.txt', [ApiDescriptionController::class, 'instructions']);

// 鉴权
$router->post('/api/login',  [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout']);
$router->get('/api/me',      [AuthController::class, 'me']);
$router->get('/api/setup/status', [SettingsController::class, 'status']);
$router->post('/api/setup', [SettingsController::class, 'setup']);
$router->get('/api/settings', [SettingsController::class, 'index']);
$router->put('/api/settings/http', [SettingsController::class, 'http']);
$router->put('/api/auth/credentials', [SettingsController::class, 'credentials']);
$router->get('/api/tokens', [SettingsController::class, 'tokens']);
$router->post('/api/tokens', [SettingsController::class, 'createToken']);
$router->delete('/api/tokens/{id}', [SettingsController::class, 'revokeToken']);

// 域名与 IP 健康看板（只读快照、手工 IP、分批检查）
$router->get('/api/health', [HealthController::class, 'index']);
$router->post('/api/health/check', [HealthController::class, 'check']);
$router->post('/api/health/ips', [HealthController::class, 'addIp']);
$router->delete('/api/health/ips/{id}', [HealthController::class, 'deleteIp']);
$router->put('/api/health/ips/{id}', [HealthController::class, 'updateIp']);
$router->put('/api/health/domains/{id}/bindings', [HealthController::class, 'bindings']);

// 账号与密钥测试
$router->get('/api/accounts',            [AccountController::class, 'index']);
$router->post('/api/accounts',           [AccountController::class, 'create']);
$router->put('/api/accounts/{id}',       [AccountController::class, 'update']);
$router->post('/api/accounts/{id}/test', [AccountController::class, 'test']);
$router->post('/api/test-all',           [AccountController::class, 'testAll']);

// 域名同步与查询
$router->post('/api/sync',         [SyncController::class, 'sync']);
$router->get('/api/sync/logs',     [SyncController::class, 'logs']);
$router->get('/api/domains',       [DomainController::class, 'index']);
$router->get('/api/domains/stats', [DomainController::class, 'stats']);
$router->post('/api/domains',      [DomainController::class, 'create']);
$router->put('/api/domains',       [DomainController::class, 'update']);
$router->delete('/api/domains',    [DomainController::class, 'delete']);

// DNS 解析记录
$router->get('/api/domains/{provider}/{domain}/records',             [RecordController::class, 'list']);
$router->post('/api/domains/{provider}/{domain}/records',            [RecordController::class, 'add']);
$router->put('/api/domains/{provider}/{domain}/records/{recordId}',  [RecordController::class, 'update']);
$router->delete('/api/domains/{provider}/{domain}/records/{recordId}', [RecordController::class, 'delete']);
$router->post('/api/domains/{provider}/{domain}/records/access-check', [RecordController::class, 'accessCheck']);

// 访问检测
$router->get('/api/access-check',  [AccessCheckController::class, 'index']);
$router->post('/api/access-check', [AccessCheckController::class, 'check']);

// 环境检查（免登录，用于虚拟主机部署排查）
$router->get('/env-check',     [EnvCheckController::class, 'page']);
$router->get('/api/env-check', [EnvCheckController::class, 'index']);

$path = $reqPath;
$router->dispatch($_SERVER['REQUEST_METHOD'], $path);
