"""Disposable cloud simulator. Injection is made ONLY into a temporary source copy."""
import json
from http_fixture import Installation

PROVIDER_SOURCE = '''<?php
class FixtureDnsProvider extends CloudProvider {
    public function testConnection(): array { return ['ok'=>true]; }
    public function listDomains(): array { return []; }
    public function listRecords(string $domain): array {
        $store=new PrivateJsonStore(DATA_DIR.'/fixture_dns.json'); $state=$store->read();
        if (!empty($state['fail_read'])) { throw new RuntimeException('FIXTURE_PRIVATE_ERROR'); }
        return $state['records'];
    }
    private function mutate(string $action, ?string $id, ?array $params): array {
        $result=[];
        (new PrivateJsonStore(DATA_DIR.'/fixture_dns.json'))->update(function($state)use($action,$id,$params,&$result){
            $state['writes']=($state['writes']??0)+1;
            if (($state['mode']??'')!=='ignore') {
                if ($action==='add') { $result=['id'=>'new-'.$state['writes'],'status'=>'']+$params; $state['records'][]=$result; }
                elseif ($action==='delete') { $state['records']=array_values(array_filter($state['records'],function($r)use($id){return $r['id']!==$id;})); }
                else { foreach($state['records'] as &$record)if($record['id']===$id)$record=array_replace($record,$params);unset($record); }
            }
            if (($state['mode']??'')==='readback-fail')$state['fail_read']=true;
            return $state;
        });
        return $result;
    }
    public function addRecord(string $domain,array $params): array { return $this->mutate('add',null,$params); }
    public function updateRecord(string $domain,string $id,array $params): bool { $this->mutate('update',$id,$params);return true; }
    public function deleteRecord(string $domain,string $id): bool { $this->mutate('delete',$id,null);return true; }
}
'''


def installation(auto_login=False):
    site = Installation(legacy=True)
    site.stop()
    root = site.root
    bootstrap = root / "app/bootstrap.php"
    source = bootstrap.read_text().replace("function provider_factory(", "function original_provider_factory(")
    source += "\nfunction provider_factory(array $account): CloudProvider { return new FixtureDnsProvider(); }\n"
    if auto_login:
        source += "if (PHP_SAPI === 'cli-server' && ($_SERVER['REQUEST_URI'] ?? '') === '/') { Auth::establish('legacy'); }\n"
    bootstrap.write_text(source)
    (root / "app/providers/FixtureDnsProvider.php").write_text(PROVIDER_SOURCE)
    (root / "config/config.php").write_text("<?php return ['auth'=>['user'=>'legacy','pass'=>'legacy-password-test'],'accounts'=>[['id'=>'fixture','name'=>'合成演示账号','provider'=>'godaddy','enabled'=>true,'api_key'=>'fixture-key','api_secret'=>'fixture-secret']]];")
    (root / "data/domains.json").write_text(json.dumps([{"domain":"example.com","provider":"fixture","valid":True,"note":"合成 DNS 演示"}]))
    (root / "data/fixture_dns.json").write_text(json.dumps({"writes":0,"records":[
        {"id":"r1","type":"A","name":"www","value":"192.0.2.1","ttl":600,"priority":0,"line":"","status":""},
        {"id":"r2","type":"A","name":"www","value":"192.0.2.2","ttl":600,"priority":0,"line":"","status":""},
    ]}))
    site.start()
    return site
