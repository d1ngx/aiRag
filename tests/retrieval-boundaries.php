<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
class AiRagTextNormalizer {static function rewriteQuery($q){return array('raw'=>$q,'keyword'=>$q);}static function keywordHeavy($q){return false;}}
class ES {public $calls=0;function search($q,$n,$ids,$filter){$this->calls++;return array(array('fileID'=>8,'rank'=>1,'snippet'=>'ES overview','name'=>'test'));}}
class MV {public $calls=0;function search($q,$n,$ids,$filter,$offset=0,$group=false){$this->calls++;return array(array('fileID'=>8,'chunk'=>4,'rank'=>1,'text'=>'best chunk','name'=>'test','score'=>0.9),array('fileID'=>8,'chunk'=>9,'rank'=>2,'text'=>'second chunk','name'=>'test','score'=>0.4));}}
class Embed {public $calls=0;function embedQuery($q,$timeout=8){$this->calls++;return array(1);}}
class PluginBase {}
class IO {static function info($p){return array('name'=>'folder','isFolder'=>1,'parentLevel'=>',','sourceID'=>99);}}
class FakeModel {
 public $after=0;function where($w){$this->after=isset($w['sourceID'])?intval($w['sourceID'][1]):0;return $this;}
 function field($v){return $this;}function order($v){return $this;}function limit($v){return $this;}
 function select(){$out=array();for($i=$this->after+1;$i<=min(650,$this->after+500);$i++)$out[]=array('sourceID'=>$i,'fileID'=>$i);return $out;}
}
function Model($n){return new FakeModel;}
require __DIR__.'/../lib/HybridRetriever.class.php';require __DIR__.'/../app.php';
function check($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS $m\n";}
$es=new ES;$mv=new MV;$embed=new Embed;$h=new AiRagHybridRetriever($es,$mv,$embed);
$out=$h->search('test',10,true,true,array());check(!$out['hybrid']&&!$es->calls&&!$mv->calls&&!$embed->calls,'empty restricted scope does not search or embed');
$out=$h->search('test');$hit=$out['hybrid'][0];check(count($hit['chunks'])===2,'multiple matches from same file retained');check($hit['chunk']===4&&$hit['snippet']==='ES overview'&&$hit['source']['vector']['text']==='best chunk','best chunk stays aligned and keyword snippet is kept');
$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();$scope=$r->getMethod('resolveAskScope')->invoke($app,array('folder'));
check($scope['folderIDs']===array(99)&&!$scope['fileIDs']&&$scope['restricted'],'folder reference stays a folder filter');
$scope=$r->getMethod('resolveAskScope')->invoke($app,array());check(!$scope['restricted'],'unrestricted scope remains distinct');
$prepared=$h->prepare('again',true);$before=$embed->calls;
$h->search('again',10,true,true,null,array('parentID'=>1),$prepared);
$h->search('again',10,true,true,null,array('parentID'=>2),$prepared);
check($embed->calls===$before,'folder searches reuse one embedding');
