<?php

/**
 * 修改记录与文档（仅管理员会话）：操作审计与仓库文档（README / AGENTS / CHANGELOG / docs/）的浏览与维护。
 * 文档读取按白名单校验真实路径；写操作（新建 / 保存 / 删除）仅限 docs/ 与根目录三个白名单文件，
 * 删除仅限 docs/ 内文件，全部经原子写入并自动进入操作审计。
 */
class DocsController
{
    private const MAX_BYTES = 2097152; // 2 MB

    /** 文档管理页（/docs）：管理员会话；自包含视图，$docsBootstrap 携带 CSRF。 */
    public function page(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $docsBootstrap = [
            'csrf' => Auth::csrfToken(),
            'user' => (string) Auth::user(),
        ];
        require PUBLIC_DIR . '/views/docs.php';
    }

    public function audit(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'entries' => array_slice(AuditLog::recent(), -100)]);
    }

    /** 系统概况：代码规模（文件 / 行数 / 磁盘）、数据目录与运行时内存（供设置页展示） */
    public function stats(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $scan = static function (array $specs): array {
            $files = 0; $lines = 0; $bytes = 0;
            foreach ($specs as $dir => $extensions) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile() || !in_array(strtolower($fileInfo->getExtension()), $extensions, true)) { continue; }
                    $files++;
                    $bytes += $fileInfo->getSize();
                    $lines += substr_count((string) file_get_contents($fileInfo->getPathname()), "\n") + 1;
                }
            }
            return ['files' => $files, 'lines' => $lines, 'bytes' => $bytes];
        };
        $dataFiles = 0; $dataBytes = 0;
        if (is_dir(DATA_DIR)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(DATA_DIR, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) { continue; }
                $dataFiles++;
                $dataBytes += $fileInfo->getSize();
            }
        }
        json_out(['ok' => true, 'code' => [
                'app' => $scan(['app' => ['php']]),
                'public' => $scan(['public' => ['php', 'js', 'css']]),
                'scripts' => $scan(['scripts' => ['php', 'py']]),
                'tests' => $scan(['tests' => ['php', 'mjs', 'py']]),
            ],
            'data' => ['files' => $dataFiles, 'bytes' => $dataBytes],
            'memory' => ['current' => memory_get_usage(true), 'peak' => memory_get_peak_usage(true)],
            'php' => PHP_VERSION,
            'generated_at' => date('c')]);
    }

    /** 文档清单：根目录三个白名单 md + docs/ 全部 md（真实路径，大小与更新时间） */
    public function index(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'items' => self::catalog()]);
    }

    /** 读取单篇文档：path 须命中白名单（root:README.md 等，或 docs/ 内的 md） */
    public function read(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $path = (string) ($_GET['path'] ?? '');
        $file = self::resolve($path);
        if ($file === null) { json_out(['ok' => false, 'error' => '文档不存在或不在可读范围内'], 404); }
        $content = file_get_contents($file);
        if ($content === false) { json_out(['ok' => false, 'error' => '文档读取失败'], 500); }
        json_out(['ok' => true, 'path' => $path, 'content' => $content]);
    }

    /** 新建文档：仅限 docs/ 内的 .md；目录不存在时自动创建 */
    public function create(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $body = body_json();
        $content = $body['content'] ?? '';
        if (!is_string($content) || strlen($content) > self::MAX_BYTES) {
            json_out(['ok' => false, 'error' => '文档内容必须是字符串且不超过 2 MB'], 422);
        }
        $file = self::docsPath((string) ($body['path'] ?? ''));
        if ($file === null) {
            json_out(['ok' => false, 'error' => '路径不合法：新文档只能创建在 docs/ 目录内（如 docs/06-运维/新文档.md，以 .md 结尾）'], 422);
        }
        if (is_file($file)) { json_out(['ok' => false, 'error' => '同名文档已存在，请换个文件名'], 409); }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            json_out(['ok' => false, 'error' => '无法创建文档目录'], 500);
        }
        self::store($file, $content);
        json_out(['ok' => true, 'path' => $body['path']], 201);
    }

    /** 保存已有文档（覆盖写入，原子替换） */
    public function save(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $body = body_json();
        $content = $body['content'] ?? '';
        if (!is_string($content) || strlen($content) > self::MAX_BYTES) {
            json_out(['ok' => false, 'error' => '文档内容必须是字符串且不超过 2 MB'], 422);
        }
        $file = self::resolve((string) ($body['path'] ?? ''));
        if ($file === null) { json_out(['ok' => false, 'error' => '文档不存在或路径不在可写范围内'], 404); }
        self::store($file, $content);
        json_out(['ok' => true, 'path' => $body['path'], 'updated_at' => date('Y-m-d H:i:s', (int) filemtime($file))]);
    }

    /** 删除文档：仅限 docs/ 内的 .md；根目录白名单文件受保护 */
    public function remove(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $body = body_json();
        $path = (string) ($body['path'] ?? '');
        $file = self::resolve($path);
        if ($file === null || !is_file($file)) { json_out(['ok' => false, 'error' => '文档不存在或不在可读范围内'], 404); }
        if (strpos($path, 'docs/') !== 0) {
            json_out(['ok' => false, 'error' => '根目录白名单文档（README / AGENTS / CHANGELOG）受保护，只能编辑不能删除'], 403);
        }
        if (!@unlink($file)) { json_out(['ok' => false, 'error' => '文档删除失败'], 500); }
        json_out(['ok' => true, 'path' => $path]);
    }

    /** 新建路径校验：docs/ 前缀、合法字符、无 ..、逐级规范化后必须位于 docs/ 内（父目录可尚不存在） */
    private static function docsPath(string $path): ?string
    {
        if (strpos($path, 'docs/') !== 0 || strlen($path) > 260 || !str_ends_with($path, '.md')) { return null; }
        if (!preg_match('/^[\w\x{4e00}-\x{9fa5}\/.()（）·\-]+\.md$/u', $path) || str_contains($path, '..')) { return null; }
        $docsRoot = realpath(APP_ROOT . '/docs');
        if ($docsRoot === false) { return null; }
        // 逐段规范化（过滤空段与单点段；'..' 已被整路径排除），拼出真实目标路径。
        $segments = [];
        foreach (explode('/', substr($path, strlen('docs/'))) as $segment) {
            if ($segment === '' || $segment === '.') { continue; }
            $segments[] = $segment;
        }
        if (!$segments) { return null; }
        $file = $docsRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        // 最近一个已存在的祖先目录必须位于 docs/ 内（新建多级子目录时父目录尚不存在）。
        $probe = dirname($file);
        while ($probe !== $docsRoot && !is_dir($probe)) { $probe = dirname($probe); }
        $parent = realpath($probe);
        if ($parent === false || ($parent !== $docsRoot && strpos($parent, $docsRoot . DIRECTORY_SEPARATOR) !== 0)) { return null; }
        return $file;
    }

    /** 原子写入：临时文件 + rename，失败不留半截文件 */
    private static function store(string $file, string $content): void
    {
        $temp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $content) === false || !@rename($temp, $file)) {
            if (is_file($temp)) { @unlink($temp); }
            json_out(['ok' => false, 'error' => '文档保存失败，请检查文件权限'], 500);
        }
        @chmod($file, 0644);
    }

    /** @return string[] 白名单文件的绝对路径 */
    private static function catalog(): array
    {
        $items = [];
        $add = static function (string $relative, string $file) use (&$items): void {
            if (!is_file($file)) { return; }
            $items[] = ['path' => $relative,
                'title' => self::titleOf($relative, $file),
                'bytes' => filesize($file) ?: 0,
                'updated_at' => date('Y-m-d H:i:s', (int) filemtime($file))];
        };
        foreach (['README.md', 'AGENTS.md', 'CHANGELOG.md', 'tasks.md'] as $name) { $add($name, APP_ROOT . '/' . $name); }
        foreach (glob(APP_ROOT . '/docs/**/*.md', GLOB_BRACE) ?: [] as $file) {
            $relative = 'docs/' . ltrim(str_replace(APP_ROOT . '/docs/', '', $file), '/');
            $add($relative, $file);
        }
        return $items;
    }

    private static function titleOf(string $relative, string $file): string
    {
        $first = strtok((string) file_get_contents($file), "\n") ?: '';
        if (preg_match('/^#\s+(.+)$/u', trim($first), $m)) { return trim($m[1]); }
        return basename($relative);
    }

    private static function resolve(string $path): ?string
    {
        if ($path === '' || strlen($path) > 260 || !preg_match('/^[\w\x{4e00}-\x{9fa5}\/.()（）·\-]+\.md$/u', $path)) { return null; }
        if (str_contains($path, '..')) { return null; }
        $rootList = ['README.md', 'AGENTS.md', 'CHANGELOG.md', 'tasks.md'];
        if (in_array($path, $rootList, true)) { $file = APP_ROOT . '/' . $path; }
        elseif (strpos($path, 'docs/') === 0) { $file = APP_ROOT . '/' . $path; }
        else { return null; }
        $real = realpath($file);
        if ($real === false || !is_file($real)) { return null; }
        // 重新确认真实路径仍在白名单根之内，防符号链接越界。
        if ($real !== realpath(APP_ROOT . '/' . $path)) { return null; }
        $inDocs = strpos($real, realpath(APP_ROOT . '/docs') . DIRECTORY_SEPARATOR) === 0;
        if (!$inDocs && !in_array($path, $rootList, true)) { return null; }
        return $real;
    }
}
