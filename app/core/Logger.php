<?php

/**
 * 日志：追加写入 data/sync_log.json（每条含时间、类型、内容）。
 */
class Logger
{
    /** 运行日志保留上限：超出后丢弃最旧记录，避免 sync_log.json 无限增长。 */
    private const MAX_ENTRIES = 500;

    public static function log(string $type, array $data = []): void
    {
        JsonStore::append(DATA_DIR . '/sync_log.json', [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'data' => $data,
        ], self::MAX_ENTRIES);
    }

    public static function recent(int $limit = 50): array
    {
        $logs = JsonStore::read(DATA_DIR . '/sync_log.json', []);
        if (!is_array($logs)) {
            return [];
        }
        return array_slice(array_reverse($logs), 0, $limit);
    }
}
