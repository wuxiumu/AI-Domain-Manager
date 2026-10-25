<?php

/** Atomic, owner-readable storage for configuration and token hashes outside public/. */
class PrivateJsonStore
{
    private string $path;

    public function __construct(string $path) { $this->path = $path; }

    public function read(): array
    {
        if (!is_file($this->path)) { return []; }
        $raw = file_get_contents($this->path);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) { throw new RuntimeException('配置存储无法读取，请检查服务器文件'); }
        return $data;
    }

    public function update(callable $change): array
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建配置目录');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if (!$lock) { throw new RuntimeException('无法锁定配置文件'); }
        chmod($this->path . '.lock', 0600);
        $temp = $this->path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('配置文件正忙，请重试'); }
            $next = $change($this->read());
            if (!is_array($next)) { throw new RuntimeException('配置存储格式错误'); }
            $raw = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($raw === false) { throw new RuntimeException('配置包含无法保存的字符'); }
            $file = fopen($temp, 'x');
            if (!$file) { throw new RuntimeException('无法创建配置文件'); }
            try {
                if (!chmod($temp, 0600) || fwrite($file, $raw) !== strlen($raw) || !fflush($file)) {
                    throw new RuntimeException('配置文件未完整保存');
                }
            } finally { fclose($file); }
            if (!rename($temp, $this->path)) { throw new RuntimeException('无法更新配置文件'); }
            return $next;
        } finally {
            if (is_file($temp)) { unlink($temp); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
