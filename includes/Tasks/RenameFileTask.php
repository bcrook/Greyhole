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

class RemameFileTask extends Task {
    public function execute() {
        parent::execute();
        
		$fix_symlinks_scanned_dirs = array();
		// @TODO Remove unneeded params?
		gh_mv($this->task->share, $this->task->full_path, $this->task->additional_info, $this->task);
    }
}
?>
