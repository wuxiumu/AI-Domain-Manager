<?php

/**
 * /api/handover：AI 接管工作台的交接简报。一次读取即可知道当前状况、
 * 自己持有的权限和可执行的动作序列；只读，不触发任何检查或同步。
 */
class HandoverController
{
    public function index(): void
    {
        Auth::requireLogin();
        try {
            json_out($this->briefing());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '交接简报生成失败：' . $e->getMessage()], 500);
        }
    }

    private function briefing(): array
    {
        $dashboard = (new HealthService())->dashboard();
        $summary = $dashboard['summary'];
        $proposals = (new AiProposalService())->counts();

        $pendingIds = [];
        $unhealthyCount = 0;
        foreach ($dashboard['assets'] as $asset) {
            if ($asset['kind'] !== 'domain') { continue; }
            $health = $asset['health'];
            $https = $health['protocols']['https']['status'] ?? null;
            if (!empty($health['invalidated'])) { $https = null; }
            if ($https !== 'healthy' && count($pendingIds) < 5) { $pendingIds[] = $asset['id']; }
            if (($health['last_status'] ?? null) === 'unhealthy') { $unhealthyCount++; }
        }

        $next = [];
        if ($pendingIds) { $next[] = ['action' => 'check', 'summary' => '检查待运营域名（每批最多 5 个）', 'asset_ids' => $pendingIds, 'endpoint' => 'POST /api/health/check']; }
        $next[] = ['action' => 'report', 'summary' => '读取健康报告与分析提示词', 'endpoint' => 'GET /api/health/report'];
        $next[] = ['action' => 'propose', 'summary' => '发现需要改 DNS 时提交提案，等待管理员批准', 'endpoint' => 'POST /api/proposals'];

        return ['ok' => true, 'generated_at' => $dashboard['generated_at'],
            'scopes' => Auth::isBearer() ? Auth::scopes() : ['session' => '管理员会话，拥有全部能力；写操作需 X-CSRF-Token'],
            'summary' => array_intersect_key($summary, array_flip(['domains', 'ips', 'healthy', 'unhealthy', 'stale', 'unchecked', 'expiring_30', 'subdomains'])),
            'unhealthy_domains' => $unhealthyCount,
            'proposals' => $proposals,
            'next_actions' => $next,
            'playbook' => self::playbook()];
    }

    /** 与 llms.txt 工作流保持一致的机器可读操作序列。 */
    private static function playbook(): array
    {
        return [
            ['step' => 1, 'do' => '读取交接与统计', 'method' => 'GET', 'path' => '/api/handover', 'scope' => 'assets:read'],
            ['step' => 2, 'do' => '读取缓存资产与健康证据', 'method' => 'GET', 'path' => '/api/health', 'scope' => 'assets:read'],
            ['step' => 3, 'do' => '读取待运营与异常域名及解析 IP', 'method' => 'GET', 'path' => '/api/operations', 'scope' => 'assets:read'],
            ['step' => 4, 'do' => '读取健康报告与分析提示词', 'method' => 'GET', 'path' => '/api/health/report', 'scope' => 'assets:read'],
            ['step' => 5, 'do' => '分批检查资产（每批 ≤5 个 ID）', 'method' => 'POST', 'path' => '/api/health/check', 'scope' => 'checks:run'],
            ['step' => 6, 'do' => '读取 DNS 记录与版本（refresh=1 显式刷新）', 'method' => 'GET', 'path' => '/api/domains/{provider}/{domain}/records', 'scope' => 'dns:read'],
            ['step' => 7, 'do' => '不直接写 DNS：提交变更提案并等待管理员批准', 'method' => 'POST', 'path' => '/api/proposals', 'scope' => 'proposals:write'],
            ['step' => 8, 'do' => '跟踪提案状态（pending/executed/failed/rejected）', 'method' => 'GET', 'path' => '/api/proposals', 'scope' => 'proposals:write'],
            ['step' => 9, 'do' => '提案执行后重新检查目标资产，确认恢复', 'method' => 'POST', 'path' => '/api/health/check', 'scope' => 'checks:run'],
        ];
    }
}
