<?php

class MaintenanceController
{
    private function service(): MaintenanceService
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        return new MaintenanceService(DATA_DIR, array_column(config('accounts') ?? [], 'id'));
    }

    public function index(): void
    {
        $service = $this->service();
        json_out(['ok' => true, 'version' => AssetSnapshot::VERSION, 'backups' => $service->backups(),
            'counts' => AssetSnapshot::counts(AssetSnapshot::capture(DATA_DIR)), 'checks' => DeliveryCheck::run(), 'audit' => AuditLog::recent(),
            'dns_changes' => DnsChangeService::recent(DATA_DIR)]);
    }

    public function backup(): void { json_out(['ok' => true, 'backup' => $this->service()->backup()], 201); }
    public function download(string $id): void { json_out(['ok' => true] + $this->service()->download($id)); }
    public function deleteBackup(string $id): void { $this->service()->deleteBackup($id); json_out(['ok' => true]); }
    public function export(): void { json_out(['ok' => true] + $this->service()->export((string) ($_GET['format'] ?? 'json'))); }
    public function preview(): void
    {
        $service = $this->service();
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AssetSnapshot::MAX_BYTES * 2) { throw new InvalidArgumentException('请求过大，资产文件不能超过 5 MB'); }
        json_out(['ok' => true, 'preview' => $service->preview(body_json(), settings_service()->fingerprint() . '|' . Auth::csrfToken())]);
    }
    public function restore(): void
    {
        $service = $this->service(); $body = body_json();
        if (($body['confirmation'] ?? '') !== '恢复资产') { throw new InvalidArgumentException('请输入“恢复资产”确认替换当前资产'); }
        if (!is_string($body['password'] ?? null) || !settings_service()->verifyPassword($body['password'])) {
            json_out(['ok' => false, 'error' => '管理员密码不正确'], 403);
        }
        json_out($service->restore((string) ($body['token'] ?? ''), settings_service()->fingerprint() . '|' . Auth::csrfToken()));
    }
}
