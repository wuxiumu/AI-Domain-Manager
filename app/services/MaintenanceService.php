<?php

class MaintenanceService
{
    private string $dir;
    private array $accountIds;

    public function __construct(string $dir, array $accountIds = []) { $this->dir = $dir; $this->accountIds = $accountIds; }

    public function backup(string $reason = 'manual'): array
    {
        return WorkspaceData::locked($this->dir, function () use ($reason) {
            $folder = $this->dir . '/backups';
            $this->privateDirectory($folder);
            if (count(glob($folder . '/*.json') ?: []) >= 30) { throw new DomainException('备份已达 30 份，请先下载并删除不需要的旧备份'); }
            $snapshot = AssetSnapshot::capture($this->dir);
            // Validate without converting existing account IDs in this local snapshot.
            AssetSnapshot::validate($snapshot, array_unique(array_merge($this->accountIds, array_column($snapshot['domains'], 'provider'))));
            $id = bin2hex(random_bytes(16));
            $snapshot['reason'] = $reason;
            if (strlen((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) > AssetSnapshot::MAX_BYTES) {
                throw new DomainException('资产备份超过 5 MB，请联系维护人员安排文件级备份');
            }
            WorkspaceData::write($folder . '/' . $id . '.json', $snapshot);
            return $this->describe($id, $snapshot);
        });
    }

    public function backups(): array
    {
        $entries = [];
        foreach (glob($this->dir . '/backups/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            try { $entries[] = $this->describe($id, AssetSnapshot::decode((string) file_get_contents($path))); }
            catch (Throwable $e) { $entries[] = ['id' => $id, 'corrupt' => true, 'created_at' => '', 'counts' => null]; }
        }
        usort($entries, static function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
        return $entries;
    }

    public function download(string $id): array
    {
        $path = $this->backupPath($id);
        $content = file_get_contents($path);
        if ($content === false) { throw new RuntimeException('无法读取备份'); }
        AssetSnapshot::decode($content);
        return ['filename' => 'asset-workspace-' . $id . '.json', 'content' => $content, 'sha256' => hash('sha256', $content)];
    }

    public function deleteBackup(string $id): void
    {
        WorkspaceData::locked($this->dir, function () use ($id) {
            if (!unlink($this->backupPath($id))) { throw new RuntimeException('无法删除备份'); }
        });
    }

    public function export(string $format): array
    {
        return WorkspaceData::locked($this->dir, function () use ($format) {
            $snapshot = AssetSnapshot::capture($this->dir);
            if ($format === 'csv') { $content = AssetSnapshot::csv($snapshot); }
            elseif ($format === 'json') { $content = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
            else { throw new InvalidArgumentException('仅支持 JSON 或 CSV 导出'); }
            if ($content === false || strlen($content) > AssetSnapshot::MAX_BYTES) { throw new DomainException('导出超过 5 MB，请联系维护人员拆分资料'); }
            return ['filename' => 'assets-' . date('Ymd-His') . '.' . $format, 'content' => $content, 'sha256' => hash('sha256', $content)];
        });
    }

    public function preview(array $body, string $identity): array
    {
        return WorkspaceData::locked($this->dir, function () use ($body, $identity) {
            if (isset($body['backup_id']) && isset($body['content'])) { throw new InvalidArgumentException('请选择一种恢复来源'); }
            $raw = isset($body['backup_id']) ? $this->download((string) $body['backup_id'])['content'] : ($body['content'] ?? null);
            if (!is_string($raw)) { throw new InvalidArgumentException('请选择资产备份文件'); }
            $snapshot = AssetSnapshot::decode($raw);
            $validated = AssetSnapshot::validate($snapshot, $this->accountIds);
            $current = AssetSnapshot::capture($this->dir);
            $folder = $this->dir . '/restore-previews';
            $this->privateDirectory($folder);
            foreach (glob($folder . '/*.json') ?: [] as $path) { if (filemtime($path) < time() - 900) { unlink($path); } }
            if (count(glob($folder . '/*.json') ?: []) >= 20) { throw new DomainException('预览过多，请 15 分钟后重试'); }
            $token = bin2hex(random_bytes(24));
            $expires = time() + 900;
            $plan = ['identity' => $identity, 'expires' => $expires, 'revision' => WorkspaceData::revision($this->dir),
                'files' => $validated['files'], 'sha256' => hash('sha256', $raw)];
            WorkspaceData::write($folder . '/' . $token . '.json', $plan);
            return ['token' => $token, 'expires_at' => date('c', $expires), 'sha256' => $plan['sha256'],
                'before' => AssetSnapshot::counts($current), 'after' => $validated['counts'], 'converted_domains' => $validated['converted_domains'],
                'orphaned_bindings' => $validated['orphaned_bindings'], 'mode' => 'replace', 'clears_checks' => true];
        });
    }

    public function restore(string $token, string $identity): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) { throw new InvalidArgumentException('恢复预览无效，请重新预览'); }
        return WorkspaceData::locked($this->dir, function () use ($token, $identity) {
            $path = $this->dir . '/restore-previews/' . $token . '.json';
            if (!is_file($path)) { throw new DomainException('恢复预览已使用或不存在，请重新预览'); }
            $plan = WorkspaceData::read($path);
            if (($plan['expires'] ?? 0) < time() || !hash_equals((string) ($plan['identity'] ?? ''), $identity)) {
                throw new DomainException('恢复预览已失效，请重新预览');
            }
            if (!hash_equals((string) ($plan['revision'] ?? ''), WorkspaceData::revision($this->dir))) {
                throw new DomainException('预览后资产或检查结果已变化，请重新预览以免覆盖新数据');
            }
            $safety = $this->backup('before-restore');
            $files = $plan['files'];
            $files['meta.json'] = ['status' => 'restored', 'restored_at' => date('Y-m-d H:i:s')];
            WorkspaceData::replace($this->dir, $files);
            // Consume only after a committed restore; an expired preview cannot be replayed.
            unlink($path);
            return ['ok' => true, 'safety_backup' => $safety, 'message' => '资产已恢复，历史检查缓存已清空，请重新检查状态'];
        });
    }

    private function backupPath(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new InvalidArgumentException('备份标识无效'); }
        $path = $this->dir . '/backups/' . $id . '.json';
        if (!is_file($path) || is_link($path)) { throw new OutOfBoundsException('备份不存在'); }
        return $path;
    }

    private function privateDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('无法创建备份目录'); }
        if (!chmod($dir, 0700)) { throw new RuntimeException('无法保护备份目录'); }
    }

    private function describe(string $id, array $snapshot): array
    {
        $path = $this->dir . '/backups/' . $id . '.json';
        return ['id' => $id, 'created_at' => $snapshot['created_at'] ?? '', 'reason' => $snapshot['reason'] ?? 'manual',
            'counts' => AssetSnapshot::counts($snapshot), 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'corrupt' => false];
    }
}
