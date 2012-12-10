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
    private $fix_symlinks_scanned_dirs;
    
    public function execute() {
        parent::execute();
        
    	global $storage_pool_drives, $sleep_before_task;

        $share = $this->task->share;
        $full_path = $this->task->full_path;
        $target_full_path = $this->task->additional_info;

    	$landing_zone = get_share_landing_zone($share);
    	if (!$landing_zone) {
    		return;
    	}

		$this->fix_symlinks_scanned_dirs = array();

    	if (is_dir("$landing_zone/$target_full_path") || Metastore::is_dir("$share/$full_path")) {
    		Log::info("Directory renamed: $landing_zone/$full_path -> $landing_zone/$target_full_path");

    		foreach ($storage_pool_drives as $sp_drive) {
    			if (!StoragePoolHelper::is_drive_ok($sp_drive)) {
    				continue;
    			}
    			list($original_path, $dirname) = explode_full_path(get_share_landing_zone($share) . "/$target_full_path");

    			if (is_dir("$sp_drive/$share/$full_path")) {
    				# Make sure the parent directory of target_full_path exists, before we try moving something there...
    				list($path, $dirname) = explode_full_path("$sp_drive/$share/$target_full_path");
    				gh_mkdir($path, $original_path);

    				gh_rename("$sp_drive/$share/$full_path", "$sp_drive/$share/$target_full_path");
    				Log::debug("  Directory moved: $sp_drive/$share/$full_path -> $sp_drive/$share/$target_full_path");
    			}

    			list($path, $dirname) = explode_full_path("$sp_drive/.gh_metastore/$share/$target_full_path");
    			gh_mkdir($path, $original_path);
    			$result = @gh_rename("$sp_drive/.gh_metastore/$share/$full_path", "$sp_drive/.gh_metastore/$share/$target_full_path");
    			if ($result) {
    				Log::debug("  Metadata Store directory moved: $sp_drive/.gh_metastore/$share/$full_path -> $sp_drive/.gh_metastore/$share/$target_full_path");
    			}
    			$result = @gh_rename("$sp_drive/.gh_metastore_backup/$share/$full_path", "$sp_drive/.gh_metastore_backup/$share/$target_full_path");
    			if ($result) {
    				Log::debug("  Backup Metadata Store directory moved: $sp_drive/.gh_metastore_backup/$share/$full_path -> $sp_drive/.gh_metastore_backup/$share/$target_full_path");
    			}
    		}

    		foreach (Metastore::metafiles_for_dir($share, $target_full_path, METAFILES_OPTION_NO_SYMLINK_CHECK) as $existing_metafiles) {
    			foreach ($existing_metafiles as $file_path => $file_metafiles) {
    				Log::debug("  File metafiles: " . count($file_metafiles));
    				$new_file_metafiles = array();
    				$symlinked = FALSE;
    				foreach ($file_metafiles as $key => $metafile) {
    					$old_path = $metafile->path;
    					$metafile->path = str_replace("/$share/$full_path/$file_path", "/$share/$target_full_path/$file_path", $metafile->path);
    					Log::debug("  Changing metadata file: $old_path -> $metafile->path");
    					$new_file_metafiles[$metafile->path] = $metafile;

    					// is_linked = is the target of the existing symlink
    					if ($metafile->is_linked) {
    						$symlinked = TRUE;
    						$symlink_target = $metafile->path;
    					}
    				}
    				if (!$symlinked && count($file_metafiles) > 0) {
    					// None of the metafiles were is_linked; use the last one for the symlink.
    					$metafile->is_linked = TRUE;
    					$file_metafiles[$key] = $metafile;
    					$symlink_target = $metafile->path;
    				}

    				if (is_link("$landing_zone/$target_full_path/$file_path") && readlink("$landing_zone/$target_full_path/$file_path") != $symlink_target) {
    					Log::debug("  Updating symlink at $landing_zone/$target_full_path/$file_path to point to $symlink_target");
    					unlink("$landing_zone/$target_full_path/$file_path");
    					symlink($symlink_target, "$landing_zone/$target_full_path/$file_path");
    				} else if (is_link("$landing_zone/$full_path/$file_path") && !file_exists(readlink("$landing_zone/$full_path/$file_path"))) {
    					Log::debug("  Updating symlink at $landing_zone/$full_path/$file_path to point to $symlink_target");
    					unlink("$landing_zone/$full_path/$file_path");
    					symlink($symlink_target, "$landing_zone/$full_path/$file_path");
    				} else {
    					$this->fix_symlinks($landing_zone, $share, "$full_path/$file_path", "$target_full_path/$file_path");
    				}

    				list($path, $filename) = explode_full_path("$target_full_path/$file_path");
    				Metastore::save_metafiles($share, $path, $filename, $new_file_metafiles);
    			}
    		}
    	} else {
    		Log::info("File renamed: $landing_zone/$full_path -> $landing_zone/$target_full_path");

    		// Check if another process locked this file before we work on it.
    		global $locked_files;
    		if (isset($locked_files[clean_dir("$share/$target_full_path")]) || file_is_locked($share, $target_full_path) !== FALSE) {
    			Log::debug("  File $landing_zone/$target_full_path is locked by another process. Will wait until it's unlocked to work on it.");
    			$this->task->postpone();
    			$locked_files[clean_dir("$share/$target_full_path")] = TRUE;
    			return;
    		}

    		list($path, $filename) = explode_full_path($full_path);
    		list($target_path, $target_filename) = explode_full_path($target_full_path);

    		foreach (Metastore::metafiles_for_file($share, $path, $filename, METAFILES_OPTION_NO_SYMLINK_CHECK) as $existing_metafiles) {
    			// There might be old metafiles... for example, when a delete task was skipped.
    			// Let's remove the file copies if there are any leftovers; correct copies will be re-created below.
    			if (file_exists("$landing_zone/$target_full_path") && (count($existing_metafiles) > 0 || !is_link("$landing_zone/$target_full_path"))) {
    				foreach (Metastore::metafiles_for_file($share, $target_path, $target_filename, METAFILES_OPTION_LOAD_NOK|METAFILES_OPTION_NO_SYMLINK_CHECK) as $existing_target_metafiles) {
    					if (count($existing_target_metafiles) > 0) {
    						foreach ($existing_target_metafiles as $metafile) {
    							gh_recycle($metafile->path);
    						}
    						Metastore::remove_metafiles($share, $target_path, $target_filename);
    					}
    				}
    			}

    			if (count($existing_metafiles) == 0) {
    				// Any NOK metafiles that need to be removed?
    				foreach (Metastore::metafiles_for_file($share, $path, $filename, METAFILES_OPTION_LOAD_NOK|METAFILES_OPTION_NO_SYMLINK_CHECK) as $all_existing_metafiles) {
    					if (count($all_existing_metafiles) > 0) {
    						Metastore::remove_metafiles($share, $path, $filename);
    					}
    				}
    				// New file
    				gh_write($share, $target_full_path, $this->task->id);
    			} else {
    				$symlinked = FALSE;
    				foreach ($existing_metafiles as $key => $metafile) {
    					$old_path = $metafile->path;
    					$metafile->path = str_replace("/$share/$full_path", "/$share/$target_full_path", $old_path);
    					Log::debug("  Renaming copy at $old_path to $metafile->path");

    					// Make sure the target directory exists
    					list($metafile_dir_path, $metafile_filename) = explode_full_path($metafile->path);
    					list($original_path, $dirname) = explode_full_path(get_share_landing_zone($share) . "/$target_full_path");
    					gh_mkdir($metafile_dir_path, $original_path);

    					$it_worked = gh_rename($old_path, $metafile->path);

    					if ($it_worked) {
    						// is_linked = is the target of the existing symlink
    						if ($metafile->is_linked) {
    							$symlinked = TRUE;
    							$symlink_target = $metafile->path;
    						}
    					} else {
    						Log::warn("    Warning! An error occured while renaming file copy $old_path to $metafile->path.");
    					}
    					$existing_metafiles[$key] = $metafile;
    				}
    				if (!$symlinked && count($existing_metafiles) > 0) {
    					// None of the metafiles were is_linked; use the last one for the symlink.
    					$metafile->is_linked = TRUE;
    					$existing_metafiles[$key] = $metafile;
    					$symlink_target = $metafile->path;
    				}
    				Metastore::remove_metafiles($share, $path, $filename);
    				Metastore::save_metafiles($share, $target_path, $target_filename, $existing_metafiles);

    				if (is_link("$landing_zone/$target_full_path")) {
    					// New link exists...
    					if (readlink("$landing_zone/$target_full_path") != $symlink_target) {
    						// ...and needs to be updated.
    						Log::debug("  Updating symlink at $landing_zone/$target_full_path to point to $symlink_target");
    						unlink("$landing_zone/$target_full_path");
    						symlink($symlink_target, "$landing_zone/$target_full_path");
    					}
    				} else if (is_link("$landing_zone/$full_path") && !file_exists(readlink("$landing_zone/$full_path"))) {
    					Log::debug("  Updating symlink at $landing_zone/$full_path to point to $symlink_target");
    					unlink("$landing_zone/$full_path");
    					symlink($symlink_target, "$landing_zone/$full_path");
    				} else {
    					$this->fix_symlinks($landing_zone, $share, $full_path, $target_full_path);
    				}
    			}
    		}
    	}
    	$sleep_before_task = array();
    }

    private function fix_symlinks($landing_zone, $share, $full_path, $target_full_path) {
    	global $storage_pool_drives;
    	if (isset($this->fix_symlinks_scanned_dirs[$landing_zone])) {
    		return;
    	}
    	Log::info("  Scanning $landing_zone for broken links... This can take a while!");
    	exec("find -L " . escapeshellarg($landing_zone) . " -type l", $broken_links);
    	Log::debug("    Found " . count($broken_links) . " broken links.");
    	foreach ($broken_links as $broken_link) {
    		$fixed_link_target = readlink($broken_link);
    		foreach ($storage_pool_drives as $sp_drive) {
    			$fixed_link_target = str_replace(clean_dir("$sp_drive/$share/$full_path/"), clean_dir("$sp_drive/$share/$target_full_path/"), $fixed_link_target);
    			if ($fixed_link_target == "$sp_drive/$share/$full_path") {
    				$fixed_link_target = "$sp_drive/$share/$target_full_path";
    				break;
    			}
    		}
    		if (gh_is_file($fixed_link_target)) {
    			Log::debug("  Found a broken symlink to update: $broken_link. Old (broken) target: " . readlink($broken_link) . "; new (fixed) target: $fixed_link_target");
    			unlink($broken_link);
    			symlink($fixed_link_target, $broken_link);
    		}
    	}
    	$this->fix_symlinks_scanned_dirs[$landing_zone] = TRUE;
    }
}
?>
