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

class WriteFileTask extends Task {
    public function execute() {
        parent::execute();
        
		$share = $this->task->share;
		$full_path = $this->task->full_path;
		$task = $this->task;
		
		global $storage_pool_drives, $sleep_before_task;

    	$landing_zone = get_share_landing_zone($share);
    	if (!$landing_zone) {
    		return;
    	}

    	if (!gh_file_exists("$landing_zone/$full_path")) {
    		Log::info("$real_path doesn't exist anymore.");
    		$new_full_path = find_future_full_path($share, $full_path, $task->id);
    		if ($new_full_path != $full_path && gh_is_file("$landing_zone/$new_full_path")) {
    			Log::debug("  Found that $full_path has been renamed to $new_full_path. Will work using that instead.");
    			if (is_link("$landing_zone/$new_full_path")) {
    				$source_file = clean_dir(readlink("$landing_zone/$new_full_path"));
    			} else {
    				$source_file = clean_dir("$landing_zone/$new_full_path");
    			}
    		} else {
    			Log::info("  Skipping.");
    			if (!gh_file_exists($landing_zone)) {
    				Log::info("  The landing zone of share '$share', $real_path, doesn't exist anymore. Will not process this task until it re-appears...");
    				$task->postpone();
    			}
    			return;
    		}
    	}

    	$num_copies_required = get_num_copies($share);
    	if ($num_copies_required === -1) {
    		return;
    	}

    	list($path, $filename) = explode_full_path($full_path);

    	if ((isset($new_full_path) && is_link("$landing_zone/$new_full_path")) || is_link("$landing_zone/$full_path")) {
    		if (!isset($source_file)) {
    			$source_file = clean_dir(readlink("$landing_zone/$full_path"));
    		}
    		clearstatcache();
    		$filesize = gh_filesize($source_file);
    		if (Log::$level >= DEBUG) {
    			Log::info("File changed: $share/$full_path - " . bytes_to_human($filesize, FALSE));
    		} else {
    			Log::info("File changed: $share/$full_path");
    		}
    		Log::debug("  Will use source file: $source_file");

    		foreach (Metastore::metafiles_for_file($share, $path, $filename, METAFILES_OPTION_LOAD_NOK) as $existing_metafiles) {
    			// Remove old copies (but not the one that was updated!)
    			$keys_to_remove = array();
    			$found_source_file = FALSE;
    			foreach ($existing_metafiles as $key => $metafile) {
    				$metafile->path = clean_dir($metafile->path);
    				if ($metafile->path == $source_file) {
    					$metafile->is_linked = TRUE;
    					$metafile->state = 'OK';
    					$found_source_file = TRUE;
    				} else {
    					Log::debug("  Will remove copy at $metafile->path");
    					$keys_to_remove[] = $metafile->path;
    				}
    			}
    			if (!$found_source_file && count($keys_to_remove) > 0) {
    				// This shouldn't happen, but if we're about to remove all copies, let's make sure we keep at least one.
    				$key = array_shift($keys_to_remove);
    				$source_file = $existing_metafiles[$key]->path;
    				Log::debug("  Change of mind... Will use source file: $source_file");
    			}
    			foreach ($keys_to_remove as $key) {
    				if ($existing_metafiles[$key]->path != $source_file) {
    					gh_recycle($existing_metafiles[$key]->path);
    				}
    				unset($existing_metafiles[$key]);
    			}
    			$this->process_metafiles($num_copies_required, $existing_metafiles, $source_file, $filesize);
    		}
    	} else {
    		if (!isset($source_file)) {
    			$source_file = clean_dir("$landing_zone/$full_path");
    		}
    		clearstatcache();
    		$filesize = gh_filesize($source_file);
    		if (Log::$level >= DEBUG) {
    			Log::info("File created: $share/$full_path - " . bytes_to_human($filesize, FALSE));
    		} else {
    			Log::info("File created: $share/$full_path");
    		}

    		if (is_dir($source_file)) {
    			Log::info("$share/$full_path is now a directory! Aborting.");
    			return;
    		}

    		// There might be old metafiles... for example, when a delete task was skipped.
    		// Let's remove the file copies if there are any leftovers; correct copies will be re-created in self::create_copies_from_metafiles()
    		foreach (Metastore::metafiles_for_file($share, $path, $filename) as $existing_metafiles) {
    			Log::debug(count($existing_metafiles) . " metafiles loaded.");
    			if (count($existing_metafiles) > 0) {
    				foreach ($existing_metafiles as $metafile) {
    					gh_recycle($metafile->path);
    				}
    				Metastore::remove_metafiles($share, $path, $filename);
    				$existing_metafiles = array();
    				// Maybe there's other file copies, that weren't metafiles, or were NOK metafiles!
    				global $storage_pool_drives;
    				foreach ($storage_pool_drives as $sp_drive) {
    					if (file_exists("$sp_drive/$share/$path/$filename")) {
    						gh_recycle("$sp_drive/$share/$path/$filename");
    					}
    				}
    			}
    			$this->process_metafiles($num_copies_required, $existing_metafiles, $source_file, $filesize);
    		}
    	}
    }

    private function process_metafiles($num_copies_required, $existing_metafiles, $source_file, $filesize) {
		$share = $this->task->share;
		$full_path = $this->task->full_path;
		$task = $this->task;
		
		$landing_zone = get_share_landing_zone($share);
    	list($path, $filename) = explode_full_path($full_path);

    	// Only need to check for locking if we have something to do!
    	if ($num_copies_required > 1 || count($existing_metafiles) == 0) {
    		// Check if another process locked this file before we work on it.
    		global $locked_files;
    		if (isset($locked_files[clean_dir("$share/$full_path")]) || ($locked_by = file_is_locked($share, $full_path)) !== FALSE) {
    			Log::debug("  File $landing_zone/$full_path is locked by another process. Will wait until it's unlocked to work on it.");
    			$task->postpone();
    			$locked_files[clean_dir("$share/$full_path")] = TRUE;
    			return;
    		}
    		$sleep_before_task = array();
    	}

    	$metafiles = Metastore::create_metafiles($share, $full_path, $num_copies_required, $filesize, $existing_metafiles);

    	if (count($metafiles) == 0) {
    		Log::warn("  No metadata files could be created. Will wait until metadata files can be created to work on this file.");
    		$task->postpone();
    		return;
    	}

    	if (!is_link("$landing_zone/$full_path")) {
    		// Use the 1st metafile for the symlink; it might be on a sticky drive.
    		$i = 0;
    		foreach ($metafiles as $metafile) {
    			$metafile->is_linked = ($i++ == 0);
    		}
    	}

    	Metastore::save_metafiles($share, $path, $filename, $metafiles);

    	self::create_copies_from_metafiles($metafiles, $share, $full_path, $source_file);
    }

    public static function create_copies_from_metafiles($metafiles, $share, $full_path, $source_file, $missing_only=FALSE) {
    	$landing_zone = get_share_landing_zone($share);

    	list($path, $filename) = explode_full_path($full_path);

    	$source_file = clean_dir($source_file);

    	$link_next = FALSE;
    	$file_infos = gh_get_file_infos("$landing_zone/$full_path");
    	foreach ($metafiles as $key => $metafile) {
    		if (!gh_file_exists("$landing_zone/$full_path")) {
    			Log::info("$real_path doesn't exist anymore. Aborting.");
    			return;
    		}

    		if ($metafile->path == $source_file && $metafile->state == 'OK' && gh_filesize($metafile->path) == gh_filesize($source_file)) {
    			Log::debug("  File copy at $metafile->path is already up to date.");
    			continue;
    		}

    		if ($missing_only && gh_file_exists($metafile->path) && $metafile->state == 'OK' && gh_filesize($metafile->path) == gh_filesize($source_file)) {
    			Log::debug("  File copy at $metafile->path is already up to date.");
    			continue;
    		}

    		if (is_link($source_file)) {
    			$source_size = gh_filesize(readlink($source_file));
    		} else if (gh_is_file($source_file)) {
    			$source_size = gh_filesize($source_file);
    		}
    		if (isset($source_size)) {
    			Log::debug("  Copying " . bytes_to_human($source_size, FALSE) . " file to $metafile->path");
    		} else {
    			Log::debug("  Copying file to $metafile->path");
    		}

    		$root_path = str_replace(clean_dir("/$share/$full_path"), '', $metafile->path);
    		if (!StoragePoolHelper::is_drive_ok($root_path)) {
    			Log::warn("  Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
    			$metafile->state = 'Gone';
    			$metafiles[$key] = $metafile;
    			continue;
    		}

    		list($metafile_dir_path, $metafile_filename) = explode_full_path($metafile->path);

    		list($original_path, $metafile_filename) = explode_full_path(get_share_landing_zone($share) . "/$full_path");
    		if (!gh_mkdir($metafile_dir_path, $original_path)) {
    			$metafile->state = 'Gone';
    			$metafiles[$key] = $metafile;
    			continue;
    		}

    		$temp_path = get_temp_filename($metafile->path);

    		$copied = FALSE;
    		$it_worked = FALSE;
    		$start_time = time();
    		if (is_link($source_file)) {
    			exec(get_copy_cmd(readlink($source_file), $temp_path));
    			$it_worked = file_exists($temp_path) && gh_filesize($temp_path) == $source_size;
    		} else if (gh_is_file($source_file)) {
    			$source_dev = gh_file_deviceid($source_file);
    			$target_dev = gh_file_deviceid($metafile_dir_path);
    			if ($source_dev === $target_dev && $source_dev !== FALSE) {
    				Log::debug("  (using rename)");
    				gh_rename($source_file, $temp_path);
    				$copied = FALSE;
    			} else {
    				exec(get_copy_cmd($source_file, $temp_path));
    				$copied = TRUE;
    			}
    			$it_worked = file_exists($temp_path) && gh_filesize($temp_path) == $source_size;
    		}

    		if ($it_worked) {
    			if (time() - $start_time > 0) {
    				$speed = number_format($source_size/1024/1024 / (time() - $start_time), 1);
    				Log::debug("    Copy created at $speed MBps.");
    			}
    			gh_rename($temp_path, $metafile->path);
    			gh_chperm($metafile->path, $file_infos);
    		} else {
    			Log::warn("    Failed file copy. Will mark this metadata file 'Gone'.");
    			@unlink($temp_path);
    			if ($metafile->is_linked) {
    				$metafile->is_linked = FALSE;
    				$link_next = TRUE;
    			}
    			$metafile->state = 'Gone';
    			gh_recycle($metafile->path);
    			$metafiles[$key] = $metafile;
    			Metastore::save_metafiles($share, $path, $filename, $metafiles);

    			if (file_exists("$landing_zone/$full_path")) {
    				global $current_task_id;
    				if ($current_task_id === 0) {
    					Log::error("    Failed file copy (cont). We already retried this task. Aborting.");
    					return;
    				}
    				Log::warn("    Failed file copy (cont). Will try to re-process this write task, since the source file seems intact.");
    				// Queue a new write task, to replace the now gone copy.
    				global $next_task;
    				$next_task = (object) array(
    					'id' => 0,
    					'action' => 'write',
    					'share' => $share, 
    					'full_path' => clean_dir($full_path),
    					'complete' => 'yes'
    				);
    				return;
    			}
    			continue;
    		}

    		if ($link_next && !$metafile->is_linked) {
    			$metafile->is_linked = TRUE;
    			$metafiles[$key] = $metafile;
    		}
    		$link_next = FALSE;
    		if ($metafile->is_linked) {
    			Log::debug("    Creating symlink in share pointing to the above file copy.");
    			symlink($metafile->path, "$landing_zone/$path/.gh_$filename");
    			if (!file_exists("$landing_zone/$full_path") || unlink("$landing_zone/$full_path")) {
    				gh_rename("$landing_zone/$path/.gh_$filename", "$landing_zone/$path/$filename");
    			} else {
    				unlink("$landing_zone/$path/.gh_$filename");
    			}
    		}

    		if (gh_file_exists($metafile->path)) {
    			Log::info("  Copy at $real_path doesn't exist. Will not mark it OK!");
    			$metafile->state = 'OK';
    			$metafiles[$key] = $metafile;
    		}
    		Metastore::save_metafiles($share, $path, $filename, $metafiles);
    	}
    }
}
?>
