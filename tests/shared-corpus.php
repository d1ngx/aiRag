<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function write_log($m){throw new RuntimeException($m);}
class PluginBase {public function getConfig(){return array();}}
class FakeModel {
 public static $row=array();
 public function getConfig($name){return array('elasticUrl'=>'http://corpus:9200','indexName'=>'canonical');}
 public function where($q){return $this;} public function find(){return self::$row;}
 public function save($r){self::$row=$r;return true;} public function add($r){return $this->save($r);}
 public function setDataAuto($v){} public function delete(){self::$row=array();}
}
function Model($name){return new FakeModel;}
class AiRagHttpJson {
 public static $calls=array();public static $doc=array();
 public static function fixUrl($v){return $v;}
 public static function request($method,$url){self::$calls[]=array($method,$url);return self::$doc;}
}
class AiRagMilvusStore {public static $deleted=array();public function __construct($c){} public function deleteFile($id){self::$deleted[]=$id;}}
define('TEMP_PATH',sys_get_temp_dir());
require __DIR__.'/../lib/CorpusShare.class.php';
require __DIR__.'/../lib/ElasticStore.class.php';
require __DIR__.'/../lib/TextNormalizer.class.php';
require __DIR__.'/../app.php';
function check($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS $m\n";}
$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();
$store=$r->getMethod('elastic')->invoke($app);
foreach(array('ensureInfrastructure'=>array(),'indexFile'=>array(array(),'text',true),'updateContent'=>array(42,'text'),'deleteFile'=>array(42),'rebuild'=>array()) as $method=>$args){
 $blocked=false;try{$store->$method(...$args);}catch(RuntimeException $e){$blocked=true;}
 check($blocked,"shared store blocks $method");
}
check(!AiRagHttpJson::$calls,'write protection acts before HTTP');
$f=array('fileID'=>42,'modifyTime'=>100,'name'=>'test.txt','size'=>100);
$extract=$r->getMethod('extractFile');
AiRagHttpJson::$doc=array('_status'=>404);
check($extract->invoke($app,$f,'txt',array())==='wait' && FakeModel::$row['status']===5,'missing body waits without extraction');
AiRagHttpJson::$doc=array('_status'=>200,'content'=>'正文','modifyTime'=>90);
check($extract->invoke($app,$f,'txt',array())==='wait','stale body waits');
AiRagHttpJson::$doc['modifyTime']=100;
check($extract->invoke($app,$f,'txt',array())==='ok' && FakeModel::$row['status']===1,'fresh body queues vector task');
check(AiRagHttpJson::$calls[0][1]==='http://corpus:9200/canonical/_source/42?_source_includes=content,modifyTime,name,size','source follows fulltext config');
$f['modifyTime']=101;
check($r->getMethod('vectorizeFile')->invoke($app,$f,array())==='wait','version checked again before embedding');
FakeModel::$row=array('status'=>3,'error'=>'已禁用','modifyTime'=>90);
check($extract->invoke($app,$f,'txt',array())===false,'updated source does not reactivate disabled file');
$r->getMethod('dropFile')->invoke($app,42);
check(AiRagMilvusStore::$deleted===array(42) && !FakeModel::$row,'drop removes own vectors and state');
foreach(AiRagHttpJson::$calls as $call)check($call[0]==='GET','all corpus requests are read only');
