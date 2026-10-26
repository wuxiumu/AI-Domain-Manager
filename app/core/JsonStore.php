<?php

require_once __DIR__ . '/WorkspaceData.php';

/**
 * 文件数据库（JSON）：读加共享锁，写采用临时文件 + rename 原子替换。
 */
class JsonStore
{
    public static function read(string $file, $default = [])
    {
        if (!is_file($file)) {
            return $default;
        }
        $fp = fopen($file, 'r');
        if ($fp === false) {
            return $default;
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($raw, true);
        return is_array($data) || is_scalar($data) ? $data : $default;
    }

    public static function write(string $file, $data): bool
    {
        if (in_array(basename($file), WorkspaceData::FILES, true)) {
            return WorkspaceData::locked(dirname($file), static function () use ($file, $data) { return self::writeUnlocked($file, $data); });
        }
        return self::writeUnlocked($file, $data);
    }

    private static function writeUnlocked(string $file, $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('数据保存失败：JSON 编码错误（' . json_last_error_msg() . '）');
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('数据保存失败：无法创建数据目录');
        }
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(8));
        try {
            $written = @file_put_contents($tmp, $json);
            @chmod($tmp, 0600);
            if ($written === false || $written !== strlen($json)) {
                throw new RuntimeException('数据保存失败：无法完整写入临时文件');
            }
            if (!@rename($tmp, $file)) {
                throw new RuntimeException('数据保存失败：无法替换数据文件');
            }
            return true;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** 追加一条记录（数组文件） */
    public static function append(string $file, array $entry): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('记录追加失败：无法创建数据目录');
        }
        // 锁文件不随数据文件的原子替换而改变，覆盖完整的读取、修改、写回过程。
        // 仅协调 append 调用；其他调用方独立 read/write 不构成事务。
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('记录追加失败：无法打开锁文件');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('记录追加失败：无法取得文件锁');
            }
            $data = self::read($file, []);
            if (!is_array($data)) {
                $data = [];
            }
            $data[] = $entry;
            return self::write($file, $data);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
