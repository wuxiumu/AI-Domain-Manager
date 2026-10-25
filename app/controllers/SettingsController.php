<?php

class SettingsController
{
    public function status(): void
    {
        header('Cache-Control: no-store');
        $configured = settings_service()->configured();
        json_out(['configured' => $configured, 'needs_setup' => !$configured,
            'csrf_token' => !$configured ? Auth::csrfToken() : null]);
    }

    public function setup(): void
    {
        header('Cache-Control: no-store');
        if (settings_service()->configured()) { json_out(['ok' => false, 'error' => '工作台已初始化，请登录', 'code' => 'already_configured'], 409); }
        Auth::requireCsrf();
        $body = body_json();
        settings_service()->setup($body);
        Auth::establish(settings_service()->all()['auth']['user']);
        json_out(['ok' => true, 'user' => Auth::user(), 'csrf_token' => Auth::csrfToken()]);
    }

    public function index(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        json_out(settings_service()->summary());
    }

    public function http(): void
    {
        Auth::requireSession();
        settings_service()->updateHttp(body_json());
        json_out(['ok' => true, 'http' => settings_service()->summary()['http']]);
    }

    public function credentials(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        settings_service()->updateCredentials(body_json());
        Auth::logout();
        json_out(['ok' => true, 'requires_login' => true]);
    }

    public function tokens(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        json_out(['ok' => true, 'tokens' => (new ApiTokenService(DATA_DIR, settings_service()))->listing(), 'scopes' => ApiTokenService::SCOPES]);
    }

    public function createToken(): void
    {
        Auth::requireSession();
        header('Cache-Control: no-store');
        $body = body_json();
        if (!is_string($body['current_password'] ?? null) || !settings_service()->verifyPassword($body['current_password'])) {
            json_out(['ok' => false, 'error' => '当前管理员密码不正确'], 422);
        }
        json_out((new ApiTokenService(DATA_DIR, settings_service()))->create($body), 201);
    }

    public function revokeToken(string $id): void
    {
        Auth::requireSession();
        (new ApiTokenService(DATA_DIR, settings_service()))->revoke($id);
        json_out(['ok' => true]);
    }
}
