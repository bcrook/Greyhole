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

class RemoveDirectoryTask extends Task {
    public function execute() {
        parent::execute();
        
        $share = $this->task->share;
        $full_path = $this->task->full_path;
        
    	global $storage_pool_drives, $trash_share_names;
    	$landing_zone = get_share_landing_zone($share);
    	if (!$landing_zone) {
    		return;
    	}

    	Log::info("Directory deleted: $landing_zone/$full_path");

    	if (array_search($share, $trash_share_names) !== FALSE) {
    		// Remove that directory from all trashes
    		foreach ($storage_pool_drives as $sp_drive) {
    			if (@rmdir("$sp_drive/.gh_trash/$full_path")) {
    				Log::debug("  Removed copy from trash at $sp_drive/.gh_trash/$full_path");
    			}
    		}
    		return;
    	}

    	foreach ($storage_pool_drives as $sp_drive) {
    		if (@rmdir("$sp_drive/$share/$full_path/")) {
    			Log::debug("  Removed copy at $sp_drive/$share/$full_path");
    		}
    		$metastore = "$sp_drive/.gh_metastore";
    		if (@rmdir("$metastore/$share/$full_path/")) {
    			Log::debug("  Removed metadata files directory $metastore/$share/$full_path");
    		}
    	}
    }
}
?>
