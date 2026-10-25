<?php
require __DIR__ . '/../app/core/JsonStore.php';
require __DIR__ . '/../app/core/SnapshotCache.php';
require __DIR__ . '/../app/services/HealthService.php';
require __DIR__ . '/../app/services/DnsRecordCache.php';
date_default_timezone_set('Asia/Shanghai');
$dir = sys_get_temp_dir() . '/relations-test-' . bin2hex(random_bytes(6)); mkdir($dir);
$count=0;
function check($value,$message) { global $count; $count++; if(!$value) throw new RuntimeException($message); }
function domain($name) { return ['domain'=>$name,'provider'=>'manual','valid'=>true,'expire_at'=>'2027-01-01']; }
try {
    JsonStore::write($dir.'/domains.json',[domain('a.example.com'),domain('b.example.com')]);
    $s=new HealthService($dir);
    $d=$s->dashboard(); check(!$d['cache']['hit'],'first snapshot builds');
    check($s->dashboard()['cache']['hit'],'second snapshot uses file');
    $domain=$d['assets'][0]['id'];
    $ip=$s->addIp(['ip'=>'1.1.1.1','name'=>'edge','port'=>443])['asset']['id'];
    check(!$s->dashboard()['cache']['hit'],'add invalidates fingerprint');
    $s->saveBindings($domain,['ip_ids'=>[$ip]]);
    $d=$s->dashboard();
    check($d['summary']['confirmed_domains']===1,'confirmed summary');
    check($d['summary']['unlinked_domains']===1,'unlinked summary');
    $assets=array_column($d['assets'],null,'id');
    check($assets[$domain]['bound_ips']===[$ip],'domain sees server');
    check($assets[$ip]['bound_domains']===[$domain],'server reverse lookup');
    $s->updateIp($ip,['name'=>'Production','server_provider'=>'self','note'=>'verified','expires_at'=>'2027-02-01']);
    $assets=array_column($s->dashboard()['assets'],null,'id');
    check($assets[$ip]['name']==='Production' && $assets[$ip]['note']==='verified','metadata saved');
    check($assets[$domain]['bound_ips']===[$ip],'metadata preserves binding');
    try {$s->saveBindings($domain,['ip_ids'=>['missing']]);check(false,'invalid binding');} catch(InvalidArgumentException $e) {check(true,'invalid target rejected');}
    check(array_column($s->dashboard()['assets'],null,'id')[$domain]['bound_ips']===[$ip],'invalid write leaves old binding');
    $s->saveBindings($domain,['ip_ids'=>[]]);
    check($s->dashboard()['summary']['confirmed_domains']===0,'unbind saved');
    $s->saveBindings($domain,['ip_ids'=>[$ip]]); $s->deleteIp($ip);
    check(array_column($s->dashboard()['assets'],null,'id')[$domain]['bound_ips']===[],'delete removes binding');
    $calls=0; $fetch=function()use(&$calls){$calls++;return [['id'=>'1','value'=>'1.1.1.1']];};
    DnsRecordCache::read($dir,'account','a.example.com',$fetch);
    check(DnsRecordCache::read($dir,'account','a.example.com',$fetch)['cached'] && $calls===1,'DNS list cached without extra cloud request');
    DnsRecordCache::read($dir,'other-account','a.example.com',$fetch); check($calls===2,'DNS account isolation');
    DnsRecordCache::read($dir,'account','a.example.com',$fetch,true); check($calls===3,'explicit DNS refresh');
    DnsRecordCache::invalidate($dir,'account','a.example.com');
    DnsRecordCache::read($dir,'account','a.example.com',$fetch); check($calls===4,'write invalidates DNS cache');
    JsonStore::write($dir.'/domains.json',[domain('new.example.com')]);
    check($s->dashboard()['summary']['domains']===1 && $s->dashboard()['assets'][0]['name']==='new.example.com','external file changes invalidate snapshot');
    echo "PASS: $count relationship and file-cache assertions\n";
} finally {
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());} rmdir($dir);
}
