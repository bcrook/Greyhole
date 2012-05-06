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

class GoneCliRunner extends AbstractCliRunner {
	private $drive;
	private $action;

	function __construct($options) {
		parent::__construct($options);

		$this->action = isGoing() ? 'going' : 'gone';

		global $storage_pool_drives;

		if (isset($this->options['cmd_param'])) {
			$this->drive = $this->options['cmd_param'];
			if (array_search($this->drive, $storage_pool_drives) === FALSE) {
				$this->drive = '/' . trim($this->drive, '/');
			}
		}

		if (empty($this->drive) || array_search($this->drive, $storage_pool_drives) === FALSE) {
			if (!empty($this->drive)) {
				$this->log("Directory $this->drive is not one of your defined storage pool drives.");
			}
			$this->log("Please use one of the following with the --$this->action option:"_;
			$this->log("  " . implode("\n  ", $storage_pool_drives));
			$this->finish(0);
		}
	}
	
	private function isGoing() {
		return $this instanceof GoingCliRunner;
	}

	public function run() {
		global $shares_options;
		
		// Removing this drive here will insure it won't be used for new files while we're moving files away, and that it can later be replaced.
		remove_drive_definition($this->drive);

		if ($this->action == 'going') {
			file_put_contents($this->drive . "/.greyhole_used_this", "Flag to prevent Greyhole from thinking this drive disappeared for no reason...");
			Settings::set_metastore_backup();
			Log::log(INFO, "Storage pool drive " . $this->drive . " will be removed from the storage pool.");
			echo("Storage pool drive " . $this->drive . " will be removed from the storage pool.\n");

			// global $going_drive; // Used in function is_greyhole_owned_drive()
			$going_drive = $this->drive;

			// fsck shares with only 1 file copy to remove those from $this->drive
			initialize_fsck_report('Shares with only 1 copy');
			foreach ($shares_options as $share_name => $share_options) {
				if ($share_options['num_copies'] == 1) {
					$this->logn("Moving file copies for share '$share_name'... Please be patient... ");
					if (is_dir("$going_drive/$share_name")) {
						gh_fsck($share_options['landing_zone'], $share_name);
					}
					$this->log("Done.");
				} else {
					fix_symlinks_on_share($share_name);
				}
			}
		}

		// Remove $going_drive from config file and restart (if it was running)
		$escaped_drive = str_replace('/', '\/', $this->drive);
		exec("/bin/sed -i 's/^.*storage_pool_directory.*$escaped_drive.*$//' " . escapeshellarg($config_file)); // Deprecated notation
		exec("/bin/sed -i 's/^.*storage_pool_drive.*$escaped_drive.*$//' " . escapeshellarg($config_file));
		$this->restart_service();

		// For Amahi users
		if (file_exists('/usr/bin/hdactl')) {
			$this->log("You should de-select this partition in your Amahi dashboard (http://hda), in the Shares > Storage Pool page.");
		}

		mark_gone_ok($this->drive, 'remove');
		mark_gone_drive_fscked($this->drive, 'remove');
		Log::log(INFO, "Storage pool drive " . $this->drive . " has been removed.");
		$this->log("Storage pool drive $this->drive has been removed from your pool, which means the missing file copies that are in this drive will be re-created during the next fsck.");

		if ($this->action == 'going') {
			// Schedule fsck for all shares to re-create missing copies on other shares
			schedule_fsck_all_shares();
			$this->log("All the files that were only on $going_drive have been copied somewhere else.");
			$this->log("A fsck of all shares has been scheduled, to recreate other file copies. It will start after all currently pending tasks have been completed.");
			unlink($this->drive . "/.greyhole_used_this");
		} else { // $this->action == 'gone'
			$this->log("Sadly, file copies that were only on this drive, if any, are now lost!");
		}
	}
}

?>
