<?php

class AiRagHybridRetriever {
	private $elastic;
	private $milvus;
	private $embed;
	private $policy;

	public function __construct($elastic, $milvus, $embed, $policy = null) {
		$this->elastic = $elastic;
		$this->milvus = $milvus;
		$this->embed = $embed;
		$this->policy = $policy;
	}

	public function prepare($words, $vectorOn = true) {
		$parsed = AiRagTextNormalizer::rewriteQuery($words);
		$vector = null;
		if ($vectorOn) {
			try { $vector = $this->embed->embedQuery($parsed['raw'], 8); }
			catch (Throwable $e) { error_log('aiRag vector search skipped: '.$e->getMessage()); }
		}
		return array('parsed' => $parsed, 'keywordHeavy' => AiRagTextNormalizer::keywordHeavy($words), 'vector' => $vector);
	}

	public function search($words, $limit = 80, $esOn = true, $vectorOn = true, $fileIDs = null, $filter = null, $prepared = null) {
		$limit = max(5, min(200, intval($limit)));
		$filter = is_array($filter) ? $filter : array();
		if ($this->policy) {
			$filter = $this->policy->filters($filter);
			if (empty($filter['extensions'])) $fileIDs = array();
			elseif ($fileIDs !== null) $fileIDs = $this->policy->allowedIDs($fileIDs);
		}
		$parent = intval(_get($filter, 'parentID', 0));
		if ($parent) unset($filter['parentID']);
		if ($fileIDs !== null && !$fileIDs) {
			$parsed = is_array($prepared) ? $prepared['parsed'] : AiRagTextNormalizer::rewriteQuery($words);
			$keywordHeavy = is_array($prepared) ? $prepared['keywordHeavy'] : AiRagTextNormalizer::keywordHeavy($words);
			return array('query'=>$parsed,'keywordHeavy'=>$keywordHeavy,'es'=>array(),'vector'=>array(),'hybrid'=>array());
		}
		if (!is_array($prepared)) $prepared = $this->prepare($words, $vectorOn);
		$parsed = $prepared['parsed'];
		$keywordHeavy = $prepared['keywordHeavy'];
		$esHits = array();
		$vecHits = array();
		if ($esOn) {
			try {
				$esHits = $this->eligibleHits(function($size, $nextFilter) use ($parsed, $fileIDs, $parent) { return $this->keywordHits($parsed['keyword'], $size, $fileIDs, $nextFilter, $parent); }, $limit, $filter);
				if (!$esHits && $parsed['keyword'] !== $parsed['raw']) $esHits = $this->eligibleHits(function($size, $nextFilter) use ($parsed, $fileIDs, $parent) { return $this->keywordHits($parsed['raw'], $size, $fileIDs, $nextFilter, $parent); }, $limit, $filter);
			} catch (Throwable $e) {
				$esHits = array();
				error_log('aiRag keyword search skipped: '.$e->getMessage());
			}
		}
		if ($vectorOn && is_array(_get($prepared, 'vector', null))) {
			try { $vecHits = $this->eligibleHits(function($size, $nextFilter) use ($prepared, $fileIDs, $parent) { return $this->vectorHits($prepared['vector'], $size, $fileIDs, $nextFilter, $parent); }, $limit, $filter); }
			catch (Throwable $e) {
				$vecHits = array();
				error_log('aiRag vector search skipped: '.$e->getMessage());
			}
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
				if ($rrf[$id]['snippet'] === '') {
					$text = (string)_get($hit, 'text', '');
					$rrf[$id]['snippet'] = function_exists('mb_substr') ? mb_substr($text, 0, 300, 'UTF-8') : substr($text, 0, 900);
				}
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

	private function eligibleHits($fetch, $limit, $filter) {
		if (!$this->policy) return $fetch($limit, $filter);
		$out = array();
		$seen = array();
		// At most four bounded fetches; never enumerate the entire disabled-file table.
		for ($round = 0; $round < 4; $round++) {
			$need = max(1, $limit - $this->fileCount($out));
			$nextFilter = $filter;
			$nextFilter['excludeFileIDs'] = array_values(array_unique(array_merge((array)_get($filter, 'excludeFileIDs', array()), array_keys($seen))));
			$batch = (array)$fetch($need, $nextFilter);
			$fresh = array();
			foreach ($batch as $hit) if (!isset($seen[intval($hit['fileID'])])) $fresh[] = $hit;
			if (!$fresh) break;
			foreach ($fresh as $hit) $seen[intval($hit['fileID'])] = true;
			$out = array_merge($out, $this->policy->keepHits($fresh));
			if ($this->fileCount($out) >= $limit || count($batch) < $need) break;
		}
		return $this->freshRank($out);
	}

	private function keywordHits($words, $limit, $fileIDs, $filter, $parent) {
		if ($parent && class_exists('KodboxCorpusShare')) {
			$scope = KodboxCorpusShare::folderFileIDs($parent, 4000);
			if (!empty($scope['complete'])) {
				$ids = (array)_get($scope, 'ids', array());
				if ($fileIDs !== null) {
					$want = array_flip(array_map('intval', (array)$fileIDs));
					$ids = array_values(array_filter($ids, function ($id) use ($want) { return isset($want[intval($id)]); }));
				}
				if (!$ids) return array();
				return $this->elastic->search($words, $limit, $ids, $filter);
			}
		}
		$esFilter = $filter;
		if ($parent) $esFilter['ancestorID'] = $parent;
		$hits = $this->elastic->search($words, $limit, $fileIDs, $esFilter);
		if (!$parent || !class_exists('KodboxCorpusShare')) return $hits;
		$rawCount = count($hits);
		$hits = $this->hitsInside($parent, $hits, $fileIDs);
		if ($rawCount >= $limit && count($hits) < $rawCount) {
			$wider = $this->elastic->search($words, min(400, $limit * 2), $fileIDs, $esFilter);
			$hits = array_slice($this->hitsInside($parent, $wider, $fileIDs), 0, $limit);
		}
		if (!method_exists($this->elastic, 'searchPage')) return $this->scoreRank($hits);
		$seen = array();
		foreach ($hits as $hit) $seen[intval($hit['fileID'])] = true;
		$after = null;
		$pageFilter = $filter;
		$pageFilter['missingAncestors'] = 1;
		if ($fileIDs !== null) $pageFilter['fileIDs'] = array_values(array_map('intval', (array)$fileIDs));
		for ($i = 0; $i < 4; $i++) {
			$page = $this->elastic->searchPage($words, 200, $after, $pageFilter);
			if (empty($page['hits'])) break;
			$after = _get($page, 'after', null);
			$worst = -1;
			if (count($hits) >= $limit) {
				$worst = floatval(_get($hits[0], 'score', 0));
				foreach ($hits as $hit) {
					$score = floatval(_get($hit, 'score', 0));
					if ($score < $worst) $worst = $score;
				}
			}
			if ($worst >= 0 && floatval(_get($page['hits'][0], 'score', 0)) <= $worst) break;
			$ids = array();
			foreach ($page['hits'] as $hit) {
				if ($worst >= 0 && floatval(_get($hit, 'score', 0)) <= $worst) break;
				$ids[] = intval($hit['fileID']);
			}
			$allow = array_flip(KodboxCorpusShare::keepInFolder($parent, $ids));
			$scope = $fileIDs === null ? null : array_flip(array_map('intval', (array)$fileIDs));
			foreach ($page['hits'] as $hit) {
				$id = intval($hit['fileID']);
				if (!$id || isset($seen[$id]) || !isset($allow[$id])) continue;
				if ($scope !== null && !isset($scope[$id])) continue;
				if ($worst >= 0 && floatval(_get($hit, 'score', 0)) <= $worst) break;
				$seen[$id] = true;
				$hits[] = $hit;
			}
			$hits = $this->scoreRank($hits);
			if (count($hits) > $limit) $hits = array_slice($hits, 0, $limit);
			if (empty($page['more'])) break;
		}
		return $this->scoreRank(array_slice($hits, 0, $limit));
	}

	private function vectorHits($vector, $limit, $fileIDs, $filter, $parent) {
		if (!$parent) return $this->freshRank($this->keepChunks($this->groupedSearch($vector, $limit, $fileIDs, $filter), $limit));
		$scopeIds = null;
		if (class_exists('KodboxCorpusShare')) {
			$scope = KodboxCorpusShare::folderFileIDs($parent, 4000);
			if (!empty($scope['complete'])) {
				$scopeIds = (array)_get($scope, 'ids', array());
				if ($fileIDs !== null) {
					$want = array_flip(array_map('intval', (array)$fileIDs));
					$scopeIds = array_values(array_filter($scopeIds, function ($id) use ($want) { return isset($want[intval($id)]); }));
				}
				if (!$scopeIds) return array();
			}
		}
		if ($scopeIds === null && method_exists($this->milvus, 'hasAncestorField') && $this->milvus->hasAncestorField()) {
			$hits = $this->groupedSearch($vector, $limit, $fileIDs, array_merge($filter, array('ancestorID' => $parent)));
			if (!is_array($hits)) $hits = array();
			if ($hits && class_exists('KodboxCorpusShare')) $hits = $this->hitsInside($parent, $hits, $fileIDs);
			if (class_exists('KodboxCorpusShare')) {
				$more = $this->groupedSearch($vector, max($limit, 80), $fileIDs, array_merge($filter, array('ancestorMissing' => 1)));
				if (is_array($more)) {
					$ids = array();
					foreach ($more as $hit) $ids[] = intval(_get($hit, 'fileID', 0));
					$allow = array_flip(KodboxCorpusShare::keepInFolder($parent, $ids));
					foreach ($more as $hit) {
						$id = intval(_get($hit, 'fileID', 0));
						if ($id && isset($allow[$id])) $hits[] = $hit;
					}
				}
			}
			return $this->freshRank($this->keepChunks($hits, $limit));
		}
		$hits = array();
		$exclude = array();
		$searchIds = $scopeIds !== null ? $scopeIds : $fileIDs;
		for ($round = 0; $round < 4 && $this->fileCount($hits) < $limit; $round++) {
			$roundFilter = $filter;
			if ($exclude) $roundFilter['excludeFileIDs'] = array_values(array_unique(array_merge((array)_get($filter, 'excludeFileIDs', array()), $exclude)));
			$need = $limit - $this->fileCount($hits);
			$ask = $scopeIds !== null ? $need : max($need, 80);
			$batch = $this->groupedSearch($vector, $ask, $searchIds, $roundFilter);
			if ($batch === null) {
				if ($scopeIds === null) return $this->freshRank($hits);
				$scopeIds = null;
				$searchIds = $fileIDs;
				$exclude = array();
				$hits = array();
				continue;
			}
			if (!$batch) break;
			$allow = null;
			if ($scopeIds === null && class_exists('KodboxCorpusShare')) {
				$ids = array();
				foreach ($batch as $hit) $ids[] = intval($hit['fileID']);
				$allow = array_flip(KodboxCorpusShare::keepInFolder($parent, $ids));
			}
			$seen = array();
			foreach ($hits as $hit) $seen[intval($hit['fileID'])] = true;
			foreach ($batch as $hit) {
				$id = intval($hit['fileID']);
				if (!$id) continue;
				if (!isset($seen[$id])) {
					$exclude[] = $id;
					$seen[$id] = true;
					if ($allow !== null && !isset($allow[$id])) continue;
				} else if ($allow !== null && !isset($allow[$id])) {
					continue;
				}
				$hits[] = $hit;
				if ($this->fileCount($hits) >= $limit) break;
			}
			if (count($batch) < $ask) break;
		}
		return $this->freshRank($this->keepChunks($hits, $limit));
	}

	private function groupedSearch($vector, $limit, $fileIDs, $filter) {
		try {
			return $this->milvus->search($vector, $limit, $fileIDs, $filter, 0, true);
		} catch (Throwable $e) {
			try {
				return $this->milvus->search($vector, $limit, $fileIDs, $filter, 0, false);
			} catch (Throwable $again) {
				error_log('aiRag vector search skipped: '.$again->getMessage());
				return null;
			}
		}
	}

	private function fileCount($hits) {
		$seen = array();
		foreach ((array)$hits as $hit) {
			$id = intval(_get($hit, 'fileID', 0));
			if ($id) $seen[$id] = true;
		}
		return count($seen);
	}

	private function keepChunks($hits, $limit) {
		$groups = array();
		foreach ((array)$hits as $hit) {
			$id = intval(_get($hit, 'fileID', 0));
			if (!$id) continue;
			if (!isset($groups[$id])) $groups[$id] = array();
			$groups[$id][] = $hit;
		}
		$order = array_keys($groups);
		usort($order, function ($a, $b) use ($groups) {
			$sa = 0;
			$sb = 0;
			foreach ($groups[$a] as $hit) $sa = max($sa, floatval(_get($hit, 'score', 0)));
			foreach ($groups[$b] as $hit) $sb = max($sb, floatval(_get($hit, 'score', 0)));
			if ($sa == $sb) return $a - $b;
			return $sa > $sb ? -1 : 1;
		});
		$out = array();
		$n = 0;
		foreach ($order as $id) {
			if ($n >= $limit) break;
			$n++;
			foreach ($groups[$id] as $hit) $out[] = $hit;
		}
		return $out;
	}

	private function hitsInside($parent, $hits, $fileIDs) {
		$ids = array();
		foreach ((array)$hits as $hit) $ids[] = intval(_get($hit, 'fileID', 0));
		$allow = array_flip(KodboxCorpusShare::keepInFolder($parent, $ids));
		$scope = $fileIDs === null ? null : array_flip(array_map('intval', (array)$fileIDs));
		$out = array();
		foreach ((array)$hits as $hit) {
			$id = intval(_get($hit, 'fileID', 0));
			if (!$id || !isset($allow[$id])) continue;
			if ($scope !== null && !isset($scope[$id])) continue;
			$out[] = $hit;
		}
		return $out;
	}

	private function scoreRank($hits) {
		usort($hits, function ($a, $b) {
			$sa = floatval(_get($a, 'score', 0));
			$sb = floatval(_get($b, 'score', 0));
			if ($sa == $sb) return intval($a['fileID']) - intval($b['fileID']);
			return $sa > $sb ? -1 : 1;
		});
		return $this->freshRank($hits);
	}

	private function freshRank($hits) {
		$out = array();
		$rank = 0;
		$seen = array();
		foreach ((array)$hits as $hit) {
			$id = intval(_get($hit, 'fileID', 0));
			if (!isset($seen[$id])) {
				$rank++;
				$seen[$id] = $rank;
			}
			$hit['rank'] = $seen[$id];
			$out[] = $hit;
		}
		return $out;
	}
}
