<?php

require_once __DIR__ . '/TrendService.php';
require_once __DIR__ . '/AiProposalService.php';

/**
 * 监控平台对接：以 Prometheus 文本格式（exposition format 0.0.4）输出资产与
 * 提案状态指标。只读；所有数值来自缓存证据，不触发检查或同步。
 */
class MetricsService
{
    private $dir;
    private $clock;

    public function __construct(?string $dir = null, ?callable $clock = null)
    {
        $this->dir = $dir ?? DATA_DIR;
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function text(): string
    {
        $dashboard = (new HealthService($this->dir))->dashboard();
        $summary = $dashboard['summary'];
        $trend = (new TrendService($this->dir, $this->clock))->series()['summary'];
        $proposals = (new AiProposalService($this->dir))->counts();
        $pending = 0;
        foreach ($dashboard['assets'] as $asset) {
            if ($asset['kind'] !== 'domain') { continue; }
            $health = $asset['health'];
            $https = $health['protocols']['https']['status'] ?? null;
            if (!empty($health['invalidated'])) { $https = null; }
            if ($https !== 'healthy') { $pending++; }
        }

        $lines = [];
        $emit = static function (string $name, string $help, string $type, array $samples) use (&$lines): void {
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' ' . $type;
            foreach ($samples as $labels => $value) {
                $lines[] = $name . ($labels === '' ? '' : '{' . $labels . '}') . ' ' . $value;
            }
        };
        $emit('asset_workspace_assets', '按类型统计的受管资产数量。', 'gauge', [
            'kind="domain"' => (int) ($summary['domains'] ?? 0), 'kind="ip"' => (int) ($summary['ips'] ?? 0)]);
        $emit('asset_workspace_assets_by_health', '按最近一次检查结果统计的资产数量。', 'gauge', [
            'status="healthy"' => (int) ($summary['healthy'] ?? 0), 'status="unhealthy"' => (int) ($summary['unhealthy'] ?? 0),
            'status="stale"' => (int) ($summary['stale'] ?? 0), 'status="unchecked"' => (int) ($summary['unchecked'] ?? 0)]);
        $emit('asset_workspace_domains_pending_https', '最近一次 HTTPS 检查未通过或尚未确认的主域名数量。', 'gauge', [
            '' => $pending]);
        $emit('asset_workspace_expiring_in_30d', '30 天内到期的资产数量（域名与服务器）。', 'gauge', [
            '' => (int) ($summary['expiring_30'] ?? 0)]);
        $emit('asset_workspace_subdomains', '已缓存的子域名总数。', 'gauge', [
            '' => (int) ($summary['subdomains'] ?? 0)]);
        $emit('asset_workspace_proposals', 'AI 变更提案按状态的数量。', 'gauge', [
            'status="pending"' => (int) ($proposals['pending'] ?? 0), 'status="executed"' => (int) ($proposals['executed'] ?? 0),
            'status="failed"' => (int) ($proposals['failed'] ?? 0), 'status="rejected"' => (int) ($proposals['rejected'] ?? 0)]);
        $emit('asset_workspace_checks_30d', '最近 30 天完成的单项检查数量。', 'counter', [
            '' => (int) $trend['checks_30d']]);
        $emit('asset_workspace_checks_unhealthy_30d', '最近 30 天检查异常数量。', 'counter', [
            '' => (int) $trend['unhealthy_30d']]);
        $emit('asset_workspace_checks_avg_latency_ms', '最近 30 天平均检查延迟（毫秒）。', 'gauge', [
            '' => (int) ($trend['avg_latency_ms'] ?? 0)]);
        $emit('asset_workspace_last_checked_timestamp_seconds', '最近一次检查的 Unix 时间戳；从未检查为 0。', 'gauge', [
            '' => $dashboard['last_checked_at'] ? (string) strtotime($dashboard['last_checked_at']) : '0']);
        return implode("\n", $lines) . "\n";
    }
}
