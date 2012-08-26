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

   in /etc/greyhole.conf to enable SQLite support.

   Carlos Puchol, Amahi
   cpg+git@amahi.org
*/

class DatabaseHelperSQLite {
    public const engine = 'sqlite';

	public function connect() {
		// create the database file, if it does not exists already.
		if (!file_exists($this->options->db_path)) {
			system("sqlite3 $this->options->db_path < $this->options->schema");
		}
		$this->dbh = new PDO("sqlite:" . $this->options->db_path);
		if ($this->dbh) {
			$this->migrate();
		}
		return $this->dbh;
	}

	public function query($query) {
		return $this->dbh->query($query);
	}

	public function escape_string($string) {
		$escaped_string = $this->dbh->quote($string);
		return substr($escaped_string, 1, strlen($escaped_string)-2);
	}

	public function fetch_object($result) {
		return $result->fetchObject();
	}

	public function free_result($result) {
		return TRUE;
	}

	public function insert_id() {
		return $this->dbh->lastInsertId();
	}

	public function error() {
		$error = $this->dbh->errorInfo();
		return $error[2];
	}	

	public function insert_setting($name, $value) {
		$query = sprintf("DELETE FROM settings WHERE name = '%s'", $name);
		$this->query($query) or Log::log(CRITICAL, "Can't delete '$name' setting: " . $this->error());
		$query = sprintf("INSERT INTO settings (name, value) VALUES ('%s', '%s')", $name, $value);
		$this->query($query) or Log::log(CRITICAL, "Can't insert '$name' setting: " . $this->error());
	}
	
	public function repair_tables() {
	}

	protected function migrate() {
		// Migration #1 (complete = frozen|thawed)
		$query = "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'tasks'";
		$result = $this->query($query) or die("Can't describe tasks with query: $query - Error: " . $this->error());
		while ($row = $this->fetch_object($result)) {
			if (strpos($row->sql, 'complete BOOL NOT NULL') !== FALSE) {
				// migrate; not supported! @see http://sqlite.org/omitted.html
				Log::log(CRITICAL, "Your SQLite database is not up to date. Column tasks.complete needs to be a TINYTEXT. Please fix, then retry.");
			}
		}
	}
}
?>
