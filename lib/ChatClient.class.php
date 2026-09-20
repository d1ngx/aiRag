<?php

class AiRagChatClient {
	private $url;
	private $model;
	private $apiKey;
	private $temperature;
	private $maxTokens;
	public $lastReasoning = '';
	public $lastModel = '';
	public $lastUsage = array();
	public $lastElapsedMs = 0;
	public $lastFirstMs = 0;
	private $requestStartedAt = 0;

	public $lastTrimmed = false;
	public $lastContext = 0;

	public function __construct($config) {
		$llm = self::normalizeBaseUrl(_get($config, 'llmUrl', ''));
		$embed = AiRagEmbedClient::normalizeBaseUrl(_get($config, 'embedUrl', ''));
		$this->url = $llm !== '' ? $llm : $embed;
		$this->model = trim((string)_get($config, 'llmModel', 'deepseek-ai/DeepSeek-V3'));
		$key = trim((string)_get($config, 'llmApiKey', ''));
		$this->apiKey = $key !== '' ? $key : trim((string)_get($config, 'embedApiKey', ''));
		$this->temperature = max(0, min(2, floatval(_get($config, 'llmTemperature', 0.3))));
		$this->maxTokens = max(256, min(8192, intval(_get($config, 'llmMaxTokens', 2048))));
	}

	public static function normalizeBaseUrl($url) {
		$url = self::fixUrl($url);
		if ($url === '') return '';
		$url = preg_replace('#/embeddings$#i', '', $url);
		$url = preg_replace('#/(chat/)?completions$#i', '', $url);
		return rtrim($url, '/');
	}

	public static function fixUrl($url) {
		return AiRagHttpJson::fixUrl($url);
	}

	public function enabled() {
		return $this->url !== '' && $this->model !== '';
	}

	public function ping() {
		$result = $this->chat(array(array('role' => 'user', 'content' => '只回复ok')), 8, 20);
		return array('model' => $this->lastModel ?: $this->model, 'reply' => mb_substr((string)$result, 0, 80));
	}

	public function chat($messages, $maxTokens = null, $timeout = 90, $options = array()) {
		if (is_array($timeout)) {
			$options = $timeout;
			$timeout = 90;
		}
		if (!$this->enabled()) throw new Exception('未配置对话模型，请填写 LLM API 地址和模型名');
		$headers = array();
		if ($this->apiKey !== '') $headers['Authorization'] = 'Bearer '.$this->apiKey;
		$model = trim((string)_get($options, 'model', $this->model));
		if ($model === '') $model = $this->model;
		$thinking = !empty($options['thinking']);
		$this->lastUsage = array('prompt'=>0,'output'=>0,'total'=>0,'cache'=>0);
		$this->lastElapsedMs = 0;
		$this->lastFirstMs = 0;
		$this->lastReasoning = '';
		$this->lastModel = $model;
		$this->lastTrimmed = false;
		$context = AiRagModelHub::contextSize(_get($options, 'context', 0));
		$maxOut = $maxTokens === null ? ($thinking ? min($this->maxTokens, 2048) : $this->maxTokens) : intval($maxTokens);
		$windows = array($context);
		$endpoint = $this->url.'/chat/completions';
		$started = microtime(true);
		$this->requestStartedAt = $started;
		$lastError = null;
		$text = '';
		$usedMessages = $messages;
		$attempt = 0;
		while ($attempt < 3) {
			$tryCtx = $windows[$attempt];
			$attempt++;
			$fit = self::fitToContext($messages, $tryCtx, $maxOut);
			$usedMessages = $fit['messages'];
			$usedMax = $fit['maxTokens'];
			if (!empty($fit['trimmed'])) $this->lastTrimmed = true;
			$this->lastContext = $tryCtx;
			$body = array(
				'model' => $model,
				'messages' => array_values($usedMessages),
				'temperature' => $thinking ? min($this->temperature, 0.5) : $this->temperature,
				'max_tokens' => $usedMax,
				'stream' => !empty($options['stream']),
			);
			if ($thinking) $body['enable_thinking'] = true;
			if (!empty($options['stream'])) $body['stream_options'] = array('include_usage' => true);
			try {
				if (!empty($options['stream'])) {
					$text = $this->chatViaStream($endpoint, $body, $headers, $timeout, $options);
				} else {
					$text = $this->chatViaJson($endpoint, $body, $headers, $timeout, $thinking);
				}
				$lastError = null;
				break;
			} catch (Throwable $e) {
				$lastError = $e;
				if (!self::isContextOverflow($e->getMessage())) {
					throw new Exception('LLM '.$endpoint.' model='.$model.' ：'.$e->getMessage());
				}
				$parsed = self::parseContextError($e->getMessage());
				$next = intval(_get($parsed, 'n_ctx', 0));
				if ($next < 1024) $next = (int)floor($tryCtx * 0.72);
				$next = max(2048, $next);
				if ($next >= $tryCtx) $next = max(2048, $tryCtx - 1024);
				if ($attempt >= 3) break;
				$windows[$attempt] = $next;
			}
		}
		if ($lastError) {
			throw new Exception('LLM '.$endpoint.' model='.$model.' ：上下文超出（按 '.$this->lastContext.' 截断后仍失败）。请把模型卡片里的上下文改成与引擎加载值一致，LM Studio 常见 4096 或 8192。原始错误：'.$lastError->getMessage());
		}
		$this->lastElapsedMs = intval((microtime(true) - $started) * 1000);
		if ($this->lastFirstMs <= 0) $this->lastFirstMs = $this->lastElapsedMs;
		$this->fillUsage($usedMessages, $text);
		if ($text === '') throw new Exception('模型没有返回内容');
		return $text;
	}

	private function chatViaJson($endpoint, $body, $headers, $timeout, $thinking) {
		$body['stream'] = false;
		try {
			$response = AiRagHttpJson::request('POST', $endpoint, $body, $headers, $timeout, array(200, 201));
		} catch (Throwable $e) {
			if ($thinking && isset($body['enable_thinking'])) {
				unset($body['enable_thinking']);
				$response = AiRagHttpJson::request('POST', $endpoint, $body, $headers, $timeout, array(200, 201));
			} else {
				throw $e;
			}
		}
		$message = _get(_get(_get($response, 'choices', array()), 0, array()), 'message', array());
		$text = (string)_get($message, 'content', '');
		$reason = (string)_get($message, 'reasoning_content', _get($message, 'reasoning', ''));
		if ($text === '' && $reason !== '') $text = $reason;
		$this->lastReasoning = $reason;
		$this->takeUsage(_get($response, 'usage', array()));
		return $text;
	}

	private function chatViaStream($endpoint, $body, $headers, $timeout, $options) {
		$onDelta = _get($options, 'onDelta');
		$text = '';
		$reason = '';
		$fail = '';
		$self = $this;
		$consume = function($line) use ($self, $onDelta, &$text, &$reason, &$fail) {
			if ($fail !== '') return;
			$payload = $line;
			if (stripos($line, 'data:') === 0) $payload = trim(substr($line, 5));
			$payload = trim((string)$payload);
			if ($payload === '' || $payload === '[DONE]') return;
			$json = json_decode($payload, true);
			if (!is_array($json)) return;
			if (!empty($json['usage'])) $self->takeUsage($json['usage']);
			elseif (isset($json['prompt_eval_count']) || isset($json['eval_count'])) $self->takeUsage($json);
			$err = _get($json, 'error', '');
			if (is_array($err)) $err = (string)_get($err, 'message', json_encode($err));
			if ($err) { $fail = (string)$err; return; }
			$choice = _get(_get($json, 'choices', array()), 0, array());
			if (is_array($choice) && array_key_exists('delta', $choice)) {
				$delta = (array)_get($choice, 'delta', array());
			} elseif (is_array($choice) && array_key_exists('message', $choice) && $text === '') {
				$delta = (array)_get($choice, 'message', array());
			} else {
				$delta = (array)_get($json, 'message', array());
			}
			$piece = (string)_get($delta, 'content', '');
			$think = (string)_get($delta, 'reasoning_content', _get($delta, 'reasoning', ''));
			if ($piece === '' && $think === '') return;
			if ($self->lastFirstMs <= 0 && $self->requestStartedAt > 0) {
				$self->lastFirstMs = max(1, intval((microtime(true) - $self->requestStartedAt) * 1000));
			}
			$text .= $piece;
			$reason .= $think;
			if (is_callable($onDelta)) $onDelta($piece, $think);
		};
		$run = function($payload) use ($endpoint, $headers, $timeout, $consume, &$fail, &$text, &$reason) {
			$fail = '';
			AiRagHttpJson::stream($endpoint, $payload, $headers, $timeout, $consume);
			if ($fail !== '') throw new Exception($fail);
		};
		try {
			$run($body);
		} catch (Throwable $e) {
			$msg = $e->getMessage();
			if (isset($body['stream_options']) && (stripos($msg, 'stream_options') !== false || preg_match('/\b400\b/', $msg))) {
				unset($body['stream_options']);
				$text = ''; $reason = '';
				try { $run($body); goto streamed; } catch (Throwable $e2) { $e = $e2; }
			}
			if (isset($body['enable_thinking'])) {
				unset($body['enable_thinking'], $body['stream_options']);
				$text = ''; $reason = '';
				try { $run($body); goto streamed; } catch (Throwable $e2) { $e = $e2; }
			}
			unset($body['stream'], $body['stream_options']);
			$full = $this->chatViaJson($endpoint, $body, $headers, $timeout, !empty($options['thinking']));
			if (is_callable($onDelta) && $full !== '' && $text === '') $onDelta($full, '');
			return $full !== '' ? $full : $text;
		}
		streamed:
		$this->lastReasoning = $reason;
		if ($text === '' && $reason !== '') $text = $reason;
		return $text;
	}

	public function takeUsage($usage) {
		$usage = (array)$usage;
		$details = (array)_get($usage, 'prompt_tokens_details', array());
		$prompt = intval(_get($usage, 'prompt_tokens', _get($usage, 'input_tokens', _get($usage, 'prompt_eval_count', 0))));
		$completion = intval(_get($usage, 'completion_tokens', _get($usage, 'output_tokens', _get($usage, 'eval_count', 0))));
		$total = intval(_get($usage, 'total_tokens', $prompt + $completion));
		if ($prompt || $completion || $total) {
			$this->lastUsage = array(
				'prompt' => $prompt,
				'output' => $completion,
				'total' => $total ?: ($prompt + $completion),
				'cache' => intval(_get($details, 'cached_tokens', _get($usage, 'cached_tokens', 0))),
				'est' => 0,
			);
		}
	}

	public static function estimateTokens($text) {
		$text = (string)$text;
		if ($text === '') return 0;
		if (preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text, $m)) {
			$cjk = count($m[0]);
		} else {
			$cjk = 0;
		}
		$other = max(0, mb_strlen($text) - $cjk);
		return max(1, (int)ceil($cjk / 1.7 + $other / 4));
	}

	private function fillUsage($messages, $text) {
		if (intval(_get($this->lastUsage, 'total', 0)) > 0 || intval(_get($this->lastUsage, 'output', 0)) > 0) return;
		$prompt = '';
		foreach ((array)$messages as $item) $prompt .= (string)_get($item, 'content', '');
		$in = self::estimateTokens($prompt);
		$out = self::estimateTokens($text.' '.$this->lastReasoning);
		$this->lastUsage = array('prompt' => $in, 'output' => $out, 'total' => $in + $out, 'cache' => 0, 'est' => 1);
	}

	public static function isContextOverflow($message) {
		$e = (string)$message;
		return (bool)preg_match('/exceed_context_size|exceeds the available context size|context size (?:has been )?exceeded|n_prompt_tokens|context.?(?:length|size)|maximum context|too many tokens|n_keep|out of context/i', $e);
	}

	public static function parseContextError($message) {
		$e = (string)$message;
		$nCtx = 0;
		$nPrompt = 0;
		if (preg_match('/request \((\d+) tokens\) exceeds the available context size \((\d+) tokens\)/i', $e, $m)) {
			$nPrompt = intval($m[1]);
			$nCtx = intval($m[2]);
		}
		if (!$nCtx && preg_match('/"n_ctx"\s*:\s*(\d+)/', $e, $m)) $nCtx = intval($m[1]);
		if (!$nPrompt && preg_match('/"n_prompt_tokens"\s*:\s*(\d+)/', $e, $m)) $nPrompt = intval($m[1]);
		return array('n_ctx' => $nCtx, 'n_prompt' => $nPrompt);
	}

	public static function fitTokens($text) {
		$text = (string)$text;
		if ($text === '') return 0;
		if (preg_match_all('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $text, $m)) {
			$cjk = count($m[0]);
		} else {
			$cjk = 0;
		}
		$other = max(0, mb_strlen($text) - $cjk);
		return $cjk + (int)ceil($other / 4);
	}

	public static function fitToContext($messages, $context, $maxTokens) {
		$context = AiRagModelHub::contextSize($context);
		$usable = max(1024, $context - 256);
		$maxTokens = max(128, min(intval($maxTokens), (int)floor($usable * 0.10), 1024));
		// Tokenizers and chat templates vary by engine; keep a larger safety margin.
		$budget = (int)floor(($usable - $maxTokens) * 0.70);
		if ($budget < 512) {
			$maxTokens = 128;
			$budget = max(400, $usable - 256);
		}
		$messages = array_values((array)$messages);
		$trimmed = false;
		$measure = function($msgs) {
			$n = 48;
			foreach ((array)$msgs as $item) $n += 16 + AiRagChatClient::fitTokens(_get($item, 'content', ''));
			return $n;
		};
		$guard = 0;
		while ($measure($messages) > $budget && $guard < 48) {
			$guard++;
			$last = count($messages) - 1;
			if ($last < 0) break;
			$user = (string)_get($messages[$last], 'content', '');
			$over = $measure($messages) - $budget;
			if (mb_strlen($user) > 200) {
				$ratio = $budget / max(1, $measure($messages));
				$keep = max(180, (int)floor(mb_strlen($user) * min(0.85, max(0.45, $ratio))));
				if ($over > 0 && $keep >= mb_strlen($user) - 8) $keep = max(180, mb_strlen($user) - max(80, (int)($over * 1.2)));
				$messages[$last]['content'] = rtrim(mb_substr($user, 0, $keep))."\n\n[资料过长，已按模型上下文截断]";
				$trimmed = true;
				continue;
			}
			$dropped = false;
			for ($i = 1; $i < $last; $i++) {
				array_splice($messages, $i, 1);
				$dropped = true;
				$trimmed = true;
				break;
			}
			if (!$dropped) {
				$messages[$last]['content'] = mb_substr($user, 0, max(80, min(mb_strlen($user), $budget)));
				$trimmed = true;
				break;
			}
		}
		return array('messages' => $messages, 'maxTokens' => $maxTokens, 'trimmed' => $trimmed, 'budget' => $budget);
	}
}
