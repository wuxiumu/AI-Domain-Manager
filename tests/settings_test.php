<?php
require __DIR__ . '/../app/core/PrivateJsonStore.php';
require __DIR__ . '/../app/services/SettingsService.php';
require __DIR__ . '/../app/services/ApiTokenService.php';
function mask_secret(string $value): string { return $value === '' ? '' : substr($value, 0, 4) . '****' . substr($value, -4); }
$root = sys_get_temp_dir() . '/workspace-settings-' . bin2hex(random_bytes(6));
mkdir($root . '/config', 0700, true); mkdir($root . '/data', 0700, true);
$count = 0;
function verify($ok, string $message): void { global $count; $count++; if (!$ok) { throw new RuntimeException($message); } }
function rejects(callable $work, string $type): void {
    try { $work(); } catch (Throwable $error) { verify($error instanceof $type, 'Expected ' . $type . ', got ' . get_class($error)); return; }
    throw new RuntimeException('Expected exception: ' . $type);
}
function cleanup(string $dir): void { foreach (scandir($dir) as $name) { if ($name === '.' || $name === '..') continue; $file = $dir . '/' . $name; is_dir($file) ? cleanup($file) : unlink($file); } rmdir($dir); }
try {
    $settings = new SettingsService($root . '/config', $root . '/data');
    verify(!$settings->configured(), 'Fresh installation is unconfigured');
    $competing = new SettingsService($root . '/config', $root . '/data');
    verify(!$competing->configured(), 'Another visitor sees the unconfigured installation');
    $body = ['user' => 'admin', 'password' => 'test-password-1234'];
    rejects(fn () => $settings->setup(['user' => 'admin', 'password' => 'short']), InvalidArgumentException::class);
    rejects(fn () => $settings->setup(['user' => '!', 'password' => $body['password']]), InvalidArgumentException::class);
    verify(!is_file($root . '/data/settings.json'), 'Rejected setup does not initialize');
    $settings->setup($body);
    verify($settings->configured() && $settings->verifyPassword('test-password-1234'), 'New administrator can authenticate');
    verify(strpos(file_get_contents($root . '/data/settings.json'), 'test-password-1234') === false, 'Password never stored in plaintext');
    verify((fileperms($root . '/data/settings.json') & 0777) === 0600, 'Private file permissions');
    rejects(fn () => $settings->setup($body), DomainException::class);
    rejects(fn () => $competing->setup(['user' => 'other', 'password' => 'another-password-1234']), DomainException::class);
    $persisted = new SettingsService($root . '/config', $root . '/data');
    verify($persisted->all()['auth']['user'] === 'admin' && $persisted->verifyPassword($body['password']), 'Stale visitor cannot overwrite the saved administrator');
    rejects(fn () => $settings->saveAccount(['provider' => 'unknown', 'name' => 'X']), InvalidArgumentException::class);
    $rawSecrets = [];
    foreach (SettingsService::providerSchema() as $provider) {
        $account = ['provider' => $provider['id'], 'name' => $provider['name']];
        foreach ($provider['fields'] as $field) { $account[$field['key']] = $provider['id'] . '-' . $field['key'] . '-private-test'; $rawSecrets[] = $account[$field['key']]; }
        $id = $settings->saveAccount($account);
        $settings->saveAccount(['name' => 'Renamed', 'enabled' => false, $provider['fields'][0]['key'] => ''], $id);
        $saved = array_values(array_filter($settings->all()['accounts'], fn ($item) => $item['id'] === $id))[0];
        verify($saved['id'] === $id && $saved['enabled'] === false, 'Stable account ID and explicit disabled state');
        verify($saved[$provider['fields'][0]['key']] === $account[$provider['fields'][0]['key']], 'Empty credential edit preserves existing secret');
        rejects(fn () => $settings->saveAccount(['provider' => 'other'], $id), InvalidArgumentException::class);
    }
    $public = json_encode($settings->summary());
    foreach ($rawSecrets as $secret) { verify(strpos($public, $secret) === false, 'Secret not returned from settings'); }
    rejects(fn () => $settings->saveAccount(['name' => 'missing'], 'missing'), OutOfBoundsException::class);
    $settings->updateHttp(['proxy' => 'http://proxyuser:proxypass@127.0.0.1:8088', 'timeout' => 30, 'connect_timeout' => 5]);
    verify($settings->all()['http']['proxy'] === 'http://proxyuser:proxypass@127.0.0.1:8088', 'Proxy stored for provider clients');
    verify(strpos(json_encode($settings->summary()), 'proxypass') === false, 'Proxy credentials never echoed');
    rejects(fn () => $settings->updateHttp(['proxy' => 'file:///etc/passwd']), InvalidArgumentException::class);
    rejects(fn () => $settings->updateHttp(['timeout' => 5, 'connect_timeout' => 10]), InvalidArgumentException::class);
    rejects(fn () => $settings->updateHttp(['timeout' => true]), InvalidArgumentException::class);
    rejects(fn () => $settings->updateHttp(['clear_proxy' => 'false']), InvalidArgumentException::class);
    rejects(fn () => $settings->saveAccount(['name' => ['not', 'text']]), InvalidArgumentException::class);
    rejects(fn () => $settings->updateCredentials(['user' => 'admin', 'current_password' => 'test-password-1234', 'password' => "test-password\0test"]), InvalidArgumentException::class);
    $settings->updateHttp(['clear_proxy' => true]); verify($settings->all()['http']['proxy'] === '', 'Proxy clearing is explicit');
    $tokens = new ApiTokenService($root . '/data', $settings);
    $issued = $tokens->create(['name' => 'AI reader']); $token = $issued['token'];
    verify($tokens->authenticate($token)['scopes'] === ['assets:read', 'dns:read'], 'Default token is read only');
    verify($tokens->authenticate('aw_' . str_repeat('0', 64)) === null, 'Unknown token rejected');
    verify(strpos(file_get_contents($root . '/data/api_tokens.json'), $token) === false, 'Only API token hash is stored');
    verify(!isset($tokens->listing()[0]['hash'], $tokens->listing()[0]['auth_fingerprint']), 'List response contains no secret hashes');
    rejects(fn () => $tokens->create(['name' => 'bad', 'scopes' => ['admin']]), InvalidArgumentException::class);
    rejects(fn () => $tokens->create(['name' => 'empty', 'scopes' => []]), InvalidArgumentException::class);
    rejects(fn () => $tokens->create(['name' => 'forever', 'expires_in_days' => 366]), InvalidArgumentException::class);
    $second = $tokens->create(['name' => 'revocable']); $tokens->revoke($second['metadata']['id']);
    verify($tokens->authenticate($second['token']) === null, 'Revocation immediately takes effect');
    rejects(fn () => $tokens->revoke($second['metadata']['id']), OutOfBoundsException::class);
    rejects(fn () => $settings->updateCredentials(['user' => 'next-admin', 'current_password' => 'wrong', 'password' => 'next-password-1234']), InvalidArgumentException::class);
    $settings->updateCredentials(['user' => 'next-admin', 'current_password' => 'test-password-1234', 'password' => 'next-password-1234']);
    verify($tokens->authenticate($token) === null, 'Changing administrator credentials invalidates previous API tokens');
    verify($settings->verifyPassword('next-password-1234') && !$settings->verifyPassword('test-password-1234'), 'Credential changes use a password hash');
    $expired = $tokens->create(['name' => 'expired']);
    (new PrivateJsonStore($root . '/data/api_tokens.json'))->update(function ($stored) use ($expired) { foreach ($stored['tokens'] as &$record) { if ($record['id'] === $expired['metadata']['id']) $record['expires_at'] = date('c', time() - 1); } return $stored; });
    verify($tokens->authenticate($expired['token']) === null, 'Expired token is rejected');
    // Legacy credentials remain in their original file until the administrator changes them.
    mkdir($root . '/legacy-config'); mkdir($root . '/legacy-data');
    file_put_contents($root . '/legacy-config/config.php', '<?php return ' . var_export(['auth' => ['user' => 'legacy', 'pass' => 'legacy-secret'], 'accounts' => [], 'http.proxy' => 'http://localhost:7890'], true) . ';');
    $legacy = new SettingsService($root . '/legacy-config', $root . '/legacy-data');
    verify($legacy->configured() && $legacy->verifyPassword('legacy-secret'), 'Legacy login configuration stays compatible');
    verify($legacy->all()['http']['proxy'] === 'http://localhost:7890', 'Dotted legacy proxy is normalized');
    $legacy->updateHttp(['timeout' => 20, 'clear_proxy' => true]);
    verify($legacy->all()['http']['proxy'] === '', 'An explicit runtime setting overrides a dotted legacy setting');
    verify(strpos(file_get_contents($root . '/legacy-data/settings.json'), 'legacy-secret') === false, 'Saving other settings does not copy plaintext legacy credentials');
    $latest = new SettingsService($root . '/config', $root . '/data');
    verify(count($latest->all()['accounts']) === 3 && $latest->all()['auth']['user'] === 'next-admin', 'Settings survive a new process/service instance');
    echo "Settings / API tokens: {$count} assertions passed\n";
} finally { cleanup($root); }
