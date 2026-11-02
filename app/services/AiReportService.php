<?php

require_once __DIR__ . '/HealthService.php';

/**
 * Compiles cached dashboard evidence into one self-contained Markdown report
 * that an administrator can paste into any AI assistant for analysis.
 * Read-only: it never triggers checks, syncs or DNS refreshes, and carries no
 * credentials — only asset names, observed IPs and check evidence.
 */
class AiReportService
{
    private const MAX_ROWS = 50;
    private const MAX_NOTE = 80;
    private const LABELS = ['healthy' => '正常', 'unhealthy' => '检查异常', 'stale' => '结果过时', 'unchecked' => '未检查'];

    private $health;
    private $clock;

    public function __construct(?HealthService $health = null, ?callable $clock = null)
    {
        $this->health = $health ?? new HealthService();
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function report(): array
    {
        $dashboard = $this->health->dashboard();
        $markdown = $this->markdown($dashboard);
        return ['ok' => true, 'generated_at' => $dashboard['generated_at'],
            'version' => class_exists('AssetSnapshot') ? AssetSnapshot::VERSION : '',
            'freshness_seconds' => $dashboard['freshness_seconds'],
            'last_checked_at' => $dashboard['last_checked_at'],
            'summary' => $dashboard['summary'], 'format' => 'markdown',
            'usage' => '推荐一键复制 prompt（已内嵌报告与完整分析要求）粘贴给任意 AI 助手；也可只复制 markdown，或让 AI 以 assets:read 权限请求 /api/health/report?format=markdown。',
            'prompt' => $this->prompt($markdown), 'markdown' => $markdown];
    }

    /** A ready-to-run analysis prompt wrapping the report, for one-click paste into any chat assistant. */
    public function prompt(string $markdown): string
    {
        return <<<'TEXT'
你是一名资深的域名与服务器运维顾问。请基于下方 <report> 标签内的「资产健康报告」（资产工作台生成的缓存检查证据快照）完成分析。

输出要求：
1. 先用不超过 3 句话概括整体状况：健康面、主要风险、最紧迫的事项。
2. 把异常按可能原因分组（TLS 证书 / DNS 解析 / 网络与防火墙 / 站点未部署 / 结果过时需复查），每组列出涉及资产并注明依据报告中的哪一行。
3. 结合「解析 IP」与备注，判断哪些资产可能共用同一服务器或 CDN，说明单点故障的影响面。
4. 给出建议动作清单，按 P0（立即）/ P1（本周）/ P2（择期）排序，每条注明依据与验证方法。
5. 证据不足时明确说明，并指出需要补充哪项检查（如重新执行健康检查、刷新 DNS 缓存），不要臆测。
6. 始终遵守数据边界：解析 IP 可能是 CDN 或代理入口；未检查与结果过时不等于正常；报告是生成时刻的快照。

TEXT
            . "<report>\n" . $markdown . "</report>\n";
    }

    public function markdown(?array $dashboard = null): string
    {
        $dashboard = $dashboard ?? $this->health->dashboard();
        $assets = $dashboard['assets'];
        $domains = array_values(array_filter($assets, static function ($a) { return $a['kind'] === 'domain'; }));
        $ips = array_values(array_filter($assets, static function ($a) { return $a['kind'] === 'ip'; }));
        $summary = $dashboard['summary'];

        $lines = [];
        $lines[] = '# 资产健康报告（Asset Workspace）';
        $lines[] = '';
        $lines[] = '- 生成时间：' . $dashboard['generated_at'] . '（时区 Asia/Shanghai）';
        $lines[] = '- 数据性质：服务器缓存的健康检查证据快照；结果超过 24 小时视为过时，未检查不等于正常。';
        $lines[] = '- 资产规模：主域名 ' . count($domains) . ' · IP 检查目标 ' . count($ips) . ' · 子域名 ' . ($summary['subdomains'] ?? 0)
            . '；正常 ' . ($summary['healthy'] ?? 0) . ' · 检查异常 ' . ($summary['unhealthy'] ?? 0)
            . ' · 结果过时 ' . ($summary['stale'] ?? 0) . ' · 未检查 ' . ($summary['unchecked'] ?? 0)
            . '；30 天内到期 ' . ($summary['expiring_30'] ?? 0) . '。';
        if ($dashboard['last_checked_at']) {
            $lines[] = '- 最近一次检查：' . $dashboard['last_checked_at'];
        }

        // 1. Failing domains and 2. HTTPS-unconfirmed domains (pending operations),
        //    classified in one pass; pending excludes the ones already listed as failed.
        $failed = []; $pending = [];
        foreach ($domains as $asset) {
            $health = $asset['health'];
            if (($health['status'] ?? '') === 'unhealthy') { $failed[] = $asset; continue; }
            $https = $health['protocols']['https']['status'] ?? null;
            if (!empty($health['invalidated']) || $https !== 'healthy') { $pending[] = $asset; }
        }
        $lines[] = '';
        $lines[] = '## 一、检查异常的域名（' . count($failed) . '）';
        $lines[] = $this->domainTable($failed);
        $lines[] = '';
        $lines[] = '## 二、HTTPS 尚未确认的域名（' . count($pending) . '）';
        $lines[] = $pending
            ? $this->domainTable($pending)
            : '全部主域名的最近一次 HTTPS 检查均已通过。';

        // 3. Failing IP endpoints.
        $failedIps = array_values(array_filter($ips, static function ($a) { return ($a['health']['status'] ?? '') === 'unhealthy'; }));
        $lines[] = '';
        $lines[] = '## 三、检查异常的 IP / 服务器（' . count($failedIps) . '）';
        $lines[] = $failedIps
            ? $this->table(['名称 / IP', '来源', '状态', '上次检查', '说明'], array_map(static function ($a) {
                return [($a['name'] ?? '') . ' / ' . $a['ip'], $a['source'] === 'manual' ? '手工登记' : 'DNS 解析发现',
                    self::LABELS[$a['health']['status']] ?? $a['health']['status'],
                    $a['health']['checked_at'] ?? '尚未检查', $a['health']['message'] ?? ''];
            }, $failedIps))
            : '没有检查异常的 IP 检查目标。';

        // 4. Assets expiring within 30 days (cloud expiry for domains, recorded expiry for servers).
        $expiring = array_values(array_filter($assets, function ($a) {
            if (!$a['expires_at']) { return false; }
            $expiry = strtotime($a['expires_at'] . ' 23:59:59');
            return $expiry !== false && $expiry >= $this->now() && $expiry <= $this->now() + 30 * 86400;
        }));
        $lines[] = '';
        $lines[] = '## 四、30 天内到期（' . count($expiring) . '）';
        $lines[] = $expiring
            ? $this->table(['资产', '类型', '到期日期', '来源'], array_map(static function ($a) {
                return [$a['name'], $a['kind'] === 'domain' ? '域名' : 'IP / 服务器', $a['expires_at'],
                    $a['kind'] === 'domain' ? ($a['provider_name'] ?? $a['provider']) : ($a['server_provider'] ?: '未填写')];
            }, $expiring))
            : '没有 30 天内到期的资产。';

        $lines[] = '';
        $lines[] = '## 给 AI 的分析指引';
        $lines[] = '';
        $lines[] = '请基于以上证据输出：';
        $lines[] = '1. 异常归类：按可能原因分组（TLS 证书、DNS 解析、网络 / 防火墙、站点未部署、结果过时需复查）。';
        $lines[] = '2. 影响面：结合“解析 IP”与备注判断哪些域名可能共用同一服务器或 CDN。';
        $lines[] = '3. 建议动作：按优先级排序，并注明每条建议依据表中哪一行。';
        $lines[] = '4. 数据边界：解析 IP 可能是 CDN 或代理入口，不等于源站；未检查与结果过时不能视为正常；本报告是生成时刻的快照。';
        $lines[] = '';
        $lines[] = '补充说明：域名检查同时验证 HTTP 与 HTTPS（HTTPS 校验证书），两者均 2xx/3xx 才算正常；IP 检查访问 http://IP/ 且不跟随跳转。如需更新数据，请管理员先执行健康检查，再重新生成报告。';
        return implode("\n", $lines) . "\n";
    }

    private function domainTable(array $domains): string
    {
        if (!$domains) { return '没有这类域名。'; }
        return $this->table(['域名', '来源', '状态', '上次检查', '解析 IP', '说明', '备注'], array_map(static function ($a) {
            $health = $a['health'];
            return [$a['name'], $a['provider_name'] ?? $a['provider'], self::LABELS[$health['status']] ?? $health['status'],
                $health['checked_at'] ?? '尚未检查', implode('、', $health['resolved_ips'] ?? []) ?: '未发现',
                $health['message'] ?? '', $a['note'] ?? ''];
        }, $domains));
    }

    /** Every cell is bounded and pipe/newline-safe, so pasted user notes cannot break the table. */
    private function table(array $header, array $rows): string
    {
        $cell = static function ($value): string {
            $text = trim((string) $value);
            if (mb_strlen($text) > self::MAX_NOTE) { $text = mb_substr($text, 0, self::MAX_NOTE - 1) . '…'; }
            return str_replace(["|", "\r", "\n"], ['\\|', ' ', ' '], $text);
        };
        $lines = ['| ' . implode(' | ', array_map($cell, $header)) . ' |', '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |'];
        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $lines[] = '| ' . implode(' | ', array_map($cell, $row)) . ' |';
        }
        if (count($rows) > self::MAX_ROWS) {
            $lines[] = '';
            $lines[] = '（仅列出前 ' . self::MAX_ROWS . ' 项，其余 ' . (count($rows) - self::MAX_ROWS) . ' 项省略；统计数字仍为全量。）';
        }
        return implode("\n", $lines);
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
