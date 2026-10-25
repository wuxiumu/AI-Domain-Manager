<?php

/** Run the host resolver in a disposable CLI process with a hard deadline. */
class DnsResolver
{
    public static function lookup(string $host, float $timeout = 4.0): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('当前环境未开放有界 DNS 检查所需的 proc_open，请启用后重试');
        }
        $binary = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' ? PHP_BINARY : dirname(PHP_BINARY) . '/php';
        if (!is_executable($binary)) {
            throw new RuntimeException('未找到用于 DNS 检查的 PHP CLI，请配置运行环境');
        }
        // No shell; host input travels through stdin and is never executable code.
        $code = '$host=trim(stream_get_contents(STDIN)); $records=@dns_get_record($host,DNS_A|DNS_AAAA); '
            . 'if (!$records) {$records=@dns_get_record($host,DNS_CNAME);} '
            . 'echo json_encode(is_array($records)?$records:[]);';
        $process = @proc_open([$binary, '-n', '-r', $code], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('无法启动 DNS 检查进程'); }
        $deadline = microtime(true) + max(0.1, $timeout);
        $output = '';
        try {
            fwrite($pipes[0], $host);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            while (true) {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (strlen($output) > 131072) { throw new RuntimeException('DNS 响应超出检查上限'); }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) { throw new RuntimeException('DNS 查询超时，请检查本机解析器或稍后重试'); }
                $read = [$pipes[1], $pipes[2]]; $write = null; $except = null;
                $wait = min(0.05, $remaining);
                @stream_select($read, $write, $except, 0, (int) ($wait * 1000000));
            }
            $records = json_decode($output, true);
            if (!is_array($records)) { throw new RuntimeException('DNS 检查进程未返回有效结果'); }
            return $records;
        } finally {
            if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
            proc_close($process);
        }
    }
}
