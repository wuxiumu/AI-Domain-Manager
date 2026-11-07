<?php

require_once __DIR__ . '/HealthService.php';

/**
 * 问题中心：把缓存证据里的待处理问题合并为一份分级清单（P0 HTTP 检查异常 →
 * P1 结果过时/证书 7 天内到期 → P2 即将到期/证书 30 天内到期）。可达性以 HTTP 为准；
 * HTTPS 证书「验证失败」仍在 httpsReview()（/api/https-review）单独跟进，
 * 而「证书临期」依据检查观测里的证书到期日进入本清单（30/14/7 天三级提示）。
 * 首页与 AI 读同一份 /api/issues，保证「人一眼看到的问题」与「AI 定位的问题」完全一致。只读。
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
                'provider' => $asset['provider'] ?? '', 'ips' => array_slice($health['resolved_ips'] ?? [], 0, 5),
                'url' => $health['url'] ?? ($kind === 'domain' ? 'http://' . strtolower((string) $asset['name']) . '/' : null)];
            if ($kind === 'domain' && ($asset['provider'] ?? 'manual') !== 'manual') {
                $base['dns_path'] = '/api/domains/' . rawurlencode((string) $asset['provider']) . '/' . rawurlencode((string) $asset['name']) . '/records';
            }

            // 每个资产在检查类问题中只保留最高优先级的一条，避免同一资产重复出现。
            // 可达性判定只看 HTTP；HTTPS 证据见 httpsReview()。
            if (($health['status'] ?? '') === 'unhealthy') {
                $issues[] = $base + $this->item('unhealthy', 'P0', $health, $asset,
                    'HTTP 检查异常：' . ($health['message'] ?: '未通过'), [['type' => 'check', 'label' => '重新检查']]);
                $issued[$asset['id']] = true;
                continue;
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
        // TLS 证书临期：依据最近一次检查观测到的证书到期日，30/14/7 天三级提示；
        // 稳定 ID（cert:domain:域名）保证重复检查不产生重复条目，续期后按新剩余天数重新判定。
        foreach ($dashboard['assets'] as $asset) {
            if ($asset['kind'] !== 'domain' || empty($asset['health']['cert']['expires_at'])) { continue; }
            $cert = $asset['health']['cert'];
            $expiry = strtotime($cert['expires_at'] . ' 23:59:59');
            if ($expiry === false) { continue; }
            $days = (int) ceil(($expiry - $now) / 86400);
            if ($days > 30) { continue; }
            if ($days <= 7) { $severity = 'P1'; $tier = '7 天内到期，请尽快续期'; }
            elseif ($days <= 14) { $severity = 'P2'; $tier = '14 天内到期，建议安排续期'; }
            else { $severity = 'P2'; $tier = '30 天内到期，可择期续期'; }
            $issues[] = ['asset_id' => $asset['id'], 'kind' => 'domain', 'name' => $asset['name'],
                'provider' => $asset['provider'] ?? '', 'ips' => array_slice($asset['health']['resolved_ips'] ?? [], 0, 5), 'type' => 'cert_expiring',
                'id' => 'cert:domain:' . $asset['name'], 'severity' => $severity,
                'summary' => 'TLS 证书 ' . ($days <= 0 ? '今天' : $days . ' 天内') . '到期（' . $cert['expires_at'] . ' · ' . $tier . '）',
                'since' => $asset['health']['checked_at'] ?? null, 'due' => $cert['expires_at'],
                'actions' => [['type' => 'check', 'label' => '重新检查'], ['type' => 'detail', 'label' => '查看证书']]];
        }

        usort($issues, static function ($a, $b) {
            return [$a['severity'], $a['since'] ?? '9999', $a['name']] <=> [$b['severity'], $b['since'] ?? '9999', $b['name']];
        });
        $counts = array_fill_keys(['total', 'unhealthy', 'stale', 'expiring', 'cert_expiring'], 0);
        foreach ($issues as $issue) { if (isset($counts[$issue['type']])) { $counts[$issue['type']]++; } }
        $counts['total'] = count($issues);
        return ['ok' => true, 'generated_at' => date('Y-m-d H:i:s', $now),
            'counts' => $counts, 'issues' => array_slice($issues, 0, self::MAX_ISSUES),
            'truncated' => count($issues) > self::MAX_ISSUES,
            'severity_labels' => self::SEVERITY,
            'usage' => 'AI 三步处理：1) 本接口定位问题（id 可在备注与提案中引用）；2) 记录结论：assets:write 更新资产备注，或以 proposals:write 提交变更提案（rationale 引用问题 id）；3) 处理后用 checks:run 重新检查，再读本接口确认问题消失。HTTPS 证书「验证失败」不在本清单，见 GET /api/https-review；「证书临期」（cert_expiring）依据检查观测的到期日进入本清单。'];
    }

    /** HTTPS 跟进清单：证书验证未通过、HTTPS 未确认、证据过时或证书临期（≤30 天）的域名，独立于可达性问题。 */
    public function httpsReview(): array
    {
        $dashboard = (new HealthService($this->dir))->dashboard();
        $now = ($this->clock)();
        $items = [];
        foreach ($dashboard['assets'] as $asset) {
            if ($asset['kind'] !== 'domain') { continue; }
            $health = $asset['health'];
            $https = $health['protocols']['https']['status'] ?? null;
            $invalidated = !empty($health['invalidated']);
            if ($invalidated) { $https = null; }
            // 证书临期：即使 HTTPS 验证正常，到期日进入 30 天窗口也进入跟进清单。
            $cert = $health['cert'] ?? null;
            $certDays = null;
            if (is_array($cert) && !empty($cert['expires_at'])) {
                $expiry = strtotime($cert['expires_at'] . ' 23:59:59');
                if ($expiry !== false) { $certDays = (int) ceil(($expiry - $now) / 86400); }
            }
            $httpsHealthy = $https === 'healthy' && !$invalidated;
            if ($httpsHealthy && ($certDays === null || $certDays > 30)) { continue; }
            $http = $health['protocols']['http'] ?? null;
            $state = $invalidated ? 'pending' : ($https === null ? 'unknown' : ($https === 'unhealthy' ? 'fail' : 'unknown'));
            if ($state === 'unknown' && ($health['status'] ?? '') === 'stale') { $state = 'stale'; }
            if ($httpsHealthy && $certDays !== null && $certDays <= 30) { $state = 'expiring'; }
            $items[] = ['asset_id' => $asset['id'], 'name' => $asset['name'], 'provider' => $asset['provider'] ?? '',
                'state' => $state,
                'state_label' => ['fail' => 'HTTPS 异常', 'pending' => 'DNS 已更新待复检', 'stale' => '证据过时', 'unknown' => 'HTTPS 未确认', 'expiring' => '证书临期'][$state],
                'url' => $health['https_url'] ?? ('https://' . strtolower((string) $asset['name']) . '/'),
                'http_status' => $http['status'] ?? null, 'http_message' => $http ? 'HTTP 返回 ' . ($http['code'] ?? '?') : null,
                'https_message' => $health['checks'][2]['message'] ?? ($health['message'] ?: null),
                'cert_expires_at' => $cert['expires_at'] ?? null,
                'cert_days_left' => $certDays !== null && $certDays >= 0 ? $certDays : ($certDays !== null ? 0 : null),
                'cert_issuer' => $cert['issuer'] ?? null,
                'checked_at' => $health['checked_at'] ?? null,
                'ips' => array_slice($health['resolved_ips'] ?? [], 0, 5)];
        }
        usort($items, static function ($a, $b) {
            return [$a['state'] === 'fail' ? 0 : 1, $a['name']] <=> [$b['state'] === 'fail' ? 0 : 1, $b['name']];
        });
        $counts = array_fill_keys(['total', 'fail', 'pending', 'stale', 'unknown', 'expiring'], 0);
        foreach ($items as $item) { $counts[$item['state']]++; }
        $counts['total'] = count($items);
        return ['ok' => true, 'generated_at' => date('Y-m-d H:i:s', $now),
            'counts' => $counts, 'items' => $items,
            'usage' => '可达性判定只看 HTTP（健康看板与 /api/issues 不受本清单影响）。本清单用于跟进 HTTPS 证书与 HTTPS 可达性：fail 需更换/修复证书，expiring 为证书 30 天内到期（问题中心同源提示），unknown 建议重新检查确认。'];
    }

    private function item(string $type, string $severity, array $health, array $asset, string $summary, array $actions): array
    {
        $actions[] = ['type' => 'detail', 'label' => '查看详情'];
        return ['id' => $type . ':' . $asset['kind'] . ':' . $asset['name'], 'severity' => $severity,
            'type' => $type, 'summary' => $summary, 'since' => $health['checked_at'] ?? null, 'due' => null, 'actions' => $actions];
    }
}
