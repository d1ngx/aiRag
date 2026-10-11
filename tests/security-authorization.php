<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
class PluginBase {public $in=array();}
class KodUser {static function isRoot(){return false;}}
class KodIO {static function make($id){return '{source:'.$id.'}';}}
class Auth {function fileCanRead($path){return $path==='{source:20}';}}
function Action($name){return new Auth;}
class IO {static $reads=0;static function info($path){self::$reads++;return array('fileID'=>7,'sourceID'=>20,'name'=>'mine.txt');}}
class Db {
 public $name;public $where;
 function __construct($name){$this->name=$name;}
 function db(){return $this;}function getTables(){return array('plugin_airag_state');}
 function where($w){$this->where=$w;return $this;}function field($x){return $this;}function limit($x){return $this;}
 function select(){
  if($this->name==='Source')return array(array('sourceID'=>10,'fileID'=>7),array('sourceID'=>20,'fileID'=>7),array('sourceID'=>30,'fileID'=>8));
  if($this->name==='plugin_airag_state'){
   if($this->where['fileID'][1]!==array(7))throw new RuntimeException('state query includes unreadable IDs');
   return array(array('fileID'=>7,'status'=>3,'chunkCount'=>4));
  }
  return array();
 }
 function find(){if($this->name==='Source'){if($this->where['sourceID']!==20)throw new RuntimeException('private source was hydrated');return array('fileID'=>7,'sourceID'=>20,'name'=>'mine.txt','isFolder'=>0);}
 return array('fileID'=>7,'name'=>'physical-name.txt');}
}
function Model($name=''){return new Db($name);}
require __DIR__.'/../app.php';$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();
$app->in=array('fileIDs'=>array(7,8),'paths'=>array('http://127.0.0.1/','/etc/passwd','{source:30}','{source:20}'));
$out=$r->getMethod('fileFlags')->invoke($app);
check(array_keys($out['flags'])===array(7),'fileFlags omits unreadable IDs before querying state');
check(IO::$reads===1,'external, local and unauthorized paths never reach IO');
check($out['flags'][7]['status']===3,'authorized disabled file still has a visible status');
$meta=$r->getMethod('hydrateState')->invoke($app,array('fileID'=>7,'sourceID'=>10));
check($meta['sourceID']===20&&$meta['name']==='mine.txt','citations use readable source copy instead of private duplicate');
$scope=$r->getMethod('resolveAskScope')->invoke($app,array('http://127.0.0.1/','{source:30}'));
check($scope['restricted']&&!$scope['fileIDs']&&!$scope['folderIDs'],'invalid explicit references never widen search to all files');
