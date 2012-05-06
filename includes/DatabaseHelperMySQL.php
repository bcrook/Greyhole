<?php
/*
Copyright 2010-2012 Guillaume Boudreau, Carlos Puchol (Amahi)

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

/*
   Small abstraction layer for supporting MySQL and SQLite based
   on a user choice. Specify

             db_engine = sqlite
             db_path = /var/cache/greyhole.sqlite

   in /etc/greyhole.conf to enable sqlite support, otherwise the
   standard Greyhole MySQL support will be used.

   Carlos Puchol, Amahi
   cpg+git@amahi.org
*/

class DatabaseHelperMySQL {

	public function connect() {
		$this->dbh = mysql_connect($this->options->host, $this->options->user, $this->options->pass);
		if ($this->dbh) {
			if (mysql_select_db($this->options->name)) {
				$this->query("SET SESSION group_concat_max_len = 1048576");
				$this->query("SET SESSION wait_timeout = 86400"); # Allow 24h fsck!
				$this->migrate();
			}
		}
		return $this->dbh;
	}

	public function query($query) {
		return mysql_query($query);
	}

	public function escape_string($string) {
		return mysql_real_escape_string($string);
	}

	public function fetch_object($result) {
		return mysql_fetch_object($result);
	}

	public function free_result($result) {
		return mysql_free_result($result);
	}

	public function insert_id() {
		return mysql_insert_id();
	}

	public function error() {
		return mysql_error();
	}
	
	public function insert_setting($name, $value) {
		$query = sprintf("INSERT INTO settings (name, value) VALUES ('%s', '%s') ON DUPLICATE KEY UPDATE value = VALUES(value)", $name, $value);
		$this->query($query) or Log::log(CRITICAL, "Can't insert/update '$name' setting: " . $this->error());
	}
	
	public function repair_tables() {
		if (Log::action_equals('daemon')) {
			Log::log(INFO, "Optimizing MySQL tables...");
		}
		db_query("REPAIR TABLE tasks") or Log::log(CRITICAL, "Can't repair tasks table: " . db_error());
		db_query("REPAIR TABLE settings") or Log::log(CRITICAL, "Can't repair settings table: " . db_error());
		// Let's repair tasks_completed only if it's broken!
		$result = db_query("SELECT * FROM tasks_completed LIMIT 1");
		if ($result === FALSE) {
			Log::log(INFO, "Repairing MySQL tables...");
			db_query("REPAIR TABLE tasks_completed") or Log::log(CRITICAL, "Can't repair tasks_completed table: " . db_error());
		}
	}

	protected function migrate() {
		// Migration #1 (complete = frozen|thawed)
		$query = "DESCRIBE tasks";
		$result = $this->query($query) or die("Can't describe tasks with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if ($row->Field == 'complete') {
				if ($row->Type == "enum('yes','no')") {
					// migrate
					$this->query("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
					$this->query("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed') NOT NULL");
				}
				break;
			}
		}

		// Migration #2 (complete = idle)
		$query = "DESCRIBE tasks";
		$result = $this->query($query) or die("Can't describe tasks with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if ($row->Field == 'complete') {
				if ($row->Type == "enum('yes','no','frozen','thawed')") {
					// migrate
					$this->query("ALTER TABLE tasks CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
					$this->query("ALTER TABLE tasks_completed CHANGE complete complete ENUM('yes','no','frozen','thawed','idle') NOT NULL");
				}
				break;
			}
		}

		// Migration #3 (larger settings.value: tinytext > text)
		$query = "DESCRIBE settings";
		$result = $this->query($query) or die("Can't describe settings with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if ($row->Field == 'value') {
				if ($row->Type == "tinytext") {
					// migrate
					$this->query("ALTER TABLE settings CHANGE value value TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL");
				}
				break;
			}
		}

		// Migration #4 (new index for find_next_task function, used by simplify_task, and also for execute_next_task function; also remove deprecated indexes)
		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result) === FALSE) {
			// migrate
			$this->query("ALTER TABLE tasks ADD INDEX find_next_task (complete, share(64), id)");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'incomplete_open'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result)) {
			// migrate
			$this->query("ALTER TABLE tasks DROP INDEX incomplete_open");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'subsequent_writes'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result)) {
			// migrate
			$this->query("ALTER TABLE tasks DROP INDEX subsequent_writes");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'unneeded_unlinks'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result)) {
			// migrate
			$this->query("ALTER TABLE tasks DROP INDEX unneeded_unlinks");
		}

		// Migration #5 (fix find_next_task index)
	    $query = "SHOW INDEX FROM tasks WHERE Key_name = 'find_next_task' and Column_name = 'share'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result) !== FALSE) {
			// migrate
			$this->query("ALTER TABLE tasks DROP INDEX find_next_task ADD INDEX find_next_task (complete, id)");
		}

		// Migration #6 (new indexes for md5_worker_thread/gh_check_md5 functions)
		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_worker'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result) === FALSE) {
			// migrate
			$this->query("ALTER TABLE tasks ADD INDEX md5_worker (action, complete, additional_info(100), id)");
		}

		$query = "SHOW INDEX FROM tasks WHERE Key_name = 'md5_checker'";
		$result = $this->query($query) or die("Can't show index with query: $query - Error: " . $this->error());
		if ($this->fetch_object($result) === FALSE) {
			// migrate
			$this->query("ALTER TABLE tasks ADD INDEX md5_checker (action, share(64), full_path(255), complete)");
		}

		$query = "DESCRIBE tasks";
		$result = $this->query($query) or die("Can't describe tasks with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if ($row->Field == 'additional_info') {
				if ($row->Type == "tinytext") {
					// migrate
					$this->query("ALTER TABLE tasks CHANGE additional_info additional_info TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
				}
				break;
			}
		}

		// Migration #7 (full_path new size: 4096)
		$query = "DESCRIBE tasks";
		$result = $this->query($query) or die("Can't describe tasks with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if ($row->Field == 'full_path') {
				if ($row->Type == "tinytext") {
					// migrate
					$this->query("ALTER TABLE tasks CHANGE full_path full_path TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
					$this->query("ALTER TABLE tasks_completed CHANGE full_path full_path TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL");
				}
				break;
			}
		}
	}
}
?>
