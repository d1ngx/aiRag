<?php

class AiRagMilvusStore {
	private $url;
	private $token;
	private $collection;
	private $dim;
	private $config;
	private $ancestorReady = null;

	public function __construct($config) {
		$this->config = $config;
		$this->url = AiRagHttpJson::fixUrl(_get($config, 'milvusUrl', 'http://milvus:19530'));
		$this->token = trim((string)_get($config, 'milvusToken', ''));
		$this->collection = preg_replace('/[^A-Za-z0-9_]/', '_', _get($config, 'milvusCollection', 'kodbox_airag_chunk'));
		$this->dim = max(32, min(4096, intval(_get($config, 'embedDim', 1024))));
	}

	public function info($timeout = 8) {
		return $this->post('/v2/vectordb/collections/list', (object)array(), $timeout, array(200, 201));
	}

	public function ensureInfrastructure() {
		if (!$this->collectionExists()) $this->createCollection();
		$this->validateSchema();
		$this->ensureAncestorField();
		$this->post('/v2/vectordb/collections/load', array('collectionName' => $this->collection), 20, array(200, 201));
		return true;
	}

	public function upsertChunks($rows, $batchSize = 300, $pauseMs = 700) {
		$batchSize = max(50, min(500, intval($batchSize)));
		$pauseMs = max(0, min(5000, intval($pauseMs)));
		$chunks = array_chunk((array)$rows, $batchSize);
		$keepAncestors = $this->hasAncestorField();
		foreach ($chunks as $part) {
			if (!$keepAncestors) {
				foreach ($part as &$one) { if (is_array($one)) unset($one['ancestor_ids']); }
				unset($one);
			}
			AiRagBackpressure::assertReady($this->config);
			$this->post('/v2/vectordb/entities/upsert', array(
				'collectionName' => $this->collection,
				'data' => $part,
			), 60);
			if ($pauseMs) usleep($pauseMs * 1000);
		}
		return count($rows);
	}

	public function hashesByFile($fileID) {
		$response = $this->post('/v2/vectordb/entities/query', array(
			'collectionName' => $this->collection,
			'filter' => 'file_id == '.intval($fileID),
			'outputFields' => array_merge(array('chunk_index', 'content_hash', 'source_id', 'parent_id', 'modify_time', 'name', 'ext'), $this->hasAncestorField() ? array('ancestor_ids') : array()),
			'consistencyLevel' => 'Strong',
			'limit' => 4096,
		), 20, array(200, 201));
		$rows = (array)_get($response, 'data', $response);
		$out = array();
		foreach ($rows as $hit) {
			if (!is_array($hit)) continue;
			$entity = (array)_get($hit, 'entity', $hit);
			$index = intval(_get($entity, 'chunk_index', _get($hit, 'chunk_index', -1)));
			$hash = (string)_get($entity, 'content_hash', _get($hit, 'content_hash', ''));
			if ($index < 0 || $hash === '') continue;
			$out[$index] = $entity;
		}
		return $out;
	}

	public function listByFile($fileID, $limit = 200, $chunkIndex = null) {
		$response = $this->post('/v2/vectordb/entities/query', array(
			'collectionName' => $this->collection,
			'filter' => 'file_id == '.intval($fileID).($chunkIndex === null ? '' : ' && chunk_index == '.intval($chunkIndex)),
			'outputFields' => array('chunk_id', 'chunk_index', 'text', 'name', 'content_hash'),
			'limit' => max(1, min(400, intval($limit))),
		), 20, array(200, 201));
		$rows = (array)_get($response, 'data', $response);
		$out = array();
		foreach ($rows as $hit) {
			if (!is_array($hit)) continue;
			$entity = (array)_get($hit, 'entity', $hit);
			$out[] = array(
				'id' => (string)_get($entity, 'chunk_id', _get($hit, 'chunk_id', '')),
				'index' => intval(_get($entity, 'chunk_index', _get($hit, 'chunk_index', 0))),
				'text' => (string)_get($entity, 'text', _get($hit, 'text', '')),
				'name' => (string)_get($entity, 'name', ''),
				'hash' => (string)_get($entity, 'content_hash', ''),
				'size' => strlen((string)_get($entity, 'text', _get($hit, 'text', ''))),
			);
		}
		usort($out, function($a, $b) { return $a['index'] - $b['index']; });
		return $out;
	}

	public function deleteFile($fileID) {
		$this->post('/v2/vectordb/entities/delete', array(
			'collectionName' => $this->collection,
			'filter' => 'file_id == '.intval($fileID),
		), 20, array(200, 201));
	}

	public function deleteChunks($fileID, $indexes) {
		$indexes = array_values(array_unique(array_filter(array_map('intval', (array)$indexes), function($n) { return $n >= 0; })));
		if (!$indexes) return 0;
		$this->post('/v2/vectordb/entities/delete', array(
			'collectionName' => $this->collection,
			'filter' => 'file_id == '.intval($fileID).' && chunk_index in ['.implode(',', $indexes).']',
		), 20, array(200, 201));
		return count($indexes);
	}

	public function search($vector, $limit, $fileIDs = null, $filter = null, $offset = 0, $groupByFile = false) {
		$body = array(
			'collectionName' => $this->collection,
			'data' => array($vector),
			'annsField' => 'vector',
			'consistencyLevel' => 'Strong',
			'limit' => max(1, intval($limit)),
			'outputFields' => array('file_id', 'chunk_index', 'text', 'name', 'ext', 'source_id'),
			'searchParams' => array('metricType' => 'COSINE'),
		);
		if (intval($offset) > 0) $body['offset'] = intval($offset);
		if ($groupByFile) {
			$body['groupingField'] = 'file_id';
			$body['groupSize'] = 3;
			$body['strictGroupSize'] = false;
		}
		$expr = self::filterExpr($fileIDs, $filter);
		if ($expr !== '') $body['filter'] = $expr;
		$response = $this->post('/v2/vectordb/entities/search', $body, 20);
		$hits = _get($response, 'data', array());
		if (isset($hits[0]) && is_array($hits[0]) && (isset($hits[0]['id']) || isset($hits[0]['chunk_id']) || isset($hits[0]['file_id']) || isset($hits[0]['entity']))) $rows = $hits;
		else $rows = (array)_get($hits, 0, $hits);
		$result = array();
		foreach ((array)$rows as $rank => $hit) {
			$entity = (array)_get($hit, 'entity', $hit);
			$fileID = intval(_get($entity, 'file_id', _get($hit, 'file_id', 0)));
			if (!$fileID) continue;
			$result[] = array(
				'fileID' => $fileID,
				'chunk' => intval(_get($entity, 'chunk_index', 0)),
				'text' => (string)_get($entity, 'text', ''),
				'name' => (string)_get($entity, 'name', ''),
				'ext' => (string)_get($entity, 'ext', ''),
				'sourceID' => intval(_get($entity, 'source_id', 0)),
				'score' => floatval(_get($hit, 'distance', _get($hit, 'score', 0))),
				'rank' => $rank + 1,
			);
		}
		return $result;
	}

	public function rebuild() {
		if ($this->collectionExists()) {
			$this->post('/v2/vectordb/collections/drop', array('collectionName' => $this->collection), 20, array(200, 201));
		}
		return $this->ensureInfrastructure();
	}

	public static function chunkId($fileID, $chunkIndex) {
		return intval($fileID).':'.intval($chunkIndex);
	}

	public static function chunkHash($text, $model = '') {
		return sha1($model === '' ? (string)$text : $model."\0".(string)$text);
	}

	public static function diffChunks($chunks, $existing, $model = '') {
		$existing = is_array($existing) ? $existing : array();
		$wanted = array();
		$work = array();
		$reuse = array();
		foreach ((array)$chunks as $chunk) {
			$index = intval(_get($chunk, 'index', 0));
			$hash = self::chunkHash(_get($chunk, 'text', ''), $model);
			$wanted[$index] = $hash;
			$chunk['hash'] = $hash;
			if (isset($existing[$index]) && (string)(is_array($existing[$index]) ? _get($existing[$index], 'content_hash', '') : $existing[$index]) === $hash) $reuse[] = $index;
			else $work[] = $chunk;
		}
		$stale = array();
		foreach ($existing as $index => $_hash) {
			$index = intval($index);
			if (!isset($wanted[$index])) $stale[] = $index;
		}
		return array('work' => $work, 'reuse' => $reuse, 'stale' => $stale, 'wanted' => $wanted);
	}

	public static function filterExpr($fileIDs = null, $filter = null) {
		$filter = is_array($filter) ? $filter : array();
		$parts = array();
		$ids = array_values(array_filter(array_map('intval', (array)$fileIDs)));
		if ($fileIDs !== null && !$ids) $parts[] = 'file_id < 0';
		if ($ids) $parts[] = 'file_id in ['.implode(',', $ids).']';
		$exclude = array_values(array_unique(array_filter(array_map('intval', (array)_get($filter, 'excludeFileIDs', array())))));
		if ($exclude) $parts[] = 'file_id not in ['.implode(',', $exclude).']';
		$ancestor = intval(_get($filter, 'ancestorID', 0));
		if ($ancestor) $parts[] = 'ARRAY_CONTAINS(ancestor_ids, '.$ancestor.')';
		if (!empty($filter['ancestorMissing'])) $parts[] = 'ancestor_ids is null';
		$source = intval(_get($filter, 'sourceID', 0));
		if ($source) $parts[] = 'source_id == '.$source;
		$parent = intval(_get($filter, 'parentID', 0));
		if ($parent) $parts[] = 'parent_id == '.$parent;
		$ext = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)_get($filter, 'ext', '')));
		if ($ext !== '') $parts[] = 'ext == "'.$ext.'"';
		$since = intval(_get($filter, 'modifyTime', 0));
		if ($since) $parts[] = 'modify_time >= '.$since;
		return implode(' && ', $parts);
	}

	public static function row($meta, $vector) {
		$row = array(
			'chunk_id' => self::chunkId(_get($meta, 'fileID', 0), _get($meta, 'index', 0)),
			'file_id' => intval(_get($meta, 'fileID', 0)),
			'source_id' => intval(_get($meta, 'sourceID', 0)),
			'parent_id' => intval(_get($meta, 'parentID', 0)),
			'chunk_index' => intval(_get($meta, 'index', 0)),
			'text' => mb_strcut((string)_get($meta, 'text', ''), 0, 8192, 'UTF-8'),
			'name' => mb_strcut((string)_get($meta, 'name', ''), 0, 512, 'UTF-8'),
			'ext' => substr(strtolower(preg_replace('/[^a-z0-9]+/', '', (string)_get($meta, 'ext', ''))), 0, 16),
			'content_hash' => (string)_get($meta, 'hash', self::chunkHash(_get($meta, 'text', ''))),
			'modify_time' => intval(_get($meta, 'modifyTime', 0)),
			'vector' => $vector,
		);
		if (array_key_exists('ancestorIDs', $meta)) $row['ancestor_ids'] = self::ancestorList(_get($meta, 'ancestorIDs', array()));
		return $row;
	}

	public static function ancestorList($ids) {
		$out = array();
		foreach ((array)$ids as $id) {
			$id = intval($id);
			if ($id > 0) $out[$id] = $id;
		}
		return array_slice(array_values($out), 0, 64);
	}

	public function hasAncestorField() {
		if ($this->ancestorReady !== null) return $this->ancestorReady;
		try {
			$result = $this->post('/v2/vectordb/collections/describe', array('collectionName' => $this->collection), 8);
			$names = array();
			foreach ((array)_get(_get($result, 'data', array()), 'fields', array()) as $field) $names[(string)_get($field, 'name', '')] = true;
			$this->ancestorReady = isset($names['ancestor_ids']);
		} catch (Throwable $e) {
			$this->ancestorReady = false;
		}
		return $this->ancestorReady;
	}

	private function ensureAncestorField() {
		if ($this->hasAncestorField()) return;
		$this->ancestorReady = null;
		try {
			$this->post('/v2/vectordb/collections/fields/add', array(
				'collectionName' => $this->collection,
				'schema' => array(
					'fieldName' => 'ancestor_ids',
					'dataType' => 'Array',
					'elementDataType' => 'Int64',
					'nullable' => true,
					'elementTypeParams' => array('max_capacity' => 64),
				),
			), 20);
			try { $this->createIndex('ancestor_ids', 'idx_ancestor_ids', array('index_type' => 'AUTOINDEX')); } catch (Throwable $e) {}
		} catch (Throwable $e) {}
		$this->ancestorReady = null;
		$this->hasAncestorField();
	}

	private function collectionExists() {
		$has = $this->post('/v2/vectordb/collections/has', array('collectionName' => $this->collection), 8, array(200, 201));
		$exists = _get($has, 'data', _get($has, 'has', false));
		if (is_array($exists)) $exists = !empty($exists['has']) || !empty($exists['exists']);
		return !!$exists;
	}

	private function createCollection() {
		$this->post('/v2/vectordb/collections/create', array(
			'collectionName' => $this->collection,
			'schema' => array(
				'autoID' => false,
				'enableDynamicField' => false,
				'fields' => array(
					array('fieldName' => 'chunk_id', 'dataType' => 'VarChar', 'isPrimary' => true, 'elementTypeParams' => array('max_length' => 64)),
					array('fieldName' => 'file_id', 'dataType' => 'Int64'),
					array('fieldName' => 'source_id', 'dataType' => 'Int64'),
					array('fieldName' => 'parent_id', 'dataType' => 'Int64'),
					array('fieldName' => 'chunk_index', 'dataType' => 'Int64'),
					array('fieldName' => 'text', 'dataType' => 'VarChar', 'elementTypeParams' => array('max_length' => 8192)),
					array('fieldName' => 'name', 'dataType' => 'VarChar', 'elementTypeParams' => array('max_length' => 512)),
					array('fieldName' => 'ext', 'dataType' => 'VarChar', 'elementTypeParams' => array('max_length' => 16)),
					array('fieldName' => 'content_hash', 'dataType' => 'VarChar', 'elementTypeParams' => array('max_length' => 40)),
					array('fieldName' => 'modify_time', 'dataType' => 'Int64'),
					array('fieldName' => 'ancestor_ids', 'dataType' => 'Array', 'elementDataType' => 'Int64', 'nullable' => true, 'elementTypeParams' => array('max_capacity' => 64)),
					array('fieldName' => 'vector', 'dataType' => 'FloatVector', 'elementTypeParams' => array('dim' => (string)$this->dim)),
				),
			),
		), 30);
		$this->createIndex('vector', 'vector_cosine', array('metricType' => 'COSINE', 'index_type' => 'AUTOINDEX'));
		foreach (array('file_id', 'source_id', 'parent_id', 'ext', 'modify_time', 'ancestor_ids') as $field) {
			$this->createIndex($field, 'idx_'.$field, array('index_type' => 'AUTOINDEX'));
		}
	}

	private function createIndex($field, $name, $params) {
		$this->post('/v2/vectordb/indexes/create', array(
			'collectionName' => $this->collection,
			'indexParams' => array(array_merge(array('fieldName' => $field, 'indexName' => $name), $params)),
		), 30, array(200, 201));
	}

	private function validateSchema() {
		$result = $this->post('/v2/vectordb/collections/describe', array('collectionName' => $this->collection));
		$fields = array();
		foreach ((array)_get(_get($result, 'data', array()), 'fields', array()) as $field) $fields[$field['name']] = $field;
		$required = array('chunk_id'=>'VarChar','file_id'=>'Int64','source_id'=>'Int64','parent_id'=>'Int64','chunk_index'=>'Int64','text'=>'VarChar','name'=>'VarChar','ext'=>'VarChar','content_hash'=>'VarChar','modify_time'=>'Int64','vector'=>'FloatVector');
		foreach ($required as $name=>$type) {
			if (!isset($fields[$name]) || strcasecmp((string)_get($fields[$name], 'type', ''), $type) !== 0) throw new RuntimeException('Milvus 集合结构不兼容，请备份后重建 AIRAG 向量索引');
		}
		foreach ((array)_get($fields['vector'], 'params', array()) as $param) {
			if (_get($param, 'key', '') === 'dim' && intval($param['value']) !== $this->dim) throw new RuntimeException('Milvus 集合维度与 Embedding 配置不一致，请重建向量索引');
		}
	}

	public function refreshMetadata($fileID, $indexes, $file) {
		foreach (array_chunk(array_values($indexes), 50) as $batch) {
			$response = $this->post('/v2/vectordb/entities/query', array(
				'collectionName'=>$this->collection,
				'filter'=>'file_id == '.intval($fileID).' && chunk_index in ['.implode(',', array_map('intval', $batch)).']',
				'outputFields'=>array('chunk_id','file_id','source_id','parent_id','chunk_index','text','name','ext','content_hash','modify_time','vector'),
				'limit'=>count($batch), 'consistencyLevel'=>'Strong',
			));
			$rows = (array)_get($response, 'data', array());
			if (count($rows) !== count($batch)) throw new RuntimeException('复用分片已变化，请重试');
			$ancestors = $this->hasAncestorField() ? self::ancestorList(_get($file, 'ancestorIDs', array())) : null;
			foreach ($rows as &$row) {
				$row['source_id'] = intval(_get($file,'sourceID',0));
				$row['parent_id'] = intval(_get($file,'parentID',0));
				$row['modify_time'] = intval(_get($file,'modifyTime',0));
				$row['name'] = mb_strcut((string)_get($file,'name',''),0,512,'UTF-8');
				$row['ext'] = substr(strtolower(preg_replace('/[^a-z0-9]+/','',(string)_get($file,'ext',''))),0,16);
				if ($ancestors !== null) $row['ancestor_ids'] = $ancestors;
			}
			unset($row);
			$this->upsertChunks($rows, 50, intval(_get($this->config,'milvusPauseMs',700)));
		}
	}

	private function headers() {
		$headers = array();
		if ($this->token !== '') $headers['Authorization'] = 'Bearer '.$this->token;
		return $headers;
	}

	private function post($path, $body, $timeout = 20, $allowed = array(200, 201)) {
		$result = AiRagHttpJson::request('POST', $this->url.$path, $body, $this->headers(), $timeout, $allowed);
		if (!array_key_exists('code', $result) || !is_numeric($result['code']) || intval($result['code']) !== 0) throw new RuntimeException('Milvus '.$path.' 失败：'.(string)_get($result, 'message', '无效业务响应'));
		return $result;
	}
}
