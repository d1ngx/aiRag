<?php
namespace AiRagTlsTest;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use Exception;
use RuntimeException;
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function curl_init(){return new \stdClass;}
function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
function curl_exec($ch){
 if($ch->options[CURLOPT_SSL_VERIFYPEER]!==true||$ch->options[CURLOPT_SSL_VERIFYHOST]!==2)throw new RuntimeException('TLS peer/hostname verification is disabled');
 if(isset($ch->options[CURLOPT_WRITEFUNCTION])) {($ch->options[CURLOPT_WRITEFUNCTION])($ch,"data: {}\n\n");return true;}
 return '{}';
}
function curl_getinfo($ch,$key){return 200;}
function curl_close($ch){}
$source=file_get_contents(__DIR__.'/../lib/HttpJson.class.php');
eval('namespace AiRagTlsTest; use Exception; '.substr($source,5));
AiRagHttpJson::request('POST','https://service.invalid/v1',array());echo "PASS JSON requests verify TLS peer and hostname\n";
AiRagHttpJson::stream('https://service.invalid/v1',array());echo "PASS streaming requests verify TLS peer and hostname\n";
AiRagHttpJson::upload('https://service.invalid/v1',array());echo "PASS uploads verify TLS peer and hostname\n";
