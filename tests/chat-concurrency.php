<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
class PluginBase {}class KodUser {static function id(){return 1;}}
$worker=isset($argv[1])&&$argv[1]==='worker';
define('TEMP_PATH',$worker?$argv[2]:sys_get_temp_dir().'/airag-chat-test-'.getmypid());
require __DIR__.'/../app.php';
$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();$mutate=$r->getMethod('chatUpdate');$read=$r->getMethod('chatStore');
if($worker){for($i=0;$i<25;$i++)$mutate->invoke($app,function(&$store){$store['counter']=intval(_get($store,'counter',0))+1;usleep(1000);});exit;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
mkdir(TEMP_PATH);$processes=array();
try {
 $mutate->invoke($app,function(&$store){$store['counter']=0;});
 for($i=0;$i<4;$i++){$pipes=array();$p=proc_open(array(PHP_BINARY,__FILE__,'worker',TEMP_PATH),array(0=>array('pipe','r'),1=>array('file',TEMP_PATH.'/out-'.$i,'w'),2=>array('file',TEMP_PATH.'/err-'.$i,'w')),$pipes);if(!is_resource($p))throw new RuntimeException('cannot start worker');fclose($pipes[0]);$processes[]=$p;}
 // Concurrent readers must only see complete JSON snapshots.
 for($i=0;$i<100;$i++){$store=$read->invoke($app);if(!isset($store['counter']))throw new RuntimeException('partial JSON snapshot');usleep(1000);}
 foreach($processes as$p)check(proc_close($p)===0,'chat writer completed');$processes=array();
 check($read->invoke($app)['counter']===100,'four concurrent writers preserve all 100 updates');
 $file=$r->getMethod('chatFile')->invoke($app);file_put_contents($file,'broken');$failed=false;try{$mutate->invoke($app,function(&$s){$s['items']=array();});}catch(RuntimeException $e){$failed=true;}
 check($failed&&file_get_contents($file)==='broken','invalid JSON is reported without overwriting original records');
}finally{foreach($processes as$p){proc_terminate($p);proc_close($p);}foreach(glob(TEMP_PATH.'/airag-chat/*')as$f)unlink($f);rmdir(TEMP_PATH.'/airag-chat');foreach(glob(TEMP_PATH.'/*')as$f)unlink($f);rmdir(TEMP_PATH);}
