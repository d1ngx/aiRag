<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function write_log($message){throw new RuntimeException($message);}
define('TEMP_PATH',sys_get_temp_dir().'/airag-vector-test-'.getmypid()); mkdir(TEMP_PATH);
class PluginBase { public function getConfig(){return array('embedUrl'=>'mock','milvusBatch'=>50,'milvusPauseMs'=>0);} }
class FakeModel {
 public static $state=array('status'=>1);
 public function where($a){return $this;} public function find(){return self::$state;}
 public function save($a){self::$state=$a;return true;}
}
function Model($name){return new FakeModel();}
class AiRagPressureException extends RuntimeException {}
class AiRagBackpressure { public static $calls=0; public static $pauseAt=8;
 public static function assertReady($config){if(++self::$calls===self::$pauseAt) throw new AiRagPressureException('test');} }
class AiRagElasticStore { public function __construct($c){} public function getDocument($id){return array('content'=>'正文','modifyTime'=>1);} }
class AiRagTextNormalizer { public static function clean($s){return $s;} }
class AiRagTextChunker { public static function split($t,$s,$o,$p){$out=array();for($i=0;$i<120;$i++)$out[]=array('index'=>$i,'text'=>'chunk'.$i);return $out;} }
class AiRagEmbedClient {public static $texts=array(); public function __construct($c){} public function embed($texts,$t){self::$texts=array_merge(self::$texts,$texts);return array_fill(0,count($texts),array(1,0));} }
class AiRagMilvusStore {public static $deletes=0;public static $rows=array();public function __construct($c){}
 public function deleteFile($id){self::$deletes++;foreach(self::$rows as $k=>$r) if(intval($r['file_id'])==intval($id)) unset(self::$rows[$k]);}
 public function deleteChunks($id,$idx){foreach((array)$idx as $i) unset(self::$rows[self::chunkId($id,$i)]);}
 public function hashesByFile($id){$out=array();foreach(self::$rows as $r){if(intval($r['file_id'])==intval($id))$out[intval($r['chunk_index'])]=(string)$r['content_hash'];}return $out;}
 public static function chunkId($id,$n){return intval($id).':'.intval($n);}
 public static function chunkHash($t){return sha1((string)$t);}
 public static function diffChunks($chunks,$existing){
  $existing=is_array($existing)?$existing:array();$wanted=array();$work=array();$reuse=array();
  foreach((array)$chunks as $chunk){$index=intval(_get($chunk,'index',0));$hash=self::chunkHash(_get($chunk,'text',''));$wanted[$index]=$hash;$chunk['hash']=$hash;
   if(isset($existing[$index])&&(string)$existing[$index]===$hash)$reuse[]=$index; else $work[]=$chunk;}
  $stale=array();foreach($existing as $index=>$_h){$index=intval($index);if(!isset($wanted[$index]))$stale[]=$index;}
  return array('work'=>$work,'reuse'=>$reuse,'stale'=>$stale,'wanted'=>$wanted);
 }
 public static function row($meta,$vector){
  return array('chunk_id'=>self::chunkId(_get($meta,'fileID',0),_get($meta,'index',0)),'file_id'=>intval(_get($meta,'fileID',0)),'source_id'=>intval(_get($meta,'sourceID',0)),'parent_id'=>intval(_get($meta,'parentID',0)),'chunk_index'=>intval(_get($meta,'index',0)),'text'=>(string)_get($meta,'text',''),'name'=>(string)_get($meta,'name',''),'ext'=>(string)_get($meta,'ext',''),'content_hash'=>(string)_get($meta,'hash',self::chunkHash(_get($meta,'text',''))),'modify_time'=>intval(_get($meta,'modifyTime',0)),'vector'=>$vector);
 }
 public function upsertChunks($rows,$size,$pause){foreach($rows as $r)self::$rows[$r['chunk_id']]=$r;}
}
require __DIR__.'/../lib/CorpusShare.class.php';
require __DIR__.'/../app.php';
function check($c,$s){if(!$c)throw new RuntimeException($s);echo "PASS $s\n";}
try {
 $ref=new ReflectionClass('aiRagPlugin');$app=$ref->newInstanceWithoutConstructor();
 $p=$ref->getProperty('cursorFile');$p->setValue($app,TEMP_PATH.'/cursor.json');
 $method=$ref->getMethod('vectorizeFile');$file=array('fileID'=>42,'name'=>'test.txt','modifyTime'=>1);
 try{$method->invoke($app,$file,$app->getConfig());throw new RuntimeException('pause missing');}catch(AiRagPressureException $e){}
 check(FakeModel::$state['status']===1,'pressure leaves file pending');
 $progress=json_decode(file_get_contents(TEMP_PATH.'/airag-vector-42.json'),true);
 check($progress['written']===50,'checkpoint records only flushed vectors');
 check(count(AiRagMilvusStore::$rows)===50,'partial vectors retained');
 AiRagBackpressure::$pauseAt=0;AiRagEmbedClient::$texts=array();
 check($method->invoke($app,$file,$app->getConfig())==='ok','resume completes');
 check(AiRagMilvusStore::$deletes===0,'resume reuses chunk hashes instead of deleting the file');
 check(count(AiRagEmbedClient::$texts)===70 && AiRagEmbedClient::$texts[0]==='chunk50','resume embeds only missing chunks');
 check(count(AiRagMilvusStore::$rows)===120 && FakeModel::$state['status']===2,'all chunks stored before complete status');
 check(!file_exists(TEMP_PATH.'/airag-vector-42.json'),'completed checkpoint cleaned');
 AiRagEmbedClient::$texts=array();
 check($method->invoke($app,$file,$app->getConfig())==='ok','second run is idempotent');
 check(AiRagEmbedClient::$texts===array(),'unchanged chunks skip embedding');
} finally {foreach(glob(TEMP_PATH.'/*') as $f)unlink($f);rmdir(TEMP_PATH);}
