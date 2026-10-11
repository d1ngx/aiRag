<?php
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
require_once __DIR__.'/../lib/Security.class.php';
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$s=array('REQUEST_METHOD'=>'POST','HTTP_HOST'=>'kod.test','HTTP_ORIGIN'=>'https://kod.test','HTTP_SEC_FETCH_SITE'=>'same-origin','HTTP_X_KODBOX_PLUGIN'=>'1');
check(KodboxSearchSecurity::requestAllowed($s,array(),true),'same-origin custom-header request allowed');
foreach(array('HTTP_X_KODBOX_PLUGIN'=>'','HTTP_ORIGIN'=>'https://evil.test','HTTP_SEC_FETCH_SITE'=>'cross-site','REQUEST_METHOD'=>'GET')as$k=>$v){$bad=$s;$bad[$k]=$v;check(!KodboxSearchSecurity::requestAllowed($bad,array(),true),'reject forged request '.$k);}
check(!KodboxSearchSecurity::requestAllowed($s,array('callback'=>'steal'),true),'JSONP cannot expose authenticated data');
foreach(array('file:///etc/passwd','gopher://127.0.0.1:6379/_test','https://user:pass@host/','https://host/#frag',"https://host/\r\nX-Test:yes")as$url)check(rejects(function()use($url){KodboxSearchSecurity::httpUrl($url);}),'reject unsafe URL '.$url);
check(KodboxSearchSecurity::httpUrl('http://elasticsearch:9200')==='http://elasticsearch:9200','explicit internal service URL remains supported');
foreach(array('/etc/passwd','http://127.0.0.1/private','{source:1}/../secret','{source:1}/%2e%2e/secret','{source:1}/..\\secret',"{source:1}/\0x")as$path)check(!KodboxSearchSecurity::sourcePath($path),'reject external/local/traversal path '.json_encode($path));
check(KodboxSearchSecurity::sourcePath('{source:123}/合同.txt'),'canonical source reference accepted');
foreach(array('../x.php','..\\x.php','.htaccess','/tmp/a',"x\0.txt")as$name)check(rejects(function()use($name){KodboxSearchSecurity::saveName($name);}),'reject generated traversal/control filename');
check(KodboxSearchSecurity::saveName('shell.php')==='shell.php.txt','generated executable becomes inert text');
check(KodboxSearchSecurity::saveName('报告.md')==='报告.md','safe document name preserved');
class Auth {function fileCanRead($path){return $path==='{source:1}';}}
function Action($name){return new Auth;}
check(KodboxSearchSecurity::readablePath('{source:1}')&&!KodboxSearchSecurity::readablePath('{source:2}'),'reference authorization fails closed');
class PageTemplate {
 public $pluginHost='/plugins/aiRag/';public $pluginPath;public $pluginApi='/api/';public $airagBoot;
 function render(){ob_start();include __DIR__.'/../static/page.html';return ob_get_clean();}
}
$p=new PageTemplate;$p->pluginPath=__DIR__.'/../';$attack='</script><script>window.auditXss=1</script>';$p->airagBoot=array('refs'=>array(array('name'=>$attack,'path'=>'{source:1}')));$html=$p->render();
check(strpos($html,$attack)===false&&strpos($html,'\\u003C/script\\u003E')!==false,'real PHP template escapes script closing tag');
if(getenv('AUDIT_RENDER_PATH'))file_put_contents(getenv('AUDIT_RENDER_PATH'),$html);
// Search terms must never be concatenated into the raw JOIN.
class PluginBase {}
require __DIR__.'/../app.php';$r=new ReflectionClass('aiRagPlugin');$app=$r->newInstanceWithoutConstructor();
$sql=$r->getMethod('libraryJoin')->invoke($app,"' OR 1=1 --",array("txt'); DROP TABLE users; --"),null,0);
check(strpos($sql,'OR 1=1')===false&&strpos($sql,'DROP TABLE')===false,'raw JOIN excludes hostile search text and normalizes extensions');
