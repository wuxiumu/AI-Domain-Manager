<?php

/** 改进计划：功能点的提出、审核与执行跟踪（仅管理员会话）。 */
class PlanController
{
    public function index(): void
    {
        Auth::requireSession();
        json_out((new PlanService())->list());
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
}
