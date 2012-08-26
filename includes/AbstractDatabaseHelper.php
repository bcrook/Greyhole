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

abstract class AbstractDatabaseHelper {

	protected $options; // connection options
	protected $dbh; // database handle

	function __construct($options) {
		$this->options = $options;
	}

	abstract public function connect();
	abstract public function query($query);
	abstract public function escape_string($string);
	abstract public function fetch_object($result);
	abstract public function free_result($result);
	abstract public function insert_id();
	abstract public function error();
	abstract public function insert_setting($name, $value);
	abstract public function repair_tables();
	abstract protected function migrate();

	public function getEngine() {
	    return $this->options->engine;
	}
}

// Those always accessible static functions will simplify the use of the $DB->something() functions outside the AbstractDatabaseHelper classes.
class DB {
    public static function connect() {
    	global $DB;
    	return $DB->connect() or Log::log(CRITICAL, "Can't connect to " . $DB->getEngine() . " database.");
    }

    public static function query($query) {
    	global $DB;
    	return $DB->query($query);
    }

    public static function escape_string($string) {
    	global $DB;
    	return $DB->escape_string($string);
    }

    public static function fetch_object($result) {
    	global $DB;
    	return $DB->fetch_object($result);
    }

    public static function free_result($result) {
    	global $DB;
    	return $DB->free_result($result);
    }

    public static function insert_id() {
    	global $DB;
    	return $DB->insert_id();
    }

    public static function error() {
    	global $DB;
    	return $DB->error();
    }

    public static function insert_setting($name, $value) {
    	global $DB;
    	return $DB->insert_setting($name, $value);
    }

    public static function repair_tables() {
    	global $DB;
    	return $DB->repair_tables();
    }
}

?>
