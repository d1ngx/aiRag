<?php
namespace HttpSecurityTest;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
use Exception;use KodboxSearchSecurity;
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
class Wire{static $mode='normal';static $last;}
function curl_init(){return new \stdClass;}
function curl_setopt_array($ch,$o){$ch->options=$o;}
function curl_setopt($ch,$k,$v){$ch->options[$k]=$v;}
function curl_getinfo($ch,$k){return 200;}function curl_close($ch){}function curl_error($ch){return 'write limit';}
function curl_exec($ch){
 Wire::$last=$ch->options;
 $fn=$ch->options[CURLOPT_WRITEFUNCTION];
 if(Wire::$mode==='normal'){if($fn($ch,'{"ok":true}')!==11)return false;return true;}
 $block=str_repeat('x',1024*1024);
 for($i=0;$i<66;$i++){if($fn($ch,$block)!==strlen($block))return false;}
 return true;
}
require __DIR__.'/../lib/Security.class.php';
$source=file_get_contents(__DIR__.'/../lib/HttpJson.class.php');$source=preg_replace('/require_once __DIR__[^;]+;/','',$source);
eval('namespace HttpSecurityTest; use Exception; use KodboxSearchSecurity; '.substr($source,5));
$r=AiRagHttpJson::request('GET','https://service.invalid/');if(empty($r['ok']))throw new \RuntimeException('JSON body lost');echo "PASS bounded transport retains normal JSON response\n";
if(Wire::$last[CURLOPT_FOLLOWLOCATION]!==false||Wire::$last[CURLOPT_PROTOCOLS]!== (CURLPROTO_HTTP|CURLPROTO_HTTPS))throw new \RuntimeException('unsafe transport');echo "PASS redirects disabled and cURL protocols restricted\n";
foreach(array('json','stream')as$type){Wire::$mode='oversize';$denied=false;try{if($type==='json')AiRagHttpJson::request('GET','https://service.invalid/');else AiRagHttpJson::stream('https://service.invalid/',array());}catch(Exception $e){$denied=true;}if(!$denied)throw new \RuntimeException('unbounded '.$type);echo "PASS reject oversized $type response\n";}
