<?php

/**
 * Fiber + curl_multi 并发编排：同一作业内的请求保持串行语义（按序挂起/恢复），
 * 不同作业之间并行，句柄总数由调用方批量上限决定（≤8）。
 * 环境不支持 Fiber 或 curl_multi 初始化失败时自动回退串行，行为与旧路径一致。
 */
class ConcurrentCurl
{
    /** 并发句柄上限；批量大小与此解耦（批量超过时由调用方分块）。 */
    public const MAX_CONCURRENCY = 8;

    private $multi = null;
    private array $handles = [];   // (int)handle => handle
    private array $fibers = [];    // (int)handle => Fiber

    /**
     * 并发执行作业。$jobs[id] => callable($self)：作业内用 $self->exec($ch) 发起可挂起请求。
     * 返回 id => ['ok' => true, 'value' => mixed] 或 ['ok' => false, 'error' => Throwable]。
     */
    public static function run(array $jobs): array
    {
        $scheduler = new self();
        return $scheduler->execute($jobs);
    }

    /** 当前是否具备并发条件（PHP ≥ 8.1 的 Fiber）。 */
    public static function supported(): bool
    {
        return class_exists('Fiber') && function_exists('curl_multi_init');
    }

    /**
     * 发起一个 curl 请求并取回结果。在并发作业内调用时挂起当前 Fiber，
     * 句柄完成后由调度器恢复；在并发之外调用时等价于阻塞 curl_exec。
     */
    public function exec($ch): array
    {
        if ($this->multi !== null) {
            $fiber = \Fiber::getCurrent();
            if ($fiber === null) { throw new LogicException('exec() 必须在并发作业内调用'); }
            curl_multi_add_handle($this->multi, $ch);
            $this->handles[(int) $ch] = $ch;
            $this->fibers[(int) $ch] = $fiber;
            \Fiber::suspend();
            $result = $this->takeResult($ch);
            curl_multi_remove_handle($this->multi, $ch);
            unset($this->handles[(int) $ch], $this->fibers[(int) $ch]);
            return $result;
        }
        curl_exec($ch);
        return $this->takeResult($ch);
    }

    private function execute(array $jobs): array
    {
        if (!self::supported()) { return self::runSerially($jobs); }
        $this->multi = curl_multi_init();
        if ($this->multi === false) { $this->multi = null; return self::runSerially($jobs); }
        $outcomes = [];
        $fibers = [];
        try {
            foreach ($jobs as $id => $job) {
                $fibers[$id] = new \Fiber(function () use ($job, &$outcomes, $id) {
                    try { $outcomes[$id] = ['ok' => true, 'value' => $job($this)]; }
                    catch (Throwable $e) { $outcomes[$id] = ['ok' => false, 'error' => $e]; }
                });
            }
            foreach ($fibers as $fiber) {
                $this->startFiber($fiber);
            }
            while ($this->handles) {
                curl_multi_exec($this->multi, $stillRunning);
                if ($stillRunning > 0 && curl_multi_select($this->multi, 1.0) === -1) { usleep(10000); }
                while (($info = curl_multi_info_read($this->multi)) !== false) {
                    $fiber = $this->fibers[(int) $info['handle']] ?? null;
                    if ($fiber !== null && $fiber->isSuspended()) {
                        try { $fiber->resume(); }
                        catch (Throwable) { /* 作业内部已把异常记入 outcome */ }
                    }
                }
            }
        } finally {
            foreach ($this->handles as $ch) { curl_multi_remove_handle($this->multi, $ch); }
            if ($this->multi !== null) { curl_multi_close($this->multi); }
            $this->multi = null;
            $this->handles = [];
            $this->fibers = [];
        }
        foreach ($fibers as $id => $fiber) {
            if (!isset($outcomes[$id])) {
                $outcomes[$id] = ['ok' => false, 'error' => new RuntimeException('并发探测作业未完成')];
            }
        }
        return $outcomes;
    }

    private function startFiber(\Fiber $fiber): void
    {
        try { $fiber->start(); }
        catch (Throwable) { /* 作业内部已把异常记入 outcome */ }
    }

    /** 不支持并发时的回退：逐个执行，返回结构完全一致。 */
    private static function runSerially(array $jobs): array
    {
        $scheduler = new self();
        $outcomes = [];
        foreach ($jobs as $id => $job) {
            try { $outcomes[$id] = ['ok' => true, 'value' => $job($scheduler)]; }
            catch (Throwable $e) { $outcomes[$id] = ['ok' => false, 'error' => $e]; }
        }
        return $outcomes;
    }

    private function takeResult($ch): array
    {
        return ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch),
            'error' => curl_error($ch), 'ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000)];
    }
}
