<?php

require_once __DIR__ . '/HealthService.php';

/**
 * 给人看的时间趋势：把 health.json 的逐项检查事件与延迟、calendar.json 的
 * 完整检查汇总，聚合为最近 30 天的每日序列。只读，不写任何状态文件。
 */
class TrendService
{
    public const DAYS = 30;

    private $dir;
    private $clock;

    public function __construct(?string $dir = null, ?callable $clock = null)
    {
        $this->dir = $dir ?? DATA_DIR;
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function series(): array
    {
        $now = ($this->clock)();
        $start = strtotime('today', $now) - (self::DAYS - 1) * 86400;
        $daily = [];
        for ($ts = $start; $ts <= $now; $ts += 86400) {
            $daily[date('Y-m-d', $ts)] = ['date' => date('Y-m-d', $ts), 'checks' => 0, 'healthy' => 0, 'unhealthy' => 0, 'latency_sum' => 0, 'latency_samples' => 0, 'full_runs' => 0];
        }
        $state = $this->readFile('health.json');
        foreach ($state['check_events'] ?? [] as $event) {
            if (!is_array($event)) { continue; }
            $ts = strtotime((string) ($event['checked_at'] ?? ''));
            if ($ts === false || $ts < $start || !isset($daily[date('Y-m-d', $ts)])) { continue; }
            $bucket = &$daily[date('Y-m-d', $ts)];
            $bucket['checks']++;
            if (($event['status'] ?? '') === 'healthy') { $bucket['healthy']++; }
            elseif (($event['status'] ?? '') === 'unhealthy') { $bucket['unhealthy']++; }
            unset($bucket);
        }
        foreach ($state['observations'] ?? [] as $observation) {
            if (!is_array($observation)) { continue; }
            foreach ($observation['history'] ?? [] as $entry) {
                if (!is_array($entry) || !isset($entry['latency_ms'])) { continue; }
                $ts = strtotime((string) ($entry['checked_at'] ?? ''));
                if ($ts === false || $ts < $start || !isset($daily[date('Y-m-d', $ts)])) { continue; }
                $bucket = &$daily[date('Y-m-d', $ts)];
                $bucket['latency_sum'] += (int) $entry['latency_ms'];
                $bucket['latency_samples']++;
                unset($bucket);
            }
        }
        $calendar = $this->readFile('calendar.json');
        foreach ($calendar['runs'] ?? [] as $run) {
            if (!is_array($run)) { continue; }
            $ts = strtotime((string) ($run['date'] ?? ''));
            if ($ts === false || $ts < $start || !isset($daily[date('Y-m-d', $ts)])) { continue; }
            $daily[date('Y-m-d', $ts)]['full_runs']++;
        }
        $checks = $healthy = $unhealthy = $runs = 0;
        $latencyTotal = $latencySamplesAll = 0;
        $result = [];
        foreach ($daily as $bucket) {
            $avg = $bucket['latency_samples'] ? (int) round($bucket['latency_sum'] / $bucket['latency_samples']) : null;
            $result[] = ['date' => $bucket['date'], 'checks' => $bucket['checks'], 'healthy' => $bucket['healthy'],
                'unhealthy' => $bucket['unhealthy'], 'full_runs' => $bucket['full_runs'], 'avg_latency_ms' => $avg];
            $checks += $bucket['checks']; $healthy += $bucket['healthy']; $unhealthy += $bucket['unhealthy'];
            $runs += $bucket['full_runs'];
            $latencyTotal += $bucket['latency_sum']; $latencySamplesAll += $bucket['latency_samples'];
        }
        return ['ok' => true, 'generated_at' => date('Y-m-d H:i:s', $now), 'days' => self::DAYS,
            'daily' => $result,
            'summary' => ['checks_30d' => $checks, 'healthy_30d' => $healthy, 'unhealthy_30d' => $unhealthy,
                'full_runs_30d' => $runs, 'active_days' => count(array_filter($daily, static function ($b) { return $b['checks'] > 0; })),
                'avg_latency_ms' => $latencySamplesAll ? (int) round($latencyTotal / $latencySamplesAll) : null],
            'current' => $this->current()];
    }

    /** 当前时点状态分布（与看板同源），供图表与监控对照。 */
    private function current(): array
    {
        $summary = (new HealthService($this->dir))->dashboard()['summary'] ?? [];
        return array_intersect_key($summary, array_flip(['domains', 'ips', 'healthy', 'unhealthy', 'stale', 'unchecked', 'expiring_30', 'subdomains']));
    }

    private function readFile(string $name): array
    {
        $path = $this->dir . '/' . $name;
        if (!is_file($path)) { return []; }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) { throw new RuntimeException($name . ' 数据文件格式损坏，已停止读取以保护数据'); }
        return $data;
    }
}
