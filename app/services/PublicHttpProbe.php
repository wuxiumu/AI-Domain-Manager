<?php

/** Shared bounded probes for legacy access checks: public destinations, pinned DNS, verified TLS. */
class PublicHttpProbe
{
    public static function plan(string $url, ?callable $resolver = null, ?float $deadline = null): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) { throw new InvalidArgumentException('探测 URL 不能包含空白或控制字符'); }
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) { throw new InvalidArgumentException('拒绝不安全的探测 URL'); }
        $host = trim($parts['host'], '[]'); $scheme = $parts['scheme'];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!HealthService::isPublicIp($host)) { throw new InvalidArgumentException('拒绝探测非公网或保留 IP'); }
            $ip = inet_ntop(inet_pton($host)); $host = $ip;
        } else {
            if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/iD', $host)) { throw new InvalidArgumentException('拒绝非标准 IP 表示'); }
            $host = HealthService::normalizeHost($host);
            if ($host === null) { throw new InvalidArgumentException('探测域名格式无效'); }
            $deadline = $deadline ?? microtime(true) + 4;
            $pending = [$host]; $seen = []; $ips = [];
            for ($depth = 0; $pending && $depth < 6; $depth++) {
                if (microtime(true) >= $deadline) { throw new RuntimeException('DNS 查询超时'); }
                $lookup = array_shift($pending);
                if (isset($seen[$lookup])) { continue; }
                $seen[$lookup] = true;
                $records = $resolver ? $resolver($lookup) : DnsResolver::lookup($lookup, $deadline - microtime(true));
                foreach (is_array($records) ? $records : [] as $record) {
                    $candidate = $record['ip'] ?? $record['ipv6'] ?? null;
                    if ($candidate !== null && HealthService::isPublicIp($candidate)) { $ips[] = inet_ntop(inet_pton($candidate)); }
                    if (($record['type'] ?? '') === 'CNAME') {
                        $alias = HealthService::normalizeHost($record['target'] ?? '');
                        if ($alias && !isset($seen[$alias])) { $pending[] = $alias; }
                    }
                }
                if ($ips) { break; }
            }
            if (!$ips) { throw new RuntimeException('DNS 未返回可用公网地址，已拒绝探测'); }
            usort($ips, static function ($a,$b) { return (int)(strpos($a,':')!==false) <=> (int)(strpos($b,':')!==false); });
            $ip = $ips[0];
        }
        $display = strpos($host, ':') === false ? $host : '[' . $host . ']';
        return ['url'=>$scheme.'://'.$display.'/', 'host'=>$host, 'ip'=>$ip, 'scheme'=>$scheme, 'port'=>$scheme==='https'?443:80];
    }

    public static function run(array $urls, ?callable $resolver = null, ?callable $transport = null): array
    {
        $out = [];
        foreach (array_chunk($urls, 20, true) as $chunk) {
            $multi = $transport ? null : curl_multi_init(); $handles = []; $limits = []; $headMs = [];
            $deadline = microtime(true) + 4;
            foreach ($chunk as $key => $url) {
                try {
                    $plan = self::plan($url, $resolver, $deadline);
                    if ($transport) { $out[$key] = $transport($plan); continue; }
                    $handle = curl_init($plan['url']);
                    $limit = (object) ['bytes'=>0,'cut'=>false]; $limits[$key] = $limit;
                    $pinned = strpos($plan['ip'], ':') === false ? $plan['ip'] : '['.$plan['ip'].']';
                    curl_setopt_array($handle, [
                        CURLOPT_RESOLVE=>[$plan['host'].':'.$plan['port'].':'.$pinned], CURLOPT_PROXY=>'',
                        CURLOPT_NOBODY=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0,
                        CURLOPT_TIMEOUT_MS=>4000, CURLOPT_CONNECTTIMEOUT_MS=>2000,
                        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                        CURLOPT_RETURNTRANSFER=>false, CURLOPT_WRITEFUNCTION=>static function($ch,$bytes)use($limit){
                            $limit->bytes+=strlen($bytes);if($limit->bytes>65536){$limit->cut=true;return 0;}return strlen($bytes);
                        },
                        CURLOPT_USERAGENT=>'AssetWorkspaceAccess/1.2',
                    ]);
                    if (defined('CURLOPT_PROTOCOLS')) { curl_setopt($handle, CURLOPT_PROTOCOLS, $plan['scheme']==='https'?CURLPROTO_HTTPS:CURLPROTO_HTTP); }
                    curl_multi_add_handle($multi,$handle); $handles[$key]=[$handle,$plan];
                } catch (Throwable $failure) {
                    $out[$key]=['http_code'=>0,'ms'=>0,'scheme'=>'https','error'=>$failure->getMessage()];
                }
            }
            if (!$transport) {
                try {
                    $started = microtime(true);
                    do {
                        $status=curl_multi_exec($multi,$running);
                        if($running && curl_multi_select($multi,.2)===-1) usleep(1000);
                    } while($running && $status===CURLM_OK);
                    $retry = false;
                    foreach($handles as $key=>[$handle]) {
                        $remaining=4000-(int)round((microtime(true)-$started)*1000);
                        if($remaining>100 && curl_errno($handle)===0 && in_array((int)curl_getinfo($handle,CURLINFO_HTTP_CODE),[405,501],true)) {
                            $headMs[$key]=(int)round(curl_getinfo($handle,CURLINFO_TOTAL_TIME)*1000);
                            curl_multi_remove_handle($multi,$handle);
                            curl_setopt_array($handle,[CURLOPT_NOBODY=>false,CURLOPT_HTTPGET=>true,CURLOPT_RANGE=>'0-1023',CURLOPT_TIMEOUT_MS=>$remaining]);
                            curl_multi_add_handle($multi,$handle);$retry=true;
                        }
                    }
                    if($retry) {
                        do {$status=curl_multi_exec($multi,$running);if($running&&curl_multi_select($multi,.2)===-1)usleep(1000);}while($running&&$status===CURLM_OK);
                    }
                    foreach($handles as $key=>[$handle,$plan]) {
                        $errno=curl_errno($handle);
                        if($limits[$key]->cut && $errno===CURLE_WRITE_ERROR && curl_getinfo($handle,CURLINFO_HTTP_CODE)>0)$errno=0;
                        $out[$key]=['http_code'=>$errno===0?(int)curl_getinfo($handle,CURLINFO_HTTP_CODE):0,
                            'ms'=>($headMs[$key]??0)+(int)round(curl_getinfo($handle,CURLINFO_TOTAL_TIME)*1000), 'scheme'=>$plan['scheme'],'error'=>$errno?curl_error($handle):''];
                    }
                } finally {
                    foreach($handles as [$handle]) { curl_multi_remove_handle($multi,$handle);curl_close($handle); }
                    curl_multi_close($multi);
                }
            }
        }
        return $out;
    }
}
