<?php
namespace UploadSecurityTest;
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
use InvalidArgumentException;use Throwable;use CURLFile;
class finfo {function __construct($v){}function file($p){return State::$mime;}}
class State {static $uploaded=true;static $size=123;static $mime='audio/mpeg';}
function is_uploaded_file($p){return State::$uploaded;}
function filesize($p){return State::$size;}
$src=file_get_contents(__DIR__.'/../lib/Security.class.php');
$src=str_replace("class_exists('KodboxSearchSecurity'", "class_exists('UploadSecurityTest\\\\KodboxSearchSecurity'",$src);
eval('namespace UploadSecurityTest; use InvalidArgumentException; use Throwable; use CURLFile; '.substr($src,5));
$file=array('error'=>UPLOAD_ERR_OK,'tmp_name'=>'/tmp/php-audit','name'=>'../../attack.php','type'=>'audio/mpeg');
function deny($file,$label){try{KodboxSearchSecurity::audioFile($file);}catch(InvalidArgumentException $e){echo "PASS $label\n";return;}throw new \RuntimeException($label);}
State::$uploaded=false;deny($file,'reject local file masquerading as upload');State::$uploaded=true;
State::$size=26*1024*1024;deny($file,'reject upload exceeding 25 MB');State::$size=0;deny($file,'reject empty upload');State::$size=123;
State::$mime='text/x-php';deny($file,'reject forged client audio MIME using detected MIME');State::$mime='audio/mpeg';
$f=KodboxSearchSecurity::audioFile($file);if($f->getPostFilename()!=='audio.mp3')throw new \RuntimeException('untrusted filename forwarded');echo "PASS safe multipart name and server-detected MIME\n";
$file['error']=UPLOAD_ERR_PARTIAL;deny($file,'reject partial upload');
