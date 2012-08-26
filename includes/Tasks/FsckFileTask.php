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

class FsckFileTask extends Task {
    public function execute() {
        parent::execute();
        
        $task = $this->task;
		set_fsck_options($task);
		$task->full_path = get_share_landing_zone($task->share) . '/' . $task->full_path;
		$file_type = @filetype($task->full_path);
		list($path, $filename) = explode_full_path($task->full_path);
		FSCKLogFile::loadFSCKReport('Missing files'); // Create or load the fsck_report from disk
		gh_fsck_file($path, $filename, $file_type, 'metastore', $task->share);
		if ($this->hasOption(OPTION_EMAIL)) {
			// Save the report to disk to be able to email it when we're done with all fsck_file tasks
			FSCKLogFile::saveFSCKReport();
		}
    }
}
?>
