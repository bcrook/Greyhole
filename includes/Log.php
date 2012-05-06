<?php
/*
Copyright 2009-2012 Guillaume Boudreau

This file is part of Greyhole.

Greyhole is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Greyhole is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Greyhole.  If not, see <http://www.gnu.org/licenses/>.
*/

class Log {
	private static $action = 'initialize';
	private static $old_action;
	
	public static function set_action($action) {
		self::$old_action = self::$action;
		self::$action = $action;
	}

	public static function action_equals($action) {
		return self::$action == $action;
	}

	public static function restore_previous_action() {
		self::$action = self::$old_action;
	}

	public static function log($local_log_level, $text) {
		global $greyhole_log_file, $log_level, $log_memory_usage;
		if ($local_log_level > $log_level) {
			return;
		}

		$date = date("M d H:i:s");
		if ($log_level >= PERF) {
			$utimestamp = microtime(true);
			$timestamp = floor($utimestamp);
			$date .= '.' . round(($utimestamp - $timestamp) * 1000000);
		}
		$log_text = sprintf("%s %s %s: %s%s\n", 
			$date,
			$local_log_level,
			self::$action,
			$text,
			@$log_memory_usage ? " [" . memory_get_usage() . "]" : ''
		);

		if (strtolower($greyhole_log_file) == 'syslog') {
			$worked = syslog($local_log_level, $log_text);
		} else if (!empty($greyhole_log_file)) {
			$worked = error_log($log_text, 3, $greyhole_log_file);
		} else {
			$worked = FALSE;
		}
		if (!$worked) {
			error_log(trim($log_text));
		}

		if ($local_log_level === CRITICAL) {
			exit(1);
		}
	}
}
?>
