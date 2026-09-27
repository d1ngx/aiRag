<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
require __DIR__.'/../lib/TextNormalizer.class.php';require __DIR__.'/../lib/TextChunker.class.php';
$start=microtime(true);$text=str_repeat('甲',800000).'正文末尾标记';$chunks=AiRagTextChunker::split($text,200,50);
check(count($chunks)>4096&&strpos(end($chunks)['text'],'正文末尾标记')!==false,'long documents retain tail beyond 4096 chunks');
echo 'INFO 800k-character split: '.round((microtime(true)-$start)*1000).' ms, '.count($chunks)." chunks\n";
$text=str_repeat('😀',10000);$name=str_repeat('中文名',100);$chunks=AiRagTextChunker::split($text,2000,0,$name);$body='';
foreach($chunks as$chunk){check(strlen($chunk['text'])<=8192&&mb_check_encoding($chunk['text'],'UTF-8'),'chunk including title fits Milvus byte limit');$body.=substr($chunk['text'],strpos($chunk['text'],"\n")+1);}
check($body===$text,'byte-boundary slicing and tail merge preserve every character without overlap');
