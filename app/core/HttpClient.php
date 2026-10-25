<?php

/**
 * HTTP 客户端：优先 curl，回退到 stream 包装器。
 * 统一返回 ['status' => int, 'body' => string]。
 */
class HttpClient
{
    public const TIMEOUT = 25;

    public static function get(string $url, array $headers = []): array
    {
        return self::request('GET', $url, null, $headers);
    }

    public static function post(string $url, ?string $body, array $headers = []): array
    {
        return self::request('POST', $url, $body, $headers);
    }

    public static function patch(string $url, ?string $body, array $headers = []): array
    {
        return self::request('PATCH', $url, $body, $headers);
    }

    public static function put(string $url, ?string $body, array $headers = []): array
    {
        return self::request('PUT', $url, $body, $headers);
    }

    public static function delete(string $url, array $headers = []): array
    {
        return self::request('DELETE', $url, null, $headers);
    }

    private static function request(string $method, string $url, ?string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $body, $headers);
        }
        return self::viaStreams($method, $url, $body, $headers);
    }

    private static function viaCurl(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) (config('http.timeout') ?? self::TIMEOUT),
            CURLOPT_CONNECTTIMEOUT => (int) (config('http.connect_timeout') ?? 10),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'DomainManager/1.0',
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true) && $body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        // 可选代理（config 中 http.proxy，用于访问被网络屏蔽的云商 API）
        $proxy = config('http.proxy');
        if (is_string($proxy) && $proxy !== '') {
            $opts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('网络请求失败: ' . $err);
        }
        curl_close($ch);
        return ['status' => $status, 'body' => $out];
    }

    private static function viaStreams(string $method, string $url, ?string $body, array $headers): array
    {
        $headerStr = $headers ? implode("\r\n", $headers) . "\r\n" : '';
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => $headerStr,
            'content'       => $body,
            'timeout'       => (int) (config('http.timeout') ?? self::TIMEOUT),
            'ignore_errors' => true,
            'user_agent'    => 'DomainManager/1.0',
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out === false) {
            throw new RuntimeException('网络请求失败: ' . (error_get_last()['message'] ?? '未知错误'));
        }
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'body' => $out];
    }
}
