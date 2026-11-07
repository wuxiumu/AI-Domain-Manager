<?php

/**
 * 提示词库：prompts/ 目录下的 Markdown 文件。页面只读展示、一键复制；
 * 内容即文件——调整直接编辑 md（可版本化），展示层不做编辑。
 */
class PromptLibraryService
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (defined('APP_ROOT') ? APP_ROOT . '/prompts' : dirname(__DIR__, 2) . '/prompts');
    }

    public function list(): array
    {
        $items = [];
        if (!is_dir($this->dir)) { return ['ok' => true, 'items' => []]; }
        foreach (scandir($this->dir) ?: [] as $name) {
            // 仅根目录下的 .md；basename 即键，杜绝路径拼接。
            if (!is_string($name) || !str_ends_with($name, '.md') || !is_file($this->dir . '/' . $name)) { continue; }
            $raw = @file_get_contents($this->dir . '/' . $name);
            if ($raw === false || trim($raw) === '') { continue; }
            $firstLine = strtok(trim($raw), "\n") ?: '';
            $title = str_starts_with($firstLine, '# ') ? trim(substr($firstLine, 2)) : '';
            $items[] = ['name' => basename($name, '.md'), 'file' => 'prompts/' . $name,
                'title' => $title !== '' ? $title : basename($name, '.md'),
                'updated_at' => date('Y-m-d H:i:s', (int) filemtime($this->dir . '/' . $name)),
                'bytes' => strlen($raw), 'content' => $raw];
        }
        usort($items, static function (array $a, array $b) { return strcmp($a['name'], $b['name']); });
        return ['ok' => true, 'items' => $items];
    }
}
