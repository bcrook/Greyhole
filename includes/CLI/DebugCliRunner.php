<?php
/*
Copyright 2009-2012 Guillaume Boudreau, Andrew Hopkinson

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

require_once('includes/CLI/AbstractAnonymousCliRunner.php');

class DebugCliRunner extends AbstractAnonymousCliRunner {
	public function run() {
		global $storage_pool_drives, $greyhole_log_file;
		
		if (!isset($this->options['cmd_param'])) {
			$this->log("Please specify a file to debug.");
			$this->finish(1);
		}
		$filename = $this->options['cmd_param'];

		if (mb_strpos($filename, '/') === FALSE) {
			$filename = "/$filename";
		}

		$this->log("Debugging file operations for file named \"$filename\"");
		$this->log("");
		$this->log("From DB");
		$this->log("=======");
		$debug_tasks = array();
		$query = sprintf("SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE full_path LIKE '%%%s%%' ORDER BY id ASC",
			DB::escape_string($filename)
		);
		$result = DB::query($query) or die("Can't query tasks_completed with query: $query - Error: " . DB::error());
		while ($row = DB::fetch_object($result)) {
			$debug_tasks[$row->id] = $row;
		}

		// Renames
		$query = sprintf("SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE additional_info LIKE '%%%s%%' ORDER BY id ASC",
			DB::escape_string($filename)
		);
		while (TRUE) {
			$result = DB::query($query) or die("Can't query tasks_completed for renames with query: $query - Error: " . DB::error());
			while ($row = DB::fetch_object($result)) {
				$debug_tasks[$row->id] = $row;
				$query = sprintf("SELECT id, action, share, full_path, additional_info, event_date FROM tasks_completed WHERE additional_info = '%s' ORDER BY id ASC",
					DB::escape_string($row->full_path)
				);
			}

			# Is there more?
			$new_query = preg_replace('/SELECT .* FROM/i', 'SELECT COUNT(*) FROM', $query);
			$result = DB::query($new_query) or die("Can't query tasks_completed for COUNT of renames with query: $new_query - Error: " . DB::error());
			if (DB::fetch_object($result) !== FALSE) {
				break;
			}
		}

		ksort($debug_tasks);
		$to_grep = array();
		foreach ($debug_tasks as $task) {
			$this->log("  [$task->event_date] Task ID $task->id: $task->action $task->share/$task->full_path" . ($task->action == 'rename' ? " -> $task->share/$task->additional_info" : ''));
			$to_grep["$task->share/$task->full_path"] = 1;
			if ($task->action == 'rename') {
				$to_grep["$task->share/$task->additional_info"] = 1;
			}
		}
		if (empty($to_grep)) {
			$to_grep[$filename] = 1;
			if (mb_strpos($filename, '/') !== FALSE) {
				$share = trim(mb_substr($filename, 0, mb_strpos(mb_substr($filename, 1), '/')+1), '/');
				$full_path = trim(mb_substr($filename, mb_strpos(mb_substr($filename, 1), '/')+1), '/');
				$debug_tasks[] = (object) array('share' => $share, 'full_path' => $full_path);
			}
		}

		$this->log("");
		$this->log("From logs");
		$this->log("=========");
		$to_grep = array_keys($to_grep);
		$to_grep = implode("|", $to_grep);
		$commands = array();
		$commands[] = "zgrep -h -E -B 1 -A 2 -h " . escapeshellarg($to_grep) . " $greyhole_log_file*.gz";
		$commands[] = "grep -h -E -B 1 -A 2 -h " . escapeshellarg($to_grep) . " " . escapeshellarg($greyhole_log_file);
		foreach ($commands as $command) {
			exec($command, $result);
		}

		$result2 = array();
		$i = 0;
		foreach ($result as $rline) {
			if ($rline == '--') { continue; }
			$date_time = substr($rline, 0, 15);
			$timestamp = strtotime($date_time);
			$result2[$timestamp.sprintf("%04d", $i++)] = $rline;
		}
		ksort($result2);
		$this->log(implode("\n", $result2));

		$this->log("");
		$this->log("From filesystem");
		$this->log("===============");

		$last_task = array_pop($debug_tasks);
		$share = $last_task->share;
		if ($last_task->action == 'rename') {
			$full_path = $last_task->additional_info;
		} else {
			$full_path = $last_task->full_path;
		}
		list($path, $filename) = explode_full_path($full_path);
		$this->log("Landing Zone:");
		$this->logn("  "); passthru("ls -l " . escapeshellarg(get_share_landing_zone($share) . "/" . $full_path));

		$this->log("");
		$this->log("Metadata store:");
		foreach ($storage_pool_drives as $sp_drive) {
			$metastore = clean_dir("$sp_drive/.gh_metastore");
			if (file_exists("$metastore/$share/$full_path")) {
				$this->logn("  "); passthru("ls -l " . escapeshellarg("$metastore/$share/$full_path"));
				$data = var_export(unserialize(file_get_contents("$metastore/$share/$full_path")), TRUE);
				$data = str_replace("\n", "\n    ", $data);
				$this->log("    $data");
			}
		}

		$this->log("");
		$this->log("File copies:");
		foreach ($storage_pool_drives as $sp_drive) {
			if (file_exists("$sp_drive/$share/$full_path")) {
				$this->logn("  "); passthru("ls -l " . escapeshellarg("$sp_drive/$share/$full_path"));
			}
		}
	}
}

?>
