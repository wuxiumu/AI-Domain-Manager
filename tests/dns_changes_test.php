<?php
require __DIR__ . '/../app/providers/CloudProvider.php';
require __DIR__ . '/../app/providers/GoDaddyProvider.php';
require __DIR__ . '/../app/core/JsonStore.php';
require __DIR__ . '/../app/core/PrivateJsonStore.php';
require __DIR__ . '/../app/services/DnsRecordCache.php';
require __DIR__ . '/../app/services/DnsChangeService.php';

class FakeDnsCloud extends CloudProvider
{
    public array $records = [];
    public int $reads = 0;
    public int $writes = 0;
    public string $mode = '';
    public $beforeWrite;
    public function testConnection(): array { return []; }
    public function listDomains(): array { return []; }
    public function listRecords(string $domain): array {
        $this->reads++;
        if ($this->mode === 'readback-fail' && $this->reads > 1) { throw new RuntimeException('SECRET_SENTINEL in upstream error'); }
        if ($this->mode === 'duplicate-after' && $this->reads > 1) { return array_merge($this->records, [$this->records[0]]); }
        return $this->records;
    }
    private function start(): void { $this->writes++; if ($this->beforeWrite) ($this->beforeWrite)(); }
    public function addRecord(string $domain, array $params): array {
        $this->start(); $record = ['id' => 'new-id', 'status' => ''] + $params; $this->records[] = $record; return $record;
    }
    public function updateRecord(string $domain, string $id, array $params): bool {
        $this->start();
        if ($this->mode === 'throw-before') throw new RuntimeException('SECRET_SENTINEL');
        if ($this->mode !== 'ignore') {
            foreach ($this->records as &$record) { if ($record['id'] === $id) $record = array_replace($record, $params); } unset($record);
        }
        if ($this->mode === 'apply-then-throw') throw new RuntimeException('SECRET_SENTINEL');
        return true;
    }
    public function deleteRecord(string $domain, string $id): bool {
        $this->start();
        $this->records = $this->mode === 'drop-siblings' ? [] : array_values(array_filter($this->records, static function ($record) use ($id) { return $record['id'] !== $id; }));
        return true;
    }
}
class FakeGoDaddyCloud extends GoDaddyProvider
{
    public array $records = []; public int $writes = 0; public bool $ignore = true;
    public function listRecords(string $domain): array { return $this->records; }
    public function updateRecord(string $domain,string $id,array $params): bool {
        $this->writes++;
        if (!$this->ignore) foreach ($this->records as &$record) if ($record['id'] === $id) $record = array_replace($record,$params,['id'=>$params['type'].'|'.$params['name'].'|'.$params['value']]);
        return true;
    }
}
$n = 0;
function check($pass, string $message): void { global $n; $n++; if (!$pass) throw new RuntimeException($message); }
function rejects(callable $action, string $class, string $message): void {
    try { $action(); } catch (Throwable $error) { check($error instanceof $class, $message); return; }
    check(false, $message);
}
function cleanup(string $path): void {
    if (is_dir($path)) { foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') cleanup($path . '/' . $name); } rmdir($path); }
    elseif (is_file($path)) unlink($path);
}
$root = sys_get_temp_dir() . '/dns-changes-' . bin2hex(random_bytes(8)); mkdir($root, 0700);
$baseline = ['id' => 'r1', 'type' => 'A', 'name' => 'www', 'value' => '192.0.2.1', 'ttl' => 600, 'priority' => 0, 'line' => 'custom', 'status' => 'DISABLE', 'raw' => ['private_marker' => 'SECRET_SENTINEL']];
$actor = ['user' => 'fixture-admin', 'via' => 'browser'];
try {
    $cloud = new FakeDnsCloud(); $cloud->records = [$baseline, array_replace($baseline, ['id'=>'r2','value'=>'192.0.2.2'])];
    $invalidated = 0;
    $service = new DnsChangeService($root, $cloud, static function () use (&$invalidated) { $invalidated++; });
    $version = DnsRecordCache::recordVersion($baseline);
    rejects(static function () use ($service,$actor) { $service->apply('fixture','example.com','update','r1',['value'=>'192.0.2.3'],$actor); }, InvalidArgumentException::class, 'Missing precondition denied before any cloud fetch');
    check($cloud->reads === 0 && $cloud->writes === 0, 'Missing version performs no remote request');
    rejects(static function () use ($service,$actor) { $service->apply('fixture','example.com','update','r1',['expected_version'=>str_repeat('a',64),'value'=>'192.0.2.3'],$actor); }, DomainException::class, 'Stale version denied');
    check($cloud->writes === 0, 'Stale version never writes');
    $cloud->beforeWrite = static function () use ($root) {
        $entries = DnsChangeService::recent($root);
        check($entries[0]['outcome'] === 'pending', 'Journal persisted before the remote write');
    };
    $result = $service->apply('fixture','example.com','update','r1',['expected_version'=>$version,'value'=>'192.0.2.3'],$actor);
    check($result['verified'] && $result['status'] === 'verified', 'Successful update verified by cloud readback');
    check($cloud->writes === 1 && $invalidated === 1, 'Exactly one cloud write and health invalidation');
    check($cloud->records[0]['ttl'] === 600 && $cloud->records[0]['line'] === 'custom' && $cloud->records[0]['status'] === 'DISABLE', 'Partial edit preserves TTL, routing line and disabled status');
    check($cloud->records[1]['value'] === '192.0.2.2', 'Unselected sibling preserved');
    $journal = DnsChangeService::recent($root)[0];
    check($journal['before']['value'] === '192.0.2.1' && $journal['after']['value'] === '192.0.2.3', 'Before and readback values recorded');
    check($journal['requested']['value'] === '192.0.2.3' && $journal['actor'] === 'fixture-admin', 'Intended value and actor recorded');
    check(strpos(file_get_contents($root.'/dns_changes.json'), 'SECRET_SENTINEL') === false, 'Raw cloud fields excluded from journal');
    check((fileperms($root.'/dns_changes.json') & 0777) === 0600, 'Journal is private');
    $updatedVersion = DnsRecordCache::recordVersion($cloud->records[0]);
    $noop = $service->apply('fixture','example.com','update','r1',['expected_version'=>$updatedVersion,'value'=>'192.0.2.3'],$actor);
    check($noop['status'] === 'unchanged' && $cloud->writes === 1, 'No-op is verified without another cloud write');
    foreach (['ignore','throw-before','apply-then-throw','readback-fail','duplicate-after'] as $mode) {
        $cloud->records = [$baseline]; $cloud->reads = 0; $cloud->mode = $mode;
        $result = $service->apply('fixture','example.com','update','r1',['expected_version'=>$version,'value'=>'192.0.2.4'],$actor);
        check(!$result['verified'] && $result['status'] === 'unconfirmed', $mode . ' cannot be reported as success');
        check(strpos(json_encode($result),'SECRET_SENTINEL') === false, 'Provider error details not returned');
        check(DnsChangeService::recent($root)[0]['outcome'] === 'unconfirmed', 'Unconfirmed result remains in journal');
    }
    $cloud->mode = ''; $cloud->records = [$baseline, array_replace($baseline,['id'=>'r2'])];
    $cloud->mode = 'drop-siblings';
    $result = $service->apply('fixture','example.com','delete','r1',['expected_version'=>$version],$actor);
    check(!$result['verified'], 'Unexpected sibling deletion prevents verified success');
    $cloud->mode = ''; $cloud->records = [$baseline];
    $deleted = $service->apply('fixture','example.com','delete','r1',['expected_version'=>$version],$actor);
    check($deleted['verified'] && !$cloud->records, 'Single record deletion verified');
    $empty = DnsRecordCache::versioned(['records'=>[]]);
    $add = ['expected_revision'=>$empty['revision'],'type'=>'A','name'=>'@','value'=>'192.0.2.5','ttl'=>600,'priority'=>0,'line'=>''];
    $added = $service->apply('fixture','example.com','add',null,$add,$actor);
    check($added['verified'] && count($cloud->records) === 1, 'Add checks zone revision and verifies new record');
    $writes = $cloud->writes;
    rejects(static function () use ($service,$actor,$add) { $service->apply('fixture','example.com','add',null,$add,$actor); }, DomainException::class, 'Replayed add has stale zone revision');
    check($cloud->writes === $writes, 'Replayed add performs no write');
    $live = DnsRecordCache::versioned(['records'=>$cloud->records]);
    rejects(static function () use ($service,$actor,$add,$live) { $service->apply('fixture','example.com','add',null,array_replace($add,['expected_revision'=>$live['revision']]),$actor); }, DomainException::class, 'Duplicate existing record denied');
    foreach ([['value'=>[]],['type'=>'INVALID'],['ttl'=>1.5],['ttl'=>0],['priority'=>-1],['value'=>'999.1.1.1']] as $bad) {
        rejects(static function () use ($bad,$baseline) { DnsChangeService::params($bad,$baseline); }, InvalidArgumentException::class, 'Invalid record fields rejected');
    }
    $cloud->records = [$baseline, $baseline];
    rejects(static function () use ($service,$actor,$version) { $service->apply('fixture','example.com','update','r1',['expected_version'=>$version,'value'=>'192.0.2.9'],$actor); }, DomainException::class, 'Duplicate cloud IDs fail closed');
    $badDir = $root.'/bad-journal'; mkdir($badDir); mkdir($badDir.'/dns_changes.json');
    $cloud->records = [$baseline]; $writes = $cloud->writes;
    $badService = new DnsChangeService($badDir,$cloud);
    rejects(static function () use ($badService,$actor,$version) { $badService->apply('fixture','example.com','update','r1',['expected_version'=>$version,'value'=>'192.0.2.8'],$actor); }, RuntimeException::class, 'Journal failure denies mutation');
    check($cloud->writes === $writes, 'No remote write when pending journal cannot persist');

    $concurrent = $root . '/parallel'; mkdir($concurrent, 0700);
    file_put_contents($concurrent . '/cloud.json', json_encode(['records'=>[$baseline], 'writes'=>0]));
    $worker = '<?php ';
    foreach (['app/providers/CloudProvider.php','app/core/JsonStore.php','app/core/PrivateJsonStore.php','app/services/DnsRecordCache.php','app/services/DnsChangeService.php'] as $file) {
        $worker .= 'require ' . var_export(dirname(__DIR__) . '/' . $file, true) . ';';
    }
    $worker .= <<<'PHP'
class SharedDnsCloud extends CloudProvider {
    public function testConnection(): array { return []; }
    public function listDomains(): array { return []; }
    public function listRecords(string $domain): array { usleep(10000); return json_decode(file_get_contents($GLOBALS['dir'].'/cloud.json'),true)['records']; }
    public function addRecord(string $domain,array $params): array { throw new LogicException('not used'); }
    public function deleteRecord(string $domain,string $id): bool { throw new LogicException('not used'); }
    public function updateRecord(string $domain,string $id,array $params): bool {
        $path=$GLOBALS['dir'].'/cloud.json';$state=json_decode(file_get_contents($path),true);
        $state['writes']++;$state['records'][0]=array_replace($state['records'][0],$params);file_put_contents($path,json_encode($state));return true;
    }
}
$dir=$argv[1];$service=new DnsChangeService($dir,new SharedDnsCloud());
try {$result=$service->apply('shared','example.com','update','r1',['expected_version'=>$argv[2],'value'=>'192.0.2.9'],['user'=>'parallel']);echo $result['verified']?'200':'202';}
catch(DomainException $error){echo '409';}
PHP;
    file_put_contents($concurrent . '/worker.php', $worker);
    $workers = [];
    for ($i = 0; $i < 6; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $concurrent . '/worker.php', $concurrent, $version], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $workers[] = [$process,$pipes];
    }
    $statuses = [];
    foreach ($workers as [$process,$pipes]) {
        $statuses[] = stream_get_contents($pipes[1]);$error = stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        check(proc_close($process) === 0, 'Concurrent DNS worker completed: ' . $error);
    }
    check(count(array_filter($statuses,static function($status){return $status==='200';}))===1,'Only one concurrent edit with the same old version writes');
    check(count(array_filter($statuses,static function($status){return $status==='409';}))===5,'Remaining concurrent edits report stale versions');
    check(json_decode(file_get_contents($concurrent . '/cloud.json'),true)['writes'] === 1,'Zone lock prevents duplicate cloud writes across PHP processes');
    $godaddy = new FakeGoDaddyCloud(['api_key'=>'fixture','api_secret'=>'fixture']);
    $source = array_replace($baseline,['id'=>'A|www|192.0.2.1','line'=>'','status'=>'']);
    $godaddy->records = [$source,array_replace($source,['id'=>'A|www|192.0.2.2','value'=>'192.0.2.2'])];
    $gdService = new DnsChangeService($root,$godaddy);
    $gdVersion = DnsRecordCache::recordVersion($source);
    rejects(static function()use($gdService,$source,$gdVersion,$actor){$gdService->apply('godaddy','example.com','update',$source['id'],['expected_version'=>$gdVersion,'value'=>'192.0.2.2'],$actor);},DomainException::class,'GoDaddy cannot mistake a matching sibling for a successful update');
    check($godaddy->writes === 0,'Duplicate GoDaddy target rejected before writing');
    $godaddy->records = [$source];
    $result=$gdService->apply('godaddy','example.com','update',$source['id'],['expected_version'=>$gdVersion,'value'=>'192.0.2.3'],$actor);
    check(!$result['verified'],'GoDaddy unchanged old composite ID is not verified');
    check(DnsChangeService::recent($root)[0]['after']['value']==='192.0.2.1','GoDaddy unconfirmed journal retains actual old readback value');
    $godaddy->ignore = false;
    $result=$gdService->apply('godaddy','example.com','update',$source['id'],['expected_version'=>$gdVersion,'value'=>'192.0.2.3'],$actor);
    check($result['verified'] && $result['record']['id']==='A|www|192.0.2.3','GoDaddy new composite ID verified after a real change');
    $cloud->beforeWrite = null; $cloud->mode = ''; $cloud->reads = 0; $cloud->records = [];
    for ($i = 0; $i < 5000; $i++) { $cloud->records[] = array_replace($baseline,['id'=>'scale-'.$i,'name'=>'host-'.$i]); }
    $largeVersion = DnsRecordCache::recordVersion($cloud->records[0]);
    $started = microtime(true);
    $result=$service->apply('large-fixture','example.com','update','scale-0',['expected_version'=>$largeVersion,'value'=>'192.0.2.30'],$actor);
    check($result['verified'] && count($cloud->records)===5000,'Large synthetic zone preserves all 4,999 unselected records');
    check($cloud->reads===2,'Large zone requires only one preflight and one verification read');
    echo 'Synthetic 5,000-record DNS change: ' . round((microtime(true)-$started)*1000) . " ms (local simulator, not a capacity promise)\n";
    echo "DNS change workflow: $n assertions passed, no cloud calls\n";
} finally { cleanup($root); }
