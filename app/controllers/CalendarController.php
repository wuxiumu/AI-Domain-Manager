<?php
class CalendarController
{
    public function index(): void {
        Auth::requireSession();
        $result = (new CalendarService())->listing((string) ($_GET['month'] ?? date('Y-m')));
        $dashboard = (new HealthService())->dashboard();
        $overview = [];
        foreach (['domain', 'ip', 'subdomain'] as $kind) { $overview[$kind] = ['total' => 0, 'healthy' => 0, 'unhealthy' => 0, 'stale' => 0, 'unchecked' => 0]; }
        $count = static function (array $asset, string $kind) use (&$overview): void {
            $status = $asset['health']['status'] ?? 'unchecked';
            $overview[$kind]['total']++;
            if (isset($overview[$kind][$status])) { $overview[$kind][$status]++; }
        };
        foreach ($dashboard['assets'] as $asset) {
            $count($asset, $asset['kind']);
            if ($asset['kind'] === 'domain') foreach ($asset['subdomains'] ?? [] as $host) { if ($host['checkable']) $count($host, 'subdomain'); }
        }
        $result['overview'] = $overview;
        $result['dns_uncached_domains'] = $dashboard['summary']['dns_uncached_domains'];
        $result['overview_at'] = $dashboard['generated_at'];
        json_out($result);
    }
    public function createTask(): void { Auth::requireSession(); json_out((new CalendarService())->saveTask(body_json())); }
    public function updateTask(string $id): void { Auth::requireSession(); json_out((new CalendarService())->saveTask(body_json(), $id)); }
    public function deleteTask(string $id): void { Auth::requireSession(); json_out((new CalendarService())->deleteTask($id)); }
    public function addRun(): void { Auth::requireSession(); json_out((new CalendarService())->addRun(body_json())); }
    public function saveCheckin(string $date): void { Auth::requireSession(); json_out((new CalendarService())->saveCheckin($date, body_json())); }
    public function deleteCheckin(string $date): void { Auth::requireSession(); json_out((new CalendarService())->deleteCheckin($date)); }
}
