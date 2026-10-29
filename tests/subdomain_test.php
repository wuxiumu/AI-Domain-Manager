<?php
require __DIR__.'/../app/core/JsonStore.php';require __DIR__.'/../app/core/SnapshotCache.php';require __DIR__.'/../app/services/DnsRecordCache.php';require __DIR__.'/../app/services/DomainInventory.php';require __DIR__.'/../app/services/HealthService.php';
date_default_timezone_set('Asia/Shanghai');$dir=sys_get_temp_dir().'/subdomain-'.bin2hex(random_bytes(6));mkdir($dir);$n=0;
function ok($v,$m){global$n;$n++;if(!$v)throw new RuntimeException($m);}
try{
 JsonStore::write($dir.'/domains.json',[['domain'=>'example.com','provider'=>'cloud','valid'=>true]]);
 $records=[['id'=>'root','name'=>'@','type'=>'A','value'=>'1.1.1.1'],['id'=>'www','name'=>'www','type'=>'A','value'=>'8.8.8.8'],['id'=>'txt','name'=>'www','type'=>'TXT','value'=>'verify'],['id'=>'v6','name'=>'api','type'=>'AAAA','value'=>'2606:4700:4700::1111'],['id'=>'wild','name'=>'*','type'=>'CNAME','value'=>'edge.example.net']];
 DnsRecordCache::read($dir,'cloud','example.com',fn()=>$records);
 $s=new HealthService($dir,fn($a)=>['status'=>'healthy','message'=>'mock','resolved_ips'=>[]]);$d=$s->dashboard();$domain=$d['assets'][0];
 ok($domain['subdomain_count']===3,'unique hostnames exclude root and deduplicate types');ok(count($domain['dns_records'])===5,'all records retained');
 $ips=array_column(array_filter($d['assets'],fn($a)=>$a['kind']==='ip'),null,'ip');ok($ips['8.8.8.8']['subdomain_count']===1,'subdomain reverse count');ok($ips['8.8.8.8']['domain_count']===1,'parent count');ok($ips['2606:4700:4700::1111']['ip_version']==='IPv6','IPv6 identified');ok($ips['1.1.1.1']['subdomain_count']===0,'root excluded from subdomain count');
 $www=current(array_filter($domain['subdomains'],fn($h)=>$h['name']==='www.example.com'));$wild=current(array_filter($domain['subdomains'],fn($h)=>$h['name']==='*.example.com'));ok(!$wild['checkable'],'wildcards cannot be probed literally');
 $s->check([$www['id']]);$d=$s->dashboard();$www=current(array_filter($d['assets'][0]['subdomains'],fn($h)=>$h['name']==='www.example.com'));ok($www['health']['status']==='healthy','child check persisted');
 $events=WorkspaceData::read($dir.'/health.json')['check_events'];ok($events[0]['kind']==='subdomain' && $events[0]['domain']==='example.com' && $events[0]['name']==='www.example.com','child check recorded with parent and status');
 $s->invalidateDnsHealth('cloud','example.com');$d=$s->dashboard();$www=current(array_filter($d['assets'][0]['subdomains'],fn($h)=>$h['name']==='www.example.com'));ok($www['health']['status']==='stale','DNS change invalidates old child health');
 $s->saveBindings($domain['id'],['ip_ids'=>[$ips['1.1.1.1']['id']]]);$ips=array_column(array_filter($s->dashboard()['assets'],fn($a)=>$a['kind']==='ip'),null,'ip');ok($ips['1.1.1.1']['domain_count']===1,'confirmed plus DNS parent counted once');
 DnsRecordCache::invalidate($dir,'cloud','example.com');ok($s->dashboard()['assets'][0]['subdomain_count']===null,'missing snapshot remains unknown');
 echo "PASS: $n subdomain assertions\n";
}finally{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($dir);}
