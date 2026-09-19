<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a, $k, $d = null) { return isset($a[$k]) ? $a[$k] : $d; }
require __DIR__.'/../lib/CorpusShare.class.php';
function check($condition, $name) { if (!$condition) throw new RuntimeException($name); echo "PASS $name\n"; }
check(KodboxCorpusShare::isFresh(array('content' => '合同 A2026-001', 'modifyTime' => 100), 100), 'same mtime is reusable');
check(KodboxCorpusShare::isFresh(array('content' => '合同', 'modifyTime' => 120), 100), 'newer extract is reusable');
check(!KodboxCorpusShare::isFresh(array('content' => '合同', 'modifyTime' => 80), 100), 'stale extract is not reusable');
check(!KodboxCorpusShare::isFresh(array('content' => '合同', 'modifyTime' => 0), 100), 'legacy docs without mtime are not trusted for dated files');
check(KodboxCorpusShare::isFresh(array('content' => '合同', 'modifyTime' => 0), 0), 'undated legacy docs remain reusable for undated files');
check(!KodboxCorpusShare::isFresh(array('content' => '  ', 'modifyTime' => 100), 100), 'blank content is not reusable');
check(!KodboxCorpusShare::isFresh(array(), 100), 'missing document is not reusable');
$opts = KodboxCorpusShare::storeOptions('missingPlugin', 'http://elasticsearch:9200', 'kodbox-fulltext');
check($opts['indexName'] === 'kodbox-fulltext' && $opts['elasticUrl'] === 'http://elasticsearch:9200', 'fallback store options');
echo "OK\n";
