<?php

class HealthController
{
    public function refreshLocation(string $id): void
    {
        Auth::requireLogin();
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $this->respond(static function () use ($id) { return (new IpLocationService(DATA_DIR))->refresh($id); });
    }

    /** 记录浏览器侧探测结果；仅管理员会话（探测走管理员浏览器网络，密钥视角无法产生该证据）。 */
    public function browserProbe(string $id): void
    {
        Auth::requireSession();
        $body = body_json();
        $reachable = $body['reachable'] ?? null;
        $ms = $body['ms'] ?? null;
        if (!is_bool($reachable)) {
            json_out(['ok' => false, 'error' => 'reachable 必须为布尔值'], 422);
        }
        if (!is_int($ms) || $ms < 0 || $ms > 60000) {
            json_out(['ok' => false, 'error' => 'ms 必须为 0–60000 的整数'], 422);
        }
        $this->respond(static function () use ($id, $reachable, $ms) {
            return (new HealthService())->recordBrowserProbe($id, $reachable, $ms);
        });
    }

    public function operations(): void
    {
        Auth::requireLogin();
        $this->respond(static function () { return (new HealthService())->operations(); });
    }

    public function index(): void
    {
        Auth::requireLogin();
        // 台账未变化时直接 304，省去快照重建与全量载荷传输。
        conditional_get(asset_etag());
        $this->respond(static function () { return (new HealthService())->dashboard(); });
    }

    /** AI 协同入口：把缓存证据整理成可直接交给 AI 分析的报告。 */
    public function report(): void
    {
        Auth::requireLogin();
        try {
            $report = (new AiReportService())->report();
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '生成报告失败：' . $e->getMessage()], 500);
        }
        $format = strtolower(trim((string) ($_GET['format'] ?? '')));
        if ($format === 'markdown' || $format === 'md') {
            // GET requests are not journalled; plain markdown is safe to emit directly.
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: inline; filename="health-report.md"');
            header('Cache-Control: no-store');
            echo $report['markdown'];
            return;
        }
        json_out($report);
    }

    public function addIp(): void
    {
        Auth::requireLogin();
        $body = body_json();
        $this->respond(static function () use ($body) { return (new HealthService())->addIp($body); });
    }

    public function deleteIp(string $id): void
    {
        Auth::requireLogin();
        $this->respond(static function () use ($id) { return (new HealthService())->deleteIp($id); });
    }

    public function check(): void
    {
        Auth::requireLogin();
        $body = body_json();
        if (!isset($body['asset_ids']) || !is_array($body['asset_ids'])) {
            json_out(['ok' => false, 'error' => 'asset_ids 必须是包含 1 至 5 个资产 ID 的数组'], 422);
        }
        // Release PHP's session lock before bounded network work, so polling and
        // other browser requests remain responsive during a batch.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        @set_time_limit(90);
        $this->respond(static function () use ($body) { return (new HealthService())->check($body['asset_ids']); });
    }

    public function updateIp(string $id): void
    {
        Auth::requireLogin();
        $body = body_json();
        $this->respond(static function () use ($id, $body) { return (new HealthService())->updateIp($id, $body); });
    }

    public function bindings(string $id): void
    {
        Auth::requireLogin();
        $body = body_json();
        $this->respond(static function () use ($id, $body) { return (new HealthService())->saveBindings($id, $body); });
    }

    private function respond(callable $work): void
    {
        try {
            json_out($work());
        } catch (InvalidArgumentException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (OutOfBoundsException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '健康检查服务失败：' . $e->getMessage()], 500);
        }
    }
}
