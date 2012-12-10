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

class FsckTask extends Task {
    public function execute() {
        // @TODO Remove those globals!
        global $storage_pool_drives, $shares_options, $email_to;
        
        parent::execute();
        
        $task = $this->task;
		$new_conf_md5 = get_conf_md5();
		if ($this->hasOption(OPTION_IF_CONF_CHANGED)) {
			// Let's check if the conf file changed since the last fsck

			// Last value
			$last_md5 = Settings::get('last_fsck_conf_md5');

			// New value
			if ($new_conf_md5 == $last_md5) {
				Log::info("Skipping fsck; --if-conf-changed was specified, and the configuration file didn't change since the last fsck.");
				break;
			}
		}

		$where_clause = "";
		if ($task->share == '') {
   			$fsck_what_dir = 'All shares';
		} else {
   			$fsck_what_dir = $task->share;
			$max_lz_length = 0;
			foreach ($shares_options as $share_name => $share_options) {
				if (strpos($fsck_what_dir, $share_options['landing_zone']) === 0 && strlen($share_options['landing_zone']) > $max_lz_length) {
					$max_lz_length = strlen($share_options['landing_zone']);
		   			$where_clause = sprintf("AND share = '%s'", $share_name);
				}
			}
		}

		// First, let's remove all md5 tasks that would be duplicates of the ones we'll create during this fsck
		DB::query("DELETE FROM tasks WHERE action = 'md5' $where_clause") or Log::critical("Can't delete deprecated md5 tasks: " . DB::error());

		// Second, let's make sure all fsck_file tasks marked idle get executed.
		$query = "UPDATE tasks SET complete = 'yes' WHERE action = 'fsck_file' AND complete = 'idle' $where_clause";
		DB::query($query) or Log::critical("Can't update fsck_file/idle tasks to fsck_file/complete: " . DB::error());
		$result = DB::query("SELECT COUNT(*) AS num_updated_rows FROM tasks WHERE action = 'fsck_file' AND complete = 'yes' $where_clause") or Log::critical("Can't find number of updated tasks for fsck_file/complete tasks: " . DB::error());
		$row = DB::fetch_object($result);
		if ($row->num_updated_rows > 0) {
			// Updated some fsck_file to complete; let's just return here, to allow them to be executed first.
			Log::info("Will execute all ($row->num_updated_rows) pending fsck_file operations for $fsck_what_dir before running this fsck (task ID $task->id).");
			return;
		}

		Log::info("Starting fsck for $fsck_what_dir");
		initialize_fsck_report($fsck_what_dir);
		clearstatcache();

		if ($this->hasOption(OPTION_CHECKSUMS)) {
			// Spawn md5 worker threads; those will calculate files MD5, and save the result in the DB.
			// The Greyhole daemon will then read those, and check them against each other to make sure all is fine.
			$checksums_thread_ids = array();
			foreach ($storage_pool_drives as $sp_drive) {
				$checksums_thread_ids[] = spawn_thread('md5-worker', array($sp_drive));
			}
			Log::debug("Spawned " . count($checksums_thread_ids) . " worker threads to calculate MD5 checksums. Will now wait for results, and check them as they come in.");
		}

		set_fsck_options($task);

		if ($task->share == '') {
			foreach ($shares_options as $share_name => $share_options) {
				gh_fsck($share_options['landing_zone'], $share_name);
			}
			if ($this->hasOption(OPTION_METASTORE)) {
				foreach (Metastore::get_stores() as $metastore) {
					foreach ($shares_options as $share_name => $share_options) {
						gh_fsck_metastore($metastore, "/$share_name", $share_name);
					}
				}
			}
			if ($this->hasOption(OPTION_ORPHANED)) {
				foreach ($storage_pool_drives as $sp_drive) {
					if (StoragePoolHelper::is_drive_ok($sp_drive)) {
						foreach ($shares_options as $share_name => $share_options) {
							gh_fsck("$sp_drive/$share_name", $share_name, $sp_drive);
						}
					}
				}
			}
		} else {
			$share_options = get_share_options_from_full_path($task->share);
			$storage_volume = FALSE;
			if ($share_options === FALSE) {
				//Since share_options is FALSE we didn't get a share path, maybe we got a storage volume path, let's check 
				$storage_volume = get_storage_volume_from_path($task->share);
				$share_options = get_share_options_from_storage_volume($task->share,$storage_volume);
			}
			if ($share_options !== FALSE) {
				$share = $share_options['name'];
				$metastore = Metastore::store_from_path($task->share);
				if($metastore === FALSE) {
					//Only kick off an fsck on the passed dir if it's not a metastore
					gh_fsck($task->share, $share, $storage_volume);
				}
				if ($this->hasOption(OPTION_METASTORE) !== FALSE) {
					if($metastore === FALSE) {
						//This isn't a metastore dir so we'll check the metastore of this path on all volumes
						if($storage_volume !== FALSE) {
							$subdir = str_replace($storage_volume, '', $task->share);
						}else{
							$subdir = "/$share" . str_replace($share_options['landing_zone'], '', $task->share);
						}
						Log::debug("Starting metastores fsck for $subdir");
						foreach (Metastore::get_stores() as $metastore) {
							gh_fsck_metastore($metastore, $subdir, $share);
						}
					}else{
						//This is a metastore directory, so only kick off a metastore fsck for the indicated directory (this will not fsck the corresponding metastore path on other volumes)
						$subdir = str_replace("$metastore", '', $task->share);
						Log::debug("Starting metastore fsck for $metastore/$subdir");
						gh_fsck_metastore($metastore, $subdir, $share);
					}
				}
				if ($storage_volume === FALSE && $this->hasOption(OPTION_ORPHANED)) {
					$subdir = "/$share" . str_replace($share_options['landing_zone'], '', $task->share);
					Log::debug("Starting orphans search for $subdir");
					foreach ($storage_pool_drives as $sp_drive) {
						if (StoragePoolHelper::is_drive_ok($sp_drive)) {
							gh_fsck("$sp_drive/$subdir", $share, $sp_drive);
						}
					}
				}
			}
		}
		Log::info("fsck for " . ($task->share == '' ? 'All shares' : $task->share) . " completed.");

		Settings::set('last_fsck_conf_md5', $new_conf_md5);

		if ($this->hasOption(OPTION_EMAIL)) {
			// Email report for fsck
			$fsck_report_mail = get_fsck_report();
			Log::debug("Sending fsck report to $email_to");
			mail($email_to, 'fsck of Greyhole shares on ' . exec('hostname'), $fsck_report_mail);
		}
		if ($this->hasOption(OPTION_DU)) {
			// Save disk-usage report to disk
			$fp = fopen('/usr/share/greyhole/gh-disk-usage.log', 'w');
			if ($fp) {
				global $du;
				foreach ($du as $path => $size) {
					$chars_count = count_chars($path, 1);
					fwrite($fp, $chars_count[ord('/')] . " $path $size\n");
				}
				fwrite($fp, "# " . serialize($shares_options) . "\n");
				fclose($fp);
			}
		}
    }
}
?>
