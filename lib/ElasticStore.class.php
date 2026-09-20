<?php

class AiRagElasticStore {
	private $url;
	private $readOnly;
	private $index;
	private $pipeline = 'kodbox-airag-attachment';

	public function __construct($config) {
		$this->readOnly = !empty($config['readOnly']);
		$this->url = AiRagHttpJson::fixUrl(_get($config, 'elasticUrl', 'http://elasticsearch:9200'));
		$this->index = strtolower(_get($config, 'indexName', 'kodbox-airag'));
	}

	public function info($timeout = 5) {
		return AiRagHttpJson::request('GET', $this->url.'/', null, array(), $timeout);
	}

	public function ensureInfrastructure() {
		if ($this->readOnly) throw new RuntimeException('共享正文仅允许 elasticFulltext 修改');
		AiRagHttpJson::request('PUT', $this->url.'/_ingest/pipeline/'.$this->pipeline, array(
			'description' => 'Extract document text for Kodbox AIRAG',
			'processors' => array(
				array('attachment' => array(
					'field' => 'data',
					'target_field' => 'attachment',
					'indexed_chars' => 200000,
					'remove_binary' => true,
				)),
				array('convert' => array(
					'field' => 'attachment.content',
					'target_field' => 'content',
					'type' => 'string',
					'ignore_failure' => true,
				)),
				array('remove' => array('field' => 'attachment', 'ignore_missing' => true)),
			),
		), array(), 20, array(200, 201));
		$exists = AiRagHttpJson::request('HEAD', $this->url.'/'.$this->index, null, array(), 5, array(200, 404));
		if (intval($exists['_status']) === 404) {
			AiRagHttpJson::request('PUT', $this->url.'/'.$this->index, array(
				'settings' => array(
					'number_of_shards' => 1,
					'number_of_replicas' => 0,
					'refresh_interval' => '5s',
				),
				'mappings' => array(
					'dynamic' => false,
					'properties' => array(
						'fileID' => array('type' => 'long'),
						'sourceID' => array('type' => 'long'),
						'name' => array('type' => 'text', 'fields' => array('raw' => array('type' => 'keyword'))),
						'ext' => array('type' => 'keyword'),
						'size' => array('type' => 'long'),
						'modifyTime' => array('type' => 'date', 'format' => 'epoch_second'),
						'content' => array('type' => 'text'),
					),
				),
			), array(), 20, array(200, 201));
		}
		return true;
	}

	public function indexFile($source, $content, $plainText) {
		if ($this->readOnly) throw new RuntimeException('共享正文仅允许 elasticFulltext 修改');
		$document = array(
			'fileID' => intval($source['fileID']),
			'sourceID' => intval(_get($source, 'sourceID', 0)),
			'name' => (string)$source['name'],
			'ext' => strtolower((string)_get($source, 'fileType', _get($source, 'ext', ''))),
			'size' => intval($source['size']),
			'modifyTime' => intval($source['modifyTime']),
		);
		$path = '/'.$this->index.'/_doc/'.intval($source['fileID']).'?refresh=false';
		if ($plainText) {
			$document['content'] = $this->toUtf8($content);
			AiRagHttpJson::request('PUT', $this->url.$path, $document, array(), 25, array(200, 201));
			return $document['content'];
		}
		$document['data'] = base64_encode($content);
		AiRagHttpJson::request('PUT', $this->url.$path.'&pipeline='.$this->pipeline, $document, array(), 40, array(200, 201));
		return $this->getRawContent(intval($source['fileID']));
	}

	public function getDocument($fileID) {
		$result = AiRagHttpJson::request('GET', $this->url.'/'.$this->index.'/_source/'.intval($fileID).'?_source_includes=content,modifyTime,name,size,extractVersion', null, array(), 8, array(200, 404));
		if (intval($result['_status']) === 404) return array();
		return array(
			'content' => (string)_get($result, 'content', ''),
			'modifyTime' => intval(_get($result, 'modifyTime', 0)),
			'name' => (string)_get($result, 'name', ''),
			'size' => intval(_get($result, 'size', 0)),
			'extractVersion' => (string)_get($result, 'extractVersion', ''),
		);
	}

	public function getRawContent($fileID) {
		return (string)_get($this->getDocument($fileID), 'content', '');
	}

	public function getContent($fileID) {
		return AiRagTextNormalizer::clean($this->getRawContent($fileID));
	}

	public function updateContent($fileID, $content) {
		if ($this->readOnly) throw new RuntimeException('共享正文仅允许 elasticFulltext 修改');
		AiRagHttpJson::request('POST', $this->url.'/'.$this->index.'/_update/'.intval($fileID).'?refresh=false', array(
			'doc' => array('content' => AiRagTextNormalizer::clean($content)),
		), array(), 15, array(200, 201));
	}

	public function search($words, $limit, $fileIDs = null, $filter = null) {
		$body = array(
			'size' => max(1, intval($limit)),
			'_source' => array('fileID', 'name', 'content', 'ext', 'sourceID'),
			'query' => array('bool' => array(
				'should' => array(
					array('match_phrase' => array('content' => array('query' => (string)$words, 'boost' => 4))),
					array('match' => array('content' => array('query' => (string)$words, 'operator' => 'and', 'boost' => 2))),
					array('match' => array('name' => array('query' => (string)$words, 'boost' => 1.5))),
				),
				'minimum_should_match' => 1,
			)),
			'highlight' => array(
				'pre_tags' => array(''),
				'post_tags' => array(''),
				'fields' => array('content' => array('fragment_size' => 240, 'number_of_fragments' => 1)),
			),
		);
		$clauses = array();
		if ($fileIDs !== null && !array_filter(array_map('intval',(array)$fileIDs))) return array();
		$fileIDs = array_values(array_filter(array_map('intval', (array)$fileIDs)));
		if ($fileIDs) $clauses[] = array('terms' => array('fileID' => $fileIDs));
		$filter = is_array($filter) ? $filter : array();
		$source = intval(_get($filter, 'sourceID', 0));
		if ($source) $clauses[] = array('term' => array('sourceID' => $source));
		$ext = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)_get($filter, 'ext', '')));
		if ($ext !== '') $clauses[] = array('term' => array('ext' => $ext));
		$since = intval(_get($filter,'modifyTime',0));
		if ($since) $clauses[] = array('range'=>array('modifyTime'=>array('gte'=>(string)$since,'format'=>'epoch_second')));
		if (intval(_get($filter,'parentID',0))) throw new RuntimeException('目录过滤必须由混合检索入口解析');
		if ($clauses) $body['query']['bool']['filter'] = $clauses;
		$response = AiRagHttpJson::request('POST', $this->url.'/'.$this->index.'/_search', $body, array(), 12);
		$result = array();
		foreach ((array)_get(_get($response, 'hits', array()), 'hits', array()) as $rank => $hit) {
			$source = (array)_get($hit, '_source', array());
			$highlight = (array)_get($hit, 'highlight', array());
			$snippet = (string)_get(_get($highlight, 'content', array()), 0, '');
			if ($snippet === '') $snippet = mb_substr((string)_get($source, 'content', ''), 0, 240);
			$result[] = array(
				'fileID' => intval(_get($source, 'fileID', _get($hit, '_id', 0))),
				'name' => (string)_get($source, 'name', ''),
				'snippet' => $snippet,
				'score' => floatval(_get($hit, '_score', 0)),
				'rank' => $rank + 1,
			);
		}
		return $result;
	}

	public function deleteFile($fileID) {
		if ($this->readOnly) throw new RuntimeException('共享正文仅允许 elasticFulltext 修改');
		AiRagHttpJson::request('DELETE', $this->url.'/'.$this->index.'/_doc/'.intval($fileID), null, array(), 8, array(200, 404));
	}

	public function count($timeout = 5) {
		$result = AiRagHttpJson::request('GET', $this->url.'/'.$this->index.'/_count', null, array(), $timeout, array(200, 404));
		return intval(_get($result, 'count', 0));
	}

	public function rebuild() {
		if ($this->readOnly) throw new RuntimeException('共享正文仅允许 elasticFulltext 修改');
		AiRagHttpJson::request('DELETE', $this->url.'/'.$this->index, null, array(), 20, array(200, 404));
		return $this->ensureInfrastructure();
	}

	private function toUtf8($content) {
		if (!function_exists('mb_check_encoding') || mb_check_encoding($content, 'UTF-8')) return $content;
		return mb_convert_encoding($content, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1');
	}
}
