<?php

class AiRagHttpJson {
	public static function fixUrl($url) {
		$url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
		$url = str_replace('\\', '/', $url);
		$url = preg_replace('#^(https?):/([^/])#i', '$1://$2', $url);
		$url = preg_replace('#^(https?):/{3,}#i', '$1://', $url);
		$url = preg_replace('#(?<!:)/{2,}#', '/', $url);
		return rtrim($url, '/');
	}

	public static function explain($error, $url = '') {
		$e = (string)$error;
		$hint = '';
		if (stripos($e, 'Port number was not') !== false || preg_match('#https?:/[^/]#', $url.$e)) {
			$hint = '地址里的 :// 少了一杠（变成了 http:/host）。请改成 http:// 或 https:// 后再测。';
		} elseif (stripos($e, 'Could not resolve host') !== false || stripos($e, 'not resolve') !== false) {
			$hint = '域名解析失败。Kodbox 容器里应填 Docker 服务名，例如 http://elasticsearch:9200、http://milvus:19530，不要填 127.0.0.1。';
		} elseif (stripos($e, 'Connection refused') !== false || stripos($e, 'timed out') !== false || stripos($e, 'timedout') !== false) {
			$hint = '端口连不上。确认对应容器已启动，并和 kodbox-app 在同一 compose 网络。';
		} elseif (preg_match('/401|Unauthorized|invalid.?api.?key|incorrect api key/i', $e)) {
			$hint = 'API 密钥无效。到硅基流动控制台复制 sk- 开头的密钥，对话和向量可以各填各的。';
		} elseif (stripos($e, 'tika') !== false || stripos($e, 'parse_exception') !== false || stripos($e, 'customXml') !== false) {
			$hint = 'Office 文档含未声明的 customXml 附件，Elasticsearch/Tika 拒绝解析。';
		} elseif (preg_match('/404|does not exist|Not Found|unknown model/i', $e)) {
			$hint = '路径或模型 ID 不对。对话填 https://api.siliconflow.cn/v1 和完整 ID（如 deepseek-ai/DeepSeek-V3）；向量用 BAAI/bge-m3，不要把 chat/completions 接到 embedding。';
		} elseif (preg_match('/exceed_context_size|exceeds the available context size|context size (?:has been )?exceeded|n_prompt_tokens|n_ctx|context.?(?:length|size)|maximum context|too many tokens/i', $e)) {
			$hint = '输入超过了模型上下文。插件会按引擎返回的窗口截断资料后再试。请把模型卡片的上下文改成 LM Studio 里的 Context Length（你这次是 16128）。';
		} elseif (preg_match('/400|invalid/i', $e) && (stripos($url, 'siliconflow') !== false || stripos($url, '/v1') !== false)) {
			$hint = '参数被拒绝，多半是模型 ID、维度或该模型不支持深度思考。';
		} elseif (stripos($e, 'SSL') !== false) {
			$hint = 'HTTPS 证书校验失败，请确认地址是 https://api.siliconflow.cn/v1。';
		}
		$msg = $hint !== '' ? $hint.' 原始错误：'.$e : $e;
		if ($url !== '') $msg .= ' 请求：'.$url;
		return $msg;
	}

	public static function stream($url, $body, $headers = array(), $timeout = 90, $onLine = null) {
		if (!function_exists('curl_init')) throw new Exception('PHP cURL extension is required');
		$url = self::fixUrl($url);
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			throw new Exception(self::explain('URL 必须以 http:// 或 https:// 开头', $url));
		}
		$curl = curl_init();
		$headerLines = array('Content-Type: application/json', 'Accept: text/event-stream');
		foreach ((array)$headers as $key => $value) {
			if (is_int($key)) $headerLines[] = $value;
			else $headerLines[] = $key.': '.$value;
		}
		$buffer = '';
		$raw = '';
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_TIMEOUT => max(8, intval($timeout)),
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$buffer, &$raw, $onLine) {
				$raw .= $chunk;
				$buffer .= $chunk;
				while (($p = strpos($buffer, "\n")) !== false) {
					$line = rtrim(substr($buffer, 0, $p), "\r");
					$buffer = substr($buffer, $p + 1);
					if ($line !== '' && is_callable($onLine)) $onLine($line);
				}
				return strlen($chunk);
			},
		));
		$ok = curl_exec($curl);
		if ($ok === false) {
			$error = curl_error($curl);
			curl_close($curl);
			throw new Exception(self::explain('HTTP connection failed: '.$error, $url));
		}
		if ($buffer !== '' && is_callable($onLine)) $onLine(rtrim($buffer, "\r"));
		$status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
		curl_close($curl);
		if ($status && !in_array($status, array(200, 201), true)) {
			throw new Exception(self::explain('HTTP '.$status.': '.substr($raw, 0, 1200), $url));
		}
		return array('_status' => $status, '_raw' => $raw);
	}

	public static function request($method, $url, $body = null, $headers = array(), $timeout = 30, $allowed = array(200, 201)) {
		if (!function_exists('curl_init')) throw new Exception('PHP cURL extension is required');
		$url = self::fixUrl($url);
		if ($url !== '' && !preg_match('#^https?://#i', $url)) {
			throw new Exception(self::explain('URL 必须以 http:// 或 https:// 开头', $url));
		}
		$curl = curl_init();
		$headerLines = array('Content-Type: application/json', 'Accept: application/json');
		foreach ((array)$headers as $key => $value) {
			if (is_int($key)) $headerLines[] = $value;
			else $headerLines[] = $key.': '.$value;
		}
		$options = array(
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => min(8, max(1, intval($timeout))),
			CURLOPT_TIMEOUT => max(1, intval($timeout)),
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
		);
		if (strtoupper($method) === 'HEAD') $options[CURLOPT_NOBODY] = true;
		if ($body !== null && strtoupper($method) !== 'HEAD') {
			$options[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		curl_setopt_array($curl, $options);
		return self::finish($curl, $url, $allowed);
	}

	public static function upload($url, $fields, $headers = array(), $timeout = 120, $allowed = array(200, 201)) {
		if (!function_exists('curl_init')) throw new Exception('PHP cURL extension is required');
		$url = self::fixUrl($url);
		$curl = curl_init();
		$headerLines = array('Accept: application/json');
		foreach ((array)$headers as $key => $value) {
			if (is_int($key)) $headerLines[] = $value;
			else $headerLines[] = $key.': '.$value;
		}
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => max(1, intval($timeout)),
			CURLOPT_HTTPHEADER => $headerLines,
			CURLOPT_POSTFIELDS => $fields,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
		));
		return self::finish($curl, $url, $allowed);
	}

	private static function finish($curl, $url, $allowed) {
		$response = curl_exec($curl);
		if ($response === false) {
			$error = curl_error($curl);
			curl_close($curl);
			throw new Exception(self::explain('HTTP connection failed: '.$error, $url));
		}
		$status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
		curl_close($curl);
		$data = $response === '' ? array() : json_decode($response, true);
		if (!is_array($data)) $data = array('_raw' => $response);
		$data['_status'] = $status;
		if ($allowed && !in_array($status, $allowed, true)) {
			$msg = _get($data, 'message', _get($data, 'error', ''));
			if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
			throw new Exception(self::explain('HTTP '.$status.': '.substr((string)($msg ? $msg : $response), 0, 1200), $url));
		}
		return $data;
	}
}
