<?php

class AiRagTextChunker {
	public static function split($text, $size = 800, $overlap = 120, $fileName = '') {
		$text = AiRagTextNormalizer::clean($text);
		$size = max(200, min(2000, intval($size)));
		$overlap = max(0, min(intval($size / 4), intval($overlap)));
		$prefix = $fileName !== '' ? $fileName."\n" : '';
		if ($text === '') return array();
		$len = self::len($text);
		$minKeep = max(120, intval($size * 0.25));
		$chunks = array();
		$offset = 0;
		while ($offset < $len) {
			$remain = $len - $offset;
			$take = min($size, $remain);
			$raw = self::sub($text, $offset, $take);
			if ($remain > $size) {
				$break = self::lastBreak($raw);
				if ($break >= intval($size * 0.7)) $raw = self::sub($raw, 0, $break);
			}
			$consumed = self::len($raw);
			if ($consumed <= 0) $consumed = max(1, $take);
			$piece = trim($raw);
			if ($piece !== '') {
				if ($chunks && self::len($piece) < $minKeep) {
					$prev = &$chunks[count($chunks) - 1];
					$body = self::body($prev['text'], $prefix);
					if (self::len($body) + self::len($piece) + 1 <= $size + $minKeep) {
						$prev['text'] = $prefix.trim($body."\n".$piece);
					} else {
						$chunks[] = array('text' => $prefix.$piece);
					}
					unset($prev);
				} else {
					$chunks[] = array('text' => $prefix.$piece);
				}
			}
			$advance = $consumed - min($overlap, intval($consumed / 3));
			if ($advance < intval($consumed * 0.5)) $advance = $consumed;
			$offset += max(1, $advance);
			if (count($chunks) >= 2000) break;
		}
		$out = array();
		foreach ($chunks as $i => $chunk) {
			$out[] = array('index' => $i, 'text' => $chunk['text']);
		}
		return $out;
	}

	private static function body($text, $prefix) {
		if ($prefix !== '' && strpos($text, $prefix) === 0) return substr($text, strlen($prefix));
		return $text;
	}

	private static function lastBreak($piece) {
		$pos = 0;
		foreach (array("\n\n", '。', '！', '？', '；') as $mark) {
			$found = function_exists('mb_strrpos') ? mb_strrpos($piece, $mark, 0, 'UTF-8') : strrpos($piece, $mark);
			if ($found !== false && $found > $pos) $pos = $found + self::len($mark);
		}
		$dot = function_exists('mb_strrpos') ? mb_strrpos($piece, '. ', 0, 'UTF-8') : strrpos($piece, '. ');
		if ($dot !== false && $dot + 2 > $pos) $pos = $dot + 2;
		return $pos;
	}

	private static function len($text) {
		return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
	}

	private static function sub($text, $start, $length) {
		return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
	}
}
