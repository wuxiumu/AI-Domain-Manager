<?php

/**
 * 轻量路由：支持 {param} 占位符，匹配失败时非 /api 路径回退到页面视图。
 */
class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, array $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, array $handler): void
    {
        $this->routes[$method . ' ' . $path] = $handler;
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $key => $handler) {
            [$m, $p] = explode(' ', $key, 2);
            if ($m !== $method) {
                continue;
            }
            $params = [];
            if ($this->match($p, $path, $params)) {
                $controller = new $handler[0]();
                try {
                    Auth::authorizeRoute($method, $p);
                    if (strpos($p, '/api/') === 0 && Auth::check()) {
                        WorkspaceData::recover(DATA_DIR);
                        AuditLog::begin($method, $p, $params);
                        // Hold the local lock through JSON output. Request shutdown
                        // releases the resource, including controllers that exit.
                        if (in_array($p, ['/api/domains', '/api/domains/stats', '/api/health', '/api/health/ips',
                            '/api/health/ips/{id}', '/api/health/domains/{id}/bindings'], true) || strpos($p, '/api/maintenance') === 0) {
                            WorkspaceData::acquire(DATA_DIR);
                        }
                    }
                    call_user_func_array([$controller, $handler[1]], $params);
                } catch (InvalidArgumentException $e) {
                    json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'validation_error'], 422);
                } catch (OutOfBoundsException $e) {
                    json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'not_found'], 404);
                } catch (DomainException $e) {
                    json_out(['ok' => false, 'error' => $e->getMessage(), 'code' => 'conflict'], 409);
                } catch (Throwable $e) {
                    error_log('Application error: ' . $e->getMessage());
                    json_out(['ok' => false, 'error' => '操作未完成，请检查服务日志后重试'], 500);
                }
                return;
            }
        }

        if (strpos($path, '/api/') === 0) {
            json_out(['error' => '接口不存在'], 404);
        }

        // 页面请求：渲染单页应用外壳
        require PUBLIC_DIR . '/views/app.php';
    }

    private function match(string $pattern, string $path, array &$params): bool
    {
        $pSeg = explode('/', trim($pattern, '/'));
        $sSeg = explode('/', trim($path, '/'));
        if (count($pSeg) !== count($sSeg)) {
            return false;
        }
        foreach ($pSeg as $i => $seg) {
            if (strpos($seg, '{') === 0 && substr($seg, -1) === '}') {
                $params[] = rawurldecode($sSeg[$i] ?? '');
            } elseif (($sSeg[$i] ?? null) !== $seg) {
                return false;
            }
        }
        return true;
    }
}
