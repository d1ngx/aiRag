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
		$filter = is_array($filter) ? $filter : array();
		$parent = intval(_get($filter, 'parentID', 0));
		if ($parent) {
			$ids = array(); $after = 0;
			do {
				$rows = Model('Source')->where(array('parentID'=>$parent,'isFolder'=>0,'isDelete'=>0,'sourceID'=>array('gt',$after)))->field('fileID,sourceID')->order('sourceID asc')->limit(500)->select();
				foreach ((array)$rows as $row) {$after=intval($row['sourceID']); if (intval($row['fileID'])>0) $ids[]=intval($row['fileID']);}
			} while (count((array)$rows)===500);
			$fileIDs = $fileIDs === null ? array_values(array_unique($ids)) : array_values(array_intersect($fileIDs,$ids));
			unset($filter['parentID']);
		}
		$parsed = AiRagTextNormalizer::rewriteQuery($words);
		$keywordHeavy = AiRagTextNormalizer::keywordHeavy($words);
		$esHits = array();
		$vecHits = array();
		if ($fileIDs !== null && !$fileIDs) return array('query'=>$parsed,'keywordHeavy'=>$keywordHeavy,'es'=>array(),'vector'=>array(),'hybrid'=>array());
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
			$chunk = intval(_get($hit, 'chunk', 0));
			if (!isset($rrf[$id]['chunks'])) $rrf[$id]['chunks'] = array();
			$rrf[$id]['chunks'][$chunk] = $hit;
			if (!isset($rrf[$id]['source']['vector'])) {
				$rrf[$id]['score'] += $wvec / ($k + intval($hit['rank']));
				$rrf[$id]['snippet'] = (string)_get($hit, 'text', '');
				if ($hit['name']) $rrf[$id]['name'] = $hit['name'];
				$rrf[$id]['chunk'] = $chunk;
				$rrf[$id]['source']['vector'] = $hit;
			}
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
