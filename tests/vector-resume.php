<?php
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function write_log($message){}
define('TEMP_PATH',sys_get_temp_dir().'/airag-vector-test-'.getmypid());mkdir(TEMP_PATH);
class PluginBase {function getConfig(){return array('embedUrl'=>'mock','embedDim'=>32,'milvusBatch'=>50,'milvusPauseMs'=>0);}}
class FakeModel {static $state=array('status'=>1);function where($a){return $this;}function find(){return self::$state;}function save($a){self::$state=$a;return true;}}
function Model($name){return new FakeModel;}
class AiRagPressureException extends RuntimeException {}
class AiRagBackpressure {static $pause=true;static function assertReady($c){if(self::$pause&&count(AiRagHttpJson::$rows)>=50)throw new AiRagPressureException('test');}}
class AiRagElasticStore {function __construct($c){}function getDocument($id){return array('content'=>'正文','modifyTime'=>999,'extractVersion'=>'attachment-v2-1000000');}}
class AiRagTextNormalizer {static function clean($s){return $s;}}
class AiRagTextChunker {static $count=120;static $changed=false;static function split($t,$s,$o,$p){$r=array();for($i=0;$i<self::$count;$i++)$r[]=array('index'=>$i,'text'=>'chunk'.$i.(self::$changed&&$i===3?' updated':''));return $r;}}
class AiRagEmbedClient {static $texts=array();static $version='m1';function __construct($c){}function fingerprint(){return self::$version;}function embed($texts,$timeout){self::$texts=array_merge(self::$texts,$texts);return array_fill(0,count($texts),array_fill(0,32,0.1));}}
class AiRagHttpJson {
 static $rows=array();static $failRead=false;static $failWrite=false;static $deletes=0;
 static function fixUrl($u){return $u;}
 static function request($method,$url,$body){
  if(strpos($url,'/query')!==false){
   if(self::$failRead)return array('code'=>1100,'message'=>'read error','_status'=>200);
   $rows=array_values(self::$rows);
   if(preg_match('/chunk_index in \[([^]]+)\]/',$body['filter'],$m)){$ids=array_map('intval',explode(',',$m[1]));$rows=array_values(array_filter($rows,function($r)use($ids){return in_array($r['chunk_index'],$ids);}));}
   return array('code'=>0,'data'=>$rows);
  }
  if(strpos($url,'/upsert')!==false){if(self::$failWrite)return array('code'=>1100,'message'=>'write error','_status'=>200);foreach($body['data'] as $row)self::$rows[$row['chunk_id']]=$row;return array('code'=>0);}
  if(strpos($url,'/delete')!==false){self::$deletes++;if(preg_match('/chunk_index in \[([^]]+)\]/',$body['filter'],$m)){foreach(explode(',',$m[1]) as $id)unset(self::$rows['42:'.intval($id)]);}else self::$rows=array();return array('code'=>0);}
  throw new RuntimeException('Unexpected API '.$url);
 }
}
require __DIR__.'/../lib/CorpusShare.class.php';
require __DIR__.'/../lib/MilvusStore.class.php';
require __DIR__.'/../app.php';
function check($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS $m\n";}
try {
 $r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();$r->getProperty('cursorFile')->setValue($app,TEMP_PATH.'/cursor.json');$method=$r->getMethod('vectorizeFile');
 $file=array('fileID'=>42,'sourceID'=>5,'parentID'=>6,'ext'=>'txt','name'=>'test.txt','modifyTime'=>1);
 try{$method->invoke($app,$file,$app->getConfig());throw new RuntimeException('missing pause');}catch(AiRagPressureException $e){}
 check(count(AiRagHttpJson::$rows)===50&&FakeModel::$state['status']===1,'pause preserves only confirmed writes');
 AiRagBackpressure::$pause=false;AiRagEmbedClient::$texts=array();
 check($method->invoke($app,$file,$app->getConfig())==='ok','resume completes');
 check(count(AiRagEmbedClient::$texts)===70,'resume embeds only server-missing chunks');
 AiRagEmbedClient::$texts=array();$method->invoke($app,$file,$app->getConfig());check(!AiRagEmbedClient::$texts,'unchanged chunks skip embedding');
 $file['parentID']=99;$file['modifyTime']=2;$method->invoke($app,$file,$app->getConfig());check(!AiRagEmbedClient::$texts&&AiRagHttpJson::$rows['42:0']['parent_id']===99&&AiRagHttpJson::$rows['42:119']['modify_time']===2,'metadata refresh reuses vectors');
 AiRagEmbedClient::$version='m2';$method->invoke($app,$file,$app->getConfig());check(count(AiRagEmbedClient::$texts)===120,'new embedding model invalidates every old vector');
 AiRagTextChunker::$changed=true;AiRagEmbedClient::$texts=array();$method->invoke($app,$file,$app->getConfig());check(AiRagEmbedClient::$texts===array('chunk3 updated'),'single changed chunk alone re-embedded');
 AiRagTextChunker::$count=100;AiRagTextChunker::$changed=false;AiRagHttpJson::$failWrite=true;$deletes=AiRagHttpJson::$deletes;
 check($method->invoke($app,$file,$app->getConfig())==='fail'&&FakeModel::$state['status']===4,'HTTP 200 business error cannot mark file complete');
 check(AiRagHttpJson::$deletes===$deletes&&count(AiRagHttpJson::$rows)===120,'failed replacement retains old tail');
 AiRagHttpJson::$failWrite=false;check($method->invoke($app,$file,$app->getConfig())==='ok'&&count(AiRagHttpJson::$rows)===100,'successful replacement deletes stale tail');
 AiRagHttpJson::$failRead=true;AiRagEmbedClient::$texts=array();file_put_contents(TEMP_PATH.'/airag-vector-42.json',json_encode(array('written'=>100)));
 check($method->invoke($app,$file,$app->getConfig())==='fail'&&!AiRagEmbedClient::$texts,'read error never trusts a local checkpoint');
 AiRagHttpJson::$failRead=false;AiRagEmbedClient::$texts=array();AiRagTextChunker::$changed=true;
 check($method->invoke($app,$file,$app->getConfig(),0,1,1,microtime(true)-1)==='yield'&&!AiRagEmbedClient::$texts&&FakeModel::$state['status']===1,'expired budget yields without embedding and keeps task pending');
 AiRagTextChunker::$changed=false;AiRagTextChunker::$count=9001;
 check($method->invoke($app,$file,$app->getConfig())==='ok'&&count(AiRagHttpJson::$rows)===9001,'long document vectorization completes beyond 4096 chunks');
 AiRagTextChunker::$count=50;AiRagTextChunker::$changed=true;AiRagHttpJson::$failWrite=true;
 check($method->invoke($app,$file,$app->getConfig())==='fail'&&FakeModel::$state['chunkCount']===9001,'failed shorter replacement preserves full old vector extent');
 $r->getMethod('saveState')->invoke($app,$file,5,0,'','waiting');
 check(FakeModel::$state['chunkCount']===9001,'waiting for corpus does not lose cleanup extent');
 AiRagHttpJson::$failWrite=false;
 check($method->invoke($app,$file,$app->getConfig())==='ok'&&count(AiRagHttpJson::$rows)===50&&!isset(AiRagHttpJson::$rows['42:9000']),'resumed shorter document deletes stale tail across all hash pages');
} finally {foreach(glob(TEMP_PATH.'/*') as $f)unlink($f);rmdir(TEMP_PATH);}
