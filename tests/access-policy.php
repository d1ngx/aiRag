<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a,$k,$d=null) { return isset($a[$k]) ? $a[$k] : $d; }
function check($ok,$name) { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
class PluginBase { public function getConfig() { return array('allowExtensions'=>'txt,pdf','maxFileSizeMB'=>50); } }
class KodIO { static function make($id) { return (string)$id; } }
class KodUser { static $root=false; static function isRoot() { return self::$root; } }
class Auth { function fileCan($path,$action) { return $path === '11'; } }
function Action($name) { return new Auth; }
class PolicyModel {
 static $failSource=false; static $failFiles=false; static $failBlocked=false; static $blockedQueries=0; static $fileQueries=0;
 static $corpus=array('allowExtensions'=>'txt,pdf','maxFileSizeMB'=>30);
 static $files=array();
 private $name; private $where;
 function __construct($name) {$this->name=$name;}
 function getConfig($name) {return self::$corpus;}
 function where($where) {$this->where=$where;return $this;}
 function field($v) {return $this;} function order($v) {return $this;} function limit($v) {return $this;}
 function select() {
  if ($this->name==='plugin_airag_state') {self::$blockedQueries++;if(self::$failBlocked)throw new RuntimeException('blocked DB down');if(!is_array($this->where)||!isset($this->where['fileID'][1]))throw new RuntimeException('unbounded state query');return array(array('fileID'=>2,'status'=>3,'error'=>'已禁用'),array('fileID'=>3,'status'=>6));}
  if ($this->name==='File') {self::$fileQueries++;if(self::$failFiles)throw new RuntimeException('file DB down');$ids=$this->where['fileID'][1];return array_values(array_filter(self::$files,function($r)use($ids){return in_array($r['fileID'],$ids,true);}));}
  if ($this->name==='Source') {if(self::$failSource)throw new RuntimeException('ACL DB down');return array(array('fileID'=>1,'sourceID'=>11),array('fileID'=>7,'sourceID'=>77));}
  throw new RuntimeException('unexpected model '.$this->name);
 }
}
function Model($name) { return new PolicyModel($name); }
require __DIR__.'/../lib/CorpusShare.class.php';
require __DIR__.'/../lib/RetrievalPolicy.class.php';
require __DIR__.'/../app.php';
foreach(array(1,2,3,4,5,6,7)as$id)PolicyModel::$files[]=array('fileID'=>$id,'name'=>$id===4?'photo.png':'test.txt','size'=>$id===5?40*1024*1024:($id===6?0:100));
$p=new AiRagRetrievalPolicy(array('allowExtensions'=>'txt,pdf','maxFileSizeMB'=>50));
check($p->allowedIDs(array(1,2,3,4,5,6,7,8))===array(1,7),'disabled, deleting, excluded, oversized, empty and missing files cannot be read');
$f=$p->filters(array('excludeFileIDs'=>array(99)));
check($f['excludeFileIDs']===array(99)&&$f['maxBytes']===30*1024*1024&&$f['extensions']===array('txt','pdf'),'policy pushes intersection without enumerating disabled files');
check(PolicyModel::$blockedQueries===1,'candidate states fetched in one bounded query');
$before=PolicyModel::$fileQueries;$p->allowedIDs(array(1,2,7));check(PolicyModel::$fileQueries===$before,'repeated candidates reuse request-local metadata decisions');
$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();$r->getProperty('retrievalPolicy')->setValue($app,$p);$visible=$r->getMethod('visibleFileIDs');
check($visible->invoke($app,array(1,7))===array(1),'only readable source copies pass permission checks');
PolicyModel::$failSource=true;
check($visible->invoke($app,array(1))===array(),'ACL database exceptions fail closed');
PolicyModel::$failSource=false;PolicyModel::$failFiles=true;$r->getProperty('retrievalPolicy')->setValue($app,null);
check($visible->invoke($app,array(1))===array(),'file policy database exceptions fail closed');
PolicyModel::$failFiles=false;KodUser::$root=true;
check($visible->invoke($app,array(1,2,3))===array(1),'root bypass does not bypass disabled or deleting policy');
PolicyModel::$failBlocked=true;$r->getProperty('retrievalPolicy')->setValue($app,null);
check($visible->invoke($app,array(1))===array(),'state database exceptions fail closed even for root');
PolicyModel::$failBlocked=false;PolicyModel::$corpus=array();
check((new AiRagRetrievalPolicy(array('allowExtensions'=>'txt')))->allowedIDs(array(1))===array(),'unavailable corpus configuration denies retrieval');
PolicyModel::$corpus=array('allowExtensions'=>'txt,pdf','maxFileSizeMB'=>30);
$p=new AiRagRetrievalPolicy(array('allowExtensions'=>'txt,pdf','maxFileSizeMB'=>50));
class AiRagHttpJson {
 static $body;
 static function fixUrl($u){return $u;}
 static function request($method,$url,$body=null){self::$body=$body;return array('hits'=>array('hits'=>array()));}
}
require __DIR__.'/../lib/ElasticStore.class.php';
require __DIR__.'/../lib/MilvusStore.class.php';
$es=new AiRagElasticStore(array());$filter=$p->filters(array('excludeFileIDs'=>array(2,3)));$es->search('test',10,null,$filter);
$clauses=AiRagHttpJson::$body['query']['bool']['filter'];
check(in_array(array('terms'=>array('ext'=>array('txt','pdf'))),$clauses,true),'ES normal search receives extension policy');
check(in_array(array('bool'=>array('must_not'=>array(array('terms'=>array('fileID'=>array(2,3)))))), $clauses,true),'ES excludes disabled and pending deletion IDs before ranking');
$es->searchPage('test',10,null,$filter);
check(AiRagHttpJson::$body['query']['bool']['filter']===$clauses,'ES migration paging keeps the same policy');
$expr=AiRagMilvusStore::filterExpr(null,$filter);
check(strpos($expr,'file_id not in [2,3]')!==false&&strpos($expr,'ext in ["txt","pdf"]')!==false,'Milvus receives equivalent exclusions and extensions');
class AiRagTextNormalizer {static function rewriteQuery($q){return array('raw'=>$q,'keyword'=>$q);}static function keywordHeavy($q){return false;}}
class PolicyES {public $filters=array();function search($q,$n,$ids,$filter){$this->filters[]=$filter;return array(array('fileID'=>2,'rank'=>1,'snippet'=>'disabled','name'=>'x'),array('fileID'=>1,'rank'=>2,'snippet'=>'allowed','name'=>'x'));}}
class PolicyMV {public $filters=array();function search($v,$n,$ids,$filter,$offset=0,$group=false){$this->filters[]=$filter;return array(array('fileID'=>3,'chunk'=>0,'rank'=>1,'text'=>'deleting','name'=>'x'),array('fileID'=>5,'chunk'=>0,'rank'=>2,'text'=>'oversized','name'=>'x'));}}
class PolicyEmbed {function embedQuery($q,$timeout){return array(1);}}
require __DIR__.'/../lib/HybridRetriever.class.php';
$es=new PolicyES;$mv=new PolicyMV;$hybrid=new AiRagHybridRetriever($es,$mv,new PolicyEmbed,$p);
$out=$hybrid->search('test',10);
check(array_column($out['hybrid'],'fileID')===array(1),'final batch validation drops excluded results even when a backend returns them');
check($es->filters[0]['excludeFileIDs']===$mv->filters[0]['excludeFileIDs'],'hybrid stores receive identical exclusion set');
$out=$hybrid->search('test',10,true,true,array(2,3));
check(!$out['hybrid']&&count($es->filters)===1&&count($mv->filters)===1,'explicit references cannot bypass resource disablement');
$before=PolicyModel::$blockedQueries;$single=new AiRagRetrievalPolicy(array('allowExtensions'=>'txt'));$single->allowedIDs(array(1));
check(PolicyModel::$blockedQueries-$before===1,'single-file access uses one candidate-state query independent of disabled-table size');
for($i=8;$i<=15;$i++)PolicyModel::$files[]=array('fileID'=>$i,'name'=>'file.txt','size'=>100);
class RefillES {public $calls=0;public $filters=array();function search($q,$n,$ids,$filter){$this->calls++;$this->filters[]=$filter;$hits=array();foreach(array(2,3,1,7,8,9,10,11,12,13,14,15)as$id){if(in_array($id,$filter['excludeFileIDs']))continue;$hits[]=array('fileID'=>$id,'rank'=>count($hits)+1,'snippet'=>'text','name'=>'file.txt');if(count($hits)>=$n)break;}return $hits;}}
$refill=new RefillES;$p=new AiRagRetrievalPolicy(array('allowExtensions'=>'txt'));$h=new AiRagHybridRetriever($refill,new PolicyMV,new PolicyEmbed,$p);$out=$h->search('test',5,true,false);
check(count($out['hybrid'])===5&&!in_array(2,array_column($out['hybrid'],'fileID'))&&!in_array(3,array_column($out['hybrid'],'fileID')),'bounded refill replaces disabled hits with eligible results');
check($refill->calls===2&&in_array(2,$refill->filters[1]['excludeFileIDs']),'refill excludes previously inspected candidates at the backend');
