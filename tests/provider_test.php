<?php

/** 离线回归：php tests/provider_test.php；不加载配置，不请求云端，不读写项目 data。 */
require_once __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/providers/CloudProvider.php';
require_once __DIR__ . '/../app/providers/AliyunProvider.php';
require_once __DIR__ . '/../app/controllers/DomainController.php';
require_once __DIR__ . '/../app/services/HealthService.php';

class HttpClient
{
    public static array $queue = [];
    public static array $pages = [];

    public static function get(string $url, array $headers = []): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::$pages[] = (int) ($query['PageNum'] ?? 0);
        if (!self::$queue) {
            throw new RuntimeException('测试响应耗尽；禁止真实网络请求');
        }
        return array_shift(self::$queue);
    }
}

class Auth
{
    public static function requireLogin(): void {}
}

class Logger
{
    public static function log(string $event, array $data): void {}
}

class TestResponse extends RuntimeException
{
    public array $data;
    public function __construct(array $data, int $status)
    {
        parent::__construct('HTTP ' . $status, $status);
        $this->data = $data;
    }
}

function config(?string $key = null) { return []; }
function body_json(): array { return $GLOBALS['requestBody'] ?? []; }
function json_out($data, int $status = 200): void { throw new TestResponse($data, $status); }

$assertions = 0;
function check(bool $passed, string $label): void
{
    global $assertions;
    $assertions++;
    if (!$passed) {
        throw new RuntimeException('断言失败：' . $label);
    }
}

function fails(callable $action, string $label): void
{
    try {
        $action();
    } catch (RuntimeException $e) {
        check($e->getMessage() !== '', $label);
        return;
    }
    check(false, $label);
}

function response(array $body, int $status = 200): array
{
    return ['status' => $status, 'body' => json_encode($body)];
}

function page(array $domains, int $total, int $pages): array
{
    return ['RequestId' => 'test-request', 'TotalItemNum' => $total, 'TotalPageNum' => $pages,
        'Data' => ['Domain' => array_map(function (string $domain): array {
            return ['DomainName' => $domain, 'ExpirationDate' => '2027-08-18'];
        }, $domains)]];
}

function request(string $method, array $body, int $expectedStatus): array
{
    $GLOBALS['requestBody'] = $body;
    try {
        (new DomainController())->$method();
    } catch (TestResponse $res) {
        check($res->getCode() === $expectedStatus, $method . ' HTTP ' . $expectedStatus);
        return $res->data;
    }
    throw new RuntimeException('控制器没有返回响应');
}

function removeTree(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                removeTree($path . '/' . $name);
            }
        }
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
}

$temp = sys_get_temp_dir() . '/domain-workbench-tests-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
define('DATA_DIR', $temp);

try {
    $provider = new AliyunProvider(['access_key_id' => 'fake-key', 'access_key_secret' => 'fake-secret']);
    HttpClient::$queue = [response(['Code' => 'InvalidAccessKeyId.NotFound', 'Message' => 'denied', 'RequestId' => 'test-request'])];
    fails(fn() => $provider->listDomains(), '带 RequestId 的云端错误必须抛异常');
    HttpClient::$queue = [response(['Code' => 'Forbidden', 'RequestId' => 'test-request', 'RecordId' => 'bad'])];
    fails(fn() => $provider->updateRecord('example.com', 'r1', []), 'DNS 错误不能误报成功');
    HttpClient::$queue = [response(page([], 0, 0), 503)];
    fails(fn() => $provider->listDomains(), 'HTTP 失败不能被有效 JSON 掩盖');
    foreach (['not json', '[]', 'null'] as $body) {
        HttpClient::$queue = [['status' => 200, 'body' => $body]];
        fails(fn() => $provider->listDomains(), '拒绝非对象响应');
    }
    foreach ([[], ['RequestId' => 'test-request'], ['Data' => ['Domain' => []]],
        ['TotalItemNum' => 0, 'TotalPageNum' => 0],
        ['TotalItemNum' => 0, 'TotalPageNum' => 0, 'Data' => null],
        ['TotalItemNum' => 0, 'TotalPageNum' => 0, 'Data' => ['unexpected' => 'field']],
        ['TotalItemNum' => 'abc', 'TotalPageNum' => 1, 'Data' => []],
        page([], 1, 1), page(['example.com'], 0, 0), page(['example.com'], 1, 0),
        page([''], 1, 1)] as $body) {
        HttpClient::$queue = [response($body)];
        fails(fn() => $provider->listDomains(), '不完整或不一致的域名响应必须失败');
    }
    foreach ([page([], 0, 0), page([], 0, 1), ['TotalItemNum' => 0, 'TotalPageNum' => 0, 'Data' => []]] as $body) {
        HttpClient::$queue = [response($body)];
        check($provider->listDomains() === [], '完整的零域名响应允许成功');
    }
    HttpClient::$queue = [response(page(['ONE.COM'], 2, 2)), response(page(['two.com'], 2, 2))];
    HttpClient::$pages = [];
    $domains = $provider->listDomains();
    check(array_column($domains, 'domain') === ['one.com', 'two.com'], '完整分页且规范化域名');
    check(HttpClient::$pages === [1, 2], '按页顺序读取');
    HttpClient::$queue = [response(page(['one.com'], 2, 1))];
    fails(fn() => $provider->listDomains(), '总条数不足不能覆盖现有资产');
    HttpClient::$queue = [response(page(['one.com'], 2, 2)), response(page(['ONE.COM'], 2, 2))];
    fails(fn() => $provider->listDomains(), '重复分页不能掩盖缺失资产');
    HttpClient::$queue = [response(page(['one.com'], 2, 2)), response(page(['two.com'], 3, 2))];
    fails(fn() => $provider->listDomains(), '分页期间总数改变必须重试');
    HttpClient::$queue = [response(page(['example.com'], 1, 1))];
    check($provider->testConnection()['count'] === 1, '连接检测解析正确计数');

    $file = $temp . '/storage/records.json';
    check(JsonStore::write($file, [['id' => 1]]) === true, '写入成功返回 true');
    fails(fn() => JsonStore::write($file, ['invalid' => INF]), 'JSON 编码失败抛异常');
    check(JsonStore::read($file) === [['id' => 1]], '编码失败保留原始文件');
    $targetDirectory = $temp . '/directory.json';
    mkdir($targetDirectory);
    fails(fn() => JsonStore::write($targetDirectory, []), 'rename 失败抛异常');
    check(glob($targetDirectory . '.tmp.*') === [], '写入失败清理临时文件');
    file_put_contents($temp . '/parent-is-file', 'x');
    fails(fn() => JsonStore::write($temp . '/parent-is-file/data.json', []), '无法创建目录抛异常');
    JsonStore::append($file, ['id' => 2]);
    check(count(JsonStore::read($file)) === 2, '追加保留已有记录');
    check(is_file($file . '.lock'), '独立锁文件保留稳定身份');

    if (function_exists('pcntl_fork')) {
        $concurrentFile = $temp . '/concurrent.json';
        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('无法创建并发测试进程');
            }
            if ($pid === 0) {
                for ($index = 0; $index < 25; $index++) {
                    JsonStore::append($concurrentFile, ['id' => $worker . '-' . $index]);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, '追加工作进程正常退出');
        }
        $records = JsonStore::read($concurrentFile);
        check(count($records) === 100 && count(array_unique(array_column($records, 'id'))) === 100, '4 个进程追加 100 条记录无丢失');
    } else {
        echo "SKIP: pcntl 不可用，并发追加测试未运行\n";
    }

    $created = request('create', ['domain' => ' EXAMPLE.COM. ', 'expire_at' => '2028-02-29'], 200);
    check($created['domain']['domain'] === 'example.com', '域名小写且去除根点');
    check($created['domain']['registration_date'] === '', '允许未知日期');
    request('create', ['domain' => 'example.com'], 409);
    $before = file_get_contents($temp . '/domains.json');
    foreach (['', ['example.com'], 'https://example.com', 'example.com:443', 'example.com/path', 'example.com\\path',
        '127.0.0.1', '[::1]', 'localhost', 'db.internal', 'db.local', 'db.home.arpa', 'example.123',
        'exa mple.com', '-example.com', 'example-.com', 'a..com', 'example.com..', 'a_' . '.com',
        str_repeat('a', 64) . '.com', "exam\0ple.com", 'example.com?x=1', 'example.com#x'] as $domain) {
        request('create', ['domain' => $domain], 422);
    }
    foreach (['2026-02-29', '2026-13-01', '2026-04-31', 'tomorrow', '2026-1-1', [], 123] as $date) {
        request('create', ['domain' => 'new.com', 'expire_at' => $date], 422);
        request('update', ['provider' => 'manual', 'domain' => 'example.com', 'registration_date' => $date], 422);
    }
    check(file_get_contents($temp . '/domains.json') === $before, '拒绝请求不得修改资产文件');
    request('update', ['provider' => 'manual', 'domain' => 'example.com', 'expire_at' => null], 200);
    check(JsonStore::read($temp . '/domains.json')[0]['expire_at'] === '', '允许清空未知日期');
    if (function_exists('idn_to_ascii')) {
        $created = request('create', ['domain' => '例子.中国'], 200);
        check($created['domain']['domain'] === 'xn--fsqu00a.xn--fiqs8s', 'IDN 规范化为 ASCII Punycode');
        request('create', ['domain' => 'xn--fsqu00a.xn--fiqs8s'], 409);
    }

    // 域名页读取新健康记录，旧访问结果只能继续作为兼容字段。
    $fixtures = [];
    foreach (['healthy.com', 'unhealthy.com', 'stale.com', 'unchecked.com'] as $name) {
        $fixtures[] = ['domain' => $name, 'provider' => 'manual', 'valid' => true];
    }
    $fixtures[] = ['domain' => 'healthy.com', 'provider' => 'second-account', 'valid' => true];
    $fixtures[] = ['domain' => 'archived.com', 'provider' => 'manual', 'valid' => false];
    JsonStore::write($temp . '/domains.json', $fixtures);
    $legacy = ['status' => 'fail', 'http_code' => 500, 'ms' => 123, 'scheme' => 'https',
        'error' => 'old error', 'checked_at' => '2020-01-01 00:00:00'];
    JsonStore::write($temp . '/access_check.json', ['results' => ['manual|healthy.com' => $legacy]]);
    $health = new HealthService($temp, static function (array $asset): array {
        return ['status' => $asset['name'] === 'unhealthy.com' ? 'unhealthy' : 'healthy',
            'message' => 'mock probe', 'resolved_ips' => []];
    });
    $ids = [];
    foreach ($health->dashboard()['assets'] as $asset) {
        $ids[$asset['provider'] . '|' . $asset['name']] = $asset['id'];
    }
    $health->check([$ids['manual|healthy.com'], $ids['manual|unhealthy.com']]);
    $oldHealth = new HealthService($temp, static function (): array {
        return ['status' => 'healthy', 'message' => 'old mock', 'resolved_ips' => []];
    }, static function (): int { return time() - 90000; });
    $oldHealth->check([$ids['manual|stale.com']]);
    $_GET = ['sort' => 'domain'];
    $listed = request('index', [], 200)['list'];
    $byKey = [];
    foreach ($listed as $domain) {
        $byKey[$domain['provider'] . '|' . $domain['domain']] = $domain;
    }
    check($byKey['manual|healthy.com']['health']['status'] === 'healthy', '列表使用新健康状态');
    check($byKey['manual|healthy.com']['access'] === $legacy, '旧 access 字段原样保留');
    check($byKey['second-account|healthy.com']['health']['status'] === 'unchecked', '相同域名按账号隔离健康状态');
    check($byKey['manual|archived.com']['health']['status'] === 'unchecked', '看板无此资产时回退未检测');
    foreach (['healthy' => 1, 'unhealthy' => 1, 'stale' => 1, 'unchecked' => 3, 'ok' => 1, 'fail' => 1] as $filter => $count) {
        $_GET = ['access' => $filter];
        $result = request('index', [], 200);
        check($result['total'] === $count, '健康筛选 ' . $filter);
        $expected = ['ok' => 'healthy', 'fail' => 'unhealthy'][$filter] ?? $filter;
        check(count(array_filter($result['list'], fn($domain) => $domain['health']['status'] !== $expected)) === 0, '筛选状态一致 ' . $filter);
    }
    $_GET = [];
    echo "PASS: {$assertions} assertions (provider, storage, domain validation)\n";
} finally {
    removeTree($temp);
}
