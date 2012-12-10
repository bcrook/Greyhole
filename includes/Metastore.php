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

define('USE_CACHE', 1);
define('SKIP_CACHE', 0);

define('FIRST_ONLY', TRUE);

define('METAFILES_OPTION_LOAD_NOK', 1);
define('METAFILES_OPTION_QUIET', 2);
define('METAFILES_OPTION_NO_SYMLINK_CHECK', 4);

class Metastore {
    static $cached_stores;
    
    public static function get_stores($use_cache=USE_CACHE) {
    	global $storage_pool_drives, $metastore_backup_drives;
    	if (empty(self::$cached_stores) || $use_cache === SKIP_CACHE) {
    		$metastores = array();
    		foreach ($storage_pool_drives as $sp_drive) {
    			if (StoragePoolHelper::is_drive_ok($sp_drive)) {
    				$metastores[] = "$sp_drive/.gh_metastore";
    			}
    		}
    		foreach ($metastore_backup_drives as $metastore_backup_drive) {
    			if (StoragePoolHelper::is_drive_ok(str_replace('/.gh_metastore_backup', '', $metastore_backup_drive))) {
    				$metastores[] = $metastore_backup_drive;
    			}
    		}
    		self::$cached_stores = $metastores;
    	}
    	return self::$cached_stores;
    }
    
    public static function store_from_path($path) {
    	$metastore_path = FALSE;
    	$max_length = 0;
    	foreach(Metastore::get_stores() as $metastore) {
    		if (mb_strpos($path, $metastore) === 0 && mb_strlen($metastore) > $max_length) {
    			$metastore_path = $metastore;
    			$max_length = mb_strlen($metastore);
    		}	
    	}
    	return $metastore_path;
    }
    
    public static function stores_on_storage_drive($sp_drive) {
    	$metastores = array();
    	if (is_dir("$sp_drive/.gh_metastore")) {
    	    $metastores[] = "$sp_drive/.gh_metastore";
    	}
    	if (is_dir("$sp_drive/.gh_metastore_backup")) {
    	    $metastores[] = "$sp_drive/.gh_metastore_backup";
    	}
    	return $metastores;
    }

    public static function is_dir($path) {
    	foreach (Metastore::get_stores() as $metastore) {
    		if (is_dir("$metastore/$path")) {
    			return TRUE;
    		}
    	}
    	return FALSE;
    }    

    public static function create_metafiles($share, $full_path, $num_copies_required, $filesize, $metafiles=array()) {
    	$found_link_metafile = FALSE;

    	list($path, $filename) = explode_full_path($full_path);

    	$num_ok = count($metafiles);
    	foreach ($metafiles as $key => $metafile) {
    		if (!file_exists($metafile->path)) {
    			// Re-use paths to old file copies that are now gone.
    			// This will allow us to use a new drive that has been installed where an old drive was previously.
    			$metafile->state = 'Pending';
    		}
    		$root_path = str_replace(clean_dir("/$share/$full_path"), '', $metafile->path);
    		if (!StoragePoolHelper::is_drive_ok($root_path)) {
    			$metafile->state = 'Gone';
    		}
    		if ($metafile->state != 'OK' && $metafile->state != 'Pending') {
    			$num_ok--;
    		}
    		if ($key != $metafile->path) {
    			unset($metafiles[$key]);
    			$key = $metafile->path;
    		}
    		if ($metafile->is_linked) {
    			$found_link_metafile = TRUE;
    		}
    		$metafiles[$key] = $metafile;
    	}

    	// Select drives that have enough free space for this file
    	if ($num_ok < $num_copies_required) {
    		$local_target_drives = order_target_drives($filesize/1024, FALSE, $share, $path, '  ');
    	}
    	while ($num_ok < $num_copies_required && count($local_target_drives) > 0) {
    		$sp_drive = array_shift($local_target_drives);
    		$clean_target_full_path = clean_dir("$sp_drive/$share/$full_path");
    		// Don't use drives that already have a copy
    		if (isset($metafiles[$clean_target_full_path])) {
    			continue;
    		}
    		foreach ($metafiles as $metafile) {
    			if ($clean_target_full_path == clean_dir($metafile->path)) {
    				continue;
    			}
    		}
    		// Prepend new target drives, to make sure sticky directories will be used first
    		$metafiles = array_reverse($metafiles);
    		$metafiles[$clean_target_full_path] = (object) array('path' => $clean_target_full_path, 'is_linked' => FALSE, 'state' => 'Pending');
    		$metafiles = array_reverse($metafiles);
    		$num_ok++;
    	}

    	if (!$found_link_metafile) {
    		foreach ($metafiles as $metafile) {
    			$metafile->is_linked = TRUE;
    			break;
    		}
    	}

    	return $metafiles;
    }
                                                                                       
    public static function metafiles_filenames_for_file($file_path, $first_only = FALSE) {
    	$filenames = array();
    	foreach (Metastore::get_stores() as $metastore) {
    		$f = clean_dir("$metastore/$file_path");
    		if (is_file($f)) {
    			$filenames[] = $f;
    			if ($first_only === FIRST_ONLY) {
    			    break;
    			}
    		}
    	}
    	return $filenames;
    }

    public static function first_metafile_filename_for_file($file_path) {
    	$filenames = Metastore::metafiles_filenames_for_file($file_path, FIRST_ONLY);
    	if (count($filenames) > 0) {
    		return $filenames[0];
    	}
    	return FALSE;
    }                                  

    public static function metafiles_for_dir($share, $path, $flags=0) {
    	return new metafile_iterator($share, $path, $flags);
    }
    
    public static function metafiles_for_file($share, $path, $filename=null, $flags=0) {
        $load_nok_metafiles = $flags & METAFILES_OPTION_LOAD_NOK !== 0;
        $quiet = $flags & METAFILES_OPTION_QUIET !== 0;
        $check_symlink = $flags & METAFILES_OPTION_NO_SYMLINK_CHECK === 0;
        
    	if (!$quiet) {
    		Log::debug("Loading metafiles for " . clean_dir($share . (!empty($path) ? "/$path" : "") . "/$filename") . ' ...');
    	}
    	$metafiles_data_file = Metastore::first_metafile_filename_for_file("$share/$path/$filename");
    	clearstatcache();
    	$metafiles = array();
    	if (file_exists($metafiles_data_file)) {
    		$t = file_get_contents($metafiles_data_file);
    		$metafiles = unserialize($t);
    	}

    	if ($check_symlink) {
    		// Fix wrong 'is_linked' flags
    		$share_file = get_share_landing_zone($share) . "/$path/$filename";
    		$share_file_link_to = FALSE;
    		if (is_link($share_file)) {
    			$share_file_link_to = readlink($share_file);
    		}
    		if (!is_array($metafiles)) {
    			$metafiles = array();
    		}
    		foreach ($metafiles as $key => $metafile) {
    			if ($metafile->state == 'OK' && $share_file_link_to !== FALSE) {
    				if ($metafile->is_linked && $metafile->path != $share_file_link_to) {
    					if (!$quiet) {
    						Log::debug('  Changing is_linked to FALSE for ' . $metafile->path);
    					}
    					$metafile->is_linked = FALSE;
    					$metafiles[$key] = $metafile;
    					self::save_metafiles($share, $path, $filename, $metafiles);
    				} else if (!$metafile->is_linked && $metafile->path == $share_file_link_to) {
    					if (!$quiet) {
    						Log::debug('  Changing is_linked to TRUE for ' . $metafile->path);
    					}
    					$metafile->is_linked = TRUE;
    					$metafiles[$key] = $metafile;
    					self::save_metafiles($share, $path, $filename, $metafiles);
    				}
    			}
    		}
    	}

    	$ok_metafiles = array();
    	foreach ($metafiles as $key => $metafile) {
    		$valid_path = FALSE;

    		$drive = get_storage_volume_from_path($metafile->path);
    		if ($drive !== FALSE) {
    			$valid_path = TRUE;
    		}
    		if ($valid_path && ($load_nok_metafiles || $metafile->state == 'OK')) {
    			$key = clean_dir($metafile->path);
    			if (isset($ok_metafiles[$key])) {
    				$previous_metafile = $ok_metafiles[$key];
    				if ($previous_metafile->state == 'OK' && $metafile->state != 'OK') {
    					// Don't overwrite previous OK metafiles with NOK metafiles that point to the same files!
    					continue;
    				}
    			}
    			$ok_metafiles[$key] = $metafile;
    		} else {
    			if (!$valid_path) {
    				Log::warn("Found a metadata file pointing to a drive not defined in your storage pool: '$metafile->path'. Will mark it as Gone.");
    				$metafile->state = 'Gone';
    				$metafiles[$key] = $metafile;
    				self::save_metafiles($share, $path, $filename, $metafiles);
    			} else {
    				#Log::debug("Found a metadata file, pointing to '$metafile->path', with state = '$metafile->state'. We just want 'OK' metadata files; will not use this metadata file.");
    			}
    		}
    	}
    	$metafiles = $ok_metafiles;

    	if (!$quiet) {
    		Log::debug("  Got " . count($metafiles) . " metadata files.");
    	}
    	return $metafiles;
    }

    public static function save_metafiles($share, $path, $filename, $metafiles) {
    	if (count($metafiles) == 0) {
    		Metastore::remove_metafiles($share, $path, $filename);
    		return;
    	}

    	// We don't care about the keys (we'll re-create them on load), so let's not waste disk space, and use numeric indexes.
    	$metafiles = array_values($metafiles);

    	Log::debug("  Saving " . count($metafiles) . " metadata files for " . clean_dir($share . (!empty($path) ? "/$path" : "") . ($filename!== null ? "/$filename" : "")));
    	$paths_used = array();
    	foreach (Metastore::get_stores() as $metastore) {
    		$sp_drive = str_replace('/.gh_metastore', '', $metastore);
    		$data_filepath = clean_dir("$metastore/$share/$path");
    		$has_metafile = FALSE;
    		foreach ($metafiles as $metafile) {
    			if (get_storage_volume_from_path($metafile->path) == $sp_drive && StoragePoolHelper::is_drive_ok($sp_drive)) {
    				gh_mkdir($data_filepath, get_share_landing_zone($share) . "/$path");
    				Log::debug("    Saving metadata in " . clean_dir("$data_filepath/$filename"));
    				if (is_dir("$data_filepath/$filename")) {
    					exec("rm -rf " . escapeshellarg("$data_filepath/$filename"));
    				}
    				file_put_contents("$data_filepath/$filename", serialize($metafiles));
    				$has_metafile = TRUE;
    				$paths_used[] = $data_filepath;
    				break;
    			}
    		}
    		if (!$has_metafile && file_exists("$data_filepath/$filename")) {
    			unlink("$data_filepath/$filename");
    		}
    	}
    	if (count($paths_used) == 1) {
    		// Also save a backup on another drive
    		global $metastore_backup_drives;
    		if (count($metastore_backup_drives) > 0) {
    			if (mb_strpos($paths_used[0], str_replace('.gh_metastore_backup', '.gh_metastore', $metastore_backup_drives[0])) === FALSE) {
    				$metastore_backup_drive = $metastore_backup_drives[0];
    			} else {
    				$metastore_backup_drive = $metastore_backup_drives[1];
    			}
    			$data_filepath = "$metastore_backup_drive/$share/$path";
    			Log::debug("    Saving backup metadata file in $data_filepath/$filename");
    			gh_mkdir($data_filepath, get_share_landing_zone($share) . "/$path");
    			file_put_contents("$data_filepath/$filename", serialize($metafiles));
    		}
    	}
    }

    public static function remove_metafiles($share, $path, $filename) {
    	Log::debug("  Removing metadata files for $share" . (!empty($path) ? "/$path" : "") . (!empty($filename) ? "/$filename" : ""));
    	foreach (Metastore::metafiles_filenames_for_file("$share/$path/$filename") as $f) {
    		@unlink($f);
    		Log::debug("    Removed metadata file at $f");
    		clearstatcache();
    	}
    }
}
?>
