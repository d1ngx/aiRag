<?php

class AiRagHybridRetriever {
	private $elastic;
	private $milvus;
	private $embed;

	public function __construct($elastic, $milvus, $embed) {
		$this->elastic = $elastic;
		$this->milvus = $milvus;
		$this->embed = $embed;
	}

	public function search($words, $limit = 80, $esOn = true, $vectorOn = true, $fileIDs = null, $filter = null) {
		$limit = max(5, min(200, intval($limit)));
		$parsed = AiRagTextNormalizer::rewriteQuery($words);
		$keywordHeavy = AiRagTextNormalizer::keywordHeavy($words);
		$esHits = array();
		$vecHits = array();
		if ($esOn) {
			$esHits = $this->elastic->search($parsed['keyword'], $limit, $fileIDs, $filter);
			if (!$esHits && $parsed['keyword'] !== $parsed['raw']) $esHits = $this->elastic->search($parsed['raw'], $limit, $fileIDs, $filter);
		}
		if ($vectorOn) {
			$vector = $this->embed->embedQuery($parsed['raw']);
			$vecHits = $this->milvus->search($vector, $limit, $fileIDs, $filter);
		}
		$wes = $keywordHeavy ? 1.6 : 0.9;
		$wvec = $keywordHeavy ? 0.7 : 1.5;
		$rrf = array();
		$k = 60;
		foreach ($esHits as $hit) {
			$id = intval($hit['fileID']);
			if (!isset($rrf[$id])) $rrf[$id] = array('fileID' => $id, 'score' => 0, 'snippet' => '', 'name' => '', 'source' => array());
			$rrf[$id]['score'] += $wes / ($k + intval($hit['rank']));
			if ($hit['snippet'] && !$rrf[$id]['snippet']) $rrf[$id]['snippet'] = $hit['snippet'];
			if ($hit['name']) $rrf[$id]['name'] = $hit['name'];
			$rrf[$id]['source']['es'] = $hit;
		}
		foreach ($vecHits as $hit) {
			$id = intval($hit['fileID']);
			if (!isset($rrf[$id])) $rrf[$id] = array('fileID' => $id, 'score' => 0, 'snippet' => '', 'name' => '', 'source' => array());
			$rrf[$id]['score'] += $wvec / ($k + intval($hit['rank']));
			if (!$rrf[$id]['snippet'] && $hit['text']) $rrf[$id]['snippet'] = mb_substr(preg_replace('/\s+/', ' ', $hit['text']), 0, 240);
			if ($hit['name']) $rrf[$id]['name'] = $hit['name'];
			if (isset($hit['chunk'])) $rrf[$id]['chunk'] = intval($hit['chunk']);
			$rrf[$id]['source']['vector'] = $hit;
		}
		usort($rrf, function($a, $b) {
			if ($a['score'] == $b['score']) return 0;
			return $a['score'] > $b['score'] ? -1 : 1;
		});
		return array(
			'query' => $parsed,
			'keywordHeavy' => $keywordHeavy,
			'es' => $esHits,
			'vector' => $vecHits,
			'hybrid' => array_values($rrf),
		);
	}
}
