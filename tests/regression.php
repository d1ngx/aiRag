<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Standalone tests: no application bootstrap, database writes, or network calls.
function _get($a, $k, $d = null) { return isset($a[$k]) ? $a[$k] : $d; }
define('TEMP_PATH', sys_get_temp_dir().'/airag-test-'.getmypid());
mkdir(TEMP_PATH);
require __DIR__.'/../lib/Backpressure.class.php';
require __DIR__.'/../lib/IndexLock.class.php';
require __DIR__.'/../lib/TextNormalizer.class.php';
function check($condition, $name) { if (!$condition) throw new RuntimeException($name); echo "PASS $name\n"; }
try {
 $base = array('Innodb_buffer_pool_pages_total'=>1000, 'Innodb_buffer_pool_pages_dirty'=>400,
  'Innodb_buffer_pool_pages_free'=>0, 'Innodb_buffer_pool_wait_free'=>42964,
  'Innodb_checkpoint_age'=>20, 'Innodb_checkpoint_max_age'=>100);
 $a = AiRagBackpressure::evaluate($base, array(), array(), 100);
 check(!$a['result']['ok'], 'pause at 40% dirty');
 $base['Innodb_buffer_pool_pages_dirty']=350;
 $b = AiRagBackpressure::evaluate($base, $a, array(), 110);
 check(!$b['result']['ok'], 'hysteresis persists between thresholds');
 $base['Innodb_buffer_pool_pages_dirty']=280;
 $c = AiRagBackpressure::evaluate($base, $b, array(), 120);
 check($c['result']['ok'], 'resume at 28%, historical wait_free and full cache do not latch');
 $base['Innodb_buffer_pool_wait_free']++;
 $d = AiRagBackpressure::evaluate($base, $c, array(), 130);
 check(!$d['result']['ok'] && $d['result']['waitFreeDelta']==1, 'wait_free delta pauses');
 $e = AiRagBackpressure::evaluate($base, $d, array(), 145);
 check(!$e['result']['ok'], 'wait_free cooldown survives new sample');
 $e = AiRagBackpressure::evaluate($base, $e, array(), 161);
 check($e['result']['ok'], 'wait_free resumes after cooldown');
 $base['Innodb_buffer_pool_wait_free']=0;
 check(AiRagBackpressure::evaluate($base,$e,array(),170)['result']['ok'], 'server counter reset is not pressure');
 $base['Innodb_checkpoint_age']=60;
 check(!AiRagBackpressure::evaluate($base,array(),array(),180)['result']['ok'], 'checkpoint 60% pauses');
 $base['Innodb_buffer_pool_pages_dirty']=100;
 $base['Innodb_buffer_pool_pages_free']=800;
 $base['Innodb_checkpoint_age']=68;
 check(AiRagBackpressure::evaluate($base,array(),array(),190)['result']['ok'], 'idle healthy pool does not deadlock on stale checkpoint age');
 $base['Innodb_checkpoint_age']=85;
 check(!AiRagBackpressure::evaluate($base,array(),array(),200)['result']['ok'], 'checkpoint 85% is a hard stop');
 $lock=AiRagIndexLock::acquire('kod-heavy-index');
 check($lock!==false && AiRagIndexLock::acquire('kod-heavy-index')===false, 'competing workers cannot acquire shared lock');
 AiRagIndexLock::release($lock);
 check(!AiRagIndexLock::busy('kod-heavy-index'), 'lock released');
 check(AiRagTextNormalizer::clean('&#24207;&#21495;')==='序号','Chinese numeric entities');
 check(AiRagTextNormalizer::clean('&amp;#24207;')==='序','nested numeric entities');
 check(AiRagTextNormalizer::clean('<p>甲</p><p>乙</p>')==="甲\n乙",'HTML block boundaries');
 check(AiRagTextNormalizer::clean('&lt;示例&gt;')==='<示例>','encoded literal tags preserved');
 check(AiRagTextNormalizer::clean('<script>bad()</script><p>正文</p>')==='正文','script text excluded');
 $GLOBALS['config']=array('database'=>array('DB_TYPE'=>'sqlite'));
 $ready=AiRagBackpressure::inspect(array());
 check($ready['ok'],'SQLite does not require InnoDB metrics');
 check(AiRagBackpressure::inspect(array('backpressure'=>0))['ok'],'explicit disable');
} finally {
 foreach(glob(TEMP_PATH.'/*') as $path) unlink($path);
 rmdir(TEMP_PATH);
}
