<?php

require_once __DIR__ . '/HealthService.php';

/**
 * 问题中心：把缓存证据里的所有待处理问题合并为一份分级清单（P0 检查异常 →
 * P1 HTTPS 未确认 / 结果过时 → P2 即将到期）。首页与 AI 读同一份 /api/issues，
 * 保证「人一眼看到的问题」与「AI 定位的问题」完全一致。只读。
 */
class IssueService
{
    public const MAX_ISSUES = 200;
    private const SEVERITY = ['P0' => '立即处理', 'P1' => '尽快处理', 'P2' => '择期处理'];

    private $dir;
    private $clock;

    public function __construct(?string $dir = null, ?callable $clock = null)
    {
        $this->dir = $dir ?? DATA_DIR;
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function issues(): array
    {
        $dashboard = (new HealthService($this->dir))->dashboard();
        $now = ($this->clock)();
        $issues = [];
        $issued = [];
        foreach ($dashboard['assets'] as $asset) {
            $health = $asset['health'];
            $kind = $asset['kind'];
            $base = ['asset_id' => $asset['id'], 'kind' => $kind, 'name' => $asset['name'],
                'provider' => $asset['provider'] ?? '', 'ips' => array_slice($health['resolved_ips'] ?? [], 0, 5)];
            if ($kind === 'domain' && ($asset['provider'] ?? 'manual') !== 'manual') {
                $base['dns_path'] = '/api/domains/' . rawurlencode((string) $asset['provider']) . '/' . rawurlencode((string) $asset['name']) . '/records';
            }

            // 每个资产在检查类问题中只保留最高优先级的一条，避免同一资产重复出现。
            if (($health['status'] ?? '') === 'unhealthy') {
                $issues[] = $base + $this->item('unhealthy', 'P0', $health, $asset,
                    '检查异常：' . ($health['message'] ?: '未通过'), [['type' => 'check', 'label' => '重新检查']]);
                $issued[$asset['id']] = true;
                continue;
            }
            if ($kind === 'domain') {
                $https = $health['protocols']['https']['status'] ?? null;
                $invalidated = !empty($health['invalidated']);
                if ($invalidated) { $https = null; }
                if ($https !== 'healthy') {
                    $why = $invalidated ? 'DNS 已更新，需重新检查' : '尚未确认';
                    $issues[] = $base + $this->item('https_pending', 'P1', $health, $asset,
                        'HTTPS ' . $why . ($health['status'] === 'stale' ? '（结果已过时）' : ''), [['type' => 'check', 'label' => '检查 HTTPS']]);
                    $issued[$asset['id']] = true;
                    continue;
                }
            }
            if (($health['status'] ?? '') === 'stale' && !isset($issued[$asset['id']])) {
                $issues[] = $base + $this->item('stale', 'P1', $health, $asset,
                    '结果过时：' . ($health['message'] ?: '超过 24 小时未检查'), [['type' => 'check', 'label' => '重新检查']]);
                $issued[$asset['id']] = true;
            }
        }
        // 到期提醒独立于检查状态，允许与检查类问题并存。
        foreach ($dashboard['assets'] as $asset) {
            if (!$asset['expires_at']) { continue; }
            $expiry = strtotime($asset['expires_at'] . ' 23:59:59');
            if ($expiry === false || $expiry < $now || $expiry > $now + 30 * 86400) { continue; }
            $days = (int) ceil(($expiry - $now) / 86400);
            $issues[] = ['asset_id' => $asset['id'], 'kind' => $asset['kind'], 'name' => $asset['name'],
                'provider' => $asset['provider'] ?? '', 'ips' => [], 'type' => 'expiring',
                'id' => 'expiring:' . $asset['kind'] . ':' . $asset['name'], 'severity' => 'P2',
                'summary' => ($days <= 0 ? '今天' : $days . ' 天内') . '到期（' . $asset['expires_at'] . '）',
                'since' => null, 'due' => $asset['expires_at'],
                'actions' => [['type' => 'detail', 'label' => '查看资料']]];
        }

        usort($issues, static function ($a, $b) {
            return [$a['severity'], $a['since'] ?? '9999', $a['name']] <=> [$b['severity'], $b['since'] ?? '9999', $b['name']];
        });
        $counts = array_fill_keys(['total', 'unhealthy', 'https_pending', 'stale', 'expiring'], 0);
        foreach ($issues as $issue) { if (isset($counts[$issue['type']])) { $counts[$issue['type']]++; } }
        $counts['total'] = count($issues);
        return ['ok' => true, 'generated_at' => date('Y-m-d H:i:s', $now),
            'counts' => $counts, 'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'truncated' => count($issues) > self::MAX_ISSUES,
            'severity_labels' => self::SEVERITY,
            'usage' => 'AI 三步处理：1) 本接口定位问题（id 可在备注与提案中引用）；2) 记录结论：assets:write 更新资产备注，或以 proposals:write 提交变更提案（rationale 引用问题 id）；3) 处理后用 checks:run 重新检查，再读本接口确认问题消失。'];
    }

    private function item(string $type, string $severity, array $health, array $asset, string $summary, array $actions): array
    {
        $actions[] = ['type' => 'detail', 'label' => '查看详情'];
        return ['id' => $type . ':' . $asset['kind'] . ':' . $asset['name'], 'severity' => $severity,
            'type' => $type, 'summary' => $summary, 'since' => $health['checked_at'] ?? null, 'due' => null, 'actions' => $actions];
    }
}
