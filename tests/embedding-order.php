<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
class AiRagHttpJson {static $data;static function fixUrl($u){return $u;}static function request(...$args){return array('data'=>self::$data);}}
require __DIR__.'/../lib/EmbedClient.class.php';
$embed=new AiRagEmbedClient(array('embedUrl'=>'https://mock.invalid','embedDim'=>32));
$one=array('index'=>0,'embedding'=>array_fill(0,32,1));$two=array('index'=>1,'embedding'=>array_fill(0,32,2));
AiRagHttpJson::$data=array($two,$one);$out=$embed->embed(array('one','two'));
check($out[0][0]===1.0&&$out[1][0]===2.0,'embedding results follow input index rather than response order');
foreach(array('duplicate'=>array($one,$one),'missing'=>array($one),'out of range'=>array($one,array_merge($two,array('index'=>2))),'no index'=>array(array('embedding'=>$one['embedding']),$two),'nonnumeric'=>array(array('index'=>0,'embedding'=>array_fill(0,32,'oops')),$two))as$name=>$data){AiRagHttpJson::$data=$data;$failed=false;try{$embed->embed(array('one','two'));}catch(Exception $e){$failed=true;}check($failed,'reject invalid embedding response: '.$name);}
