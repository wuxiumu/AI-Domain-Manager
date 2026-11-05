<?php

/**
 * 趋势与指标：人看的图表数据（/api/trends）与监控平台抓取的指标（/api/metrics）。
 * 两者都只读缓存证据，不触发检查或同步。
 */
class AnalyticsController
{
    public function trends(): void
    {
        Auth::requireLogin();
        try {
            json_out((new TrendService())->series());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '趋势数据读取失败：' . $e->getMessage()], 500);
        }
    }

    public function issues(): void
    {
        Auth::requireLogin();
        try {
            json_out((new IssueService())->issues());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '问题清单读取失败：' . $e->getMessage()], 500);
        }
    }

    public function httpsReview(): void
    {
        Auth::requireLogin();
        try {
            json_out((new IssueService())->httpsReview());
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => 'HTTPS 跟进清单读取失败：' . $e->getMessage()], 500);
        }
    }

    public function metrics(): void
    {
        Auth::requireLogin();
        try {
            $body = (new MetricsService())->text();
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '指标生成失败：' . $e->getMessage()], 500);
        }
        header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
    }
}
