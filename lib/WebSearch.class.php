<?php

class AiRagWebSearch {
	public static function lookup($query) {
		$query = trim((string)$query);
		if ($query === '') return '';
		$url = 'https://api.duckduckgo.com/?q='.rawurlencode($query).'&format=json&no_html=1&skip_disambig=1';
		try {
			$data = AiRagHttpJson::request('GET', $url, null, array(), 8, array(200));
		} catch (Throwable $e) {
			return '';
		}
		$parts = array();
		$abs = trim((string)_get($data, 'AbstractText', ''));
		if ($abs !== '') $parts[] = $abs;
		$heading = trim((string)_get($data, 'Heading', ''));
		$source = trim((string)_get($data, 'AbstractURL', ''));
		if ($heading !== '' && $source !== '') $parts[] = $heading.' '.$source;
		foreach (array_slice((array)_get($data, 'RelatedTopics', array()), 0, 5) as $item) {
			$text = trim((string)_get($item, 'Text', ''));
			if ($text !== '') $parts[] = $text;
		}
		return mb_substr(implode("\n", $parts), 0, 1800);
	}
}
