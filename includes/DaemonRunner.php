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

abstract class DaemonRunner extends AbstractRunner {
	
	public function run() {
		// Prevent multiple daemons from running simultaneously
		$num_daemon_processes = exec('ps ax | grep ".*/php .*/greyhole --daemon" | grep -v grep | wc -l');
		if ($num_daemon_processes > 1) {
			die("Found an already running Greyhole daemon with PID " . trim(file_get_contents('/var/run/greyhole.pid')) . ".\nCan't start multiple Greyhole daemons.\nQuitting.\n");
		}

		Log::log(INFO, "Greyhole (version %VERSION%) daemon started.");
		$this->initialize();
		while (TRUE) {
			// Process the spool directory, and insert each task found there into the database.
			SambaHelper::process_spool();

			// Check that storage pool drives are OK (using their UUID, or .greyhole_uses_this files)
			StoragePoolHelper::check_storage_pool_drives();

			// Execute the next tasks from the tasks queue ('tasks' table in the database)
			$task = Task::getNext(INCL_MD5);
			if ($task->execute()) {
        		$task->archive();
			}
		}
	}
	
	private function initialize() {
		// Check the database tables, and repair them if needed.
		DB::repair_tables();
		
		// Creates a GUID (if one doesn't exist); will be used to uniquely identify this client when reporting usage to greyhole.net
		GetGUIDCliRunner::setUniqID();
		
		// Terminology changed (attic > trash, graveyard > metadata store, tombstones > metadata files); this requires filesystem & database changes.
		self::terminology_conversion();
		
		// For files which don't have extra copies, we at least create a copy of the metadata on a separate drive, in order to be able to identify the missing files if a hard drive fails.
		Settings::set_metastore_backup();

		// We backup the database settings to disk, in order to be able to restore them if the database is lost.
		Settings::backup();
		
		// Check that the Greyhole VFS module used by Samba is the correct one for the current Samba version. This is needed when Samba is updated to a new major version after Greyhole is installed.
		SambaHelper::check_vfs();
		
		// Process the spool directory, and insert each task found there into the database.
		SambaHelper::process_spool();
		
		// Simplify the list of tasks in the database. Writing the same file over and over will result in Greyhole only processing one write task.
		Task::simplify();
	}
    
	private static function terminology_conversion() {
		self::convert_folders('.gh_graveyard','.gh_metastore');
		self::convert_folders('.gh_graveyard_backup','.gh_metastore_backup');
		self::convert_folders('.gh_attic','.gh_trash');
		self::convert_database();
		self::convert_sp_drives_tag_files();
	}

	private static function convert_folders($old, $new) {
		global $storage_pool_drives;
		foreach ($storage_pool_drives as $sp_drive) {
			$old_term = clean_dir("$sp_drive/$old");
			$new_term = clean_dir("$sp_drive/$new");
			if (file_exists($old_term)) {
				Log::log(INFO, "Moving $old_term to $new_term...");
				gh_rename($old_term, $new_term);
			}
		}
	}

	private static function convert_database() {
		Settings::rename('graveyard_backup_directory', 'metastore_backup_directory');
		$setting = Settings::get('metastore_backup_directory', FALSE, '%graveyard%');
		if ($setting) {
			$new_value = str_replace('/.gh_graveyard_backup', '/.gh_metastore_backup', $setting);
			Settings::set('metastore_backup_directory', $new_value);
		}
	}

	private static function convert_sp_drives_tag_files() {
		global $storage_pool_drives, $going_drive, $allow_multiple_sp_per_device;

		$drives_definitions = Settings::get('sp_drives_definitions', TRUE);
		if (!$drives_definitions) {
			$drives_definitions = array();
		}
		foreach ($storage_pool_drives as $sp_drive) {
			if (isset($going_drive) && $sp_drive == $going_drive) { continue; }
			if (!isset($drives_definitions[$sp_drive])) {
				if (is_dir($sp_drive)) {
					$drives_definitions[$sp_drive] = gh_dir_uuid($sp_drive);
				}
			}
			if (!isset($drives_definitions[$sp_drive])) {
				continue;
			}
			if ($drives_definitions[$sp_drive] === FALSE) {
				unset($drives_definitions[$sp_drive]);
				continue;
			}
			if (file_exists("$sp_drive/.greyhole_uses_this")) {
				unlink("$sp_drive/.greyhole_uses_this");
			}
			if ($drives_definitions[$sp_drive] != gh_dir_uuid($sp_drive)) {
				Log::log(WARN, "Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
			}
		}
		foreach ($drives_definitions as $sp_drive => $uuid) {
			if (array_search($sp_drive, $storage_pool_drives) === FALSE) {
				unset($drives_definitions[$sp_drive]);
			}
		}

		// Check that the user is not using two sp drives on the same device
		$devices = array();
		foreach ($drives_definitions as $sp_drive => $device_id) {
			$devices[$device_id][] = $sp_drive;
		}
		foreach ($devices as $device_id => $sp_drives) {
			if (count($sp_drives) > 1 && $device_id !== 0) {
				if ($allow_multiple_sp_per_device) {
					Log::log(INFO, "The following storage pool drives are on the same partition: " . implode(", ", $sp_drives) . ", but per greyhole.conf 'allow_multiple_sp_per_device' options, you chose to ignore this normally critical error.");
				} else {
					Log::log(CRITICAL, "ERROR: The following storage pool drives are on the same partition: " . implode(", ", $sp_drives) . ". The Greyhole daemon will now stop.");
				}
			}
		}

		Settings::set('sp_drives_definitions', $drives_definitions);
		return $drives_definitions;
	}

	public function finish($returnValue = 0) {
		// The daemon should never finish; it will be killed by the init script.
		// Not that it can reach finish() anyway, since it's in an infinite while(TRUE) loop in run()... :)
	}
}

?>
