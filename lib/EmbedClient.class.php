<?php

class AiRagEmbedClient {
	private $url;
	private $model;
	private $apiKey;
	private $dim;

	public function __construct($config) {
		$this->url = self::normalizeBaseUrl(_get($config, 'embedUrl', ''));
		$this->model = self::normalizeModel(_get($config, 'embedModel', 'BAAI/bge-m3'));
		$this->apiKey = trim((string)_get($config, 'embedApiKey', ''));
		$this->dim = max(32, min(4096, intval(_get($config, 'embedDim', 1024))));
	}

	public static function normalizeBaseUrl($url) {
		$url = self::fixUrl($url);
		if ($url === '') return '';
		$url = preg_replace('#/(chat/)?completions$#i', '', $url);
		$url = preg_replace('#/embeddings$#i', '', $url);
		return rtrim($url, '/');
	}

	public static function fixUrl($url) {
		return AiRagHttpJson::fixUrl($url);
	}

	public static function normalizeModel($model) {
		$model = trim((string)$model);
		$alias = array(
			'bge-m3' => 'BAAI/bge-m3',
			'pro/bge-m3' => 'Pro/BAAI/bge-m3',
			'pro/baai/bge-m3' => 'Pro/BAAI/bge-m3',
			'bge-large-zh-v1.5' => 'BAAI/bge-large-zh-v1.5',
			'bge-reranker-v2-m3' => 'BAAI/bge-reranker-v2-m3',
		);
		$key = strtolower($model);
		return isset($alias[$key]) ? $alias[$key] : $model;
	}

	public function ping() {
		if ($this->url === '') return array('mode' => 'local-hash', 'dim' => $this->dim);
		$sample = $this->embed(array('ping'), 8);
		return array('mode' => 'api', 'dim' => count($sample[0]), 'model' => $this->model);
	}

	public function embed($texts, $timeout = 60) {
		$texts = array_values((array)$texts);
		if (!$texts) return array();
		if ($this->url === '') {
			$out = array();
			foreach ($texts as $text) $out[] = $this->hashEmbed($text);
			return $out;
		}
		$headers = array();
		if ($this->apiKey !== '') $headers['Authorization'] = 'Bearer '.$this->apiKey;
		$body = array('model' => $this->model, 'input' => $texts, 'encoding_format' => 'float');
		$endpoint = $this->url.'/embeddings';
		try {
			$response = AiRagHttpJson::request('POST', $endpoint, $body, $headers, $timeout, array(200, 201));
		} catch (Throwable $e) {
			throw new Exception('Embedding API '.$endpoint.' model='.$this->model.' ：'.$e->getMessage());
		}
		$data = _get($response, 'data', array());
		$vectors = array();
		foreach ((array)$data as $item) {
			$vectors[] = array_map('floatval', (array)_get($item, 'embedding', array()));
		}
		if (count($vectors) !== count($texts)) throw new Exception('Embedding count mismatch');
		return $vectors;
	}

	public function embedQuery($text) {
		$vectors = $this->embed(array($text), 20);
		return $vectors[0];
	}

	private function hashEmbed($text) {
		$dim = $this->dim;
		$vec = array_fill(0, $dim, 0.0);
		$text = function_exists('mb_strtolower') ? mb_strtolower(AiRagTextNormalizer::clean($text), 'UTF-8') : strtolower($text);
		$len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
		if ($len <= 0) return $vec;
		for ($i = 0; $i < $len; $i++) {
			$gram = function_exists('mb_substr') ? mb_substr($text, $i, 2, 'UTF-8') : substr($text, $i, 2);
			$h1 = crc32($gram);
			$h2 = crc32('x'.$gram);
			$idx1 = ($h1 & 0x7fffffff) % $dim;
			$idx2 = ($h2 & 0x7fffffff) % $dim;
			$vec[$idx1] += ($h1 & 1) ? 1.0 : -1.0;
			$vec[$idx2] += ($h2 & 2) ? 1.0 : -1.0;
		}
		$norm = 0.0;
		for ($i = 0; $i < $dim; $i++) $norm += $vec[$i] * $vec[$i];
		$norm = sqrt($norm);
		if ($norm > 0) {
			for ($i = 0; $i < $dim; $i++) $vec[$i] = $vec[$i] / $norm;
		}
		return $vec;
	}
}
