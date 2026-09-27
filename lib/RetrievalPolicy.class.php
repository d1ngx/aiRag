<?php

/** Request-scoped policy shared by keyword, vector and direct-reference reads. */
class AiRagRetrievalPolicy {
	private $extensions;
	private $maxBytes;
	private $decisions = array();

	public function __construct($config) {
		// An unavailable corpus configuration denies access instead of widening scope.
		$corpus = KodboxCorpusShare::pluginConfig('elasticFulltext');
		$defaults = 'doc,docx,docm,ppt,pptx,xls,xlsx,xlsm,pdf,odt,ods,odp,rtf,epub,txt,md,log,csv,json,xml,html,htm';
		$this->extensions = array_values(array_intersect(self::extensions($config, $defaults), self::extensions($corpus, '')));
		$this->maxBytes = min(max(1, intval(_get($config, 'maxFileSizeMB', 30))), max(1, intval(_get($corpus, 'maxFileSizeMB', 30)))) * 1024 * 1024;
	}

	private static function extensions($config, $fallback) {
		$parse = function($value) {
			if (is_array($value)) $value = implode(',', $value);
			return array_values(array_unique(array_filter(array_map(function($ext) {
				return preg_replace('/[^a-z0-9]/', '', $ext);
			}, preg_split('/[\s,;]+/', strtolower((string)$value))))));
		};
		$allow = $parse(_get($config, 'allowExtensions', $fallback));
		if (!$allow && $fallback !== '') $allow = $parse($fallback);
		return _get($config, 'extensionMode', 'allow') === 'deny' ? array_values(array_diff($allow, $parse(_get($config, 'denyExtensions', '')))) : $allow;
	}


	public function filters($filter = array()) {
		$filter = is_array($filter) ? $filter : array();
		$filter['extensions'] = $this->extensions;
		$filter['maxBytes'] = $this->maxBytes;
		// Exclusions discovered while fetching are bounded by the candidate budget, not library size.
		$filter['excludeFileIDs'] = array_values(array_unique((array)_get($filter, 'excludeFileIDs', array())));
		return $filter;
	}

	public function allowedIDs($ids) {
		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
		if (!$ids || !$this->extensions) return array();
		$missing = array_values(array_filter($ids, function($id) { return !array_key_exists($id, $this->decisions); }));
		foreach (array_chunk($missing, 500) as $batch) {
			$states = Model('plugin_airag_state')->where(array('fileID' => array('in', $batch)))->field('fileID,status,error')->select();
			if ($states === false) throw new RuntimeException('无法读取资源检索策略');
			$blocked = array();
			foreach ((array)$states as $state) {
				if (intval(_get($state, 'status', 0)) === 6 || (intval(_get($state, 'status', 0)) === 3 && _get($state, 'error', '') === '已禁用')) $blocked[intval($state['fileID'])] = true;
			}
			$rows = Model('File')->where(array('fileID' => array('in', $batch)))->field('fileID,name,size')->select();
			if ($rows === false) throw new RuntimeException('无法核对文件检索策略');
			foreach ($batch as $id) $this->decisions[$id] = false;
			foreach ((array)$rows as $row) {
				$id = intval($row['fileID']);
				$ext = strtolower(pathinfo((string)_get($row, 'name', ''), PATHINFO_EXTENSION));
				$size = intval(_get($row, 'size', 0));
				if (!isset($blocked[$id]) && $size > 0 && $size < $this->maxBytes && in_array($ext, $this->extensions, true)) $this->decisions[$id] = true;
			}
		}
		return array_values(array_filter($ids, function($id) { return !empty($this->decisions[$id]); }));
	}

	public function keepHits($hits) {
		$ids = array();
		foreach ((array)$hits as $hit) $ids[] = intval(_get($hit, 'fileID', 0));
		$allowed = array_flip($this->allowedIDs($ids));
		return array_values(array_filter((array)$hits, function($hit) use ($allowed) { return isset($allowed[intval(_get($hit, 'fileID', 0))]); }));
	}
}
