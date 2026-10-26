<?php

/** Bounded operation journal. Never records request bodies, credentials or responses. */
class AuditLog
{
    private static ?string $pending = null;

    public static function begin(string $method, string $route, array $params): void
    {
        if (in_array($method, ['GET', 'HEAD'], true) || !Auth::check()) { return; }
        $id = bin2hex(random_bytes(12));
        $entry = ['id' => $id, 'started_at' => date('c'), 'finished_at' => null, 'actor' => Auth::user(),
            'via' => Auth::isBearer() ? 'api' : 'browser', 'method' => $method, 'route' => $route,
            'targets' => array_map(static function ($v) { return mb_substr((string) $v, 0, 253); }, $params), 'outcome' => 'pending', 'http_status' => null];
        (new PrivateJsonStore(DATA_DIR . '/audit.json'))->update(static function ($state) use ($entry) {
            $state['entries'] = array_slice(array_merge($state['entries'] ?? [], [$entry]), -1000);
            return $state;
        });
        self::$pending = $id;
    }

    public static function finish($data, int $status): void
    {
        if (self::$pending === null) { return; }
        $id = self::$pending; self::$pending = null;
        $success = $status < 400 && !(is_array($data) && (($data['ok'] ?? true) === false || isset($data['error'])));
        $outcome = $success ? 'success' : 'failed';
        if ($success && is_array($data)) {
            if (in_array($data['status'] ?? '', ['failed', 'partial', 'skipped'], true)) { $outcome = $data['status']; }
            else {
                $items = $data['results'] ?? $data;
                if (is_array($items)) {
                    $ok = 0; $failed = 0;
                    foreach ($items as $item) {
                        if (!is_array($item) || !is_bool($item['ok'] ?? null)) { continue; }
                        $item['ok'] ? $ok++ : $failed++;
                    }
                    if ($failed) { $outcome = $ok ? 'partial' : 'failed'; }
                }
            }
        }
        try {
            (new PrivateJsonStore(DATA_DIR . '/audit.json'))->update(static function ($state) use ($id, $status, $outcome) {
                foreach ($state['entries'] ?? [] as $i => $entry) {
                    if ($entry['id'] !== $id) { continue; }
                    $state['entries'][$i]['finished_at'] = date('c');
                    $state['entries'][$i]['outcome'] = $outcome;
                    $state['entries'][$i]['http_status'] = $status;
                    break;
                }
                return $state;
            });
        } catch (Throwable $e) { error_log('Operation journal completion failed; pending entry retained.'); }
    }

    public static function recent(): array
    {
        return array_reverse(array_slice((new PrivateJsonStore(DATA_DIR . '/audit.json'))->read()['entries'] ?? [], -100));
    }
}
