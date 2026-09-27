<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
class AiRagHttpJson {
 static $versionField=true;static $queries=array();static $describes=0;
 static function fixUrl($u){return $u;}
 static function request($method,$url,$body){
  if(strpos($url,'/describe')!==false){self::$describes++;return array('code'=>0,'data'=>array('fields'=>self::$versionField?array(array('name'=>'model_version'),array('name'=>'ancestor_ids')):array()));}
  self::$queries[]=$body;
  if(strpos($url,'/query')!==false&&strpos($body['filter'],'chunk_index >=')!==false){preg_match('/chunk_index >= (\d+)/',$body['filter'],$m);$start=(int)$m[1];$rows=array();foreach(array(0,5000,9000)as$i)if($i>=$start&&$i<$start+4096)$rows[]=array('chunk_index'=>$i,'content_hash'=>'hash','model_version'=>'m1');return array('code'=>0,'data'=>$rows);}
  return array('code'=>0,'data'=>array());
 }
}
require __DIR__.'/../lib/MilvusStore.class.php';
$store=new AiRagMilvusStore(array('modelVersion'=>'m2'));$store->search(array(1),10);$body=end(AiRagHttpJson::$queries);
check(strpos($body['filter'],'model_version == "m2"')!==false,'vector queries filter by active embedding version');
$store->listByFile(42);check(strpos(end(AiRagHttpJson::$queries)['filter'],'model_version == "m2"')!==false,'direct vector citations filter by active embedding version');
$existing=$store->hashesByFile(42,10001);check(array_keys($existing)===array(0,5000,9000),'hash pagination covers sparse chunks beyond offset limits');
check(AiRagHttpJson::$describes===1,'schema lookup reused across searches and hash pages');
$chunks=array(array('index'=>0,'text'=>'same'));
$old=array(0=>array('content_hash'=>AiRagMilvusStore::chunkHash('same','m2')));
check(count(AiRagMilvusStore::diffChunks($chunks,$old,'m2')['work'])===1,'untagged legacy vector is regenerated even when hash matches');
$old[0]['model_version']='m2';check(AiRagMilvusStore::diffChunks($chunks,$old,'m2')['reuse']===array(0),'matching tagged vectors are reused');
AiRagHttpJson::$versionField=false;$queries=count(AiRagHttpJson::$queries);$legacy=new AiRagMilvusStore(array('modelVersion'=>'m2'));
check(!$legacy->search(array(1),10)&&!$legacy->listByFile(42)&&count(AiRagHttpJson::$queries)===$queries,'unmigrated collection cannot silently return mixed-model vectors');
