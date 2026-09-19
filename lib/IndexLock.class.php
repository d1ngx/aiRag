<?php

class AiRagIndexLock {
	public static function acquire($name) {
		$path = rtrim(TEMP_PATH, '/\\').'/'.$name.'.lock';
		$fp = @fopen($path, 'c');
		if (!$fp || !@flock($fp, LOCK_EX | LOCK_NB)) {
			if ($fp) fclose($fp);
			return false;
		}
		ftruncate($fp, 0);
		fwrite($fp, (string)getmypid());
		return $fp;
	}

	public static function release($fp) {
		if (!$fp) return;
		@flock($fp, LOCK_UN);
		@fclose($fp);
	}

	public static function busy($name) {
		$fp = self::acquire($name);
		if ($fp === false) return true;
		self::release($fp);
		return false;
	}
}
