<?php

/** 提示词库：只读展示 prompts/ 下的 Markdown 文件（仅管理员会话）。 */
class PromptController
{
    public function index(): void
    {
        Auth::requireSession();
        json_out((new PromptLibraryService())->list());
    }
}
