<?php

/** Coordinates local asset writes and recovers an interrupted multi-file restore. */
class WorkspaceData
{
    private static array $locks = [];
    public const FILES = ['domains.json', 'health.json', 'access_check.json', 'record_access.json', 'meta.json'];

    public static function acquire(string $dir): void
    {
        if (isset(self::$locks[$dir])) { return; }
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('无法创建数据目录'); }
        $lock = fopen($dir . '/workspace.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('资产数据正忙，请重试'); }
        chmod($dir . '/workspace.lock', 0600);
        self::$locks[$dir] = $lock;
        try { self::recoverLocked($dir); }
        catch (Throwable $e) { self::release($dir); throw $e; }
    }

    public static function release(string $dir): void
    {
        if (!isset(self::$locks[$dir])) { return; }
        flock(self::$locks[$dir], LOCK_UN);
        fclose(self::$locks[$dir]);
        unset(self::$locks[$dir]);
    }

    public static function locked(string $dir, callable $work)
    {
        $owned = !isset(self::$locks[$dir]);
        self::acquire($dir);
        try { return $work(); }
        finally { if ($owned) { self::release($dir); } }
    }

    public static function recover(string $dir): void
    {
        self::locked($dir, static function () {});
    }

    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) { return $default; }
        $raw = file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if ($raw === false || !is_array($data)) { throw new RuntimeException('资产文件损坏，已停止操作以保护数据'); }
        return $data;
    }

    public static function write(string $path, array $data): void
    {
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($raw === false) { throw new RuntimeException('资产数据编码失败'); }
        self::writeRaw($path, $raw);
    }

    private static function writeRaw(string $path, string $raw): void
    {
        $temp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $file = fopen($temp, 'x');
        if (!$file) { throw new RuntimeException('无法创建资产文件'); }
        try {
            try {
                if (!chmod($temp, 0600) || fwrite($file, $raw) !== strlen($raw) || !fflush($file)) {
                    throw new RuntimeException('资产文件未完整写入');
                }
            } finally { fclose($file); }
            if (!rename($temp, $path)) { throw new RuntimeException('无法替换资产文件'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
    }

    /** Journal contains only fixed local files; uploaded paths are never used. */
    public static function replace(string $dir, array $files): void
    {
        self::locked($dir, static function () use ($dir, $files) {
            $before = [];
            foreach ($files as $name => $data) {
                if (!in_array($name, self::FILES, true) || !is_array($data)) { throw new InvalidArgumentException('恢复文件范围无效'); }
                $path = $dir . '/' . $name;
                $before[$name] = is_file($path) ? file_get_contents($path) : null;
                if ($before[$name] === false) { throw new RuntimeException('无法读取恢复前的数据'); }
            }
            $before['workspace-epoch.json'] = is_file($dir . '/workspace-epoch.json') ? file_get_contents($dir . '/workspace-epoch.json') : null;
            self::write($dir . '/restore-journal.json', ['before' => $before]);
            try {
                foreach ($files as $name => $data) { self::write($dir . '/' . $name, $data); }
                self::write($dir . '/workspace-epoch.json', ['epoch' => bin2hex(random_bytes(16))]);
                if (!unlink($dir . '/restore-journal.json')) { throw new RuntimeException('无法完成恢复事务'); }
            } catch (Throwable $e) { self::recoverLocked($dir); throw $e; }
        });
    }

    private static function recoverLocked(string $dir): void
    {
        $path = $dir . '/restore-journal.json';
        if (!is_file($path)) { return; }
        $journal = self::read($path);
        $before = $journal['before'] ?? null;
        if (!is_array($before)) { throw new RuntimeException('恢复日志损坏，需要人工检查'); }
        foreach ($before as $name => $raw) {
            if (!in_array($name, array_merge(self::FILES, ['workspace-epoch.json']), true) || (!is_string($raw) && $raw !== null)) {
                throw new RuntimeException('恢复日志格式无效');
            }
        }
        foreach ($before as $name => $raw) {
            if ($raw === null) {
                if (is_file($dir . '/' . $name) && !unlink($dir . '/' . $name)) { throw new RuntimeException('无法回滚恢复'); }
            } else { self::writeRaw($dir . '/' . $name, $raw); }
        }
        if (!unlink($path)) { throw new RuntimeException('无法完成数据回滚'); }
    }

    public static function revision(string $dir): string
    {
        $parts = [];
        foreach (self::FILES as $name) {
            $path = $dir . '/' . $name;
            $parts[] = is_file($path) ? hash_file('sha256', $path) : 'missing';
        }
        return hash('sha256', implode('|', $parts));
    }

    public static function epoch(string $dir): string
    {
        return (string) (self::read($dir . '/workspace-epoch.json')['epoch'] ?? 'initial');
    }
}
