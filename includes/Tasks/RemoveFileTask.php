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

class RemoveFileTask extends Task {
    public function execute() {
        parent::execute();
        
        $share = $this->task->share;
        $full_path = $this->task->full_path;
        $task_id = $this->task->id;
        
    	global $trash_share_names, $trash_share, $storage_pool_drives;

    	$landing_zone = get_share_landing_zone($share);
    	if (!$landing_zone) {
    		return;
    	}

    	Log::info("File deleted: $landing_zone/$full_path");

    	if (array_search($share, $trash_share_names) !== FALSE) {
    		// Will delete the file in the trash which has no corresponding symlink in the Greyhole Trash share.
    		// That symlink is what was deleted from that share to create the task we're currently working on.
    		$full_path = preg_replace('/ copy [0-9]+$/', '', $full_path);
    		Log::debug("  Looking for corresponding file in trash to delete...");
    		foreach ($storage_pool_drives as $sp_drive) {
    			if (file_exists("$sp_drive/.gh_trash/$full_path")) {
    				$delete = TRUE;
    				list($path, $filename) = explode_full_path("{$trash_share['landing_zone']}/$full_path");
    				if ($dh = opendir($path)) {
    					while (($file = readdir($dh)) !== FALSE) {
    						if ($file == '.' || $file == '..') { continue; }
    						if (is_link("$path/$file") && readlink("$path/$file") == "$sp_drive/.gh_trash/$full_path") {
    							$delete = FALSE;
    							continue;
    						}
    					}
    				}
    				if ($delete) {
    					Log::debug("    Deleting corresponding copy $sp_drive/.gh_trash/$full_path");
    					unlink("$sp_drive/.gh_trash/$full_path");
    					break;
    				}
    			}
    		}
    		return;
    	}

    	if (gh_file_exists("$landing_zone/$full_path") && !is_dir("$landing_zone/$full_path")) {
    		Log::debug("  File still exists in landing zone; a new file replaced the one deleted here. Skipping.");
    		return;
    	}

    	list($path, $filename) = explode_full_path($full_path);

    	foreach (Metastore::metafiles_for_file($share, $path, $filename, METAFILES_OPTION_LOAD_NOK) as $existing_metafiles) {
    		foreach ($existing_metafiles as $metafile) {
    			gh_recycle($metafile->path);
    		}
    	}
    	Metastore::remove_metafiles($share, $path, $filename);
    }
}
?>
