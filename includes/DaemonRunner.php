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
		if (self::isRunning()) {
			die("Found an already running Greyhole daemon with PID " . trim(file_get_contents('/var/run/greyhole.pid')) . ".\nCan't start multiple Greyhole daemons.\nQuitting.\n");
		}

		Log::info("Greyhole (version %VERSION%) daemon started.");
		$this->initialize();

        // The daemon runs indefinitly, this the infinite loop here.
		while (TRUE) {
			// Process the spool directory, and insert each task found there into the database.
			SambaHelper::process_spool();

			// Check that storage pool drives are OK (using their UUID, or .greyhole_uses_this files)
			StoragePoolHelper::check_storage_pool_drives();

			// Execute the next task from the tasks queue ('tasks' table in the database)
			$task = Task::getNext(INCL_MD5);
			if ($task->execute()) {
        		$task->archive();
			}
		}
	}
	
	private static function isRunning() {
		$num_daemon_processes = exec('ps ax | grep ".*/php .*/greyhole --daemon" | grep -v grep | wc -l');
	    return $num_daemon_processes > 1;
	}
    
	private function initialize() {
		// Check the database tables, and repair them if needed.
		DB::repair_tables();
		
		// Creates a GUID (if one doesn't exist); will be used to uniquely identify this Greyhole install, when reporting anonymous usage to greyhole.net
		GetGUIDCliRunner::setUniqID();
		
		// Terminology changed (attic > trash, graveyard > metadata store, tombstones > metadata files); this requires filesystem & database changes.
		MigrationHelper::terminology_conversion();
		
		// Storage pool drives used to require a .greyhole_uses_this tag file, to allow Greyhole to detect a gone drive; we now use the partitions UUID to check that, so we need to remove old tag files, if any.
		MigrationHelper::convert_sp_drives_tag_files();
		
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
	
	public function finish($returnValue = 0) {
		// The daemon should never finish; it will be killed by the init script.
		// Not that it can reach finish() anyway, since it's in an infinite while(TRUE) loop in run()... :)
	}
}

?>
