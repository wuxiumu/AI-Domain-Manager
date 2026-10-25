<?php

class AuthController
{
    public function login(): void
    {
        header('Cache-Control: no-store');
        if (!settings_service()->configured()) { json_out(['ok' => false, 'error' => '请先初始化工作台', 'code' => 'setup_required'], 409); }
        $b = body_json();
        if (!is_string($b['user'] ?? null) || !is_string($b['pass'] ?? null)) { json_out(['ok' => false, 'error' => 'user 和 pass 必须为字符串'], 422); }
        $r = Auth::attempt((string) ($b['user'] ?? ''), (string) ($b['pass'] ?? ''));
        if ($r === true) {
            json_out(['ok' => true, 'user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
        }
        json_out(['ok' => false, 'error' => $r], 401);
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
