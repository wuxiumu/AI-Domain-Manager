<?php

class AuthController
{
    public function captcha(): void
    {
        header('Cache-Control: no-store');
        $result = (new LoginRateLimiter(DATA_DIR))->run(LoginRateLimiter::clientIp(), 'captcha');
        if (!$result['ok']) { $this->respond($result); }
        json_out(['ok' => true, 'csrf_token' => Auth::csrfToken()] + (new LoginCaptcha())->issue());
    }

    public function login(): void
    {
        header('Cache-Control: no-store');
        if (!settings_service()->configured()) { json_out(['ok' => false, 'error' => '请先初始化工作台', 'code' => 'setup_required'], 409); }
        $user = '';
        $result = (new LoginRateLimiter(DATA_DIR))->run(LoginRateLimiter::clientIp(), 'login', function () use (&$user): array {
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            $body = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
            $b = is_array($body) ? $body : [];
            $validCaptcha = (new LoginCaptcha())->consume($b['captcha_id'] ?? null, $b['captcha'] ?? null);
            $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($csrf === '' || !hash_equals(Auth::csrfToken(), $csrf)) {
                return ['ok' => false, 'status' => 403, 'code' => 'csrf_invalid', 'error' => '页面验证已过期，请刷新验证码后重试'];
            }
            if (!$validCaptcha) { return ['ok' => false, 'status' => 422, 'code' => 'captcha_invalid', 'error' => '验证码错误或已过期，请输入新验证码']; }
            if (!is_string($b['user'] ?? null) || !is_string($b['pass'] ?? null) || strlen($b['user']) > 128 || strlen($b['pass']) > 4096) {
                return ['ok' => false, 'status' => 422, 'code' => 'validation_error', 'error' => '账号或密码格式不正确'];
            }
            $user = $b['user'];
            return Auth::verifyCredentials($user, $b['pass']) ? ['ok' => true]
                : ['ok' => false, 'status' => 401, 'code' => 'invalid_credentials', 'error' => '用户名或密码错误'];
        });
        if ($result['ok']) {
            Auth::establish($user);
            json_out(['ok' => true, 'user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
        }
        unset($_SESSION['login_captcha']);
        $this->respond($result);
    }

    private function respond(array $result): void
    {
        $status = $result['status'] ?? 200;
        unset($result['status']);
        if ($status === 429) { header('Retry-After: ' . $result['retry_after']); }
        json_out($result, $status);
    }

    public function logout(): void
    {
        Auth::requireSession();
        Auth::logout();
        json_out(['ok' => true]);
    }

    public function me(): void
    {
        header('Cache-Control: no-store');
        if (Auth::isBearer()) { Auth::requireLogin(); }
        json_out(['user' => Auth::user(), 'auth_type' => Auth::isBearer() ? 'bearer' : 'session',
            'csrf_token' => !Auth::isBearer() && Auth::check() ? Auth::csrfToken() : null]);
    }
}
