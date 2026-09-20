<?php

class aiRagPlugin extends PluginBase {
	private $stateTable = 'plugin_airag_state';
	private $snippets = array();
	private $matchedFileIDs = array();
	private $cursorFile = '';

	const ST_ES = 1;
	const ST_OK = 2;
	const ST_SKIP = 3;
	const ST_FAIL = 4;
	const ST_WAIT = 5;

	public function __construct() {
		parent::__construct();
		$this->cursorFile = rtrim(TEMP_PATH, '/\\').'/airag-cursor.json';
		$this->loadLib();
	}

	public function regist() {
		$this->hookRegist(array(
			'globalRequest' => 'aiRagPlugin.bindHooks',
			'user.commonJs.insert' => 'aiRagPlugin.echoJs',
		));
	}

	public function bindHooks() {
		Hook::bind('explorer.listSearch.searchDataBefore', 'aiRagPlugin.searchBefore');
		Hook::bind('explorer.listSearch.searchDataAfter', 'aiRagPlugin.searchAfter');
		Hook::bind('explorer.listSearch.fileContentText', 'aiRagPlugin.fileContentText');
		Hook::bind('docSearch.fileContentMatch', 'aiRagPlugin.docSearchMatch');
		Hook::bind('explorer.list.path.parse', 'aiRagPlugin.listPathParse');
	}

	public function echoJs() {
		$this->echoFile('static/main.js');
	}

	public function index() {
		if (!KodUser::isLogin()) return show_tips('请先登录');
		$refs = $this->decodeJson(_get($this->in, 'refs', isset($_GET['refs']) ? $_GET['refs'] : ''));
		$this->airagBoot = array(
			'api' => rtrim((string)(isset($this->pluginApi) ? $this->pluginApi : '?plugin/aiRag/'), '/').'/',
			'refs' => is_array($refs) ? $refs : array(),
			'refsGiven' => array_key_exists('refs', (array)$this->in) || isset($_GET['refs']),
			'compact' => intval(_get($this->in, 'compact', isset($_GET['compact']) ? $_GET['compact'] : 0)) === 1 ? 1 : 0,
			'models' => AiRagModelHub::chatModels($this->services()),
			'model' => (string)_get($this->getConfig(), 'llmModel', ''),
			'imageModels' => $this->typedModels($this->services(), 'image', true),
			'asrModels' => $this->typedModels($this->services(), 'asr', true),
		);
		include $this->pluginPath.'static/page.html';
	}

	public function media() {
		if (_get($_SERVER, 'REQUEST_METHOD', '') !== 'POST') return show_json(LNG('common.illegalRequest'), false);
		if (!KodUser::isLogin()) return show_json('请先登录', false);
		$this->releaseSession();
		$config = $this->getConfig();
		$operation = trim((string)_get($this->in, 'operation', 'image'));
		$image = AiRagModelHub::first($this->services(), 'image');
		$asr = AiRagModelHub::first($this->services(), 'asr');
		$chat = AiRagModelHub::first($this->services(), 'chat');
		$hit = $operation === 'asr' ? ($asr ?: $chat) : ($image ?: $chat);
		$base = $hit ? $hit['url'] : AiRagChatClient::normalizeBaseUrl(_get($config, 'llmUrl', _get($config, 'embedUrl', '')));
		$key = $hit ? $hit['apiKey'] : trim((string)_get($config, 'llmApiKey', _get($config, 'embedApiKey', '')));
		if ($base === '') return show_json(array('message' => '未配置 API 地址'), false);
		$headers = array();
		if ($key !== '') $headers['Authorization'] = 'Bearer '.$key;
		try {
			if ($operation === 'image') {
				$prompt = trim((string)_get($this->in, 'prompt', ''));
				if ($prompt === '') return show_json(array('message' => '请先描述图片'), false);
				$model = trim((string)_get($this->in, 'model', ''));
				if ($model === '') $model = $image ? $image['model']['id'] : 'Kwai-Kolors/Kolors';
				$res = AiRagHttpJson::request('POST', $base.'/images/generations', array(
					'model' => $model,
					'prompt' => $prompt,
					'image_size' => '1024x1024',
					'batch_size' => 1,
				), $headers, 120);
				$url = (string)_get(_get(_get($res, 'images', array()), 0, array()), 'url', '');
				if ($url === '') $url = (string)_get(_get(_get($res, 'data', array()), 0, array()), 'url', '');
				$b64 = (string)_get(_get(_get($res, 'data', array()), 0, array()), 'b64_json', '');
				if ($url === '' && $b64 !== '') $url = 'data:image/png;base64,'.$b64;
				if ($url === '') return show_json(array('message' => '没有返回图片，请确认模型支持文生图'), false);
				return show_json(array('url' => $url));
			}
			if ($operation === 'asr') {
				if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
					return show_json(array('message' => '请选择音频文件'), false);
				}
				$model = trim((string)_get($this->in, 'model', ''));
				if ($model === '') $model = $asr ? $asr['model']['id'] : 'FunAudioLLM/SenseVoiceSmall';
				$fields = array(
					'model' => $model !== '' ? $model : 'FunAudioLLM/SenseVoiceSmall',
					'file' => class_exists('CURLFile') ? new CURLFile($_FILES['file']['tmp_name'], _get($_FILES['file'], 'type', ''), _get($_FILES['file'], 'name', 'audio.wav')) : '@'.$_FILES['file']['tmp_name'],
				);
				$res = AiRagHttpJson::upload($base.'/audio/transcriptions', $fields, $headers, 120);
				$text = (string)_get($res, 'text', _get($res, 'result', ''));
				if ($text === '') $text = json_encode($res, JSON_UNESCAPED_UNICODE);
				return show_json(array('text' => $text));
			}
			return show_json('Unknown operation', false);
		} catch (Throwable $e) {
			return show_json(array('message' => $e->getMessage()), false);
		}
	}

	public function onChangeStatus($status) {
		if ($status) $this->initTable();
		$this->updateTask($status && $this->isOpen() ? 1 : 0);
		return true;
	}

	public function onUpdate() {
		$this->initTable();
		$this->updateTask($this->isOpen() ? 1 : 0);
	}

	public function onSetConfig($config) {
		$prev = $this->getConfig();
		$services = AiRagModelHub::parse(_get($config, 'modelServices', _get($prev, 'modelServices', '')), AiRagModelHub::defaults($prev));
		if (!$services) $services = AiRagModelHub::defaults($config);
		$config = array_merge($config, AiRagModelHub::apply($config, $services));
		$config['elasticUrl'] = AiRagHttpJson::fixUrl($this->keepConfig($config, $prev, 'elasticUrl', 'http://elasticsearch:9200'));
		$config['milvusUrl'] = AiRagHttpJson::fixUrl($this->keepConfig($config, $prev, 'milvusUrl', 'http://milvus:19530'));
		$config['embedUrl'] = AiRagEmbedClient::normalizeBaseUrl($this->keepConfig($config, $prev, 'embedUrl', ''));
		$config['embedModel'] = AiRagEmbedClient::normalizeModel($this->keepConfig($config, $prev, 'embedModel', 'BAAI/bge-m3'));
		$config['llmUrl'] = AiRagChatClient::normalizeBaseUrl($this->keepConfig($config, $prev, 'llmUrl', ''));
		$config['llmEnabled'] = _get($config, 'llmEnabled', '1') == '1' ? 1 : 0;
		$config['llmTemperature'] = max(0, min(2, floatval($this->keepConfig($config, $prev, 'llmTemperature', 0.3))));
		$config['llmMaxTokens'] = max(256, min(8192, intval($this->keepConfig($config, $prev, 'llmMaxTokens', 2048))));
		if ($config['elasticUrl'] && !preg_match('#^https?://#i', $config['elasticUrl'])) throw new Exception('Elasticsearch URL 需以 http:// 或 https:// 开头');
		if ($config['milvusUrl'] && !preg_match('#^https?://#i', $config['milvusUrl'])) throw new Exception('Milvus URL 需以 http:// 或 https:// 开头');
		$config['indexName'] = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '-', trim($this->keepConfig($config, $prev, 'indexName', 'kodbox-airag'))));
		$config['milvusCollection'] = preg_replace('/[^A-Za-z0-9_]/', '_', trim($this->keepConfig($config, $prev, 'milvusCollection', 'kodbox_airag_chunk')));
		$config['embedDim'] = max(32, min(4096, intval($this->keepConfig($config, $prev, 'embedDim', 1024))));
		$config['chunkSize'] = max(200, min(2000, intval($this->keepConfig($config, $prev, 'chunkSize', 800))));
		$config['chunkOverlap'] = max(0, min(400, intval($this->keepConfig($config, $prev, 'chunkOverlap', 120))));
		$config['pauseDirtyPercent'] = max(10, min(90, floatval($this->keepConfig($config, $prev, 'pauseDirtyPercent', 40))));
		$config['resumeDirtyPercent'] = max(5, min($config['pauseDirtyPercent'] - 5, floatval($this->keepConfig($config, $prev, 'resumeDirtyPercent', 28))));
		$config['milvusBatch'] = max(50, min(500, intval($this->keepConfig($config, $prev, 'milvusBatch', 300))));
		$config['milvusPauseMs'] = max(0, min(5000, intval($this->keepConfig($config, $prev, 'milvusPauseMs', 700))));
		$config['maxFileSizeMB'] = max(1, min(200, intval($this->keepConfig($config, $prev, 'maxFileSizeMB', 50))));
		$config['batchSize'] = max(8, min(80, intval($this->keepConfig($config, $prev, 'batchSize', 40))));
		$config['searchLimit'] = max(20, min(200, intval($this->keepConfig($config, $prev, 'searchLimit', 80))));
		$config['askLimit'] = max(4, min(80, intval($this->keepConfig($config, $prev, 'askLimit', 20))));
		$config['fillTopN'] = max(0, min(20, intval($this->keepConfig($config, $prev, 'fillTopN', 5))));
		$config['fillChunks'] = _get($config, 'fillChunks', _get($prev, 'fillChunks', '1')) == '1' ? 1 : 0;
		$config['keywordEnabled'] = _get($config, 'keywordEnabled', _get($prev, 'keywordEnabled', '1')) == '1' ? 1 : 0;
		$config['semanticEnabled'] = _get($config, 'semanticEnabled', _get($prev, 'semanticEnabled', '1')) == '1' ? 1 : 0;
		$config['imageOcrEnabled'] = _get($config, 'imageOcrEnabled', _get($prev, 'imageOcrEnabled', '0')) == '1' ? 1 : 0;
		$config['ocrModel'] = trim((string)$this->keepConfig($config, $prev, 'ocrModel', ''));
		$config['serviceEnabled'] = _get($config, 'serviceEnabled', '1') == '1' ? 1 : 0;
		$config['hybridEnabled'] = _get($config, 'hybridEnabled', '1') == '1' ? 1 : 0;
		$config['serialPhase'] = _get($config, 'serialPhase', '1') == '1' ? 1 : 0;
		$config['backpressure'] = _get($config, 'backpressure', '1') == '1' ? 1 : 0;
		$config['avoidOverlap'] = _get($config, 'avoidOverlap', '1') == '1' ? 1 : 0;
		$config['prependName'] = _get($config, 'prependName', '1') == '1' ? 1 : 0;
		$config['extensionMode'] = $this->keepConfig($config, $prev, 'extensionMode', 'allow') === 'deny' ? 'deny' : 'allow';
		$config['allowExtensions'] = implode(',', $this->normalizeExtensions($this->keepConfig($config, $prev, 'allowExtensions', $this->defaultExtensions())));
		$config['denyExtensions'] = implode(',', $this->normalizeExtensions($this->keepConfig($config, $prev, 'denyExtensions', '')));
		if (array_key_exists('debugMode', $config)) $config['debugMode'] = $config['debugMode'] == '1' ? 1 : 0;
		else $config['debugMode'] = _get($prev, 'debugMode', 0) == '1' ? 1 : 0;
		unset($config['serviceCheck'], $config['runStatus'], $config['searchTest'], $config['llmCheck'], $config['esCheck'], $config['milvusCheck'], $config['embedCheck'], $config['modelStudio']);
		$this->initTable();
		$this->updateTask($config['serviceEnabled']);
		return $config;
	}

	public function onUninstall() {
		$task = Model('SystemTask')->findByKey('event', $this->pluginName.'Plugin.task');
		if ($task) Model('SystemTask')->remove($task['id'], true);
	}

	public function docSearchMatch($param) {
		if (!$this->isOpen() || !_get($this->getConfig(), 'hybridEnabled', 1)) return $param;
		return $this->searchBefore($param);
	}

	public function searchBefore($param) {
		if (!$this->isOpen() || !_get($this->getConfig(), 'hybridEnabled', 1)) return $param;
		if (!is_array($param) || empty($param['words']) || !in_array('content', (array)_get($param, 'option', array()), true)) return $param;
		if (strlen($param['words']) <= 1 || empty($param['parentID'])) return $param;
		try {
			$result = $this->retriever()->search($param['words'], intval(_get($this->getConfig(), 'searchLimit', 80)));
			$fileIDs = array();
			$this->snippets = array();
			$this->matchedFileIDs = array();
			foreach ((array)$result['hybrid'] as $hit) {
				$fileID = intval($hit['fileID']);
				if (!$fileID) continue;
				$fileIDs[] = $fileID;
				$this->matchedFileIDs[$fileID] = true;
				if (!empty($hit['snippet'])) $this->snippets[$fileID] = $this->sanitizeSnippet($hit['snippet']);
			}
			$param['fileID'] = $fileIDs ? array_values(array_unique($fileIDs)) : array(-1);
			$param['_aiRag'] = 1;
		} catch (Throwable $e) {
			$this->log('search failed: '.$e->getMessage(), 'error');
		}
		return $param;
	}

	public function searchAfter($param, $listData) {
		if (empty($param['_aiRag']) || !is_array($listData) || !isset($listData['fileList'])) return $listData;
		$filtered = array();
		foreach ($listData['fileList'] as $item) {
			$fileID = intval(_get($item, 'fileID', 0));
			if (!$fileID || !isset($this->matchedFileIDs[$fileID])) continue;
			if (isset($this->snippets[$fileID])) $item['searchContentMatch'] = $this->snippets[$fileID];
			$filtered[] = $item;
		}
		$listData['fileList'] = $filtered;
		$listData['folderList'] = array();
		if (!isset($listData['pageInfo']) || !is_array($listData['pageInfo'])) $listData['pageInfo'] = array();
		$listData['pageInfo']['totalNum'] = count($filtered);
		$listData['pageInfo']['pageTotal'] = 1;
		$listData['disableSort'] = 1;
		return $listData;
	}

	public function listPathParse($data) {
		if (!is_array($data) || empty($data['fileList']) || !$this->isOpen()) return $data;
		try { $this->initTable(); } catch (Throwable $e) { return $data; }
		$ids = array();
		foreach ((array)$data['fileList'] as $item) {
			$id = intval(_get($item, 'fileInfo.fileID', _get($item, 'fileID', 0)));
			if ($id) $ids[] = $id;
		}
		$ids = array_values(array_unique($ids));
		if (!$ids) return $data;
		$map = array();
		try {
			$rows = Model($this->stateTable)->where(array('fileID' => array('in', $ids)))->select();
			foreach ((array)$rows as $row) {
				$st = intval(_get($row, 'status', 0));
				$map[intval($row['fileID'])] = array(
					'status' => $st,
					'ready' => $st === self::ST_OK ? 1 : 0,
					'chunks' => intval(_get($row, 'chunkCount', 0)),
					'chunkDone' => intval(_get($row, 'chunkDone', _get($row, 'chunkCount', 0))),
				);
			}
		} catch (Throwable $e) { return $data; }
		foreach ($data['fileList'] as &$item) {
			$id = intval(_get($item, 'fileInfo.fileID', _get($item, 'fileID', 0)));
			if (!$id || empty($map[$id])) continue;
			if (!isset($item['fileInfo']) || !is_array($item['fileInfo'])) $item['fileInfo'] = array();
			$item['fileInfo']['aiRagInfo'] = $map[$id];
			$item['aiRagInfo'] = $map[$id];
		}
		unset($item);
		return $data;
	}

	public function fileContentText($file, $makeNow = false) {
		if (!$this->isOpen()) return false;
		$fileID = intval(_get((array)$file, 'fileID', 0));
		if (!$fileID) return false;
		try {
			$content = $this->elastic()->getContent($fileID);
			return $content !== '' ? $content : false;
		} catch (Throwable $e) {
			return false;
		}
	}

	public function task() {
		return $this->runLocked(false);
	}

	private function runLocked($fillBatch, $retryID = null) {
		$this->releaseSession();
		if (!$this->isOpen()) return 0;
		$self = AiRagIndexLock::acquire('airag-task');
		$global = AiRagIndexLock::acquire('kod-heavy-index');
		if ($self === false || $global === false) {
			AiRagIndexLock::release($self);
			AiRagIndexLock::release($global);
			return -1;
		}
		try {
			if (function_exists('ignore_timeout')) ignore_timeout();
			@ignore_user_abort(true);
			if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
			$this->initTable();
			if ($retryID !== null) {
				AiRagBackpressure::assertReady($this->getConfig());
				return $retryID ? $this->retryFile($retryID, true) : $this->retryFailed();
			}
			$pendingVector = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
			return $this->runTask($fillBatch, $pendingVector);
		} catch (AiRagPressureException $e) {
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => '背压暂停: '.$e->getMessage()));
			return 0;
		} catch (Throwable $e) {
			$this->log('task failed: '.$e->getMessage(), 'error');
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => ''));
			return 0;
		} finally {
			AiRagIndexLock::release($self);
			AiRagIndexLock::release($global);
		}
	}

	private function runTask($fillBatch = false, $pendingVector = 0) {
		$config = $this->getConfig();
		$cursor = $this->readCursorData();
		$pressure = AiRagBackpressure::inspect($config);
		$esBusy = _get($config, 'avoidOverlap', 1) && AiRagIndexLock::busy('elastic-fulltext-task');
		$serial = _get($config, 'serialPhase', 1) == '1';
		$phase = 'extract';
		if ($pendingVector > 0) $phase = 'vector';
		else if (!$serial) $phase = 'both';
		if ($esBusy || !$pressure['ok']) {
			$why = $esBusy ? '等待全文提取任务结束' : ('背压暂停: '.$pressure['reason']);
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => $why));
			$this->log('skip extract: '.$why, 'warning');
			return 0;
		}
		// Shared corpus infrastructure belongs exclusively to elasticFulltext.
		if ($phase !== 'extract') $this->milvus()->ensureInfrastructure();
		$startedAt = time();
		$this->writeCursor(intval(_get($cursor, 'fileID', 0)), array(
			'running' => 1, 'phase' => $phase === 'both' ? 'extract' : $phase,
			'current' => $phase === 'vector' ? '切片向量化' : '扫描共享正文',
			'started' => $startedAt, 'indexed' => 0, 'skipped' => 0, 'failed' => 0,
		));
		$n = 0;
		if ($phase === 'vector') {
			$n = $this->runVectorPhase($config, $fillBatch, $startedAt);
			$this->writeCursor($this->readCursor(), array('running' => 0, 'phase' => 'extract', 'current' => '', 'indexed' => $n));
			return $n;
		}
		if ($esBusy) {
			$this->log('skip extract: elasticFulltext 正在运行', 'warning');
			return 0;
		}
		$n = $this->runExtractPhase($config, $fillBatch, $startedAt, !$serial);
		$pendingVector = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
		if ($serial) {
			$this->writeCursor($this->readCursor(), array('running' => 0, 'phase' => 'vector', 'current' => $pendingVector > 0 ? '等待下一轮向量化' : '', 'indexed' => $n));
			return $n;
		}
		AiRagBackpressure::assertReady($config);
		if ($pendingVector > 0) {
			$this->writeCursor($this->readCursor(), array('running' => 1, 'phase' => 'vector', 'current' => '切换向量化', 'started' => $startedAt));
			$this->milvus()->ensureInfrastructure();
			$n += $this->runVectorPhase($config, $fillBatch, $startedAt);
		}
		$this->writeCursor($this->readCursor(), array('running' => 0, 'phase' => 'extract', 'current' => '', 'indexed' => $n));
		return $n;
	}

	private function runExtractPhase($config, $fillBatch, $startedAt, $vectorSoon) {
		$batch = max(8, min(80, intval(_get($config, 'batchSize', 40))));
		if ($fillBatch) $batch = min(80, max($batch, 30));
		$budget = $fillBatch ? 180 : 50;
		$deadline = microtime(true) + $budget;
		$cursor = $this->readCursor();
		$extensions = $this->configuredExtensions($config);
		$like = $this->indexableWhereSql($extensions);
		$processed = 0; $skipped = 0; $failed = 0; $scanned = 0;
		$maxID = max(1, intval(Model('File')->max('fileID')));
		$page = min(80, max(20, $batch * 4));
		$wrapped = false;
		while ($like !== '' && $processed < $batch && microtime(true) < $deadline) {
			$rows = Model('File')->where('`fileID` > '.intval($cursor).' AND ('.$like.')')->order('fileID asc')->limit($page)->select();
			if (!$rows) {
				if ($wrapped) break;
				$this->cleanupDeleted($batch * 2);
				$wrapped = true;
				$this->writeCursor(0, array('scanHigh' => max(intval(_get($this->readCursorData(), 'scanHigh', 0)), $maxID), 'started' => $startedAt));
				$cursor = 0;
				continue;
			}
			foreach ((array)$rows as $file) {
				if (microtime(true) >= $deadline) break;
				AiRagBackpressure::assertReady($config);
				$cursor = max($cursor, intval($file['fileID']));
				$scanned++;
				$ext = strtolower(get_path_ext(_get($file, 'name', '')));
				if (!$ext || !in_array($ext, $extensions, true)) continue;
				$this->writeCursor($cursor, array(
					'running' => 1, 'phase' => 'extract', 'current' => (string)_get($file, 'name', ''),
					'started' => $startedAt, 'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $scanned,
				));
				$result = $this->extractFile($file, $ext, $config);
				if ($result === 'ok') $processed++;
				else if ($result === 'skip') $skipped++;
				else if ($result === 'fail') $failed++;
				if ($processed >= $batch || microtime(true) >= $deadline) break;
			}
			$this->writeCursor($cursor, array('started' => $startedAt));
			if (count((array)$rows) < $page) {
				if ($wrapped) break;
				$this->cleanupDeleted($batch * 2);
				$wrapped = true;
				$this->writeCursor(0, array('scanHigh' => max(intval(_get($this->readCursorData(), 'scanHigh', 0)), $maxID), 'started' => $startedAt));
				$cursor = 0;
			}
		}
		$this->log('extract indexed='.$processed.' skipped='.$skipped.' failed='.$failed.' scanned='.$scanned.' cursor='.$cursor.($vectorSoon ? ' next=vector-same-run' : ' next=vector'));
		return $processed;
	}

	private function runVectorPhase($config, $fillBatch, $startedAt) {
		$batch = max(8, min(80, intval(_get($config, 'batchSize', 40))));
		if ($fillBatch) $batch = min(80, max($batch, 20));
		$budget = $fillBatch ? 180 : 60;
		$deadline = microtime(true) + $budget;
		$pendingAll = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
		$doneAll = intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count());
		$fileTotal = max(1, $pendingAll + $doneAll);
		$rows = Model($this->stateTable)->where(array('status' => self::ST_ES))->order('indexTime asc')->limit($batch)->select();
		$processed = 0; $failed = 0;
		foreach ((array)$rows as $state) {
			if (microtime(true) >= $deadline) break;
			AiRagBackpressure::assertReady($config);
			$file = Model('File')->where(array('fileID' => intval($state['fileID'])))->find();
			if (!$file) {
				$this->dropFile(intval($state['fileID']));
				continue;
			}
			$doneAll = intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count());
			$pendingAll = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
			$fileTotal = max($fileTotal, $doneAll + $pendingAll);
			$fileNo = $doneAll + 1;
			$this->writeCursor($this->readCursor(), array(
				'running' => 1, 'phase' => 'vector', 'current' => (string)_get($file, 'name', ''),
				'started' => $startedAt, 'indexed' => $processed, 'failed' => $failed,
				'fileNo' => $fileNo, 'fileTotal' => $fileTotal, 'chunkDone' => 0, 'chunkTotal' => 0,
			));
			$result = $this->vectorizeFile($file, $config, $startedAt, $fileNo, $fileTotal);
			if ($result === 'ok') $processed++;
			else $failed++;
		}
		$this->log('vector indexed='.$processed.' failed='.$failed);
		return $processed;
	}

	private function extractFile($file, $ext, $config) {
		$fileID = intval(_get($file, 'fileID', 0));
		$modifyTime = intval(_get($file, 'modifyTime', 0));
		if (!$fileID) return false;
		$state = Model($this->stateTable)->where(array('fileID' => $fileID))->find();
		if ($state && _get($state, 'error', '') === '已禁用') return false;
		if ($state && intval($state['modifyTime']) >= $modifyTime && in_array(intval($state['status']), array(self::ST_ES, self::ST_SKIP), true)) return false;
		$maxBytes = intval(_get($config, 'maxFileSizeMB', 30)) * 1024 * 1024;
		if (intval(_get($file, 'size', 0)) <= 0) {
			$this->saveState($file, self::ST_SKIP, 0, '', '空文件');
			return 'skip';
		}
		if (intval(_get($file, 'size', 0)) >= $maxBytes) {
			$this->saveState($file, self::ST_SKIP, 0, '', '超过大小限制');
			$this->log('skip '.$this->fileLabel($file).' 超过大小限制', 'warning');
			return 'skip';
		}
		try {
			$doc = $this->elastic($config)->getDocument($fileID);
			if (!KodboxCorpusShare::isFresh($doc, $modifyTime)) {
				$this->saveState($file, self::ST_WAIT, 0, '', '等待 elasticFulltext 提取最新正文');
				return 'wait';
			}
			$extracted = AiRagTextNormalizer::clean($doc['content']);
			$file = $this->attachSourceMeta($file);
			if ($state && intval($state['status']) === self::ST_OK && (string)_get($state,'contentHash','') === $this->vectorStateHash($extracted, $file, $config, $this->embed($config)->fingerprint())) return false;
			$origin = 'elasticFulltext';
			$hash = sha1($extracted);
			$this->saveState($file, self::ST_ES, 0, $hash, '');
			$this->log('es '.$this->fileLabel($file).' chars='.strlen($extracted).' via='.$origin);
			return 'ok';
		} catch (Throwable $e) {
			$this->saveState($file, self::ST_FAIL, 0, '', $this->shortError($e->getMessage()));
			$this->log('extract fail '.$this->fileLabel($file).' '.$e->getMessage(), 'error');
			return 'fail';
		}
	}

	private function vectorizeFile($file, $config, $startedAt = 0, $fileNo = 1, $fileTotal = 1) {
		$file = $this->attachSourceMeta($file);
		$fileID = intval($file['fileID']);
		$name = (string)_get($file, 'name', '');
		try {
			$doc = $this->elastic($config)->getDocument($fileID);
			if (!KodboxCorpusShare::isFresh($doc, intval(_get($file, 'modifyTime', 0)))) {
				$this->saveState($file, self::ST_WAIT, 0, '', '等待 elasticFulltext 提取最新正文');
				return 'wait';
			}
			$text = $doc['content'];
			$text = AiRagTextNormalizer::clean($text);
			if ($text === '') throw new Exception('ES 中没有正文');
			$prefix = _get($config, 'prependName', 1) == '1' ? $name : '';
			$chunks = AiRagTextChunker::split($text, intval(_get($config, 'chunkSize', 800)), intval(_get($config, 'chunkOverlap', 120)), $prefix);
			$checkpointPath = rtrim(TEMP_PATH, '/\\').'/airag-vector-'.$fileID.'.json';
			$embed = $this->embed($config);
			$model = $embed->fingerprint();
			$stateHash = $this->vectorStateHash($text, $file, $config, $model);
			// Server-confirmed rows are the only source of truth for resume.
			$existing = $this->milvus()->hashesByFile($fileID);
			$plan = AiRagMilvusStore::diffChunks($chunks, $existing, $model);
			$refresh = array();
			foreach ($plan['reuse'] as $index) {
				$old = (array)$existing[$index];
				if (intval(_get($old,'source_id',0)) !== intval(_get($file,'sourceID',0)) || intval(_get($old,'parent_id',0)) !== intval(_get($file,'parentID',0)) || intval(_get($old,'modify_time',0)) !== intval(_get($file,'modifyTime',0)) || (string)_get($old,'name','') !== mb_strcut($name,0,512,'UTF-8') || (string)_get($old,'ext','') !== (string)_get($file,'ext','')) $refresh[] = $index;
			}
			if ($refresh) $this->milvus()->refreshMetadata($fileID, $refresh, $file);
			if (!$chunks) {
				if ($existing) $this->milvus()->deleteFile($fileID);
				$this->saveState($file, self::ST_OK, 0, $stateHash, '无切片');
				@unlink($checkpointPath);
				return 'ok';
			}
			$work = $plan['work'];
			$total = count($chunks);
			$reused = count($plan['reuse']);
			$this->writeCursor($this->readCursor(), array(
				'running' => 1, 'phase' => 'vector', 'current' => $name, 'started' => $startedAt ?: time(),
				'fileNo' => $fileNo, 'fileTotal' => $fileTotal, 'chunkDone' => $reused, 'chunkTotal' => $total,
			));
			if (!$work) {
				if ($plan['stale']) $this->milvus()->deleteChunks($fileID, $plan['stale']);
				$this->saveState($file, self::ST_OK, $total, $stateHash, '');
				@unlink($checkpointPath);
				$this->log('vector '.$this->fileLabel($file).' chunks='.$total.' reuse='.$reused.' embed=0');
				return 'ok';
			}
			$rows = array();
			$batchTexts = array();
			$batchMeta = array();
			$done = $reused;
			$self = $this;
			$flush = function() use (&$rows, &$batchTexts, &$batchMeta, $embed, &$done, $total, $name, $startedAt, $fileNo, $fileTotal, $self, $config) {
				if (!$batchTexts) return;
				AiRagBackpressure::assertReady($config);
				$vectors = $embed->embed($batchTexts, 60);
				if (count($vectors) !== count($batchTexts)) throw new Exception('Embedding 返回数量不匹配');
				foreach ($vectors as $i => $vector) {
					$rows[] = AiRagMilvusStore::row($batchMeta[$i], $vector);
				}
				$limit = max(50, min(500, intval(_get($config, 'milvusBatch', 300))));
				if (count($rows) >= $limit) {
					$self->milvus()->upsertChunks(array_slice($rows, 0, $limit), $limit, intval(_get($config, 'milvusPauseMs', 700)));
					$rows = array_slice($rows, $limit);
					$done += $limit;
				}
				$self->writeCursor($self->readCursor(), array(
					'running' => 1, 'phase' => 'vector', 'current' => $name, 'started' => $startedAt ?: time(),
					'fileNo' => $fileNo, 'fileTotal' => $fileTotal, 'chunkDone' => $done, 'chunkTotal' => $total,
				));
				$batchTexts = array();
				$batchMeta = array();
			};
			foreach ($work as $chunk) {
				$batchTexts[] = $chunk['text'];
				$batchMeta[] = array(
					'fileID' => $fileID,
					'index' => $chunk['index'],
					'text' => $chunk['text'],
					'name' => $name,
					'hash' => $chunk['hash'],
					'ext' => (string)_get($file, 'ext', ''),
					'sourceID' => intval(_get($file, 'sourceID', 0)),
					'parentID' => intval(_get($file, 'parentID', 0)),
					'modifyTime' => intval(_get($file, 'modifyTime', 0)),
				);
				if (count($batchTexts) >= 8) $flush();
			}
			$flush();
			$this->milvus()->upsertChunks($rows, intval(_get($config, 'milvusBatch', 300)), intval(_get($config, 'milvusPauseMs', 700)));
			if ($plan['stale']) $this->milvus()->deleteChunks($fileID, $plan['stale']);
			$this->saveState($file, self::ST_OK, $total, $stateHash, '');
			@unlink($checkpointPath);
			$this->log('vector '.$this->fileLabel($file).' chunks='.$total.' reuse='.$reused.' embed='.($total - $reused).' delete='.count($plan['stale']));
			return 'ok';
		} catch (AiRagPressureException $e) {
			throw $e; // 保持 ST_ES，下一轮继续。
		} catch (Throwable $e) {
			$this->saveState($file, self::ST_FAIL, 0, '', $this->shortError($e->getMessage()));
			$this->log('vector fail '.$this->fileLabel($file).' '.$e->getMessage(), 'error');
			return 'fail';
		}
	}

	private function vectorStateHash($text, $file, $config, $model) {
		return sha1($text."\0".$model.json_encode(array(
			_get($config,'chunkSize',800), _get($config,'chunkOverlap',120), _get($config,'prependName',1),
			_get($file,'name',''), _get($file,'sourceID',0), _get($file,'parentID',0), _get($file,'modifyTime',0), _get($file,'ext',''),
		)));
	}

	private function attachSourceMeta($file, $state = array()) {
		$file = is_array($file) ? $file : array();
		$fileID = intval(_get($file, 'fileID', 0));
		if ($fileID && (string)_get($file, 'ext', '') === '') $file['ext'] = strtolower((string)_get($file, 'fileType', function_exists('get_path_ext') ? get_path_ext(_get($file, 'name', '')) : ''));
		$file['sourceID'] = intval(_get($file, 'sourceID', _get($state, 'sourceID', 0)));
		$file['parentID'] = intval(_get($file, 'parentID', 0));
		if ($fileID && function_exists('Model') && (!$file['sourceID'] || !$file['parentID'])) {
			try {
				$src = Model('Source')->where(array('fileID' => $fileID))->find();
				if (is_array($src) && $src) {
					if (!$file['sourceID']) $file['sourceID'] = intval(_get($src, 'sourceID', 0));
					if (!$file['parentID']) $file['parentID'] = intval(_get($src, 'parentID', 0));
				}
			} catch (Throwable $e) {}
		}
		return $file;
	}

	private function retryFile($fileID, $vectorToo = true, $keepRunning = false) {
		AiRagBackpressure::assertReady($this->getConfig());
		$this->initTable();
		$file = Model('File')->where(array('fileID' => intval($fileID)))->find();
		if (!$file) {
			$this->dropFile(intval($fileID));
			return false;
		}
		$ext = strtolower(get_path_ext(_get($file, 'name', '')));
		$config = $this->getConfig();
		$this->writeCursor($this->readCursor(), array(
			'running' => 1, 'phase' => 'extract', 'current' => (string)_get($file, 'name', ''),
			'started' => time(), 'fileNo' => 1, 'fileTotal' => 1, 'chunkDone' => 0, 'chunkTotal' => 0,
		));
		$result = $this->extractFile($file, $ext, $config);
		if ($result === false) $result = 'ok';
		if ($result === 'ok' && $vectorToo && _get($config, 'serialPhase', 1) != '1') {
			AiRagBackpressure::assertReady($config);
			$this->milvus()->ensureInfrastructure();
			$result = $this->vectorizeFile($file, $config, time(), 1, 1);
		}
		if (!$keepRunning) $this->writeCursor($this->readCursor(), array('running' => 0, 'current' => '', 'chunkDone' => 0, 'chunkTotal' => 0));
		return $result;
	}

	private function retryFailed() {
		$this->initTable();
		$rows = Model($this->stateTable)->where(array('status' => self::ST_FAIL))->order('indexTime asc')->limit(30)->select();
		$total = count((array)$rows);
		$no = 0;
		foreach ((array)$rows as $state) {
			$no++;
			$this->writeCursor($this->readCursor(), array(
				'running' => 1, 'phase' => 'extract', 'current' => '重试 '.$no.'/'.$total,
				'started' => time(), 'fileNo' => $no, 'fileTotal' => $total,
			));
			$this->retryFile(intval($state['fileID']), true, true);
		}
		$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => '', 'chunkDone' => 0, 'chunkTotal' => 0));
	}

	private function shortError($message) {
		$e = (string)$message;
		if (stripos($e, 'customXml') !== false || stripos($e, 'tika') !== false || stripos($e, 'parse_exception') !== false) {
			return 'Office 文档含未声明 customXml，已可清洗后重试';
		}
		if (stripos($e, '超过大小') !== false) return '超过大小限制';
		if (stripos($e, '未能提取') !== false) return '未能提取到正文';
		if (stripos($e, 'ES 中没有正文') !== false) return 'ES 中没有正文';
		$e = preg_replace('/\s+/', ' ', $e);
		return mb_substr($e, 0, 180);
	}

	private function saveState($file, $status, $chunks, $hash, $error) {
		$fileID = intval($file['fileID']);
		$data = array(
			'sourceID' => intval(_get($file, 'sourceID', 0)),
			'modifyTime' => intval(_get($file, 'modifyTime', 0)),
			'status' => intval($status),
			'chunkCount' => intval($chunks),
			'contentHash' => (string)$hash,
			'error' => $error,
			'indexTime' => time(),
		);
		$exists = Model($this->stateTable)->where(array('fileID' => $fileID))->find();
		if ($exists) return Model($this->stateTable)->where(array('fileID' => $fileID))->save($data);
		$data['fileID'] = $fileID;
		Model($this->stateTable)->setDataAuto(false);
		return Model($this->stateTable)->add($data);
	}

	private function cleanupDeleted($limit) {
		$states = Model($this->stateTable)->order('indexTime asc')->limit(max(1, intval($limit)))->select();
		foreach ((array)$states as $state) {
			$exists = Model('File')->where(array('fileID' => intval($state['fileID'])))->count();
			if ($exists) {
				Model($this->stateTable)->where(array('fileID' => intval($state['fileID'])))->save(array('indexTime' => time()));
				continue;
			}
			$this->dropFile(intval($state['fileID']));
		}
	}

	private function dropFile($fileID) {
		@unlink(rtrim(TEMP_PATH, '/\\').'/airag-vector-'.intval($fileID).'.json');
		try { $this->milvus()->deleteFile($fileID); } catch (Throwable $e) {}
		Model($this->stateTable)->where(array('fileID' => $fileID))->delete();
	}

	public function manage() {
		if (_get($_SERVER, 'REQUEST_METHOD', '') !== 'POST') return show_json(LNG('common.illegalRequest'), false);
		if (!KodUser::isRoot()) return show_json(LNG('explorer.noPermissionAction'), false);
		$this->releaseSession();
		$operation = trim(Input::get('operation', 'require'));
		$mutationLock = null;
		if (in_array($operation, array('rebuild', 'reset', 'dropLibrary', 'disableLibrary'), true)) {
			$mutationLock = AiRagIndexLock::acquire('kod-heavy-index');
			if (!$mutationLock) return show_json(array('message' => '已有索引任务在运行，请稍后再试'), false);
		}
		try {
			if ($operation === 'getConfig') {
				return show_json($this->exportConfig());
			}
			if ($operation === 'saveConfig') {
				$patch = $this->decodeJson(_get($this->in, 'config', ''));
				if (!$patch) return show_json(array('message' => '没有可保存的配置'), false);
				$next = $this->onSetConfig(array_merge($this->getConfig(), $patch));
				if (is_array($next)) $this->setConfig($next);
				return show_json(array('message' => '已保存', 'config' => $this->exportConfig()));
			}
			if ($operation === 'listLibrary') {
				return show_json($this->libraryList());
			}
			if ($operation === 'fileFlags') {
				return show_json($this->fileFlags());
			}
			if ($operation === 'fileDetail') {
				return show_json($this->fileDetail(intval(_get($this->in, 'fileID', 0))));
			}
			if ($operation === 'disableLibrary') {
				$id = intval(_get($this->in, 'fileID', 0));
				if (!$id) return show_json(array('message' => '缺少 fileID'), false);
				$state = Model($this->stateTable)->where(array('fileID' => $id))->find();
				if (!$state) return show_json(array('message' => '文件不在资源库'), false);
				Model($this->stateTable)->where(array('fileID' => $id))->save(array(
					'status' => self::ST_SKIP,
					'error' => '已禁用',
					'indexTime' => time(),
				));
				return show_json(array('message' => '已禁用，不再参与检索与提问'));
			}
			if ($operation === 'dropLibrary') {
				$id = intval(_get($this->in, 'fileID', 0));
				if (!$id) return show_json(array('message' => '缺少 fileID'), false);
				$this->dropFile($id);
				return show_json(array('message' => '已从资源库移除'));
			}
			if ($operation === 'retryLibrary') {
				$id = intval(_get($this->in, 'fileID', 0));
				if ($this->taskBusy()) return show_json(array('message' => '已有任务在运行，请稍后再试'), false);
				$this->replyAndContinue($id ? '开始重试该文件' : '开始重试失败文件');
				$this->runLocked(false, $id);
				return;
			}
			if ($operation === 'testEs') {
				$info = $this->elastic($this->liveConfig())->info();
				return show_json(array('message' => 'Elasticsearch '._get(_get($info, 'version', array()), 'number', '').' 连接正常'));
			}
			if ($operation === 'testMilvus') {
				$this->milvus($this->liveConfig())->info();
				return show_json(array('message' => 'Milvus 连接正常'));
			}
			if ($operation === 'testEmbed') {
				$info = $this->embed($this->liveConfig())->ping();
				$mode = $info['mode'] === 'local-hash' ? '本地哈希向量（测试用）' : 'Embedding API';
				return show_json(array('message' => $mode.' dim='.$info['dim']));
			}
			if ($operation === 'testLlm') {
				$info = $this->llm($this->liveConfig())->ping();
				return show_json(array('message' => $info['model'].' 可用：'.$info['reply']));
			}
			if ($operation === 'fetchModels') {
				$list = AiRagModelHub::fetch((string)_get($this->in, 'url', ''), (string)_get($this->in, 'apiKey', ''));
				return show_json(array('message' => '获取到 '.count($list).' 个模型', 'models' => $list));
			}
			if ($operation === 'testModel') {
				$msg = AiRagModelHub::test((string)_get($this->in, 'url', ''), (string)_get($this->in, 'apiKey', ''), (string)_get($this->in, 'modelId', ''), (string)_get($this->in, 'modelType', 'chat'));
				return show_json(array('message' => $msg));
			}
			if ($operation === 'searchTest') {
				$words = trim((string)_get($this->in, 'words', ''));
				if ($words === '') return show_json(array('message' => '请输入检索词'), false);
				$mode = trim((string)_get($this->in, 'mode', 'hybrid'));
				$esOn = $mode !== 'vector';
				$vecOn = $mode !== 'keyword';
				$data = $this->retriever()->search($words, 12, $esOn, $vecOn, null, array(
					'ext' => trim((string)_get($this->in, 'ext', '')),
					'sourceID' => intval(_get($this->in, 'sourceID', 0)),
					'parentID' => intval(_get($this->in, 'parentID', 0)),
				));
				return show_json(array(
					'message' => 'hybrid='.count($data['hybrid']).' es='.count($data['es']).' vector='.count($data['vector']),
					'query' => $data['query'],
					'mode' => $mode,
					'keywordHeavy' => $data['keywordHeavy'],
					'hybrid' => array_slice($data['hybrid'], 0, 12),
					'es' => array_slice($data['es'], 0, 8),
					'vector' => array_slice($data['vector'], 0, 8),
				));
			}
			if ($operation === 'run') {
				if ($this->taskBusy()) return show_json(array('message' => '已有索引任务在运行，已跳过重入'));
				$this->replyAndContinue('已开始处理；同一时间只会跑一个阶段');
				$this->runLocked(true);
				return;
			}
			if ($operation === 'rebuild') {
				AiRagBackpressure::assertReady($this->getConfig());
				$this->initTable();
				/* Shared corpus is never reset by aiRag. */
				$this->milvus()->rebuild();
				Model($this->stateTable)->where(array('fileID' => array('gt', 0)))->delete();
				foreach (glob(rtrim(TEMP_PATH, '/\\').'/airag-vector-*.json') as $checkpoint) @unlink($checkpoint);
				$this->writeCursor(0, array('running' => 1, 'phase' => 'extract', 'current' => '准备中', 'indexed' => 0, 'started' => time(), 'scanHigh' => 0, 'vecBase' => 0));
				$this->replyAndContinue('索引已重建，正在扫描共享正文并准备向量任务');
				AiRagIndexLock::release($mutationLock); $mutationLock = null;
				$this->runLocked(true);
				return;
			}
			if ($operation === 'reset') {
				AiRagBackpressure::assertReady($this->getConfig());
				$this->initTable();
				$this->milvus()->rebuild();
				Model($this->stateTable)->where(array('fileID' => array('gt', 0)))->delete();
				foreach (glob(rtrim(TEMP_PATH, '/\\').'/airag-vector-*.json') as $checkpoint) @unlink($checkpoint);
				$this->writeCursor(0, array('running' => 0, 'phase' => 'extract', 'current' => '', 'indexed' => 0, 'started' => 0, 'scanHigh' => 0, 'vecBase' => 0));
				return show_json(array('message' => '资源库已重置，文件需重新切片入库'));
			}
			return show_json('Unknown operation', false);
		} catch (Throwable $e) {
			$this->log('manage '.$operation.' failed: '.$e->getMessage(), 'error');
			return show_json(array('message' => $e->getMessage()), false);
		} finally { AiRagIndexLock::release($mutationLock); }
	}

	public function status() {
		if (!KodUser::isRoot()) return show_json(LNG('explorer.noPermissionAction'), false);
		$this->releaseSession();
		$fast = intval(_get($_GET, 'fast', 0)) === 1;
		$cursor = $this->readCursorData();
		$scan = $this->scanProgress($cursor);
		$html = $this->statusHtml($fast, $scan);
		$vecDone = intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count());
		$vecPend = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
		$waiting = intval(Model($this->stateTable)->where(array('status' => self::ST_WAIT))->count());
		$failed = intval(Model($this->stateTable)->where(array('status' => self::ST_FAIL))->count());
		$libTotal = intval(Model($this->stateTable)->count());
		$diskTotal = intval($scan['targetTotal']);
		$scanDone = intval($scan['scanDone']);
		$scanRemain = intval($scan['scanRemain']);
		$scanHigh = intval($scan['scanHigh']);
		$fileTotal = max(intval(_get($cursor, 'fileTotal', 0)), $vecDone + $vecPend, $libTotal);
		$fileNo = intval(_get($cursor, 'fileNo', 0));
		$running = intval(_get($cursor, 'running', 0));
		$lastRun = intval(_get($cursor, 'time', 0));
		$lockBusy = AiRagIndexLock::busy('airag-task');
		if ($lockBusy) $running = 1;
		elseif ($running && $lastRun && time() - $lastRun > 900) $running = 0;
		$phase = (string)_get($cursor, 'phase', '');
		$current = (string)_get($cursor, 'current', '');
		$pressure = AiRagBackpressure::inspect($this->getConfig());
		if (!$running && strpos($current, '背压暂停:') === 0) {
			$current = empty($pressure['ok']) ? '背压暂停: '.$pressure['reason'] : '';
		}
		if ($running && $phase === 'vector' && !$fileNo) $fileNo = $vecDone + 1;
		$wait = $current;
		if (!$running && $current === '') {
			if ($scanRemain > 0) $wait = '空闲：还剩 '.$scanRemain.' 个可索引文档未检查（网盘共 '.$scan['filesTotal'].' 个文件）。自动任务每分钟一轮，也可点「手动更新」。';
			elseif ($vecPend > 0) $wait = '空闲：还剩 '.$vecPend.' 个文档待向量化。自动任务每分钟一轮，也可点「手动更新」。';
			elseif ($waiting > 0) $wait = '等待 elasticFulltext 提供最新正文：'.$waiting.' 个文件。请检查全文插件的启用状态、扩展名、大小限制及失败记录。';
			elseif ($failed > 0) $wait = '正文提取已完成，还有 '.$failed.' 个失败文件，可点「重试失败」。';
			else $wait = '文件扫描与向量化均已完成';
		}
		$chunkCurDone = intval(_get($cursor, 'chunkDone', 0));
		$chunkCurTotal = intval(_get($cursor, 'chunkTotal', 0));
		$chunkFrac = ($running && $phase === 'vector' && $chunkCurTotal > 0)
			? min(0.999, $chunkCurDone / $chunkCurTotal) : 0.0;
		$vecBase = max($vecDone + $vecPend + $waiting + $failed, 1);
		$scanPct = $diskTotal ? (int)min(100, floor(100 * $scanDone / $diskTotal)) : 100;
		$vecPct = (int)min(100, floor(100 * ($vecDone + $chunkFrac) / $vecBase));
		$scanW = 1.0;
		$vecW = 3.0;
		$unitsDone = $scanW * $scanDone + $vecW * ($vecDone + $chunkFrac);
		$unitsTotal = $scanW * max($diskTotal, 1) + $vecW * $vecBase;
		$overall = $unitsTotal > 0 ? (int)round(100 * $unitsDone / $unitsTotal) : 0;
		$overall = max(0, min(100, $overall));
		if ($running || $scanRemain > 0 || $vecPend > 0 || $waiting > 0 || $failed > 0) $overall = min(99, $overall);
		if (!$running && $scanRemain <= 0 && $vecPend <= 0 && !$waiting && !$failed) $overall = 100;
		$chunkOk = 0;
		try {
			$sum = Model($this->stateTable)->where(array('status' => self::ST_OK))->field('SUM(chunkCount) as n')->find();
			$chunkOk = intval(_get($sum, 'n', 0));
		} catch (Throwable $e) { $chunkOk = 0; }
		$chunkAvg = $vecDone > 0 ? max(1, (int)round($chunkOk / max(1, $vecDone))) : max(1, $chunkCurTotal);
		$otherPend = max(0, $vecPend - ($running && $phase === 'vector' ? 1 : 0));
		$chunkRemain = max(0, $chunkCurTotal - $chunkCurDone) + $otherPend * $chunkAvg;
		$chunkAllDone = $chunkOk + ($running && $phase === 'vector' ? $chunkCurDone : 0);
		$chunkAllEst = $chunkAllDone + $chunkRemain;
		$needRun = (!$running && $this->isOpen() && ($scanRemain > 0 || $vecPend > 0)) ? 1 : 0;
		if (!$fast) {
			$event = $this->pluginName.'Plugin.task';
			$task = Model('SystemTask')->findByKey('event', $event);
			if ($this->isOpen() && (!$task || !_get($task, 'enable', 0))) $this->updateTask(1);
		}
		return show_json(array(
			'html' => $html,
			'running' => $running,
			'current' => $current,
			'phase' => $phase,
			'needRun' => $needRun,
			'taskOn' => $this->isOpen() ? 1 : 0,
			'indexed' => intval(_get($cursor, 'indexed', 0)),
			'progress' => array(
				'running' => $running,
				'phase' => $phase,
				'current' => $current,
				'wait' => $wait,
				'needRun' => $needRun,
				'taskOn' => $this->isOpen() ? 1 : 0,
				'indexed' => intval(_get($cursor, 'indexed', 0)),
				'started' => intval(_get($cursor, 'started', 0)),
				'chunkDone' => intval(_get($cursor, 'chunkDone', 0)),
				'chunkTotal' => intval(_get($cursor, 'chunkTotal', 0)),
				'chunkAllDone' => $chunkAllDone,
				'chunkAllEst' => $chunkAllEst,
				'chunkRemain' => $chunkRemain,
				'chunkAvg' => $chunkAvg,
				'fileNo' => $fileNo,
				'fileTotal' => $fileTotal,
				'vecDone' => $vecDone,
				'vecPend' => $vecPend,
				'waiting' => $waiting,
				'libTotal' => $libTotal,
				'failed' => $failed,
				'diskTotal' => $diskTotal,
				'filesTotal' => intval($scan['filesTotal']),
				'targetTotal' => intval($scan['targetTotal']),
				'scanDone' => $scanDone,
				'scanRemain' => $scanRemain,
				'scanHigh' => $scanHigh,
				'scanPct' => $scanPct,
				'vecPct' => $vecPct,
				'overall' => $overall,
			),
		));
	}

	private function statusHtml($fast = false, $scan = null) {
		$this->initTable();
		if (!is_array($scan)) $scan = $this->scanProgress();
		$esOk = false; $mvOk = false; $esVer = '-'; $esDocs = 0; $error = '';
		if (!$fast) {
			try {
				$info = $this->elastic()->info(3);
				$esOk = true;
				$esVer = _get(_get($info, 'version', array()), 'number', '-');
				$esDocs = $this->elastic()->count(3);
			} catch (Throwable $e) { $error .= 'ES: '.$e->getMessage().' '; }
			try { $this->milvus()->info(3); $mvOk = true; } catch (Throwable $e) { $error .= 'Milvus: '.$e->getMessage(); }
		} else {
			$esOk = true; $mvOk = true; $esDocs = '-';
		}
		$esCount = intval(Model($this->stateTable)->where(array('status' => array('in', array(self::ST_ES, self::ST_OK))))->count());
		$vecCount = intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count());
		$pending = intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count());
		$failed = intval(Model($this->stateTable)->where(array('status' => self::ST_FAIL))->count());
		$skipped = intval(Model($this->stateTable)->where(array('status' => self::ST_SKIP))->count());
		$cursorData = $this->readCursorData();
		$lastRun = intval(_get($cursorData, 'time', 0));
		$running = intval(_get($cursorData, 'running', 0));
		$current = trim((string)_get($cursorData, 'current', ''));
		$phase = (string)_get($cursorData, 'phase', '');
		if ($running && $lastRun && time() - $lastRun > 900 && !AiRagIndexLock::busy('airag-task')) {
			$running = 0;
			$current = '';
		}
		$pressure = AiRagBackpressure::inspect($this->getConfig());
		$warn = $this->docSearchWarn();
		$progress = '';
		if ($running) {
			$progress = '<div class="airag-progress"><div class="airag-progress-head"><span><span class="airag-dot is-loading"></span>正在'.($phase === 'vector' ? '向量化' : '扫描').'</span></div>'
				.($current ? '<div class="airag-progress-name" title="'.$this->escape($current).'">'.$this->escape($current).'</div>' : '')
				.'</div>';
		}
		$svc = $fast ? '' : '<div class="airag-es"><span style="color:'.($esOk ? '#20a53a' : '#d9822b').'">● ES '.($esOk ? $this->escape($esVer) : '异常').'</span>　<span style="color:'.($mvOk ? '#20a53a' : '#d9822b').'">● Milvus '.($mvOk ? '正常' : '异常').'</span></div>';
		$pressureParts = array();
		$pressureParts[] = $pressure['dirtyRatio'] === null ? '脏页未采样' : '脏页 '.$pressure['dirtyRatio'].'%';
		if ($pressure['checkpointRatio'] !== null) $pressureParts[] = 'checkpoint '.$pressure['checkpointRatio'].'%';
		if ($pressure['freePages'] !== null) $pressureParts[] = '空闲页 '.intval($pressure['freePages']);
		if ($pressure['waitFreeDelta'] !== null && $pressure['waitFreeDelta'] > 0) $pressureParts[] = '等待新增 '.intval($pressure['waitFreeDelta']);
		if (!$pressure['ok']) $pressureParts[] = $pressure['reason'];
		$pressureText = implode(' / ', $pressureParts);
		$metrics = $this->statusStat('网盘文件', intval(_get($scan, 'filesTotal', 0)))
			.$this->statusStat('可索引', intval(_get($scan, 'targetTotal', 0)))
			.$this->statusStat('已检查', intval(_get($scan, 'scanDone', 0)))
			.$this->statusStat('已登记', $esCount)
			.$this->statusStat('ES正文', $esDocs === '-' ? $esCount : $esDocs)
			.$this->statusStat('已向量', $vecCount)
			.$this->statusStat('待向量', $pending)
			.$this->statusStat('失败', $failed)
			.$this->statusStat('跳过', $skipped)
			.$this->statusStat('背压', $pressureText, true)
			.$this->statusStat('上次', $lastRun ? date('Y-m-d H:i:s', $lastRun) : '尚未运行', true);
		return $progress.$svc
			.($warn ? '<div class="airag-warn">'.$this->escape($warn).'</div>' : '')
			.'<div class="airag-metrics">'.$metrics.'</div>'
			.($error ? '<div class="airag-error">'.$this->escape($error).'</div>' : '');
	}

	private function docSearchWarn() {
		try {
			$list = Model('Plugin')->select();
			foreach ((array)$list as $plugin) {
				$name = _get($plugin, 'name', _get($plugin, 'plugin', ''));
				if ($name !== 'docSearch') continue;
				$status = _get($plugin, 'status', _get($plugin, 'open', 0));
				if ($status) return '检测到官方 docSearch 可能仍在向 io_file_contents 写入 FULLTEXT。AIRAG 已接管内容搜索；建议关闭官方全文入库，避免与 Milvus 同时刷盘。';
			}
		} catch (Throwable $e) {}
		return '';
	}

	private function statusStat($label, $value, $wide = false) {
		$class = 'airag-stat'.($wide ? ' is-wide' : '');
		return '<div class="'.$class.'"><span class="k">'.$this->escape((string)$label).'</span><span class="v">'.$this->escape((string)$value).'</span></div>';
	}

	private function initTable() {
		if (in_array($this->stateTable, Model()->db()->getTables(), true)) return;
		$file = stristr($GLOBALS['config']['database']['DB_TYPE'], 'sqlite') ? 'sqlite.sql' : 'mysql.sql';
		foreach (sqlSplit(file_get_contents($this->pluginPath.'lib/data/'.$file)) as $sql) if (trim($sql)) Model()->db()->execute($sql);
	}

	private function updateTask($enable) {
		$event = $this->pluginName.'Plugin.task';
		$task = Model('SystemTask')->findByKey('event', $event);
		$data = array(
			'name' => LNG('aiRag.meta.name'),
			'type' => 'method',
			'event' => $event,
			'time' => '{"type":"minute","minute":1}',
			'desc' => LNG('aiRag.meta.desc'),
			'enable' => $enable,
			'system' => 1,
		);
		if ($task) return Model('SystemTask')->update($task['id'], array('name' => $data['name'], 'time' => $data['time'], 'enable' => $enable, 'event' => $event));
		return Model('SystemTask')->add($data);
	}

	private function loadLib() {
		static $loaded = false;
		if ($loaded) return;
		$dir = $this->pluginPath.'lib/';
		foreach (array('HttpJson','TextNormalizer','TextChunker','IndexLock','Backpressure','ElasticStore','MilvusStore','EmbedClient','ChatClient','HybridRetriever','WebSearch','ModelHub','CorpusShare') as $name) {
			include_once($dir.$name.'.class.php');
		}
		$loaded = true;
	}

	private function liveConfig() {
		$config = $this->getConfig();
		$keys = array('elasticUrl','indexName','milvusUrl','milvusToken','milvusCollection','embedUrl','embedApiKey','embedModel','embedDim','llmUrl','llmApiKey','llmModel','llmTemperature','llmMaxTokens');
		$in = is_array($this->in) ? $this->in : array();
		foreach ($keys as $key) {
			if (!array_key_exists($key, $in)) continue;
			$config[$key] = is_string($in[$key]) ? trim($in[$key]) : $in[$key];
		}
		if (!empty($config['elasticUrl'])) $config['elasticUrl'] = AiRagHttpJson::fixUrl($config['elasticUrl']);
		if (!empty($config['milvusUrl'])) $config['milvusUrl'] = AiRagHttpJson::fixUrl($config['milvusUrl']);
		$config['embedUrl'] = AiRagEmbedClient::normalizeBaseUrl(_get($config, 'embedUrl', ''));
		$config['embedModel'] = AiRagEmbedClient::normalizeModel(_get($config, 'embedModel', 'BAAI/bge-m3'));
		$config['llmUrl'] = AiRagChatClient::normalizeBaseUrl(_get($config, 'llmUrl', ''));
		return $config;
	}

	private function elastic($config = null) {
		$config = $config !== null ? $config : $this->getConfig();
		$options = KodboxCorpusShare::storeOptions('elasticFulltext', _get($config, 'elasticUrl', 'http://elasticsearch:9200'), 'kodbox-fulltext');
		$options['readOnly'] = true;
		return new AiRagElasticStore($options);
	}
	private function milvus($config = null) { return new AiRagMilvusStore($config !== null ? $config : $this->getConfig()); }
	private function embed($config = null) {
		$config = $config !== null ? $config : $this->getConfig();
		if (trim((string)_get($config, 'embedUrl', '')) === '') {
			$hit = AiRagModelHub::first($this->services(), 'embed');
			if ($hit) {
				$config['embedUrl'] = $hit['url'];
				$config['embedApiKey'] = $hit['apiKey'];
				$config['embedModel'] = $hit['model']['id'];
			}
		}
		return new AiRagEmbedClient($config);
	}
	private function llm($config = null) { return new AiRagChatClient($config !== null ? $config : $this->getConfig()); }
	private function services() {
		$config = $this->getConfig();
		$parsed = AiRagModelHub::parse(_get($config, 'modelServices', ''), AiRagModelHub::defaults($config));
		return $parsed ? $parsed : AiRagModelHub::defaults($config);
	}
	private function retriever($config = null) {
		$config = $config !== null ? $config : $this->getConfig();
		return new AiRagHybridRetriever($this->elastic($config), $this->milvus($config), $this->embed($config));
	}

	public function chat() {
		if (!KodUser::isLogin()) return show_json('请先登录', false);
		$operation = trim((string)_get($this->in, 'operation', 'list'));
		$store = $this->chatStore();
		if ($operation === 'list') {
			$list = array();
			foreach ((array)_get($store, 'items', array()) as $item) {
				$list[] = array(
					'id' => $item['id'],
					'title' => $item['title'],
					'updated' => intval(_get($item, 'updated', 0)),
				);
			}
			return show_json(array(
				'list' => $list,
				'models' => AiRagModelHub::chatModels($this->services()),
				'model' => (string)_get($this->getConfig(), 'llmModel', ''),
			));
		}
		if ($operation === 'get') {
			$item = $this->chatFind($store, (string)_get($this->in, 'id', ''));
			if (!$item) return show_json(array('message' => '对话不存在'), false);
			return show_json(array('item' => $this->repairChatItem($item)));
		}
		if ($operation === 'delete') {
			$id = (string)_get($this->in, 'id', '');
			$store['items'] = array_values(array_filter((array)_get($store, 'items', array()), function($item) use ($id) {
				return _get($item, 'id', '') !== $id;
			}));
			$this->chatSaveStore($store);
			return show_json(array('message' => '已删除'));
		}
		if ($operation === 'flag') {
			$id = (string)_get($this->in, 'id', '');
			$index = intval(_get($this->in, 'index', -1));
			$star = intval(_get($this->in, 'star', 0)) ? 1 : 0;
			$item = $this->chatFind($store, $id);
			if (!$item) return show_json(array('message' => '对话不存在'), false);
			if ($index >= 0 && isset($item['messages'][$index])) {
				$item['messages'][$index]['starred'] = $star;
			} else {
				$item['starred'] = $star;
			}
			$items = array();
			foreach ((array)$store['items'] as $row) {
				if ($row['id'] !== $item['id']) $items[] = $row;
			}
			array_unshift($items, $item);
			$store['items'] = $items;
			$this->chatSaveStore($store);
			return show_json(array('item' => $item, 'message' => $star ? '已收藏' : '已取消收藏'));
		}
		// 资源管理器里的入库标记：普通用户也需要，路径可见性由 IO::info 自行校验
		if ($operation === 'fileFlags') {
			try { return show_json($this->fileFlags()); }
			catch (Throwable $e) { return show_json(array('flags' => array(), 'byPath' => array())); }
		}
		if ($operation === 'source') {
			$fileID = intval(_get($this->in, 'fileID', 0));
			$detail = $this->fileDetail($fileID);
			$item = (array)_get($detail, 'item', array());
			$path = (string)_get($item, 'path', '');
			if ($path !== '') {
				$info = IO::info($path);
				if (!$info) return show_json(array('message' => '没有权限查看该文件'), false);
			}
			$chunkWant = intval(_get($this->in, 'chunk', -1));
			$chunks = (array)_get($item, 'chunks', array());
			$picked = null;
			if ($chunkWant >= 0) {
				foreach ($chunks as $chunk) {
					if (intval(_get($chunk, 'index', -1)) === $chunkWant) { $picked = $chunk; break; }
				}
				if (!$picked) {
					$exact = $this->milvus()->listByFile($fileID, 1, $chunkWant);
					if ($exact) {$picked=$exact[0]; $chunks[]=$picked; usort($chunks,function($a,$b){return $a['index']-$b['index'];});}
				}
				if (!$picked) return show_json(array('message'=>'引用分片已不存在，请重新检索该文件'), false);
			}
			return show_json(array(
				'item' => $item,
				'chunk' => $picked,
				'chunkIndex' => $chunkWant,
				'chunks' => $chunks,
			));
		}
		return show_json('Unknown operation', false);
	}

	public function ask() {
		if (_get($_SERVER, 'REQUEST_METHOD', '') !== 'POST') return show_json(LNG('common.illegalRequest'), false);
		if (!KodUser::isLogin()) return show_json('请先登录', false);
		$this->releaseSession();
		if (!_get($this->getConfig(), 'llmEnabled', 1)) return show_json(array('message' => '未开启对话能力'), false);
		$question = trim((string)_get($this->in, 'question', ''));
		if ($question === '') return show_json(array('message' => '请输入问题'), false);
		$paths = array();
		$rawPaths = _get($this->in, 'paths', '');
		if (is_array($rawPaths)) $paths = $rawPaths;
		elseif ($rawPaths) {
			$decoded = json_decode($rawPaths, true);
			$paths = is_array($decoded) ? $decoded : preg_split('/[\r\n]+/', (string)$rawPaths);
		}
		$one = trim((string)_get($this->in, 'path', ''));
		if ($one !== '') array_unshift($paths, $one);
		$refs = $this->decodeJson(_get($this->in, 'refs', isset($_GET['refs']) ? $_GET['refs'] : ''));
		if (is_array($refs)) {
			foreach ($refs as $ref) {
				$path = is_array($ref) ? _get($ref, 'path', '') : $ref;
				if ($path) $paths[] = $path;
			}
		}
		$paths = array_values(array_unique(array_filter(array_map('trim', $paths))));
		$history = $this->decodeJson(_get($this->in, 'history', array()));
		if (!is_array($history)) $history = array();
		$tools = $this->decodeJson(_get($this->in, 'tools', array()));
		if (!is_array($tools)) $tools = array();
		$disk = array_key_exists('disk', $tools) ? !empty($tools['disk']) : true;
		$web = !empty($tools['web']);
		$mail = !empty($tools['mail']);
		$save = !empty($tools['save']);
		$model = trim((string)_get($this->in, 'model', _get($this->in, 'llmModel', '')));
		$thinking = intval(_get($this->in, 'thinking', 0)) === 1;
		$convId = trim((string)_get($this->in, 'id', ''));
		try {
			$hit = $model !== '' ? AiRagModelHub::find($this->services(), $model, 'chat') : null;
			if (!$hit) $hit = AiRagModelHub::first($this->services(), 'chat');
			if (!$hit) return show_json(array('message' => '没有可用的对话模型，请在模型服务里启用至少一个对话模型'), false);
			$model = $hit['model']['id'];
			$svcName = (string)_get($hit, 'name', _get((array)_get($hit, 'service', array()), 'name', ''));
			if ($svcName === '' && isset($hit['url'])) $svcName = (string)_get($hit['model'], 'provider', '');
			$cfg = $this->getConfig();
			$cfg['llmUrl'] = $hit['url'];
			$cfg['llmApiKey'] = $hit['apiKey'];
			$cfg['llmModel'] = $model;
			$ctx = AiRagModelHub::contextSize(_get($hit['model'], 'context', 0));
			if ($this->isLocalLlm($hit['url']) && $ctx >= 60000) $ctx = 16384;
			$usable = max(1024, $ctx - 256);
			$outCap = min(intval(_get($cfg, 'llmMaxTokens', 1024)), max(256, (int)floor($usable * 0.10)));
			$inputTok = max(800, $usable - $outCap - 192);
			$knowChars = max(600, $inputTok);
			$scope = $this->resolveAskScope($paths);
			$hits = array();
			$context = '';
			$webText = '';
			$notes = array();
			$toolCalls = array();
			$sources = array();
			if ($disk) {
				try {
					$limit = max(4, min(40, intval(_get($this->getConfig(), 'askLimit', 20))));
					$search = $this->retriever()->search($question, $limit, !empty(_get($this->getConfig(), 'keywordEnabled', 1)), !empty(_get($this->getConfig(), 'semanticEnabled', 1)), $scope['restricted'] ? $scope['fileIDs'] : null);
					$hits = array_slice((array)$search['hybrid'], 0, 16);
				} catch (Throwable $e) {
					$this->log('ask retrieve: '.$e->getMessage(), 'warning');
					$notes[] = '网盘检索暂不可用：'.$e->getMessage();
				}
				if ($scope['fileIDs']) {
					$ready = 0;
					try { $ready = intval(Model($this->stateTable)->where(array('fileID' => array('in', $scope['fileIDs']), 'status' => self::ST_OK))->count()); } catch (Throwable $e) {}
					if (!$ready) $notes[] = '引用的文件尚未完成向量化，暂时没有正文可检索';
				}
				$pack = $this->prepareAskKnowledge($hits, $scope['fileIDs'], $knowChars);
				$context = $pack['text'];
				$sources = $pack['sources'];
				$fileSet = array();
				foreach ((array)$sources as $src) {
					$fid = intval(_get($src, 'fileID', 0));
					$fileSet[$fid ? $fid : ('n'._get($src, 'name', ''))] = 1;
				}
				$toolCalls[] = array(
					'name' => '知识库检索',
					'ok' => $context !== '',
					'files' => count($fileSet),
					'chunks' => count($sources),
					'hits' => count($hits),
				);
				if ($context === '') $notes[] = '未检索到已入库文档，本次没有把网盘正文喂给模型';
				// Retrieval counts are already shown in the citation panel; do not repeat them in the answer note.
			}
			if ($web) {
				$webText = AiRagWebSearch::lookup($question);
				$toolCalls[] = array('name' => '联网搜索', 'ok' => $webText !== '', 'count' => $webText !== '' ? 1 : 0);
				if ($webText === '') $notes[] = '联网搜索没有可用摘要';
			}
			$system = '你是企业网盘文档助手。不要编造合同编号、金额或条款。回答必须优先使用 Markdown（标题、列表、表格、加粗）。必须遵守用户本轮提出的篇幅、字数、条数要求。';
			$lengthHint = $this->askLengthHint($question);
			if ($lengthHint !== '') $system .= ' '.$lengthHint;
			if ($mail) $system .= '需要发邮件时只输出邮件草稿（收件人、主题、正文），不要声称已经发出。';
			if ($save) $system .= '若用户要求保存为文件，用 [[SAVE:文件名]]内容[[/SAVE]] 包裹要写入网盘「AI助手」目录的正文。';
			if ($thinking) $system .= '先简要给出思考要点，再给出结论。';
			if ($context !== '') $system .= '必须依据下面「网盘资料」回答。引用资料时在相关句末写 [^n]，n 对应资料编号。资料里没有的内容明确说资料中未找到。';
			else $system .= '当前没有检索到已入库的网盘资料，请明确告知用户「资料库没有命中」，不要编造文件内容。';
			$messages = array(array('role' => 'system', 'content' => $system));
			$historyUse = $history;
			if ($lengthHint !== '' || preg_match('/重新|再总结|再写|改成|换成/u', $question)) {
				$historyUse = array_values(array_filter($history, function($item) {
					$role = _get($item, 'role', '');
					return $role === 'user';
				}));
			}
			foreach (array_slice($historyUse, -8) as $item) {
				$role = _get($item, 'role', '');
				if ($role === 'bot') $role = 'assistant';
				$content = trim((string)_get($item, 'content', ''));
				if (!in_array($role, array('user', 'assistant'), true) || $content === '') continue;
				$messages[] = array('role' => $role, 'content' => mb_substr($content, 0, min(4000, max(200, intval($knowChars / 8)))));
			}
			$user = $question;
			if ($scope['labels']) $user = '当前引用：'.implode('、', $scope['labels'])."\n\n".$user;
			if ($context !== '') $user .= "\n\n网盘资料：\n".$context;
			if ($webText !== '') $user .= "\n\n公开检索：\n".$webText;
			$messages[] = array('role' => 'user', 'content' => $user);
			$llm = $this->llm($cfg);
			$wantStream = intval(_get($this->in, 'stream', 0)) === 1 && !headers_sent();
			$answer = '';
			if ($wantStream) {
				$this->beginSse();
				$this->sseSend('meta', array(
					'tools' => $toolCalls,
					'sources' => $sources,
					'note' => implode('；', $notes),
					'provider' => $svcName,
					'model' => $model,
					'fed' => $context !== '' ? 1 : 0,
					'retrieved' => count($hits),
				));
				$self = $this;
				$pendingStream = array();
				$pendingBytes = 0;
				$lastStreamFlush = microtime(true);
				$streamedAnswer = '';
				$streamedReasoning = '';
				$flushStream = function($force = false) use ($self, &$pendingStream, &$pendingBytes, &$lastStreamFlush) {
					if (!$pendingStream) return;
					if (!$force && $pendingBytes < 72 && microtime(true) - $lastStreamFlush < 0.024) return;
					foreach ($pendingStream as $item) $self->sseSend($item[0], array('text' => $item[1]));
					$pendingStream = array();
					$pendingBytes = 0;
					$lastStreamFlush = microtime(true);
				};
				$queueStream = function($event, $piece) use (&$pendingStream, &$pendingBytes, &$flushStream) {
					if ($piece === '') return;
					$last = count($pendingStream) - 1;
					if ($last >= 0 && $pendingStream[$last][0] === $event) $pendingStream[$last][1] .= $piece;
					else $pendingStream[] = array($event, $piece);
					$pendingBytes += strlen($piece);
					$flushStream(false);
				};
				$answer = $llm->chat($messages, $outCap, $thinking ? 150 : 90, array(
					'model' => $model,
					'thinking' => $thinking,
					'stream' => true,
					'context' => $ctx,
					'onDelta' => function($piece, $think) use ($queueStream, &$streamedAnswer, &$streamedReasoning) {
						if ($piece !== '') {$streamedAnswer .= $piece; $queueStream('delta', $piece);}
						if ($think !== '') {$streamedReasoning .= $think; $queueStream('think', $think);}
					},
				));
				$flushStream(true);
			} else {
				$answer = $llm->chat($messages, $outCap, $thinking ? 150 : 90, array('model' => $model, 'thinking' => $thinking, 'context' => $ctx));
			}
			if (!empty($llm->lastTrimmed)) $notes[] = '资料已按模型上下文 '.$ctx.' 截断后再提问';
			$saved = array();
			if ($save) {
				$pair = $this->applySaves($answer);
				$answer = $pair[0];
				$saved = $pair[1];
				if ($saved) $notes[] = '已保存：'.implode('、', $saved);
			}
			$usage = (array)$llm->lastUsage;
			$elapsedMs = intval($llm->lastElapsedMs);
			$firstMs = intval($llm->lastFirstMs);
			$speed = ($elapsedMs > 0 && intval(_get($usage, 'output', 0)) > 0)
				? round(intval($usage['output']) / ($elapsedMs / 1000), 1)
				: 0;
			$extra = array(
				'tools' => $toolCalls,
				'usage' => $usage,
				'elapsedMs' => $elapsedMs,
				'firstMs' => $firstMs,
				'speed' => $speed,
				'provider' => $svcName,
				'created' => time(),
			);
			$title = mb_substr($question, 0, 24);
			$conv = $this->chatUpsert($convId, $title, $model, $thinking, $tools, $refs ? $refs : array_map(function($path) {
				return array('path' => $path, 'name' => $path, 'type' => 'file');
			}, $paths), $question, $answer, $llm->lastReasoning, $sources, implode('；', $notes), $extra);
			$payload = array(
				'answer' => $answer,
				'reasoning' => $llm->lastReasoning,
				'sources' => $sources,
				'scope' => $scope['labels'],
				'note' => implode('；', $notes),
				'retrieved' => count($hits),
				'fed' => $context !== '' ? 1 : 0,
				'id' => $conv['id'],
				'title' => $conv['title'],
				'model' => $llm->lastModel ?: $model,
				'provider' => $svcName,
				'tools' => $toolCalls,
				'usage' => $usage,
				'elapsedMs' => $elapsedMs,
				'firstMs' => $firstMs,
				'speed' => $speed,
				'created' => time(),
			);
			if ($wantStream) {
				// Content and reasoning have already arrived as ordered SSE deltas.
				// Avoid sending tens or hundreds of KB twice at the end of a long reply.
				$donePayload = $payload;
				unset($donePayload['answer'], $donePayload['reasoning']);
				// A provider may fail mid-stream and then succeed through the JSON fallback.
				// Send the complete field only when it differs from what the browser received.
				if ($streamedAnswer !== $answer && !($streamedAnswer === '' && $answer === $streamedReasoning)) $donePayload['answer'] = $answer;
				if ($streamedReasoning !== $llm->lastReasoning) $donePayload['reasoning'] = $llm->lastReasoning;
				$this->sseSend('done', $donePayload);
				$this->endSse();
				return;
			}
			return show_json($payload);
		} catch (Throwable $e) {
			$this->log('ask failed: '.$e->getMessage(), 'error');
			if (intval(_get($this->in, 'stream', 0)) === 1 && !headers_sent()) {
				try { $this->beginSse(); } catch (Throwable $e2) {}
				$this->sseSend('error', array('message' => $e->getMessage()));
				$this->endSse();
				return;
			}
			if (!empty($GLOBALS['airagSseStarted'])) {
				$this->sseSend('error', array('message' => $e->getMessage()));
				$this->endSse();
				return;
			}
			return show_json(array('message' => $e->getMessage()), false);
		}
	}

	private function beginSse() {
		if (!empty($GLOBALS['airagSseStarted'])) return;
		$GLOBALS['airagSseStarted'] = 1;
		@ignore_user_abort(true);
		@ini_set('output_buffering', 'off');
		@ini_set('zlib.output_compression', '0');
		@ini_set('implicit_flush', '1');
		while (ob_get_level() > 0) { @ob_end_clean(); }
		if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
		header('Content-Type: text/event-stream; charset=utf-8');
		header('Cache-Control: no-cache, no-transform');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no');
		echo ':'.str_repeat(' ', 2048)."\n\n";
		@flush();
	}

	private function sseSend($event, $data) {
		if (empty($GLOBALS['airagSseStarted'])) $this->beginSse();
		echo 'event: '.$event."\n";
		echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
		if (ob_get_level()) @ob_flush();
		@flush();
	}

	private function endSse() {
		echo "event: close\ndata: {}\n\n";
		if (ob_get_level()) @ob_flush();
		@flush();
		exit;
	}

	private function isLocalLlm($url) {
		return (bool)preg_match('#://(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)#i', (string)$url);
	}

	private function askLengthHint($question) {
		$question = trim((string)$question);
		if ($question === '') return '';
		$bits = array();
		if (preg_match_all('/(\d+)\s*(?:个)?(字|词|句|段|条|点)/u', $question, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$n = intval($m[1]);
				if ($n <= 0) continue;
				$unit = $m[2];
				$bits[] = '本轮正文必须约 '.$n.$unit.'（允许 ±20%），不得明显更长或更短，也不得复制上一轮回答';
			}
		}
		if (preg_match('/总结|摘要|概括|简述/u', $question)) {
			$bits[] = '这是总结任务：只按本轮要求重新组织，不要复述上一轮原文';
		}
		return implode('。', array_unique($bits));
	}

	private function resolveAskScope($paths) {
		$fileIDs = array();
		$labels = array();
		foreach ((array)$paths as $path) {
			if ($path === '') continue;
			$info = IO::info($path);
			if (!is_array($info) || !$info) continue;
			$labels[] = (string)_get($info, 'name', $path);
			if (intval(_get($info, 'isFolder', 0))) {
				$level = _get($info, 'parentLevel', '').intval(_get($info, 'sourceID', 0)).',';
				$after = 0;
				do {
					$rows = Model('Source')->where(array('parentLevel'=>array('like',$level.'%'),'isFolder'=>0,'isDelete'=>0,'sourceID'=>array('gt',$after)))->field('fileID,sourceID')->order('sourceID asc')->limit(500)->select();
					foreach ((array)$rows as $row) {
						$after = intval($row['sourceID']);
						$id = intval(_get($row,'fileID',0));
						if ($id) $fileIDs[] = $id;
					}
				} while (count((array)$rows) === 500);

			} else {
				$id = intval(_get($info, 'fileID', 0));
				if ($id) $fileIDs[] = $id;
			}
		}
		return array(
			'fileIDs' => array_values(array_unique($fileIDs)),
			'restricted' => count(array_filter((array)$paths, 'strlen')) > 0,
			'labels' => array_slice(array_values(array_unique($labels)), 0, 8),
		);
	}

	private function prepareAskKnowledge($hits, $fileIDs, $budget = 0) {
		$parts = array();
		$sources = array();
		$used = array();
		$budget = intval($budget);
		if ($budget < 400) $budget = 14000;
		$n = 0;
		$filled = 0;
		$pieceMax = min(1600, max(400, intval($budget / 4)));
		$append = function($name, $text, $fileID, $chunkNo, $meta) use (&$parts, &$sources, &$used, &$n, &$filled, $budget, $pieceMax) {
			$text = trim((string)$text);
			if ($text === '') return;
			$key = intval($fileID).':'.($chunkNo === null ? 'es' : intval($chunkNo));
			if (isset($used[$key])) return;
			if ($n > 0 && $filled >= $budget) return;
			$used[$key] = true;
			$piece = $this->stripChunkTitle(mb_substr($text, 0, $pieceMax), $name);
			$filled += mb_strlen($piece) + 24;
			$n++;
			$parts[] = '[^'.$n.'] '.$name.($chunkNo !== '' && $chunkNo !== null ? ' 分片'.$chunkNo : '')."\n".$piece;
			$sources[] = array(
				'index' => $n,
				'fileID' => intval($fileID),
				'name' => $name,
				'snippet' => $piece,
				'chunk' => $chunkNo === null ? null : intval($chunkNo),
				'chunks' => intval(_get($meta, 'chunkCount', 0)),
				'size' => intval(_get($meta, 'size', 0)),
				'path' => (string)_get($meta, 'path', ''),
			);
		};
		// 引用文件按分片顺序整篇喂入；文件过多时只整篇展开前几个，其余交给下面的检索命中
		$whole = array_slice(array_values(array_filter(array_map('intval', (array)$fileIDs))), 0, 6);
		// 只引用单个文件（总结场景）时整篇可用满预算；引用多个时留出空间给命中的分片
		$wholeBudget = count($whole) > 1 ? intval($budget * 0.6) : $budget;
		$milvusDown = false;
		foreach ($whole as $fileID) {
			if ($filled >= $wholeBudget) break;
			$meta = $this->hydrateState(array('fileID' => $fileID));
			$name = (string)_get($meta, 'name', '资料');
			$rows = array();
			if (!$milvusDown) {
				try { $rows = $this->milvus()->listByFile($fileID, 120); }
				catch (Throwable $e) { $rows = array(); $milvusDown = true; $this->log('ask listByFile: '.$e->getMessage(), 'warning'); }
			}
			if (!$rows) {
				try {
					$full = $this->elastic()->getContent($fileID);
					$config = $this->getConfig();
					$split = AiRagTextChunker::split($full, intval(_get($config, 'chunkSize', 800)), intval(_get($config, 'chunkOverlap', 120)), $name);
					foreach ((array)$split as $i => $chunk) {
						$rows[] = array('index' => intval(_get($chunk, 'index', $i)), 'text' => (string)_get($chunk, 'text', ''));
					}
				} catch (Throwable $e) {}
			}
			foreach ((array)$rows as $row) {
				if ($filled >= $wholeBudget) break;
				$append($name, _get($row, 'text', ''), $fileID, _get($row, 'index', 0), $meta);
			}
		}
		$expanded = array();
		foreach ((array)$hits as $hit) {
			$matches = (array)_get($hit,'chunks',array());
			if (!$matches) {$expanded[]=$hit; continue;}
			foreach ($matches as $match) {
				$item=$hit;
				$item['snippet']=(string)_get($match,'text','');
				$item['chunk']=intval(_get($match,'chunk',0));
				$item['source']['vector']=$match;
				$expanded[]=$item;
			}
		}
		foreach ($expanded as $hit) {
			if ($filled >= $budget) break;
			$fileID = intval(_get($hit, 'fileID', 0));
			$chunkText = trim((string)_get($hit, 'snippet', ''));
			$vec = (array)_get((array)_get($hit, 'source', array()), 'vector', array());
			if ($chunkText === '') $chunkText = trim((string)_get($vec, 'text', ''));
			$chunkNo = $vec ? intval(_get($vec, 'chunk', _get($vec, 'chunk_index', 0))) : null;
			if ($vec) $chunkText = (string)_get($vec, 'text', '');
			if ($chunkText === '' && $fileID) {
				try { $chunkText = mb_substr($this->elastic()->getContent($fileID), 0, 800); } catch (Throwable $e) { $chunkText = ''; }
			}
			if ($chunkText === '') continue;
			$meta = $fileID ? $this->hydrateState(array('fileID' => $fileID)) : array('name' => _get($hit, 'name', '资料'), 'chunkCount' => 0, 'size' => 0, 'path' => '');
			$name = (string)_get($hit, 'name', _get($meta, 'name', 'fileID '.$fileID));
			$append($name, $chunkText, $fileID, $chunkNo, $meta);
		}
		return array('text' => mb_substr(implode("\n\n", $parts), 0, $budget), 'sources' => $sources);
	}

	private function stripChunkTitle($text, $name) {
		$text = trim((string)$text);
		$name = trim((string)$name);
		if ($name === '' || $text === '') return $text;
		$lines = preg_split('/\r\n|\r|\n/', $text, 2);
		if (count($lines) > 1 && trim($lines[0]) === $name) return ltrim($lines[1]);
		return $text;
	}

	private function libraryList() {
		$this->initTable();
		$page = max(1, intval(_get($this->in, 'page', 1)));
		$size = 20;
		$words = trim((string)_get($this->in, 'filterWords', _get($this->in, 'words', '')));
		$status = trim((string)_get($this->in, 'filterStatus', _get($this->in, 'status', '')));
		$extRaw = trim((string)_get($this->in, 'filterExt', _get($this->in, 'ext', '')));
		$exts = $extRaw === '' ? array() : array_values(array_filter(array_map('strtolower', preg_split('/[,;|\s]+/', $extRaw))));
		$sizeKey = trim((string)_get($this->in, 'filterSize', _get($this->in, 'size', '')));
		$timeKey = trim((string)_get($this->in, 'filterTime', _get($this->in, 'time', '')));
		$sourceID = intval(_get($this->in, 'filterSource', _get($this->in, 'sourceID', 0)));
		$statusMap = array('ok' => self::ST_OK, 'done' => self::ST_OK, 'es' => self::ST_ES, 'doing' => self::ST_ES, 'wait' => self::ST_WAIT, 'fail' => self::ST_FAIL, 'skip' => self::ST_SKIP, 'ignore' => self::ST_SKIP);
		$statusWant = isset($statusMap[$status]) ? $statusMap[$status] : null;
		$sizeRange = array('100k'=>array(0,102400),'1m'=>array(102400,1048576),'10m'=>array(1048576,10485760),'100m'=>array(10485760,104857600),'1g'=>array(104857600,1073741824),'over1g'=>array(1073741824,PHP_INT_MAX));
		$timeFrom = 0;
		$days = array('1d'=>1,'7d'=>7,'30d'=>30,'365d'=>365);
		if (isset($days[$timeKey])) $timeFrom = time() - $days[$timeKey] * 86400;
		$rows = Model($this->stateTable)->order('indexTime desc')->select();
		if (!$rows) $rows = array();
		$items = array();
		foreach ((array)$rows as $row) {
			$item = $this->hydrateState($row);
			if ($statusWant !== null && intval($item['status']) !== $statusWant) continue;
			if ($words !== '' && mb_stripos($item['name'].' '.$item['pathDisplay'].' '.$item['path'].' '.$item['fileID'], $words) === false) continue;
			if ($exts && !in_array(strtolower((string)$item['ext']), $exts, true)) continue;
			if ($sizeKey && isset($sizeRange[$sizeKey])) {
				$n = intval($item['size']);
				if ($n < $sizeRange[$sizeKey][0] || $n >= $sizeRange[$sizeKey][1]) continue;
			}
			if ($timeFrom && intval($item['modifyTime']) < $timeFrom) continue;
			if ($sourceID) {
				$parent = ','.trim((string)_get($item, 'parentLevel', ''), ',').',';
				$okFolder = intval($item['sourceID']) === $sourceID || intval(_get($item, 'parentID', 0)) === $sourceID || strpos($parent, ','.$sourceID.',') !== false;
				if (!$okFolder) continue;
			}
			$items[] = $item;
		}
		$filtered = count($items);
		$list = array_slice($items, ($page - 1) * $size, $size);
		$scan = $this->scanProgress();
		$stats = array(
			'total' => intval(Model($this->stateTable)->count()),
			'done' => intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count()),
			'pending' => intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count()),
			'failed' => intval(Model($this->stateTable)->where(array('status' => self::ST_FAIL))->count()),
			'diskTotal' => intval($scan['targetTotal']),
			'filesTotal' => intval($scan['filesTotal']),
			'targetTotal' => intval($scan['targetTotal']),
			'scanDone' => intval($scan['scanDone']),
			'scanRemain' => intval($scan['scanRemain']),
			'scanCursor' => intval($scan['scanCursor']),
			'scanMax' => intval($scan['maxFileID']),
			'filtered' => $filtered,
		);
		return array(
			'list' => $list,
			'total' => $filtered,
			'page' => $page,
			'size' => $size,
			'stats' => $stats,
		);
	}

	private function libraryEmpty($page, $size) {
		$scan = $this->scanProgress();
		return array(
			'list' => array(),
			'total' => 0,
			'page' => $page,
			'size' => $size,
			'stats' => array(
				'total' => intval(Model($this->stateTable)->count()),
				'done' => intval(Model($this->stateTable)->where(array('status' => self::ST_OK))->count()),
				'pending' => intval(Model($this->stateTable)->where(array('status' => self::ST_ES))->count()),
				'failed' => intval(Model($this->stateTable)->where(array('status' => self::ST_FAIL))->count()),
				'diskTotal' => intval($scan['targetTotal']),
				'filesTotal' => intval($scan['filesTotal']),
				'targetTotal' => intval($scan['targetTotal']),
				'scanDone' => intval($scan['scanDone']),
				'scanRemain' => intval($scan['scanRemain']),
				'scanCursor' => intval($scan['scanCursor']),
				'scanMax' => intval($scan['maxFileID']),
				'filtered' => 0,
			),
		);
	}

	private function libraryScopeFileIDs() {
		$extRaw = trim((string)_get($this->in, 'ext', ''));
		$exts = $extRaw === '' ? array() : array_values(array_filter(array_map('strtolower', preg_split('/[,;|\s]+/', $extRaw))));
		$sizeKey = trim((string)_get($this->in, 'size', ''));
		$sizeMin = intval(_get($this->in, 'sizeMin', 0));
		$sizeMax = intval(_get($this->in, 'sizeMax', 0));
		if ($sizeKey && !$sizeMin && !$sizeMax) {
			$map = array(
				'100k' => array(0, 100 * 1024),
				'1m' => array(100 * 1024, 1048576),
				'10m' => array(1048576, 10 * 1048576),
				'100m' => array(10 * 1048576, 100 * 1048576),
				'1g' => array(100 * 1048576, 1073741824),
				'over1g' => array(1073741824, 0),
			);
			if (isset($map[$sizeKey])) {
				$sizeMin = $map[$sizeKey][0];
				$sizeMax = $map[$sizeKey][1];
			}
		}
		$timeKey = trim((string)_get($this->in, 'time', ''));
		$timeFrom = intval(_get($this->in, 'timeFrom', 0));
		$timeTo = intval(_get($this->in, 'timeTo', 0));
		if ($timeKey && !$timeFrom) {
			$days = array('1d' => 1, '7d' => 7, '30d' => 30, '365d' => 365);
			if (isset($days[$timeKey])) $timeFrom = time() - $days[$timeKey] * 86400;
		}
		$sourceID = intval(_get($this->in, 'sourceID', 0));
		$need = $exts || $sizeMin || $sizeMax || $timeFrom || $timeTo || $sourceID;
		if (!$need) return null;
		$where = array('isFolder' => 0);
		if ($exts) $where['fileType'] = array('in', $exts);
		if ($sizeMin && $sizeMax) $where['size'] = array('between', array($sizeMin, $sizeMax));
		elseif ($sizeMin) $where['size'] = array('egt', $sizeMin);
		elseif ($sizeMax) $where['size'] = array('elt', $sizeMax);
		if ($timeFrom && $timeTo) $where['modifyTime'] = array('between', array($timeFrom, $timeTo));
		elseif ($timeFrom) $where['modifyTime'] = array('egt', $timeFrom);
		elseif ($timeTo) $where['modifyTime'] = array('elt', $timeTo);
		$ids = array();
		try {
			if ($sourceID) {
				$folder = array();
				try { $folder = Model('Source')->where(array('sourceID' => $sourceID))->find(); } catch (Throwable $e) { $folder = array(); }
				$level = rtrim((string)_get($folder, 'parentLevel', ''), ',').','.$sourceID.',';
				$w1 = $where; $w1['parentID'] = $sourceID;
				$w2 = $where; $w2['parentLevel'] = array('like', $level.'%');
				foreach (array($w1, $w2) as $w) {
					$rows = Model('Source')->where($w)->field('fileID')->limit(8000)->select();
					foreach ((array)$rows as $row) {
						$id = intval(_get($row, 'fileID', 0));
						if ($id) $ids[] = $id;
					}
				}
			} else {
				$rows = Model('Source')->where($where)->field('fileID')->limit(8000)->select();
				foreach ((array)$rows as $row) {
					$id = intval(_get($row, 'fileID', 0));
					if ($id) $ids[] = $id;
				}
			}
		} catch (Throwable $e) {
			return array();
		}
		return array_values(array_unique($ids));
	}

	private function fileFlags() {
		$this->initTable();
		$paths = $this->decodeJson(_get($this->in, 'paths', ''));
		$idsIn = $this->decodeJson(_get($this->in, 'fileIDs', ''));
		$map = array();
		$fileIDs = array();
		foreach ((array)$idsIn as $id) {
			$id = intval($id);
			if ($id) $fileIDs[] = $id;
		}
		foreach ((array)$paths as $path) {
			$path = is_array($path) ? (string)_get($path, 'path', '') : (string)$path;
			if ($path === '') continue;
			$info = array();
			try { $info = IO::info($path); } catch (Throwable $e) { $info = array(); }
			$fileID = intval(_get($info, 'fileID', 0));
			if ($fileID) {
				$fileIDs[] = $fileID;
				$map[$path] = $fileID;
			}
		}
		$fileIDs = array_values(array_unique($fileIDs));
		$flags = array();
		if ($fileIDs) {
			$rows = Model($this->stateTable)->where(array('fileID' => array('in', $fileIDs)))->select();
			foreach ((array)$rows as $row) {
				$st = intval(_get($row, 'status', 0));
				$flags[intval($row['fileID'])] = array(
					'status' => $st,
					'ready' => $st === self::ST_OK ? 1 : 0,
					'chunks' => intval(_get($row, 'chunkCount', 0)),
				);
			}
		}
		$byPath = array();
		foreach ($map as $path => $fileID) {
			$byPath[$path] = isset($flags[$fileID]) ? $flags[$fileID] : array('status' => 0, 'ready' => 0, 'chunks' => 0, 'fileID' => $fileID);
			$byPath[$path]['fileID'] = $fileID;
		}
		return array('flags' => $flags, 'byPath' => $byPath);
	}

	private function hydrateState($row) {
		$fileID = intval(_get($row, 'fileID', 0));
		$sourceID = intval(_get($row, 'sourceID', 0));
		$src = array();
		try {
			if ($sourceID) $src = Model('Source')->where(array('sourceID' => $sourceID))->find();
			if (!$src) $src = Model('Source')->where(array('fileID' => $fileID, 'isFolder' => 0))->find();
		} catch (Throwable $e) { $src = array(); }
		if ($src) $sourceID = intval(_get($src, 'sourceID', $sourceID));
		$file = array();
		try { $file = Model('File')->where(array('fileID' => $fileID))->find(); } catch (Throwable $e) {}
		$status = intval(_get($row, 'status', 0));
		$map = array(
			self::ST_WAIT => array('text' => '等待正文', 'cls' => 'is-wait'),
			self::ST_ES => array('text' => '待向量化', 'cls' => 'is-wait'),
			self::ST_OK => array('text' => '已完成', 'cls' => 'is-ok'),
			self::ST_SKIP => array('text' => ((string)_get($row, 'error', '') === '已禁用' ? '已禁用' : '已跳过'), 'cls' => 'is-skip'),
			self::ST_FAIL => array('text' => '失败', 'cls' => 'is-fail'),
		);
		$st = isset($map[$status]) ? $map[$status] : array('text' => '待处理', 'cls' => 'is-wait');
		$chunks = intval(_get($row, 'chunkCount', 0));
		$name = (string)_get($src, 'name', _get($file, 'name', 'fileID '.$fileID));
		$path = (string)_get($src, 'path', '');
		if ($path === '' && $sourceID) {
			$path = '{source:'.$sourceID.'}';
		}
		$ready = $status === self::ST_OK;
		return array(
			'fileID' => $fileID,
			'sourceID' => $sourceID,
			'name' => $name,
			'ext' => strtolower((string)_get($src, 'fileType', _get($src, 'ext', ''))),
			'size' => intval(_get($src, 'size', _get($file, 'size', 0))),
			'path' => $path,
			'parentID' => intval(_get($src, 'parentID', 0)),
			'parentLevel' => (string)_get($src, 'parentLevel', ''),
			'pathDisplay' => (string)_get($src, 'pathDisplay', _get($src, 'parentLevel', '')),
			'status' => $status,
			'statusText' => $st['text'],
			'statusCls' => $st['cls'],
			'ready' => $ready ? 1 : 0,
			'chunkCount' => $chunks,
			'chunkDone' => $ready ? $chunks : 0,
			'error' => (string)_get($row, 'error', ''),
			'indexTime' => intval(_get($row, 'indexTime', 0)),
			'modifyTime' => intval(_get($src, 'modifyTime', _get($row, 'modifyTime', 0))),
			'createTime' => intval(_get($src, 'createTime', _get($file, 'createTime', 0))),
			'hash' => (string)_get($row, 'contentHash', _get($file, 'hashSimple', _get($file, 'hashMd5', ''))),
		);
	}

	private function fileDetail($fileID) {
		if ($fileID <= 0) return array('message' => '缺少文件');
		$state = Model($this->stateTable)->where(array('fileID' => $fileID))->find();
		$item = $this->hydrateState($state ? $state : array('fileID' => $fileID));
		$content = '';
		$chunks = array();
		try { $content = $this->elastic()->getContent($fileID); } catch (Throwable $e) { $content = ''; }
		try { $chunks = $this->milvus()->listByFile($fileID); } catch (Throwable $e) { $chunks = array(); }
		if (!$chunks && $content !== '') {
			$config = $this->getConfig();
			$split = AiRagTextChunker::split($content, intval(_get($config, 'chunkSize', 800)), intval(_get($config, 'chunkOverlap', 120)), $item['name']);
			foreach ((array)$split as $chunk) {
				$chunkText = $this->stripChunkTitle((string)$chunk['text'], $item['name']);
				$chunks[] = array('index' => intval($chunk['index']), 'text' => $chunkText, 'name' => $item['name'], 'size' => strlen($chunkText));
			}
		}
		$item['textSize'] = strlen($content);
		$item['content'] = mb_substr($content, 0, 2000);
		$item['chunks'] = $chunks;
		return array('item' => $item);
	}

	private function exportConfig() {
		$config = $this->getConfig();
		$config = array_merge($config, KodboxCorpusShare::storeOptions('elasticFulltext', _get($config, 'elasticUrl', 'http://elasticsearch:9200'), 'kodbox-fulltext'));
		$services = $this->services();
		$config['modelServices'] = json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$config['services'] = $services;
		$config['chatModels'] = AiRagModelHub::chatModels($services);
		$config['embedModels'] = $this->typedModels($services, 'embed');
		$config['rerankModels'] = $this->typedModels($services, 'rerank');
		$config['imageModels'] = $this->typedModels($services, 'image');
		$config['asrModels'] = $this->typedModels($services, 'asr');
		return $config;
	}

	private function typedModels($services, $type, $onlyPassed = false) {
		$list = array();
		foreach ((array)$services as $svc) {
			if (empty($svc['enabled'])) continue;
			foreach ((array)$svc['models'] as $model) {
				if (empty($model['enabled']) || !AiRagModelHub::hasType($model, $type)) continue;
				if ($onlyPassed && !AiRagModelHub::passed($model)) continue;
				$list[] = array('id' => $model['id'], 'name' => $model['name'] ?: $model['id'], 'provider' => $svc['name']);
			}
		}
		return $list;
	}

	private function decodeJson($value) {
		if (is_array($value)) return $value;
		$raw = trim((string)$value);
		if ($raw === '') return array();
		$data = json_decode($raw, true);
		return is_array($data) ? $data : array();
	}

	private function parseModels($value) {
		if (is_array($value)) $value = implode(',', $value);
		$items = preg_split('/[\s,;]+/', (string)$value);
		$out = array();
		foreach ($items as $item) {
			$item = trim($item);
			if ($item !== '') $out[] = $item;
		}
		return array_values(array_unique($out));
	}

	private function modelChoices() {
		$config = $this->getConfig();
		$models = $this->parseModels(_get($config, 'llmModels', ''));
		$current = trim((string)_get($config, 'llmModel', ''));
		if ($current !== '' && !in_array($current, $models, true)) array_unshift($models, $current);
		return $models;
	}

	private function repairChatItem($item) {
		if (!is_array($item)) return $item;
		$convRefs = (array)_get($item, 'refs', array());
		$messages = array();
		$lastUser = -1;
		foreach ((array)_get($item, 'messages', array()) as $i => $msg) {
			if (!is_array($msg)) continue;
			if (_get($msg, 'role', '') === 'user') $lastUser = count($messages);
			$sources = array();
			foreach ((array)_get($msg, 'sources', array()) as $src) {
				if (!is_array($src)) continue;
				$path = (string)_get($src, 'path', '');
				$fileID = intval(_get($src, 'fileID', 0));
				if ($fileID && ($path === '' || preg_match('#^\{source:\d+\}/?$#', $path))) {
					$meta = $this->hydrateState(array('fileID' => $fileID));
					if ((string)_get($meta, 'path', '') !== '') $src['path'] = $meta['path'];
					if (empty($src['name'])) $src['name'] = $meta['name'];
				}
				$sources[] = $src;
			}
			if ($sources) $msg['sources'] = $sources;
			$messages[] = $msg;
		}
		if ($lastUser >= 0 && empty($messages[$lastUser]['refs']) && $convRefs) {
			$messages[$lastUser]['refs'] = $convRefs;
		}
		$item['messages'] = $messages;
		return $item;
	}

	private function chatFile() {
		$dir = rtrim(TEMP_PATH, '/\\').'/airag-chat';
		if (!is_dir($dir)) @mkdir($dir, 0755, true);
		$uid = intval(KodUser::id());
		return $dir.'/u'.$uid.'.json';
	}

	private function chatStore() {
		$file = $this->chatFile();
		$data = is_file($file) ? json_decode(@file_get_contents($file), true) : array();
		if (!is_array($data)) $data = array();
		if (!isset($data['items']) || !is_array($data['items'])) $data['items'] = array();
		return $data;
	}

	private function chatSaveStore($store) {
		$store['items'] = array_slice(array_values((array)_get($store, 'items', array())), 0, 50);
		@file_put_contents($this->chatFile(), json_encode($store, JSON_UNESCAPED_UNICODE), LOCK_EX);
	}

	private function chatFind($store, $id) {
		foreach ((array)_get($store, 'items', array()) as $item) {
			if (_get($item, 'id', '') === $id) return $item;
		}
		return null;
	}

	private function chatUpsert($id, $title, $model, $thinking, $tools, $refs, $question, $answer, $reasoning, $sources, $note, $extra = array()) {
		$store = $this->chatStore();
		$item = $id ? $this->chatFind($store, $id) : null;
		if (!$item) {
			$item = array(
				'id' => 'c'.dechex(time()).substr(md5(uniqid('', true)), 0, 8),
				'title' => $title,
				'created' => time(),
				'messages' => array(),
			);
		}
		$item['title'] = $item['title'] ?: $title;
		$item['updated'] = time();
		$item['model'] = $model;
		$item['thinking'] = $thinking ? 1 : 0;
		$item['tools'] = is_array($tools) ? $tools : array();
		$item['refs'] = is_array($refs) ? array_slice($refs, 0, 20) : array();
		$item['messages'][] = array('role' => 'user', 'content' => $question, 'refs' => is_array($refs) ? array_slice($refs, 0, 20) : array());
		$bot = array('role' => 'bot', 'content' => $answer, 'reasoning' => $reasoning, 'sources' => $sources, 'note' => $note);
		if (is_array($extra)) $bot = array_merge($bot, $extra);
		$item['messages'][] = $bot;
		$item['messages'] = array_slice($item['messages'], -40);
		$items = array();
		foreach ((array)$store['items'] as $row) {
			if ($row['id'] !== $item['id']) $items[] = $row;
		}
		array_unshift($items, $item);
		$store['items'] = $items;
		$this->chatSaveStore($store);
		return $item;
	}

	private function applySaves($answer) {
		$saved = array();
		if (!preg_match_all('/\[\[SAVE:([^\]]+)\]\](.*?)\[\[\/SAVE\]\]/s', $answer, $matches, PREG_SET_ORDER)) {
			return array($answer, $saved);
		}
		$clean = trim(preg_replace('/\[\[SAVE:[^\]]+\]\].*?\[\[\/SAVE\]\]/s', '', $answer));
		$home = rtrim((string)_get(Session::get('kodUser'), 'myhome', ''), '/');
		if ($home === '') $home = '{userFolder}';
		$dir = $home.'/AI助手';
		try { IO::mkdir($dir); } catch (Throwable $e) {}
		foreach ($matches as $block) {
			$name = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', trim($block[1]));
			$name = trim($name, '._');
			if ($name === '') continue;
			try {
				IO::setContent($dir.'/'.$name, $block[2]);
				$saved[] = $name;
			} catch (Throwable $e) {
				$this->log('save '.$name.': '.$e->getMessage(), 'warning');
			}
		}
		return array($clean === '' ? $answer : $clean, $saved);
	}

	private function normalizeExtensions($value) {
		if (is_array($value)) $value = implode(',', $value);
		$items = preg_split('/[\s,;]+/', strtolower((string)$value));
		return array_values(array_unique(array_filter(array_map(function($v) { return preg_replace('/[^a-z0-9]+/', '', $v); }, $items))));
	}

	private function defaultExtensions() {
		return 'doc,docx,docm,ppt,pptx,xls,xlsx,xlsm,pdf,odt,ods,odp,rtf,epub,txt,md,log,csv,json,xml,html,htm';
	}

	private function configuredExtensions($config = null) {
		$config = $config === null ? $this->getConfig() : $config;
		$allowed = $this->normalizeExtensions(_get($config, 'allowExtensions', ''));
		if (!$allowed) $allowed = $this->normalizeExtensions($this->defaultExtensions());
		if (_get($config, 'extensionMode', 'allow') !== 'deny') return $allowed;
		return array_values(array_diff($allowed, $this->normalizeExtensions(_get($config, 'denyExtensions', ''))));
	}

	private function indexableWhereSql($extensions = null) {
		$or = array();
		foreach ((array)($extensions === null ? $this->configuredExtensions() : $extensions) as $ext) {
			$safe = preg_replace('/[^a-z0-9]+/', '', strtolower((string)$ext));
			if ($safe === '') continue;
			$or[] = "LOWER(`name`) LIKE '%.".$safe."'";
		}
		return $or ? implode(' OR ', $or) : '';
	}

	private function countIndexableFiles($maxFileID = null, $extensions = null) {
		$sql = $this->indexableWhereSql($extensions);
		if ($sql === '') return 0;
		if ($maxFileID !== null) $sql = '`fileID` <= '.intval($maxFileID).' AND ('.$sql.')';
		try { return intval(Model('File')->where($sql)->count()); }
		catch (Throwable $e) { return 0; }
	}

	private function scanProgress($cursor = null) {
		if (!is_array($cursor)) $cursor = $this->readCursorData();
		$filesTotal = intval(Model('File')->count());
		$targetTotal = $this->countIndexableFiles();
		$scanCursor = intval(_get($cursor, 'fileID', 0));
		$scanHigh = max(intval(_get($cursor, 'scanHigh', 0)), $scanCursor);
		$maxFileID = 0;
		try { $maxFileID = intval(Model('File')->max('fileID')); } catch (Throwable $e) {}
		if ($scanCursor > 0) {
			$scanDone = $this->countIndexableFiles($scanCursor);
		} elseif ($scanHigh > 0 && $maxFileID > 0 && $scanHigh >= $maxFileID) {
			$scanDone = $targetTotal;
		} else {
			$scanDone = 0;
		}
		$scanDone = min($scanDone, $targetTotal);
		return array(
			'filesTotal' => $filesTotal,
			'targetTotal' => $targetTotal,
			'scanDone' => $scanDone,
			'scanRemain' => max(0, $targetTotal - $scanDone),
			'scanHigh' => $scanHigh,
			'scanCursor' => $scanCursor,
			'maxFileID' => $maxFileID,
		);
	}

	private function taskBusy() {
		return AiRagIndexLock::busy('airag-task') || AiRagIndexLock::busy('kod-heavy-index');
	}

	private function replyAndContinue($message) {
		@ob_get_clean();
		if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
		$encode = function_exists('json_encode_force') ? 'json_encode_force' : 'json_encode';
		echo $encode(array('code' => true, 'data' => array('message' => $message), 'timeUse' => '0', 'timeNow' => (string)microtime(true)));
		if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
		if (function_exists('http_close')) http_close();
		else {
			if (function_exists('ignore_timeout')) ignore_timeout();
			if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
		}
	}

	private function releaseSession() {
		if (function_exists('session_write_close')) @session_write_close();
	}

	private function keepConfig($config, $prev, $key, $default) {
		if (!array_key_exists($key, $config) || $config[$key] === '' || $config[$key] === null) return _get($prev, $key, $default);
		return $config[$key];
	}

	private function isOpen() { return _get($this->getConfig(), 'serviceEnabled', '1') == '1'; }

	private function isPlainText($ext) {
		return in_array($ext, array('txt','md','log','csv','json','xml','html','htm','css','js','php','py','java','c','cpp','h','ini','yaml','yml','vtt'), true);
	}

	private function readCursorData() {
		$data = is_file($this->cursorFile) ? json_decode(@file_get_contents($this->cursorFile), true) : array();
		$data = is_array($data) ? $data : array();
		$data['fileID'] = intval(_get($data, 'fileID', 0));
		$data['time'] = intval(_get($data, 'time', 0));
		return $data;
	}

	private function readCursor() { return intval(_get($this->readCursorData(), 'fileID', 0)); }

	private function writeCursor($fileID, $extra = null) {
		$prev = $this->readCursorData();
		$data = array('fileID' => intval($fileID), 'time' => time());
		foreach (array('indexed', 'skipped', 'failed', 'scanned', 'running', 'chunkDone', 'chunkTotal', 'fileNo', 'fileTotal') as $key) {
			$data[$key] = is_array($extra) && array_key_exists($key, $extra) ? intval($extra[$key]) : intval(_get($prev, $key, 0));
		}
		$data['current'] = is_array($extra) && array_key_exists('current', $extra) ? (string)$extra['current'] : (string)_get($prev, 'current', '');
		$data['phase'] = is_array($extra) && array_key_exists('phase', $extra) ? (string)$extra['phase'] : (string)_get($prev, 'phase', 'extract');
		$scanHigh = max(intval(_get($prev, 'scanHigh', 0)), intval($data['fileID']));
		if (is_array($extra) && array_key_exists('scanHigh', $extra)) $scanHigh = intval($extra['scanHigh']);
		$data['scanHigh'] = $scanHigh;
		$vecBase = max(intval(_get($prev, 'vecBase', 0)), intval(_get($data, 'fileTotal', 0)));
		if (is_array($extra) && array_key_exists('vecBase', $extra)) $vecBase = intval($extra['vecBase']);
		$data['vecBase'] = $vecBase;
		$started = is_array($extra) && array_key_exists('started', $extra) ? intval($extra['started']) : intval(_get($prev, 'started', 0));
		if (intval($data['running']) && !$started) $started = time();
		if (!intval($data['running'])) $started = 0;
		$data['started'] = $started;
		@file_put_contents($this->cursorFile.'.tmp', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
		@rename($this->cursorFile.'.tmp', $this->cursorFile);
	}

	private function fileLabel($file) {
		return 'fileID='.intval(_get($file, 'fileID', 0)).' name='._get($file, 'name', '');
	}

	private function sanitizeSnippet($text) {
		return AiRagTextNormalizer::clean($text);
	}

	private function escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

	private function log($message, $level = 'info') {
		if ($level === 'info' && _get($this->getConfig(), 'debugMode', 0) != '1') return;
		write_log('[aiRag] '.$message, 'aiRag', $level);
	}
}
