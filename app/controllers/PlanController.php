<?php

/** 改进计划：功能点的提出、审核与执行跟踪（仅管理员会话）。 */
class PlanController
{
    public function index(): void
    {
        Auth::requireSession();
        json_out(['ok' => true, 'items' => (new PlanService())->list()['items'], 'ai' => (new PlanAiService())->meta()]);
    }

    /** 新增条目（待审核）；source=admin 表示工作台手动提出。 */
    public function create(): void
    {
        Auth::requireSession();
        $body = body_json();
        try {
            $item = (new PlanService())->add($body, 'admin');
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        }
        json_out(['ok' => true, 'item' => $item]);
    }

    /** 编辑条目或推进执行状态（proposed / in_progress / done）。 */
    public function update(string $id): void
    {
        Auth::requireSession();
        try {
            $item = (new PlanService())->updateItem($id, body_json());
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        }
        json_out(['ok' => true, 'item' => $item]);
    }

    /** 审核结论：decision=approve 通过 / reject 驳回（驳回需附原因）。 */
    public function review(string $id): void
    {
        Auth::requireSession();
        $body = body_json();
        try {
            $item = (new PlanService())->review($id, (string) ($body['decision'] ?? ''), $body);
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        }
        json_out(['ok' => true, 'item' => $item]);
    }

    public function remove(string $id): void
    {
        Auth::requireSession();
        json_out((new PlanService())->remove($id));
    }

    /** AI 优化建议：调用已配置的智谱 GLM 生成详情改写稿，仅返回建议文本，不落盘。 */
    public function aiSuggest(): void
    {
        Auth::requireSession();
        try {
            json_out(['ok' => true] + (new PlanAiService())->suggest(body_json()));
        } catch (InvalidArgumentException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
