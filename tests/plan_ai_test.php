<?php
/** Offline regressions for the AI suggestion service. Run: php tests/plan_ai_test.php */
require_once __DIR__ . '/../app/services/PlanAiService.php';

$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function throwsClass(callable $work, string $class, string $message): void
{
    try { $work(); } catch (Throwable $e) { expect($e instanceof $class, $message . ' (' . get_class($e) . ')'); return; }
    expect(false, $message);
}

try {
    $key = 'test-key-0000000000000000000000000000';

    // 未配置密钥：直接给可操作的提示，不发起网络请求
    $noKey = new PlanAiService(['api_key' => '', 'model' => 'glm-4-flash']);
    throwsClass(static fn () => $noKey->suggest(['title' => 'x']), InvalidArgumentException::class, 'missing key rejected before any network call');

    // 输入校验
    $service = new PlanAiService(['api_key' => $key, 'model' => 'glm-4-flash']);
    throwsClass(static fn () => $service->suggest(['title' => '']), InvalidArgumentException::class, 'title is required');
    throwsClass(static fn () => $service->suggest(['title' => str_repeat('字', 121)]), InvalidArgumentException::class, 'title length capped');
    throwsClass(static fn () => $service->suggest(['title' => 'x', 'detail' => str_repeat('字', 4001)]), InvalidArgumentException::class, 'detail length capped');

    // 正常路径：解析 choices 内容；密钥只用于鉴权头，绝不进入请求体
    $captured = [];
    $glmBody = json_encode(['choices' => [['message' => ['content' => "## 现状\n…\n\n## 方案\n- 要点\n\n## 验收\n- 标准"]]]], JSON_UNESCAPED_UNICODE);
    $service = new PlanAiService(['api_key' => $key, 'model' => 'glm-4-flash'], static function (string $endpoint, string $apiKey, string $payload) use (&$captured, $glmBody) {
        $captured = ['endpoint' => $endpoint, 'key' => $apiKey, 'payload' => $payload];
        return $glmBody;
    });
    $result = $service->suggest(['title' => '分页渲染', 'detail' => '现状：慢']);
    expect($result['suggestion'] === "## 现状\n…\n\n## 方案\n- 要点\n\n## 验收\n- 标准", 'suggestion extracted from choices');
    expect($result['model'] === 'glm-4-flash', 'model echoed');
    expect($captured['endpoint'] === 'https://open.bigmodel.cn/api/paas/v4/chat/completions', 'GLM chat completions endpoint');
    expect($captured['key'] === $key, 'key passed for auth header');
    expect(strpos($captured['payload'], $key) === false, 'api key never appears in request body');
    $payload = json_decode($captured['payload'], true);
    expect($payload['model'] === 'glm-4-flash' && count($payload['messages']) === 2, 'payload carries model and two messages');
    expect(strpos($payload['messages'][1]['content'], '分页渲染') !== false, 'user message carries the plan title');
    expect(strpos($payload['messages'][0]['content'], '## 现状') !== false, 'system prompt requires the three-section structure');

    // 失败路径：全部转为可重试的通用错误，不暴露上游细节或密钥
    throwsClass(static function () use ($key) {
        $service = new PlanAiService(['api_key' => $key], static fn () => '{"error":{"message":"token invalid"}}');
        $service->suggest(['title' => 'x']);
    }, RuntimeException::class, 'empty choices becomes retryable failure');
    throwsClass(static function () use ($key) {
        $service = new PlanAiService(['api_key' => $key], static fn () => 'not-json');
        $service->suggest(['title' => 'x']);
    }, RuntimeException::class, 'unparseable response stays generic');
    throwsClass(static function () use ($key) {
        $service = new PlanAiService(['api_key' => $key], static function () { throw new RuntimeException('connect timeout'); });
        $service->suggest(['title' => 'x']);
    }, RuntimeException::class, 'network failure propagates as runtime error');

    echo 'PASS: ' . $assertions . " AI suggestion assertions\n";
} finally {
}
