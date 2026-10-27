<?php
namespace PublicProbeTransportTest;

// Exercise the actual source with namespaced cURL stubs; native extensions remain loaded.
$names=['CURLOPT_RESOLVE','CURLOPT_PROXY','CURLOPT_NOBODY','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_TIMEOUT_MS','CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_RETURNTRANSFER','CURLOPT_WRITEFUNCTION','CURLOPT_USERAGENT','CURLOPT_PROTOCOLS','CURLOPT_HTTPGET','CURLOPT_RANGE','CURLPROTO_HTTPS','CURLPROTO_HTTP','CURLINFO_HTTP_CODE','CURLINFO_TOTAL_TIME','CURLE_WRITE_ERROR','CURLM_OK'];
foreach($names as $i=>$name)if(!defined($name))define($name,$i+1);
$sent=[];
function curl_init($url){return (object)['url'=>$url,'options'=>[],'done'=>false,'errno'=>0,'code'=>0];}
function curl_setopt_array($ch,$options){$ch->options=array_replace($ch->options,$options);return true;}
function curl_setopt($ch,$option,$value){$ch->options[$option]=$value;return true;}
function curl_multi_init(){return (object)['handles'=>[]];}
function curl_multi_add_handle($multi,$ch){$ch->done=false;$multi->handles[spl_object_id($ch)]=$ch;return CURLM_OK;}
function curl_multi_remove_handle($multi,$ch){unset($multi->handles[spl_object_id($ch)]);return CURLM_OK;}
function curl_multi_exec($multi,&$running){
    global$sent;
    foreach($multi->handles as $ch){
        if($ch->done)continue;$sent[]=$ch->options;$ch->done=true;
        $ch->code=$ch->options[CURLOPT_NOBODY]?405:200;
        if(!$ch->options[CURLOPT_NOBODY]){$callback=$ch->options[CURLOPT_WRITEFUNCTION];if($callback($ch,str_repeat('x',70000))===0)$ch->errno=CURLE_WRITE_ERROR;}
    }
    $running=0;return CURLM_OK;
}
function curl_multi_select($multi,$timeout){return 0;}
function curl_getinfo($ch,$field){return $field===CURLINFO_HTTP_CODE?$ch->code:.01;}
function curl_errno($ch){return $ch->errno;}
function curl_error($ch){return $ch->errno?'body intentionally limited':'';}
function curl_close($ch){}
function curl_multi_close($multi){}
require __DIR__.'/../app/services/HealthService.php';
class_alias('\\HealthService',__NAMESPACE__.'\\HealthService');
$source=file_get_contents(__DIR__.'/../app/services/PublicHttpProbe.php');
eval('namespace '.__NAMESPACE__.'; use \\Throwable; use \\InvalidArgumentException; use \\RuntimeException;'.substr($source,5));
$resolver=static function($host){return [['type'=>'A','ip'=>'8.8.8.8']];};
$result=PublicHttpProbe::run(['safe'=>'https://app.example.com/','blocked'=>'http://127.0.0.1/'],$resolver);
$n=0;function check($pass,$message){global$n;$n++;if(!$pass)throw new \RuntimeException($message);}
check(count($sent)===2,'Only safe HEAD and its GET fallback reach cURL');
foreach($sent as $options){
    check($options[CURLOPT_SSL_VERIFYPEER]===true&&$options[CURLOPT_SSL_VERIFYHOST]===2,'TLS verification stays enabled');
    check($options[CURLOPT_FOLLOWLOCATION]===false&&$options[CURLOPT_MAXREDIRS]===0,'Redirects are never followed');
    check($options[CURLOPT_RESOLVE]===['app.example.com:443:8.8.8.8'],'Connection stays pinned to the validated IP');
    check($options[CURLOPT_PROXY]==='','Proxy DNS cannot bypass destination validation');
}
check($sent[0][CURLOPT_NOBODY]===true&&$sent[1][CURLOPT_HTTPGET]===true,'405 falls back to GET with the same security options');
check($sent[1][CURLOPT_RANGE]==='0-1023','GET requests only a bounded response range');
check($result['safe']['http_code']===200&&$result['safe']['error']==='','Deliberately capped body does not turn a completed HTTP response into failure');
check($result['blocked']['http_code']===0,'Private destination remains blocked');
echo "Public probe transport: $n assertions passed, no network requests\n";
