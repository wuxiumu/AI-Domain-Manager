<?php
/** CLI-only first-load cache warmup; does not write DNS records. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/bootstrap.php';
$accounts=[];foreach(config('accounts') as $account){$accounts[$account['id']]=$account;}
$done=0;$fail=0;$skip=0;
foreach(JsonStore::read(DATA_DIR.'/domains.json',[]) as $domain){
 if(($domain['valid']??true)===false||!isset($accounts[$domain['provider']])){continue;}
 if(DnsRecordCache::peek(DATA_DIR,$domain['provider'],$domain['domain'])!==null){$skip++;continue;}
 try{$provider=provider_factory($accounts[$domain['provider']]);$snapshot=DnsRecordCache::read(DATA_DIR,$domain['provider'],$domain['domain'],static function()use($provider,$domain){return $provider->listRecords($domain['domain']);});$done++;echo 'Cached domain '.$done.' ('.count($snapshot['records'])." records)\n";}
 catch(Throwable $e){$fail++;echo 'Domain cache failed #'.$fail.' ('.get_class($e).")\n";}
 flush();
}
echo json_encode(['cached'=>$done,'failed'=>$fail,'already_cached'=>$skip]),"\n";
