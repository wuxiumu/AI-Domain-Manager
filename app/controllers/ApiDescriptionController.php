<?php

class ApiDescriptionController
{
    public function openapi(): void
    {
        $payload = ApiDescriptionService::openapi();
        // 契约只随版本变化；未变化时 304，减少 AI 工具轮询开销。
        conditional_get(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        json_out($payload);
    }

    public function discovery(): void
    {
        $payload = ApiDescriptionService::discovery();
        conditional_get(hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        json_out($payload);
    }

    public function instructions(): void
    {
        $text = ApiDescriptionService::instructions();
        conditional_get(hash('sha256', $text));
        header('Content-Type: text/plain; charset=utf-8');
        echo $text;
    }

    /** AI bootstrap（/api/ai/bootstrap.md）：使用说明 + 能力清单 + 护栏速查 + 双视图快照的单文件接入包。 */
    public function bootstrap(): void
    {
        Auth::requireLogin();
        $text = ApiDescriptionService::bootstrapMarkdown();
        conditional_get(hash('sha256', $text));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: inline; filename="asset-workspace-bootstrap.md"');
        header('Cache-Control: no-store');
        echo $text;
    }
}
