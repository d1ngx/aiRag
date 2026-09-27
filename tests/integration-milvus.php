<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$url=rtrim((string)getenv('PLUGIN_AUDIT_MILVUS_URL'),'/');
if($url===''){echo "SKIP Milvus integration (set PLUGIN_AUDIT_MILVUS_URL)\n";exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
class AiRagBackpressure {static function assertReady($c){}}
require __DIR__.'/../lib/HttpJson.class.php';require __DIR__.'/../lib/MilvusStore.class.php';
function api($path,$body){global$url;$r=AiRagHttpJson::request('POST',$url.'/v2/vectordb/'.$path,$body,array(),30);if(!isset($r['code'])||(int)$r['code']!==0)throw new RuntimeException(json_encode($r));return $r;}
$name='airag_test_'.bin2hex(random_bytes(8));$created=false;$m1=sha1('model-1');$m2=sha1('model-2');
$config=array('milvusUrl'=>$url,'milvusCollection'=>$name,'embedDim'=>32,'modelVersion'=>$m1,'milvusPauseMs'=>0);
try {
 // Explicit legacy schema: model_version does not exist yet; migration must preserve its rows.
 $fields=array(array('fieldName'=>'chunk_id','dataType'=>'VarChar','isPrimary'=>true,'elementTypeParams'=>array('max_length'=>64)));
 foreach(array('file_id','source_id','parent_id','chunk_index','modify_time')as$f)$fields[]=array('fieldName'=>$f,'dataType'=>'Int64');
 foreach(array('text'=>8192,'name'=>512,'ext'=>16,'content_hash'=>40)as$f=>$len)$fields[]=array('fieldName'=>$f,'dataType'=>'VarChar','elementTypeParams'=>array('max_length'=>$len));
 $fields[]=array('fieldName'=>'ancestor_ids','dataType'=>'Array','elementDataType'=>'Int64','nullable'=>true,'elementTypeParams'=>array('max_capacity'=>64));
 $fields[]=array('fieldName'=>'vector','dataType'=>'FloatVector','elementTypeParams'=>array('dim'=>'32'));
 api('collections/create',array('collectionName'=>$name,'schema'=>array('autoID'=>false,'enableDynamicField'=>false,'fields'=>$fields)));$created=true;
 api('indexes/create',array('collectionName'=>$name,'indexParams'=>array(array('fieldName'=>'vector','indexName'=>'vector_cosine','metricType'=>'COSINE','index_type'=>'AUTOINDEX'))));
 $old=AiRagMilvusStore::row(array('fileID'=>42,'index'=>5,'text'=>'legacy','ext'=>'txt'),array_fill(0,32,0.2));unset($old['model_version']);
 api('entities/upsert',array('collectionName'=>$name,'data'=>array($old)));
 $store1=new AiRagMilvusStore($config);$store1->ensureInfrastructure();check($store1->hasModelField(),'real Milvus adds model version field to legacy schema');
 $rows=array();foreach(array(0=>$m1,1=>$m2,9000=>$m1)as$i=>$model)$rows[]=AiRagMilvusStore::row(array('fileID'=>42,'index'=>$i,'text'=>'versioned '.$i,'ext'=>'txt','modelVersion'=>$model),array_fill(0,32,0.2));
 $store1->upsertChunks($rows,50,0);
 $store2=new AiRagMilvusStore(array_merge($config,array('modelVersion'=>$m2)));$store2->ensureInfrastructure();check($store2->hasModelField(),'model field/index migration is idempotent across requests');
 $a=$store1->search(array_fill(0,32,0.2),10);$b=$store2->search(array_fill(0,32,0.2),10);
 $ca=array_column($a,'chunk');sort($ca);check($ca===array(0,9000)&&array_column($b,'chunk')===array(1),'real Milvus excludes null legacy rows and isolates same-dimension embedding versions');
 $direct=$store2->listByFile(42);check(array_column($direct,'index')===array(1),'real Milvus direct citations obey model version');
 $all=$store1->hashesByFile(42,10001);$keys=array_keys($all);sort($keys);check($keys===array(0,1,5,9000),'real Milvus range paging sees legacy and sparse tail for migration cleanup');
}finally{if($created)api('collections/drop',array('collectionName'=>$name));}
