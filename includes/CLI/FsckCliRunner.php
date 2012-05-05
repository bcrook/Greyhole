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

class FsckCliRunner extends AbstractCliRunner {
	private $dir = '';
	private $fsck_options = array();

	function __construct($options) {
		parent::__construct($options);

		if (isset($this->options['dir'])) {
			$this->dir = $this->options['dir'];
			if (!is_dir($this->dir)) {
				$this->log("$this->dir is not a directory. Exiting.");
				$this->finish(1);
			}
		}
		if (isset($this->options['email-report'])) {
			$this->fsck_options[] = 'email';
		}
		if (!isset($this->options['dont-walk-metadata-store'])) {
			$this->fsck_options[] = 'metastore';
		}
		if (isset($this->options['if-conf-changed'])) {
			$this->fsck_options[] = 'if-conf-changed';
		}
		if (isset($this->options['disk-usage-report'])) {
			$this->fsck_options[] = 'du';
		}
		if (isset($this->options['find-orphaned-files'])) {
			$this->fsck_options[] = 'orphaned';
		}
		if (isset($this->options['checksums'])) {
			$this->fsck_options[] = 'checksums';
		}
		if (isset($this->options['delete-orphaned-metadata'])) {
			$this->fsck_options[] = 'del-orphaned-metadata';
		}
	}

	public function run() {
		global $argv, $greyhole_log_file;
		
		$pos = array_search('fsck', $argv);
		
		if (empty($this->dir)) {
			schedule_fsck_all_shares($this->fsck_options);
			$this->dir = 'all shares';
		} else {
			$query = sprintf("INSERT INTO tasks (action, share, additional_info, complete) VALUES ('fsck', '%s', %s, 'yes')",
				db_escape_string($this->dir),
				(!empty($this->fsck_options) ? "'" . implode('|', $this->fsck_options) . "'" : "NULL")
			);
			db_query($query) or gh_log(CRITICAL, "Can't insert fsck task: " . db_error());
		}
		$this->log("fsck of $this->dir has been scheduled. It will start after all currently pending tasks have been completed.");
		if (isset($this->options['checksums'])) {
			$this->log("Any mismatch in checksums will be logged in both $greyhole_log_file and " . FSCKLogFile::PATH . "/fsck_checksums.log");
		}
	}
}

?>
