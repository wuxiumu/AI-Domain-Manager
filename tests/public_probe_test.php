<?php
require __DIR__ . '/../app/services/HealthService.php';
require __DIR__ . '/../app/services/PublicHttpProbe.php';
require __DIR__ . '/../app/services/RecordAccessService.php';
require __DIR__ . '/../app/services/DnsRecordCache.php';
require __DIR__ . '/../app/core/JsonStore.php';
require_once __DIR__ . '/../app/core/WorkspaceData.php';
$n=0;
function ok($condition,string $message):void{global$n;$n++;if(!$condition)throw new RuntimeException($message);}
function denied(callable $action,string $message):void{try{$action();}catch(Throwable $error){ok(true,$message);return;}ok(false,$message);}
$empty=static function($host){return [];};
foreach(['http://127.0.0.1/','http://169.254.169.254/','http://10.0.0.1/','http://[::1]/','http://[::ffff:127.0.0.1]/',
    'file:///etc/passwd','https://user:pass@example.com/','https://example.com:8443/','https://example.com/private','https://example.com/?secret=x',
    'http://127.1/','http://0177.0.0.1/','http://0x7f.0.0.1/','http://2130706433/'] as $url){
    denied(static function()use($url,$empty){PublicHttpProbe::plan($url,$empty);},'Unsafe URL is rejected: '.$url);
}
$blocked=static function($host){return [['type'=>'A','ip'=>'127.0.0.1'],['type'=>'AAAA','ipv6'=>'::1']];};
denied(static function()use($blocked){PublicHttpProbe::plan('https://app.example.com/',$blocked);},'DNS resolving only to private addresses is rejected');
$calls=[];$mixed=static function($host)use(&$calls){$calls[]=$host;return [['type'=>'A','ip'=>'127.0.0.1'],['type'=>'A','ip'=>'8.8.8.8']];};
$plan=PublicHttpProbe::plan('https://APP.example.com/',$mixed);
ok($plan['host']==='app.example.com'&&$plan['ip']==='8.8.8.8'&&$plan['port']===443,'Public destination is normalized and fixed before connecting');
ok(count($calls)===1,'Connection preparation does not perform a second DNS lookup');
$chain=static function($host){return $host==='app.example.com'?[['type'=>'CNAME','target'=>'alias.example.com']]:[['type'=>'A','ip'=>'8.8.8.8']];};
ok(PublicHttpProbe::plan('https://app.example.com/',$chain)['ip']==='8.8.8.8','Bounded CNAME chain resolves to a public endpoint');
$loopCalls=0;$loop=static function($host)use(&$loopCalls){$loopCalls++;return [['type'=>'CNAME','target'=>$host]];};
denied(static function()use($loop){PublicHttpProbe::plan('https://app.example.com/',$loop);},'CNAME loop is rejected');ok($loopCalls===1,'CNAME cycles do not repeat queries');
denied(static function()use($mixed){PublicHttpProbe::plan('https://app.example.com/',$mixed,microtime(true)-1);},'Expired DNS budget is rejected');
ok(PublicHttpProbe::plan('http://[2606:4700:4700::1111]/',$empty)['url']==='http://[2606:4700:4700::1111]/','Public IPv6 HTTP URL retains brackets');
$sent=[];$transport=static function($plan)use(&$sent){$sent[]=$plan;return ['http_code'=>302,'ms'=>1,'scheme'=>$plan['scheme'],'error'=>''];};
$results=PublicHttpProbe::run(['blocked'=>'http://127.0.0.1/','safe'=>'https://app.example.com/'],$mixed,$transport);
ok(count($sent)===1&&$sent[0]['ip']==='8.8.8.8','Blocked destinations never reach the transport');
ok($results['blocked']['http_code']===0&&$results['safe']['http_code']===302,'Refused destination and redirect response remain distinct');

$root=sys_get_temp_dir().'/record-access-version-'.bin2hex(random_bytes(8));mkdir($root,0700);define('DATA_DIR',$root);
try{
    $records=[['id'=>'r1','type'=>'A','name'=>'www','value'=>'192.0.2.1','ttl'=>600]];
    $snapshot=DnsRecordCache::read($root,'fixture','example.com',static function()use($records){return $records;});
    JsonStore::write($root.'/record_access.json',['fixture|example.com'=>['records_revision'=>$snapshot['revision'],'results'=>['r1'=>['status'=>'ok']]]]);
    ok(RecordAccessService::mapOf('fixture','example.com')['r1']['status']==='ok','A check applies to its original DNS snapshot');
    $records[0]['value']='192.0.2.2';DnsRecordCache::read($root,'fixture','example.com',static function()use($records){return $records;},true);
    ok(RecordAccessService::mapOf('fixture','example.com')===[],'A DNS write immediately hides access results for the old target');
    denied(static function(){RecordAccessService::runCheck('fixture','example.com',[]);},'A check from a different DNS snapshot cannot publish stale results');
    ok(JsonStore::read($root.'/record_access.json')['fixture|example.com']['records_revision']===$snapshot['revision'],'Conflicting check leaves the previously stored result unchanged');
}finally{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
echo "Public access probes: $n assertions passed, no network requests\n";
