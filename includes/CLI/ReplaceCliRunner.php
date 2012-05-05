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

class ReplaceCliRunner extends AbstractCliRunner {
	private $drive;

	function __construct($options) {
		parent::__construct($options);

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
			$this->log("Please use one of the following with the --replace option:");
			$this->log("  " . implode("\n  ", $storage_pool_drives));
			$this->finish(0);
		}
	}

	public function run() {
		if (!is_dir($this->drive)) {
			$this->log("The directory $this->drive does not exists. Greyhole can't --replace directories that don't exits.");
			$this->finish(1);
		}

		remove_drive_definition($this->drive);

		$this->log("Storage pool drive $this->drive has been marked replaced. The Greyhole daemon will now be restarted to allow it to use this new drive.");
		$this->restart_service();
	}
}

?>
