<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
require __DIR__.'/../lib/MilvusStore.class.php';
function check($c,$s){if(!$c)throw new RuntimeException($s);echo "PASS $s\n";}
$chunks=array();
for($i=0;$i<5;$i++)$chunks[]=array('index'=>$i,'text'=>'chunk'.$i);
$existing=array(0=>sha1('chunk0'),1=>sha1('chunk1'),9=>sha1('gone'));
$plan=AiRagMilvusStore::diffChunks($chunks,$existing);
check($plan['reuse']===array(0,1),'unchanged chunks reused');
check(count($plan['work'])===3 && $plan['work'][0]['index']===2,'changed and new chunks queued');
check($plan['stale']===array(9),'removed chunk indexes deleted');
check(AiRagMilvusStore::chunkId(42,7)==='42:7','string chunk primary key');
check(AiRagMilvusStore::filterExpr(array(1,2), array('ext'=>'pdf','sourceID'=>9))==='file_id in [1,2] && source_id == 9 && ext == "pdf"','scalar filter pushdown');
check(AiRagMilvusStore::filterExpr(null, array('ext'=>'pdf;drop'))==='ext == "pdfdrop"','ext filter is sanitized');
$row=AiRagMilvusStore::row(array('fileID'=>8,'index'=>3,'text'=>'正文','name'=>'a.docx','ext'=>'docx','sourceID'=>11,'parentID'=>2,'modifyTime'=>100,'hash'=>sha1('正文')), array(0.1,0.2));
check($row['chunk_id']==='8:3' && $row['content_hash']===sha1('正文') && $row['source_id']===11,'upsert row carries hash and source');
echo "OK\n";
