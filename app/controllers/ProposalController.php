<?php

/**
 * AI 提案队列接口：AI 用 proposals:write 提交并跟踪提案；
 * 批准 / 拒绝仅限管理员会话（批准会触发云端 DNS 写入）。
 */
class ProposalController
{
    public function create(): void
    {
        Auth::requireLogin();
        $body = body_json();
        $this->respond(static function () use ($body) { return (new AiProposalService())->create($body, self::actor()); }, 201);
    }

    public function index(): void
    {
        Auth::requireLogin();
        $this->respond(static function () { return (new AiProposalService())->list(); });
    }

    public function approve(string $id): void
    {
        Auth::requireSession();
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        @set_time_limit(90);
        header('Cache-Control: no-store');
        $this->respond(static function () use ($id) { return (new AiProposalService())->approve($id, self::actor()); });
    }

    public function reject(string $id): void
    {
        Auth::requireSession();
        $body = body_json();
        header('Cache-Control: no-store');
        $this->respond(static function () use ($id, $body) { return (new AiProposalService())->reject($id, self::actor(), (string) ($body['reason'] ?? '')); });
    }

    private static function actor(): array
    {
        return ['user' => Auth::isBearer() ? 'AI 密钥 ' . mb_substr((string) Auth::user(), 0, 100) : Auth::user(), 'via' => Auth::isBearer() ? 'api' : 'browser'];
    }

    private function respond(callable $work, int $code = 200): void
    {
        try {
            json_out($work(), $code);
        } catch (InvalidArgumentException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (DomainException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (OutOfBoundsException $e) {
            json_out(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            json_out(['ok' => false, 'error' => '提案服务失败：' . $e->getMessage()], 500);
        }
    }
}
