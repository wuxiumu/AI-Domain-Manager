<?php

/**
 * 数据目录清点：按代码引用白名单分类 data/ 下的一切，报告孤儿候选。
 * 只读脚本——报告模式即唯一模式，永不删除任何文件；退出码 0 = 干净，1 = 存在孤儿候选。
 *
 * 分类规则：
 * - 顶层 *.json / *.lock：文件名（去 .lock）出现在 app/ + scripts/ + public/index.php 代码中 → 在用；
 * - cache/：`dns-*.json` 为 DnsRecordCache 结构化缓存；`<sha>.json` 中 sha 对应代码里
 *   SnapshotCache::remember 的已知 key（domain-screen-v1 / ip-screen-v1 …）→ 在用；其余 → 孤儿候选；
 * - dns-locks/：DnsRecordCache 运行锁，属正常运行产物；
 * - 其他目录：目录名无代码引用 → 孤儿目录候选。
 *
 * 用法：php scripts/cleanup-data.php [--dry-run]（--dry-run 为显式只读声明，行为与默认相同）
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../app/bootstrap.php';

$dataDir = DATA_DIR;
if (!is_dir($dataDir)) {
    echo "数据目录不存在：" . $dataDir . "\n";
    exit(1);
}

// 1) 收集代码引用面（app/ + scripts/ + public/index.php）。
$code = '';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), (string) $dataDir) === false) {
        $code .= (string) file_get_contents($file->getPathname());
    }
}

// 2) SnapshotCache 已知 key（代码中的字面量）→ 合法 cache/<sha>.json 集合。
$snapshotHashes = [];
preg_match_all("/SnapshotCache::remember\(\\\$dir,\s*'([^']+)'/", $code, $keys);
foreach ($keys[1] as $key) {
    $snapshotHashes[hash('sha256', $key)] = $key;
}

$orphans = [];
$referenced = [];
$report = static function (string $status, string $label, string $note = '') use (&$referenced, &$orphans): void {
    if ($status === 'orphan') {
        $orphans[] = $label;
        echo '  [孤儿候选] ' . $label . ($note !== '' ? ' —— ' . $note : '') . "\n";
        return;
    }
    $referenced[] = $label;
    echo '  [在用]     ' . $label . ($note !== '' ? ' —— ' . $note : '') . "\n";
};

echo "data/ 清点报告（只读模式，脚本永不删除文件）\n";

// 3) 顶层条目。
$entries = scandir($dataDir) ?: [];
sort($entries);
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    $path = $dataDir . '/' . $entry;
    if (is_dir($path)) { continue; }
    if (preg_match('/^(.+)\.lock$/', $entry, $match)) {
        // 锁附属文件归属：health.lock → health.json；ai_proposals.json.lock → ai_proposals.json。
        $base = preg_match('/\.json$/', $match[1]) ? $match[1] : $match[1] . '.json';
        $isKnown = $entry === 'workspace.lock' || strpos($code, $base) !== false || strpos($code, $entry) !== false;
        $report($isKnown ? 'used' : 'orphan', $entry, $isKnown ? '在用存储的锁附属文件' : '引用方未知');
        continue;
    }
    $report(strpos($code, $entry) !== false ? 'used' : 'orphan', $entry,
        strpos($code, $entry) !== false ? '代码引用' : '引用方未知；确认功能已下线后可删除');
}

// 4) 子目录。
foreach ($entries as $entry) {
    $path = $dataDir . '/' . $entry;
    if (!is_dir($path) || $entry === '.' || $entry === '..') { continue; }
    if ($entry === 'cache') {
        $caches = scandir($path) ?: [];
        sort($caches);
        $dns = 0;
        foreach ($caches as $cacheFile) {
            if ($cacheFile === '.' || $cacheFile === '..') { continue; }
            $sha = pathinfo($cacheFile, PATHINFO_FILENAME);
            if (strpos($cacheFile, 'dns-') === 0) { $dns++; continue; }
            if (isset($snapshotHashes[$sha])) {
                $report('used', 'cache/' . $cacheFile, 'SnapshotCache 快照（' . $snapshotHashes[$sha] . '）');
            } else {
                $report('orphan', 'cache/' . $cacheFile, '未知快照 key（可能是已下线功能的缓存）');
            }
        }
        echo '  [在用]     cache/ 共 ' . $dns . " 个 dns-*.json —— DnsRecordCache 结构化缓存\n";
        continue;
    }
    if ($entry === 'dns-locks') {
        $locks = scandir($path) ?: [];
        echo '  [在用]     dns-locks/ 共 ' . max(0, count($locks) - 2) . " 个运行锁 —— DnsRecordCache 锁文件\n";
        continue;
    }
    $report('orphan', $entry . '/', '目录名无代码引用；确认功能已下线后可整目录删除');
}

echo "\n在用 " . count($referenced) . ' 项 · 孤儿候选 ' . count($orphans) . ' 项';
if ($orphans) {
    echo " —— 逐项确认后手动删除（本脚本不代删）。\n";
    exit(1);
}
echo " —— 数据目录干净。\n";
exit(0);
