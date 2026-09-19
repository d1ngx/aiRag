<?php

class AiRagModelHub {
	public static function defaults($config = array()) {
		$url = AiRagChatClient::normalizeBaseUrl(_get($config, 'llmUrl', _get($config, 'embedUrl', 'https://api.siliconflow.cn/v1')));
		if ($url === '') $url = 'https://api.siliconflow.cn/v1';
		$key = trim((string)_get($config, 'llmApiKey', ''));
		if ($key === '') $key = trim((string)_get($config, 'embedApiKey', ''));
		$chat = trim((string)_get($config, 'llmModel', 'deepseek-ai/DeepSeek-V3'));
		$embed = AiRagEmbedClient::normalizeModel(_get($config, 'embedModel', 'BAAI/bge-m3'));
		$models = array(
			array('id' => $chat ?: 'deepseek-ai/DeepSeek-V3', 'name' => self::shortName($chat ?: 'DeepSeek-V3'), 'type' => 'chat', 'enabled' => 1, 'context' => 64000, 'status' => ''),
			array('id' => 'deepseek-ai/DeepSeek-R1', 'name' => 'DeepSeek-R1', 'type' => 'chat', 'enabled' => 1, 'context' => 64000, 'status' => ''),
			array('id' => $embed, 'name' => self::shortName($embed), 'type' => 'embed', 'enabled' => 1, 'context' => 8000, 'status' => ''),
			array('id' => 'BAAI/bge-reranker-v2-m3', 'name' => 'bge-reranker-v2-m3', 'type' => 'rerank', 'enabled' => 1, 'context' => 8000, 'status' => ''),
			array('id' => 'Kwai-Kolors/Kolors', 'name' => 'Kolors', 'type' => 'image', 'enabled' => 1, 'context' => 0, 'status' => ''),
			array('id' => 'FunAudioLLM/SenseVoiceSmall', 'name' => 'SenseVoice', 'type' => 'asr', 'enabled' => 1, 'context' => 0, 'status' => ''),
		);
		$seen = array();
		$uniq = array();
		foreach ($models as $model) {
			if (isset($seen[$model['id']])) continue;
			$seen[$model['id']] = 1;
			$uniq[] = $model;
		}
		return array(array(
			'id' => 'siliconflow',
			'name' => '硅基流动',
			'enabled' => 1,
			'url' => $url,
			'apiKey' => $key,
			'models' => $uniq,
		));
	}

	public static function parse($raw, $fallback = array()) {
		if (is_array($raw)) $data = $raw;
		else {
			$raw = trim((string)$raw);
			$data = $raw === '' ? array() : json_decode($raw, true);
		}
		if (!is_array($data) || !$data) $data = $fallback;
		$out = array();
		foreach ($data as $svc) {
			if (!is_array($svc)) continue;
			$models = array();
			foreach ((array)_get($svc, 'models', array()) as $model) {
				if (!is_array($model)) continue;
				$id = trim((string)_get($model, 'id', ''));
				if ($id === '') continue;
				$types = self::typesOf($model);
				$models[] = array(
					'id' => $id,
					'name' => trim((string)_get($model, 'name', self::shortName($id))),
					'type' => $types[0],
					'types' => $types,
					'enabled' => _get($model, 'enabled', 1) ? 1 : 0,
					'context' => self::contextSize(_get($model, 'context', 8000)),
					'status' => (string)_get($model, 'status', ''),
					'tested' => self::normTested(_get($model, 'tested', _get($model, 'status', ''))),
					'testMsg' => (string)_get($model, 'testMsg', ''),
				);
			}
			$out[] = array(
				'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)_get($svc, 'id', 'svc'.substr(md5(_get($svc, 'name', 'svc')), 0, 8))),
				'name' => trim((string)_get($svc, 'name', '未命名服务')),
				'enabled' => _get($svc, 'enabled', 1) ? 1 : 0,
				'url' => AiRagChatClient::normalizeBaseUrl(_get($svc, 'url', '')),
				'apiKey' => trim((string)_get($svc, 'apiKey', '')),
				'models' => $models,
			);
		}
		return $out;
	}

	public static function apply($config, $services) {
		$chatWant = trim((string)_get($config, 'llmModel', ''));
		$embedWant = trim((string)_get($config, 'embedModel', ''));
		$rerankWant = trim((string)_get($config, 'rerankModel', ''));
		$chat = $chatWant !== '' ? (self::find($services, $chatWant, 'chat') ?: self::first($services, 'chat')) : self::first($services, 'chat');
		$embed = $embedWant !== '' ? (self::find($services, $embedWant, 'embed') ?: self::first($services, 'embed')) : self::first($services, 'embed');
		$rerank = $rerankWant !== '' ? (self::find($services, $rerankWant, 'rerank') ?: self::first($services, 'rerank')) : self::first($services, 'rerank');
		$image = self::first($services, 'image');
		$asr = self::first($services, 'asr');
		if ($chat) {
			$config['llmUrl'] = $chat['url'];
			$config['llmApiKey'] = $chat['apiKey'];
			$config['llmModel'] = $chat['model']['id'];
		} else {
			$config['llmModel'] = '';
		}
		if ($embed) {
			$config['embedUrl'] = $embed['url'];
			$config['embedApiKey'] = $embed['apiKey'];
			$config['embedModel'] = AiRagEmbedClient::normalizeModel($embed['model']['id']);
		} elseif ($chat) {
			$config['embedUrl'] = $chat['url'];
			$config['embedApiKey'] = $chat['apiKey'];
		}
		$names = array();
		foreach ($services as $svc) {
			if (empty($svc['enabled'])) continue;
			foreach ((array)$svc['models'] as $model) {
				if (!empty($model['enabled']) && self::hasType($model, 'chat') && self::passed($model)) $names[] = $model['id'];
			}
		}
		$config['llmModels'] = implode(',', array_values(array_unique($names)));
		$config['imageModel'] = $image ? $image['model']['id'] : 'Kwai-Kolors/Kolors';
		$config['asrModel'] = $asr ? $asr['model']['id'] : 'FunAudioLLM/SenseVoiceSmall';
		$config['rerankModel'] = $rerank ? $rerank['model']['id'] : '';
		$config['modelServices'] = json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $config;
	}

	public static function contextSize($value) {
		$raw = trim((string)$value);
		if ($raw === '') return 8192;
		if (preg_match('/^(\d+(?:\.\d+)?)\s*k/i', $raw, $m)) {
			return max(1024, (int)round(floatval($m[1]) * 1024));
		}
		$n = intval($raw);
		if ($n <= 0) return 8192;
		if ($n <= 256) return max(1024, $n * 1024);
		return $n;
	}

	public static function first($services, $type) {
		foreach ((array)$services as $svc) {
			if (empty($svc['enabled'])) continue;
			foreach ((array)$svc['models'] as $model) {
				if (empty($model['enabled']) || !self::hasType($model, $type)) continue;
				if ($type === 'chat' && !self::passed($model)) continue;
				return array('url' => $svc['url'], 'apiKey' => $svc['apiKey'], 'name' => $svc['name'], 'model' => $model);
			}
		}
		return null;
	}

	public static function find($services, $modelId, $type = '') {
		foreach ((array)$services as $svc) {
			if (empty($svc['enabled'])) continue;
			foreach ((array)$svc['models'] as $model) {
				if ($model['id'] !== $modelId && $model['name'] !== $modelId) continue;
				if ($type !== '' && !self::hasType($model, $type)) continue;
				if (empty($model['enabled'])) continue;
				if (($type === 'chat' || $type === '') && self::hasType($model, 'chat') && !self::passed($model)) continue;
				return array('url' => $svc['url'], 'apiKey' => $svc['apiKey'], 'name' => $svc['name'], 'model' => $model);
			}
		}
		return null;
	}

	public static function chatModels($services) {
		$list = array();
		foreach ((array)$services as $svc) {
			if (empty($svc['enabled'])) continue;
			foreach ((array)$svc['models'] as $model) {
				if (empty($model['enabled']) || !self::hasType($model, 'chat')) continue;
				if (!self::passed($model)) continue;
				$list[] = array('id' => $model['id'], 'name' => $model['name'] ?: $model['id'], 'provider' => $svc['name']);
			}
		}
		return $list;
	}

	public static function passed($model) {
		if (empty($model['enabled'])) return false;
		return self::normTested(_get($model, 'tested', _get($model, 'status', ''))) !== 'fail';
	}

	public static function normTested($value) {
		$v = strtolower(trim((string)$value));
		if ($v === 'ok' || $v === 'pass' || $v === 'passed' || $v === '检测通过') return 'ok';
		if ($v === 'fail' || $v === 'failed' || $v === 'error' || strpos($v, 'fail') !== false || strpos($v, '失败') !== false) return 'fail';
		return '';
	}

	public static function typesOf($model) {
		$raw = _get($model, 'types', _get($model, 'type', ''));
		if (is_string($raw)) {
			$trim = trim($raw);
			if ($trim !== '' && (isset($trim[0]) && $trim[0] === '[' || strpos($trim, ',') !== false)) {
				$decoded = json_decode($trim, true);
				$raw = is_array($decoded) ? $decoded : preg_split('/[,;]+/', $trim);
			} else {
				$raw = array($trim);
			}
		}
		if (!is_array($raw)) $raw = array($raw);
		$out = array();
		foreach ($raw as $item) {
			$n = self::normType($item);
			if ($n && !in_array($n, $out, true)) $out[] = $n;
		}
		return $out ? $out : array(self::guessType(_get($model, 'id', '')));
	}

	public static function hasType($model, $type) {
		return in_array($type, self::typesOf($model), true);
	}

	public static function guessType($id) {
		$low = strtolower((string)$id);
		if (strpos($low, 'rerank') !== false) return 'rerank';
		if (strpos($low, 'bge') !== false || strpos($low, 'embed') !== false || strpos($low, 'e5-') !== false) return 'embed';
		if (strpos($low, 'kolors') !== false || strpos($low, 'flux') !== false || strpos($low, 'sdxl') !== false || strpos($low, 'stable-diffusion') !== false) return 'image';
		if (strpos($low, 'sensevoice') !== false || strpos($low, 'whisper') !== false || strpos($low, 'asr') !== false) return 'asr';
		return 'chat';
	}

	public static function normType($type) {
		$type = strtolower(trim((string)$type));
		$alias = array('llm' => 'chat', '对话' => 'chat', 'embedding' => 'embed', '嵌入' => 'embed', '重排序' => 'rerank', '生图' => 'image', '语音' => 'asr');
		if (isset($alias[$type])) return $alias[$type];
		return in_array($type, array('chat', 'embed', 'rerank', 'image', 'asr'), true) ? $type : 'chat';
	}

	public static function shortName($id) {
		$parts = explode('/', str_replace('\\', '/', (string)$id));
		return trim((string)end($parts)) ?: (string)$id;
	}

	public static function fetch($url, $apiKey) {
		$base = AiRagChatClient::normalizeBaseUrl($url);
		if ($base === '') throw new Exception('请先填写 API 地址');
		$headers = array();
		if ($apiKey !== '') $headers['Authorization'] = 'Bearer '.$apiKey;
		$res = AiRagHttpJson::request('GET', $base.'/models', null, $headers, 20, array(200));
		$list = array();
		foreach ((array)_get($res, 'data', $res) as $item) {
			if (!is_array($item)) continue;
			$id = trim((string)_get($item, 'id', ''));
			if ($id === '') continue;
			$meta = (array)_get($item, 'meta', _get($item, 'metadata', array()));
			$list[] = array(
				'id' => $id,
				'name' => self::shortName($id),
				'type' => self::guessType($id),
				'enabled' => 1,
				'context' => self::contextSize(_get($item, 'loaded_context_length', _get($item, 'context_length', _get($item, 'max_model_len', _get($meta, 'n_ctx', _get($meta, 'context_length', 16384)))))),
				'status' => '',
			);
		}
		if (!$list) throw new Exception('接口没有返回模型列表，请检查地址是否为 /v1 以及密钥是否有效');
		return $list;
	}

	public static function test($url, $apiKey, $modelId, $type) {
		$parts = is_array($type) ? $type : preg_split('/[,;]+/', (string)$type);
		$types = array();
		foreach ((array)$parts as $item) {
			$n = self::normType($item);
			if ($n && !in_array($n, $types, true)) $types[] = $n;
		}
		if (count($types) > 1) {
			$msgs = array();
			$fail = 0;
			foreach ($types as $one) {
				try {
					$msgs[] = self::test($url, $apiKey, $modelId, $one);
				} catch (Throwable $e) {
					$fail++;
					$msgs[] = $one.' 失败：'.$e->getMessage();
				}
			}
			$text = implode('；', $msgs);
			if ($fail) throw new Exception($text);
			return $text;
		}
		$base = AiRagChatClient::normalizeBaseUrl($url);
		$type = $types ? $types[0] : 'chat';
		$cfg = array(
			'llmUrl' => $base,
			'llmApiKey' => $apiKey,
			'llmModel' => $modelId,
			'embedUrl' => $base,
			'embedApiKey' => $apiKey,
			'embedModel' => $modelId,
			'embedDim' => 1024,
			'llmTemperature' => 0.2,
			'llmMaxTokens' => 64,
		);
		if ($type === 'chat') {
			$info = (new AiRagChatClient($cfg))->ping();
			return $info['model'].' 检测通过：'.$info['reply'];
		}
		if ($type === 'embed') {
			$info = (new AiRagEmbedClient($cfg))->ping();
			return $modelId.' 检测通过 dim='.$info['dim'];
		}
		if ($type === 'rerank') {
			$headers = array();
			if ($apiKey !== '') $headers['Authorization'] = 'Bearer '.$apiKey;
			AiRagHttpJson::request('POST', $base.'/rerank', array(
				'model' => $modelId,
				'query' => '合同',
				'documents' => array('采购合同', '请假条'),
				'top_n' => 1,
			), $headers, 20);
			return $modelId.' 重排序检测通过';
		}
		if ($type === 'image') {
			$headers = array();
			if ($apiKey !== '') $headers['Authorization'] = 'Bearer '.$apiKey;
			AiRagHttpJson::request('GET', $base.'/models', null, $headers, 20, array(200, 201));
			return $modelId.' 图片接口检测通过';
		}
		if ($type === 'asr') {
			$headers = array();
			if ($apiKey !== '') $headers['Authorization'] = 'Bearer '.$apiKey;
			AiRagHttpJson::request('GET', $base.'/models', null, $headers, 20, array(200, 201));
			return $modelId.' 语音接口检测通过';
		}
		throw new Exception('未知模型类型');
	}
}
