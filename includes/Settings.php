<?php
/*
Copyright 2011-2012 Guillaume Boudreau

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

class Settings {
	public static function get($name, $unserialize=FALSE, $value=FALSE) {
		$query = sprintf("SELECT * FROM settings WHERE name LIKE '%s'", $name);
		if ($value !== FALSE) {
			$query .= sprintf(" AND value LIKE '%s'", $value);
		}
		$result = db_query($query) or Log::log(CRITICAL, "Can't select setting '$name'/'$value' from settings table: " . db_error());
		$setting = db_fetch_object($result);
		if ($setting === FALSE) {
			return FALSE;
		}
		return $unserialize ? unserialize($setting->value) : $setting->value;
	}

	public static function set($name, $value) {
		if (is_array($value)) {
			$value = serialize($value);
		}
		db_insert_setting($name, $value);
		return (object) array('name' => $name, 'value' => $value);
	}

	public static function rename($from, $to) {
		$query = sprintf("UPDATE settings SET name = '%s' WHERE name = '%s'", $to, $from);
		db_query($query) or Log::log(CRITICAL, "Can't rename setting '$from' to '$to': " . db_error());
	}

	public static function backup() {
		global $storage_pool_drives;
		$result = db_query("SELECT * FROM settings") or Log::log(CRITICAL, "Can't select settings for backup: " . db_error());
		$settings = array();
		while ($setting = db_fetch_object($result)) {
			$settings[] = $setting;
		}
		foreach ($storage_pool_drives as $sp_drive) {
			if (is_greyhole_owned_drive($sp_drive)) {
				$settings_backup_file = "$sp_drive/.gh_settings.bak";
				file_put_contents($settings_backup_file, serialize($settings));
			}
		}
	}

	public static function restore() {
		global $storage_pool_drives;
		foreach ($storage_pool_drives as $sp_drive) {
			$settings_backup_file = "$sp_drive/.gh_settings.bak";
			$latest_backup_time = 0;
			if (file_exists($settings_backup_file)) {
				$last_mod_date = filemtime($settings_backup_file);
				if ($last_mod_date > $latest_backup_time) {
					$backup_file = $settings_backup_file;
					$latest_backup_time = $last_mod_date;
				}
			}
		}
		if (isset($backup_file)) {
			Log::log(INFO, "Restoring settings from last backup: $backup_file");
			$settings = unserialize(file_get_contents($backup_file));
			foreach ($settings as $setting) {
				self::set($setting->name, $setting->value);
			}
			return TRUE;
		}
		return FALSE;
	}
	
	public static function set_metastore_backup($try_restore=TRUE) {
		global $metastore_backup_drives, $storage_pool_drives;

		$num_metastore_backups_needed = 2;
		if (count($storage_pool_drives) < 2) {
			$metastore_backup_drives = array();
			return;
		}

		Log::log(DEBUG, "Loading metadata store backup directories...");
		if (empty($metastore_backup_drives)) {
			// In the DB ?
			$metastore_backup_drives = self::get('metastore_backup_directory', TRUE);
			if ($metastore_backup_drives) {
				Log::log(DEBUG, "  Found " . count($metastore_backup_drives) . " directories in the settings table.");
			} else if ($try_restore) {
				// Try to load a backup from the data drive, if we can find one.
				if (self::restore()) {
					self::set_metastore_backup(FALSE);
					return;
				}
			}
		}

		// Verify the drives, if any
		if (empty($metastore_backup_drives)) {
			$metastore_backup_drives = array();
		} else {
			foreach ($metastore_backup_drives as $key => $metastore_backup_drive) {
				if (!is_greyhole_owned_drive(str_replace('/.gh_metastore_backup', '', $metastore_backup_drive))) {
					// Directory is now invalid; stop using it.
					Log::log(DEBUG, "Removing $metastore_backup_drive from available 'metastore_backup_directories' - this directory isn't a Greyhole storage pool drive (anymore?)");
					unset($metastore_backup_drives[$key]);
				} else if (!is_dir($metastore_backup_drive)) {
					// Directory is invalid, but needs to be created (was rm'ed?)
					mkdir($metastore_backup_drive);
				}
			}
		}

		if (empty($metastore_backup_drives) || count($metastore_backup_drives) < $num_metastore_backups_needed) {
			Log::log(DEBUG, "  Missing some drives. Need $num_metastore_backups_needed, currently have " . count($metastore_backup_drives) . ". Will select more...");
			$metastore_backup_drives_hash = array();
			if (count($metastore_backup_drives) > 0) {
				$metastore_backup_drives_hash[array_shift($metastore_backup_drives)] = TRUE;
			}

			while (count($metastore_backup_drives_hash) < $num_metastore_backups_needed) {
				// Let's pick new one
				$metastore_backup_drive = clean_dir($storage_pool_drives[array_rand($storage_pool_drives)] . '/.gh_metastore_backup');
				$metastore_backup_drives_hash[$metastore_backup_drive] = TRUE;
				if (!is_dir($metastore_backup_drive)) {
					mkdir($metastore_backup_drive);
				}
				Log::log(DEBUG, "    Randomly picked $metastore_backup_drive");
			}
			$metastore_backup_drives = array_keys($metastore_backup_drives_hash);

			// Got 2 drives now; save them in the DB
			self::set('metastore_backup_directory', $metastore_backup_drives);
		}
	}
}
?>
