<?php

class AiRagTextNormalizer {
	public static function sanitizeOfficeZip($content, $ext) {
		$ext = strtolower((string)$ext);
		if (!in_array($ext, array('docx','docm','xlsx','xlsm','pptx','pptm'), true)) return $content;
		if (!class_exists('ZipArchive') || substr((string)$content, 0, 2) !== 'PK') return $content;
		$tmp = tempnam(sys_get_temp_dir(), 'airagzip');
		if (!$tmp || file_put_contents($tmp, $content) === false) {
			if ($tmp) @unlink($tmp);
			return $content;
		}
		$zip = new ZipArchive();
		if ($zip->open($tmp) !== true) {
			@unlink($tmp);
			return $content;
		}
		$types = (string)$zip->getFromName('[Content_Types].xml');
		$removed = 0;
		for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
			$name = $zip->getNameIndex($i);
			if (!$name || !preg_match('#^customXml/.*\\.bin$#i', $name)) continue;
			$part = '/'.ltrim(str_replace('\\', '/', $name), '/');
			$declared = $types !== '' && (strpos($types, $part) !== false || strpos($types, $name) !== false);
			if ($declared) continue;
			if ($zip->deleteName($name)) $removed++;
		}
		$zip->close();
		$out = $removed ? file_get_contents($tmp) : $content;
		@unlink($tmp);
		return $out !== false ? $out : $content;
	}

	public static function clean($text) {
		$text = (string)$text;
		if ($text === '') return '';
		// Strip real markup before decoding so &lt;代码&gt; remains literal text.
		$text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text);
		$text = preg_replace('~<br\s*/?>|</(?:p|div|li|tr|h[1-6])\s*>~i', "\n", $text);
		$text = strip_tags($text);
		for ($i = 0; $i < 3; $i++) {
			$decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($decoded === $text) break;
			$text = $decoded;
		}
		$text = str_replace(array("\0", "\xC2\xA0"), array('', ' '), $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text);
		$text = preg_replace("/\r\n|\r/", "\n", $text);
		$text = preg_replace("/[ \t]+\n/", "\n", $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = preg_replace('/[ \t]{2,}/', ' ', $text);
		if (function_exists('utf8Repair')) $text = utf8Repair($text);
		return trim($text, " \t\r\n\f");
	}

	public static function looksLikeHtmlEntities($text) {
		if (!preg_match_all('/&#\d{2,6};/', (string)$text, $m)) return false;
		return count($m[0]) >= 20;
	}

	public static function rewriteQuery($words) {
		$year = intval(date('Y'));
		$map = array(
			'去年' => (string)($year - 1),
			'今年' => (string)$year,
			'前年' => (string)($year - 2),
			'明年' => (string)($year + 1),
		);
		$extra = array();
		foreach ($map as $word => $value) {
			if (mb_strpos($words, $word) !== false) $extra[] = $value;
		}
		return array(
			'raw' => $words,
			'keyword' => trim($words.' '.implode(' ', $extra)),
			'years' => $extra,
		);
	}

	public static function keywordHeavy($words) {
		$words = trim((string)$words);
		if ($words === '') return false;
		if (preg_match('/[A-Za-z]?\d{3,}[-_\/]\d+/', $words)) return true;
		if (preg_match('/^[A-Za-z0-9._:-]{4,}$/', $words)) return true;
		$latin = preg_match_all('/[A-Za-z0-9]/', $words);
		$cjk = preg_match_all('/\p{Han}/u', $words);
		return $latin >= 6 && $latin > $cjk * 2;
	}
}
