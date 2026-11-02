<?php

/** Cached, explicitly requested IP geolocation. The external provider sees queried IPs. */
class IpLocationService
{
    private string $dir;
    private $request;

    public function __construct(string $dir, ?callable $request = null)
    {
        $this->dir = $dir;
        $this->request = $request ?: static function (string $url): array { return HttpClient::get($url); };
    }

    public function refresh(string $id): array
    {
        $epoch = WorkspaceData::locked($this->dir, function () { return WorkspaceData::epoch($this->dir); });
        $asset = null;
        foreach ((new HealthService($this->dir))->dashboard()['assets'] as $candidate) {
            if ($candidate['id'] === $id && $candidate['kind'] === 'ip') { $asset = $candidate; break; }
        }
        if (!$asset) { throw new OutOfBoundsException('IP 资产不存在，请刷新后重试'); }
        $ip = $asset['ip'];
        if (!HealthService::isPublicIp($ip)) { throw new InvalidArgumentException('只支持查询公网 IP'); }
        try { $response = call_user_func($this->request, 'https://ipwho.is/' . rawurlencode($ip)); }
        catch (Throwable $e) { throw new RuntimeException('归属地服务连接失败，请稍后重试'); }
        if (($response['status'] ?? 0) === 429) { throw new RuntimeException('归属地服务已限流，请稍后重试'); }
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (($response['status'] ?? 0) !== 200 || !is_array($data) || ($data['success'] ?? false) !== true
            || !is_string($data['ip'] ?? null) || @inet_pton($data['ip']) !== @inet_pton($ip)) {
            throw new RuntimeException('归属地服务返回无效结果，请稍后重试');
        }
        $entry = ['ip' => $ip, 'source' => 'ipwho.is', 'checked_at' => date('Y-m-d H:i:s')];
        foreach (['country', 'country_code', 'region', 'city'] as $key) {
            $entry[$key] = is_string($data[$key] ?? null) ? mb_substr($data[$key], 0, 150) : '';
        }
        $entry['isp'] = is_string($data['connection']['isp'] ?? null) ? mb_substr($data['connection']['isp'], 0, 150) : '';
        WorkspaceData::locked($this->dir, function () use ($ip, $entry, $epoch) {
            if ($epoch !== WorkspaceData::epoch($this->dir)) { throw new DomainException('查询期间资产已恢复，请重试'); }
            (new PrivateJsonStore($this->dir . '/ip_locations.json'))->update(static function ($state) use ($ip, $entry) {
                $state[$ip] = $entry; return $state;
            });
        });
        return ['ok' => true, 'location' => $entry];
    }
}
