<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function write_log($m){}
class PluginBase {function getConfig(){return array('embedUrl'=>'mock');}}
class DeleteModel {
 static $row=array('fileID'=>42,'modifyTime'=>100,'status'=>2);static $saveFails=false;
 function where($w){return $this;}function find(){return self::$row;}function order($s){return $this;}function limit($n){return $this;}
 function select(){return self::$row?array(self::$row):array();}
 function save($v){if(self::$saveFails)return false;self::$row=array_merge(self::$row,$v);return true;}
 function setDataAuto($v){} function add($v){return $this->save($v);} function delete(){self::$row=array();return true;}
}
function Model($n){return new DeleteModel;}
class AiRagEmbedClient {function __construct($c){} function fingerprint(){return 'test-model';}}
class AiRagMilvusStore {static $fail=true;static $calls=0;function __construct($c){}function deleteFile($id){self::$calls++;if(self::$fail)throw new RuntimeException('offline');}}
class AiRagBackpressure {static function assertReady($c){}}
define('TEMP_PATH',sys_get_temp_dir().'/airag-delete-test-'.getmypid());mkdir(TEMP_PATH);
require __DIR__.'/../app.php';
try {
 $r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();$drop=$r->getMethod('dropFile');
 DeleteModel::$saveFails=true;try{$drop->invoke($app,42);}catch(RuntimeException $e){}
 check(AiRagMilvusStore::$calls===0,'remote deletion never starts without a durable tombstone');
 DeleteModel::$saveFails=false;file_put_contents(TEMP_PATH.'/airag-vector-42.json','checkpoint');
 try{$drop->invoke($app,42);}catch(RuntimeException $e){}
 check(DeleteModel::$row['status']===6&&is_file(TEMP_PATH.'/airag-vector-42.json'),'failed deletion preserves tombstone and checkpoint');
 $file=array('fileID'=>42,'name'=>'test.txt','modifyTime'=>100,'size'=>100);
 check($r->getMethod('extractFile')->invoke($app,$file,'txt',array())===false,'pending deletion cannot be rescanned into vector queue');
 check($r->getMethod('vectorizeFile')->invoke($app,$file,array())==='skip','pending deletion cannot be vectorized by manual retry');
 $r->getMethod('retryDeletes')->invoke($app);
 check(DeleteModel::$row['status']===6,'failed scheduled retry preserves tombstone');
 AiRagMilvusStore::$fail=false;$r->getMethod('retryDeletes')->invoke($app);
 check(!DeleteModel::$row&&!is_file(TEMP_PATH.'/airag-vector-42.json'),'successful scheduled retry clears state and checkpoint');
 DeleteModel::$row=array('status'=>3,'error'=>'已禁用');
 check($r->getMethod('vectorizeFile')->invoke($app,$file,array())==='skip','manual vector retry does not reactivate disabled files');
} finally {foreach(glob(TEMP_PATH.'/*')as$f)unlink($f);rmdir(TEMP_PATH);}
