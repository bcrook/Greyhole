<?php
/*
Copyright 2010-2012 Guillaume Boudreau

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

class SambaHelper {
	
	public static $config_file = '/etc/samba/smb.conf';

	public static function check_vfs() {
		$vfs_is_ok = FALSE;

		// Samba version
		$version = str_replace('.', '', self::get_version());
		
		// Get CPU architecture (x86_64 or i386)
		$arch = exec('uname -m');

		// Find VFS symlink
		$lib_dir = '/usr/lib';
		if ($arch == "x86_64") {
			$lib_dir = '/usr/lib64';
		}
		$vfs_file = "$lib_dir/samba/vfs/greyhole.so";

		if (is_file($vfs_file)) {
			// Get VFS symlink target
			$vfs_target = readlink($vfs_file);

			if (strpos($vfs_target, "/greyhole-samba$version.so") !== FALSE) {
				$vfs_is_ok = TRUE;
			}
		}

		if (!$vfs_is_ok) {
			Log::warn("Greyhole VFS module for Samba was missing, or the wrong version for your Samba. It will now be replaced with a symlink to $lib_dir/greyhole/greyhole-samba$version.so");
			$vfs_target = "$lib_dir/greyhole/greyhole-samba$version.so";
			if (is_file($vfs_file)) {
				unlink($vfs_file);
			}
			symlink($vfs_target, $vfs_file);
			self::restart();
		}
	}
	
	public static function get_version() {
		return str_replace(' ', '.', exec('/usr/sbin/smbd --version | awk \'{print $2}\' | awk -F\'-\' \'{print $1}\' | awk -F\'.\' \'{print $1,$2}\''));
	}

	public static function restart() {
		Log::info("The Samba daemon will now restart...");
		if (is_file('/etc/init/smbd.conf')) {
			exec("/sbin/restart smbd");
		} else if (is_file('/etc/init.d/samba')) {
			exec("/etc/init.d/samba restart");
		} else if (is_file('/etc/init.d/smb')) {
			exec("/etc/init.d/smb restart");
		} else if (is_file('/etc/init.d/smbd')) {
			exec("/etc/init.d/smbd restart");
		} else {
			Log::critical("Couldn't find how to restart Samba. Please restart the Samba daemon manually.");
		}
	}
	
	public static function process_spool($simplify_after_parse=TRUE) {
		global $trash_share_names, $max_queued_tasks;

		Log::set_action('read_smb_spool');

		// If we have enough queued tasks (90% of $max_queued_tasks), let's not parse the log at this time, and get some work done.
		// Once we fall below that, we'll queue up to at most $max_queued_tasks new tasks, then get back to work.
		// This will effectively 'batch' large file operations to make sure the DB doesn't become a problem because of the number of rows,
		//   and this will allow the end-user to see real activity, other that new rows in greyhole.tasks...
		$query = "SELECT COUNT(*) num_rows FROM tasks";
		$result = DB::query($query) or Log::critical("Can't get tasks count: " . DB::error());
		$row = DB::fetch_object($result);
		DB::free_result($result);
		$num_rows = (int) $row->num_rows;
		if ($num_rows >= ($max_queued_tasks * 0.9)) {
			Log::restore_previous_action();
			if (time() % 10 == 0) {
				Log::debug("  More than " . ($max_queued_tasks * 0.9) . " tasks queued... Won't queue any more at this time.");
			}
			return;
		}

		$new_tasks = 0;
		$last_line = FALSE;
		$act = FALSE;
		while (TRUE) {
			$files = array();
			$space_left_in_queue = $max_queued_tasks - $num_rows - $new_tasks;
			exec('ls -1 /var/spool/greyhole | sort -n 2> /dev/null | head -' . $space_left_in_queue, $files);
			if (count($files) == 0) {
				break;
			}

			if ($last_line === FALSE) {
				Log::debug("Processing Samba spool...");
			}

			foreach ($files as $filename) {
				@unlink($last_filename);

				$filename = "/var/spool/greyhole/$filename";
				$last_filename = $filename;

				$line = file_get_contents($filename);

				// Prevent insertion of unneeded duplicates
				if ($line === $last_line) {
					continue;
				}

				$line_ar = explode("\n", $line);

				$last_line = $line;

				// Close logs are only processed when no more duplicates are found, so we'll execute this now that a non-duplicate line was found.
				if ($act === 'close') {
					$query = sprintf("UPDATE tasks SET additional_info = NULL, complete = 'yes' WHERE complete = 'no' AND share = '%s' AND additional_info = '%s'",
						DB::escape_string($share),
						$fd
					);
					DB::query($query) or Log::critical("Error updating tasks (1): " . DB::error() . "; Query: $query");
				}

				$line = $line_ar;
				$act = array_shift($line);
				$share = array_shift($line);
				if ($act == 'mkdir') {
					// Nothing to do with those
					continue;
				}
				$result = array_pop($line);
				if (mb_strpos($result, 'failed') === 0) {
					Log::debug("Failed $act in $share/$line[0]. Skipping.");
					continue;
				}
				unset($fullpath);
				unset($fullpath_target);
				unset($fd);
				switch ($act) {
					case 'open':
						$fullpath = array_shift($line);
						$fd = array_shift($line);
						$act = 'write';
						break;
					case 'rmdir':
					case 'unlink':
						$fullpath = array_shift($line);
						break;
					case 'rename':
						$fullpath = array_shift($line);
						$fullpath_target = array_shift($line);
						break;
					case 'close':
						$fd = array_shift($line);
						break;
					default:
						$act = FALSE;
				}
				if ($act === FALSE) {
					continue;
				}

				// Close logs are only processed when no more duplicates are found, so we won't execute it just yet; we'll process it the next time we find a non-duplicate line.
				if ($act != 'close') {
					if (isset($fd) && $fd == -1) {
						continue;
					}
					if ($act != 'unlink' && $act != 'rmdir' && array_search($share, $trash_share_names) !== FALSE) { continue; }
					$new_tasks++;
					$query = sprintf("INSERT INTO tasks (action, share, full_path, additional_info, complete) VALUES ('%s', '%s', %s, %s, '%s')",
						$act,
						DB::escape_string($share),
						isset($fullpath) ? "'".DB::escape_string(clean_dir($fullpath))."'" : 'NULL',
						isset($fullpath_target) ? "'".DB::escape_string(clean_dir($fullpath_target))."'" : (isset($fd) ? "'$fd'" : 'NULL'),
						$act == 'write' ? 'no' : 'yes'
					);
					DB::query($query) or Log::critical("Error inserting task: " . DB::error() . "; Query: $query");
				}

				// If we have enough queued tasks ($max_queued_tasks), let's stop parsing the log, and get some work done.
				if ($num_rows+$new_tasks >= $max_queued_tasks) {
					Log::debug("  We now have more than $max_queued_tasks tasks queued... Will stop parsing for now.");
					break;
				}
			}
			@unlink($last_filename);
			if ($num_rows+$new_tasks >= $max_queued_tasks) {
				break;
			}
		}

		// Close logs are only processed when no more duplicates are found, so we'll execute this now that we're done parsing the current log.
		if ($act == 'close') {
			$query = sprintf("UPDATE tasks SET additional_info = NULL, complete = 'yes' WHERE complete = 'no' AND share = '%s' AND additional_info = '%s'",
				DB::escape_string($share),
				$fd
			);
			DB::query($query) or Log::critical("Error updating tasks (2): " . DB::error() . "; Query: $query");
		}

		if ($new_tasks > 0) {
			Log::debug("Found $new_tasks new tasks in spool.");

			if ($simplify_after_parse) {
				$query = "SELECT COUNT(*) num_rows FROM tasks";
				$result = DB::query($query) or Log::critical("Can't get tasks count: " . DB::error());
				$row = DB::fetch_object($result);
				DB::free_result($result);
				$num_rows = (int) $row->num_rows;
				if ($num_rows < 1000 || $num_rows % 5 == 0) { // Runs 1/5 of the times when num_rows > 1000
					if ($num_rows < 5000 || $num_rows % 100 == 0) { // Runs 1/100 of the times when num_rows > 5000
						Task::simplify();
					}
				}
			}
		}

		Log::restore_previous_action();
	}
}
?>
