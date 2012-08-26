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

class StoragePoolHelper {

    protected static $greyhole_owned_drives = array();
    
    public static function is_drive_ok($sp_drive) {
    	global $going_drive, $df_cache_time;
    	if (isset($going_drive) && $sp_drive == $going_drive) {
    		return FALSE;
    	}
    	$is_greyhole_owned_drive = isset(self::$greyhole_owned_drives[$sp_drive]);
    	if ($is_greyhole_owned_drive && self::$greyhole_owned_drives[$sp_drive] < time() - $df_cache_time) {
    		unset(self::$greyhole_owned_drives[$sp_drive]);
    		$is_greyhole_owned_drive = FALSE;
    	}
    	if (!$is_greyhole_owned_drive) {
    		$drives_definitions = Settings::get('sp_drives_definitions', TRUE);
    		if (!$drives_definitions) {
    			$drives_definitions = convert_sp_drives_tag_files();
    		}
    		$drive_uuid = gh_dir_uuid($sp_drive);
    		$is_greyhole_owned_drive = @$drives_definitions[$sp_drive] === $drive_uuid && $drive_uuid !== FALSE;
    		if (!$is_greyhole_owned_drive) {
    			// Maybe this is a remote mount? Those don't have UUIDs, so we use the .greyhole_uses_this technique.
    			$is_greyhole_owned_drive = file_exists("$sp_drive/.greyhole_uses_this");
    			if ($is_greyhole_owned_drive && isset($drives_definitions[$sp_drive])) {
    				// This remote drive was listed in MySQL; it shouldn't be. Let's remove it.
    				unset($drives_definitions[$sp_drive]);
    				Settings::set('sp_drives_definitions', $drives_definitions);
    			}
    		}
    		if ($is_greyhole_owned_drive) {
    			self::$greyhole_owned_drives[$sp_drive] = time();
    		}
    	}
    	return $is_greyhole_owned_drive;
    }

	public static function check_storage_pool_drives($skip_fsck=FALSE) {
    	global $storage_pool_drives, $email_to, $gone_ok_drives;
    	Log::set_action('check_pool');
    	$needs_fsck = FALSE;
    	$returned_drives = array();
    	$missing_drives = array();
    	$i = 0; $j = 0;
    	foreach ($storage_pool_drives as $sp_drive) {
    		if (!self::is_drive_ok($sp_drive) && !gone_fscked($sp_drive, $i++ == 0) && !file_exists("$sp_drive/.greyhole_used_this")) {
    			if($needs_fsck !== 2){	
    				$needs_fsck = 1;
    			}
    			mark_gone_drive_fscked($sp_drive);
    			$missing_drives[] = $sp_drive;
    			Log::log(WARN, "Warning! It seems the partition UUID of $sp_drive changed. This probably means this mount is currently unmounted, or that you replaced this drive and didn't use 'greyhole --replace'. Because of that, Greyhole will NOT use this drive at this time.");
    			Log::log(DEBUG, "Email sent for gone drive: $sp_drive");
    			$gone_ok_drives[$sp_drive] = TRUE; // The upcoming fsck should not recreate missing copies just yet
    		} else if ((gone_ok($sp_drive, $j++ == 0) || gone_fscked($sp_drive, $i++ == 0)) && self::is_drive_ok($sp_drive)) {
    			// $sp_drive is now back
    			$needs_fsck = 2;
    			$returned_drives[] = $sp_drive;
    			Log::log(DEBUG, "Email sent for revived drive: $sp_drive");

    			mark_gone_ok($sp_drive, 'remove');
    			mark_gone_drive_fscked($sp_drive, 'remove');
    			$i = 0; $j = 0;
    		}
    	}
    	if(count($returned_drives) > 0) {
    		$body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives came back:\n";
    		foreach ($returned_drives as $sp_drive) {
      			$body .= "$sp_drive was missing; it's now available again.\n";
    		}
    		if (!$skip_fsck) {
    			$body .= "\nA fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
    		}
    		$drive_string = join(", ", $returned_drives);
    		$subject = "Storage pool drive now online on " . exec ('hostname') . ": ";
    		$subject = $subject . $drive_string;
    		if (strlen($subject) > 255) {
    			$subject = substr($subject, 0, 255);
    		}
    		mail($email_to, $subject, $body);
    	}
    	if(count($missing_drives) > 0) {
    		$body = "This is an automated email from Greyhole.\n\nOne (or more) of your storage pool drives has disappeared:\n";

    		$drives_definitions = Settings::get('sp_drives_definitions', TRUE);
    		foreach ($missing_drives as $sp_drive) {
    			if (!is_dir($sp_drive)) {
    	  			$body .= "$sp_drive: directory doesn't exists\n";
    			} else {
    				$current_uuid = gh_dir_uuid($sp_drive);
    				if (empty($current_uuid)) {
    					$current_uuid = 'N/A';
    				}
    				$body .= "$sp_drive: expected partition UUID: " . $drives_definitions[$sp_drive] . "; current partition UUID: $current_uuid\n";
    			}
    		}
    		$sp_drive = $missing_drives[0];
    		$body .= "\nThis either means this mount is currently unmounted, or you forgot to use 'greyhole --replace' when you changed this drive.\n\n";
    		$body .= "Here are your options:\n\n";
    		$body .= "- If you forgot to use 'greyhole --replace', you should do so now. Until you do, this drive will not be part of your storage pool.\n\n";
    		$body .= "- If the drive is gone, you should either re-mount it manually (if possible), or remove it from your storage pool. To do so, use the following command:\n  greyhole --gone=" . escapeshellarg($sp_drive) . "\n  Note that the above command is REQUIRED for Greyhole to re-create missing file copies before the next fsck runs. Until either happens, missing file copies WILL NOT be re-created on other drives.\n\n";
    		$body .= "- If you know this drive will come back soon, and do NOT want Greyhole to re-create missing file copies for this drive until it reappears, you should execute this command:\n  greyhole --wait-for=" . escapeshellarg($sp_drive) . "\n\n";
    		if (!$skip_fsck) {
    			$body .= "A fsck will now start, to fix the symlinks found in your shares, when possible.\nYou'll receive a report email once that fsck run completes.\n";
    		}
    		$subject = "Missing storage pool drives on " . exec('hostname') . ": ";
    		$drive_string = join(",",$missing_drives);
    		$subject = $subject . $drive_string;
    		if (strlen($subject) > 255) {
    			$subject = substr($subject, 0, 255);
    		}
    		mail($email_to, $subject, $body);
    	}
    	if ($needs_fsck !== FALSE) {
    		Settings::set_metastore_backup();
    		get_metastores(FALSE); // FALSE => Resets the metastores cache
    		clearstatcache();

    		if (!$skip_fsck) {
    			global $shares_options;
    			initialize_fsck_report('All shares');
    			if($needs_fsck === 2) {
    				foreach ($returned_drives as $drive) {
    					$metastores = get_metastores_from_storage_volume($drive);
    					Log::log(INFO, "Starting fsck for metadata store on $drive which came back online.");
    					foreach($metastores as $metastore) {
    						foreach($shares_options as $share_name => $share_options) {
    							gh_fsck_metastore($metastore,"/$share_name", $share_name);
    						}
    					}
    					Log::log(INFO, "fsck for returning drive $drive's metadata store completed.");
    				}
    				Log::log(INFO, "Starting fsck for all shares - caused by missing drive that came back online.");
    			}else{
    				Log::log(INFO, "Starting fsck for all shares - caused by missing drive. Will just recreate symlinks to existing copies when possible; won't create new copies just yet.");
    				fix_all_symlinks();
    			}
    			schedule_fsck_all_shares(array('email'));
    			Log::log(INFO, "  fsck for all shares scheduled.");
    		}

    		// Refresh $gone_ok_drives to it's real value (from the DB)
    		get_gone_ok_drives();
    	}
    }
}
?>
