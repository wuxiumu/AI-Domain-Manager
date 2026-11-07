<?php

/**
 * AI 建议（智谱 GLM / bigmodel.cn）：为改进计划条目生成优化后的 Markdown 详情。
 * 密钥只从 config/config.php 的 ai 节点（gitignored）读取，任何响应与日志都不回显；
 * 网络调用不持有任何数据锁；建议内容不落盘，由管理员对比后自行决定是否采用。
 */
class PlanAiService
{
    private const ENDPOINT = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    private const TIMEOUT_SECONDS = 30;
    private const MAX_DETAIL = 4000;
    private const MAX_SUGGESTION = 4000;

    private const SYSTEM_PROMPT = <<<'TEXT'
你是资产管理工作台「改进计划」的助写助手。管理员会给你一个计划点标题与当前详情（Markdown，可能为空或很简单），
请输出一版优化后的完整详情正文，要求：
1. 只输出 Markdown 正文，不要任何前后缀说明，也不要用代码块包裹；
2. 保持并完善「## 现状 / ## 方案 / ## 验收」三节结构（原文有其他小节则保留）；
3. 现状只基于给定描述陈述，不要虚构系统里不存在的细节；
4. 方案拆成可执行的要点（用 - 列表），不扩大功能范围；
5. 验收写可核对的具体标准；
6. 全文中文，简洁直接，总长不超过 600 字。
TEXT;

    private ?array $config;
    private $http;

    private const DEFAULT_MODEL = 'glm-4-flash';

    /** $config 显式传入用于离线测试；生产从 config() 读取。$http 可注入替代真实网络调用。 */
    public function __construct(?array $config = null, ?callable $http = null)
    {
        $this->config = $config;
        $this->http = $http;
    }

    /** 读取 ai 配置项（可为标量或数组）；未配置返回 null。 */
    private function setting(string $key)
    {
        if ($this->config !== null) { return $this->config[$key] ?? null; }
        return config('ai.' . $key);
    }

    private function apiKey(): string
    {
        $key = $this->setting('api_key');
        return is_string($key) ? trim($key) : '';
    }

    private function allowedModels(): array
    {
        $models = $this->setting('models');
        if (is_array($models)) {
            $list = array_values(array_filter(array_map(static fn ($m) => is_string($m) ? trim($m) : '', $models), static fn ($m) => $m !== ''));
        } else {
            $list = [];
        }
        return $list ?: [self::DEFAULT_MODEL];
    }

    /** 供前端展示的 AI 元信息（不含密钥）：是否可用、可选模型与默认模型。 */
    public function meta(): array
    {
        $models = $this->allowedModels();
        $default = $this->setting('model');
        $default = is_string($default) && $default !== '' ? $default : self::DEFAULT_MODEL;
        if (!in_array($default, $models, true)) { array_unshift($models, $default); }
        return ['enabled' => $this->apiKey() !== '', 'models' => array_values(array_unique($models)), 'default' => $default];
    }

    /** 生成优化建议；返回 {suggestion, model}。失败抛异常，由路由统一转 4xx/5xx。 */
    public function suggest(array $input): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') { throw new InvalidArgumentException('未配置 AI 服务：请在 config/config.php 的 ai 节点填入 api_key'); }
        $title = trim((string) ($input['title'] ?? ''));
        $detail = (string) ($input['detail'] ?? '');
        if ($title === '' || mb_strlen($title) > 120) { throw new InvalidArgumentException('请提供不超过 120 字的标题'); }
        if (mb_strlen($detail) > self::MAX_DETAIL) { throw new InvalidArgumentException('详情超过 4000 字，请先精简'); }
        $models = $this->allowedModels();
        $requested = trim((string) ($input['model'] ?? ''));
        $fallback = $this->setting('model');
        $model = $requested !== '' ? $requested : (is_string($fallback) && $fallback !== '' ? $fallback : self::DEFAULT_MODEL);
        if (!in_array($model, $models, true)) {
            throw new InvalidArgumentException('不支持的模型：' . $model . '；可在 config/config.php 的 ai.models 列表中调整');
        }
        $payload = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => "计划点标题：{$title}\n\n当前详情：\n{$detail}"],
            ],
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE);
        $data = json_decode($this->call($apiKey, $payload), true);
        if (!is_array($data)) { throw new RuntimeException('AI 服务返回了无法解析的结果，请稍后重试'); }
        $suggestion = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($suggestion === '') { throw new RuntimeException('AI 未返回建议内容，请稍后重试'); }
        if (mb_strlen($suggestion) > self::MAX_SUGGESTION) { $suggestion = mb_substr($suggestion, 0, self::MAX_SUGGESTION); }
        return ['suggestion' => $suggestion, 'model' => $model];
    }

    /** 请求 GLM Chat Completions；密钥只进 Authorization 头，绝不写入请求体或日志。 */
    private function call(string $apiKey, string $payload): string
    {
        if ($this->http !== null) { return (string) call_user_func($this->http, self::ENDPOINT, $apiKey, $payload); }
        if (!function_exists('curl_init')) { throw new RuntimeException('服务器缺少 cURL 扩展，无法调用 AI 服务'); }
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_SECONDS * 1000,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) { throw new RuntimeException('AI 服务连接失败，请检查网络后重试'); }
        if ($code !== 200) {
            // 只记录状态码与响应片段排障，不记录请求体与密钥；上游业务错误（如套餐到期）透传给管理员。
            error_log('AI suggest HTTP ' . $code . ': ' . mb_substr((string) $raw, 0, 300));
            $detail = '';
            $err = json_decode((string) $raw, true);
            if (is_array($err) && isset($err['error']['message'])) {
                $detail = '：' . mb_substr((string) $err['error']['message'], 0, 200);
            } elseif (in_array($code, [401, 403], true)) {
                $detail = '，请检查 config/config.php 的 ai.api_key';
            }
            throw new InvalidArgumentException('AI 服务返回 HTTP ' . $code . $detail);
        }
        return (string) $raw;
    }
}
