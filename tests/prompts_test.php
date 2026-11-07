<?php
/** 提示词库离线回归：目录扫描、标题提取、只读展示。Run: php tests/prompts_test.php */
require_once __DIR__ . '/../app/services/PromptLibraryService.php';

$assertions = 0;
function expect($condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function removeTree(string $dir): void
{
    foreach (glob($dir . '/*') as $path) { is_dir($path) ? removeTree($path) : unlink($path); }
    rmdir($dir);
}

$root = sys_get_temp_dir() . '/prompts-test-' . bin2hex(random_bytes(4));
mkdir($root, 0700, true);
try {
    file_put_contents($root . '/plan-workflow.md', "# 改进计划工作流\n\n第一段介绍。\n\n## 一、核心思路\n- 要点");
    file_put_contents($root . '/daily-loop.md', "没有一级标题的内容\n第二行\n");
    file_put_contents($root . '/notes.txt', 'not markdown');
    mkdir($root . '/sub');
    file_put_contents($root . '/sub/hidden.md', '# 不应出现');

    // 缺目录：空清单不报错
    $empty = (new PromptLibraryService($root . '-missing'))->list();
    expect($empty['ok'] === true && $empty['items'] === [], 'missing directory yields empty library');

    $result = (new PromptLibraryService($root))->list();
    expect($result['ok'] === true && count($result['items']) === 2, 'only root-level .md files listed');
    expect($result['items'][0]['name'] === 'daily-loop' && $result['items'][1]['name'] === 'plan-workflow', 'items sorted by name');
    $plan = $result['items'][1];
    expect($plan['title'] === '改进计划工作流', 'title from first heading');
    expect($result['items'][0]['title'] === 'daily-loop', 'title falls back to file name');
    expect(strpos($plan['content'], '## 一、核心思路') !== false, 'content passed through verbatim');
    expect($plan['file'] === 'prompts/plan-workflow.md' && $plan['bytes'] > 0 && $plan['updated_at'] !== '', 'metadata present');

    // 只读：列清单不产生副作用
    $before = glob($root . '/*');
    (new PromptLibraryService($root))->list();
    expect(glob($root . '/*') === $before, 'listing never writes');

    echo 'PASS: ' . $assertions . " prompt library assertions\n";
} finally {
    removeTree($root);
}
