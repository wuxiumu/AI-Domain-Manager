<?php

/** Derive hostnames and reverse IP links from cached DNS; never performs I/O to a cloud. */
class DomainInventory
{
    public static function enrich(array &$assets, string $dir, callable $healthOf, callable $makeIp): void
    {
        foreach (array_keys($assets) as $id) {
            if ($assets[$id]['kind'] !== 'domain') { continue; }
            $domain = &$assets[$id];
            $snapshot = DnsRecordCache::peek($dir, $domain['provider'], $domain['name']);
            $domain['dns_cached'] = $snapshot !== null;
            $domain['dns_snapshot_at'] = $snapshot['snapshot_at'] ?? null;
            $domain['dns_records'] = [];
            $domain['subdomains'] = [];
            $domain['subdomain_count'] = $snapshot === null ? null : 0;
            if ($snapshot === null) { unset($domain); continue; }
            $groups = [];
            foreach ($snapshot['records'] as $record) {
                $name = strtolower(rtrim(trim((string) ($record['name'] ?? '@')), '.'));
                if ($name === '' || $name === '@' || $name === $domain['name']) { $fqdn = $domain['name']; }
                else { $fqdn = substr($name, -strlen('.' . $domain['name'])) === '.' . $domain['name'] ? $name : $name . '.' . $domain['name']; }
                $record['fqdn'] = $fqdn;
                $record['type'] = strtoupper((string) ($record['type'] ?? ''));
                $domain['dns_records'][] = $record;
                $groups[$fqdn][] = $record;
            }
            foreach ($groups as $fqdn => $records) {
                $hostId = $fqdn === $domain['name'] ? $id : 'host_' . substr(hash('sha256', $domain['provider'] . '|' . $fqdn), 0, 24);
                $health = $healthOf($hostId);
                $ips = [];
                foreach ($records as $record) {
                    $value = trim((string) ($record['value'] ?? ''));
                    if (in_array($record['type'], ['A','AAAA'], true) && HealthService::isPublicIp($value)) { $ips[] = inet_ntop(inet_pton($value)); }
                }
                // Resolved CNAME addresses are historical evidence, timestamped separately.
                foreach ($health['resolved_ips'] as $ip) { if (HealthService::isPublicIp($ip)) $ips[] = inet_ntop(inet_pton($ip)); }
                $ips = array_values(array_unique($ips));
                foreach ($ips as $ip) {
                    $ipId = 'ip_' . substr(hash('sha256', $ip . '|443'), 0, 24);
                    if (!isset($assets[$ipId])) { $assets[$ipId] = $makeIp(['id'=>$ipId,'ip'=>$ip,'port'=>443,'name'=>$ip]); }
                    if (!in_array($domain['name'], $assets[$ipId]['linked_domains'], true)) $assets[$ipId]['linked_domains'][] = $domain['name'];
                    if (!in_array($ipId, $domain['linked_ips'], true)) $domain['linked_ips'][] = $ipId;
                    $assets[$ipId]['dns_links'][] = ['domain_id'=>$id, 'host_id'=>$hostId, 'fqdn'=>$fqdn,
                        'provider'=>$domain['provider'], 'domain'=>$domain['name'], 'record_ids'=>array_values(array_column(array_filter($records, static function($r) use($ip){return in_array($r['type'],['A','AAAA'],true) && @inet_pton($r['value']) === @inet_pton($ip);}), 'id'))];
                    if ($fqdn !== $domain['name'] && !in_array($fqdn, $assets[$ipId]['linked_subdomains'], true)) $assets[$ipId]['linked_subdomains'][] = $fqdn;
                }
                if ($fqdn !== $domain['name']) {
                    $types = array_column($records,'type');
                    $domain['subdomains'][] = ['id'=>$hostId,'kind'=>'subdomain','name'=>$fqdn,'provider'=>$domain['provider'],
                        'domain_id'=>$id,'domain'=>$domain['name'],'records'=>$records,'ips'=>$ips,'health'=>$health,
                        'checkable'=>strpos($fqdn,'*') === false && count(array_intersect($types,['A','AAAA','CNAME'])) > 0];
                }
            }
            $domain['subdomain_count'] = count($domain['subdomains']);
            unset($domain);
        }
        foreach ($assets as &$asset) {
            if ($asset['kind'] !== 'ip') continue;
            $asset['ip_version'] = strpos($asset['ip'], ':') === false ? 'IPv4' : 'IPv6';
            $asset['domain_count'] = count(array_unique(array_merge($asset['linked_domains'], array_map(static function($id) use($assets){return $assets[$id]['name'] ?? $id;}, $asset['bound_domains']))));
            $asset['subdomain_count'] = count($asset['linked_subdomains']);
        }
    }
}
