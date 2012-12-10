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

define('INCL_MD5', TRUE);
define('DONT_UPDATE_IDLE', FALSE);

define('OPTION_EMAIL', 'email');
define('OPTION_IF_CONF_CHANGED', 'if-conf-changed');
define('OPTION_CHECKSUMS','checksums');
define('OPTION_METASTORE','metastore');
define('OPTION_ORPHANED','orphaned');
define('OPTION_DU','du');
define('OPTION_DEL_ORPHANED_METADATA','del-orphaned-metadata');

class Task {
	private $task;
	public static $result_set;

	function __construct($task) {
		$this->task = $task;
	}
	
	public static function instantiate($task) {
		switch ($task->action) {
			case 'balance':
				return new BalanceTask($task);
			case 'fsck':
				return new FsckTask($task);
			case 'fsck_file':
				return new FsckFileTask($task);
			case 'md5':
				return new MD5Task($task);
			case 'mkdir':
				return new MakeDirectoryTask($task);
			case 'rmdir':
				return new RemoveDirectoryTask($task);
			case 'write':
				return new WriteFileTask($task);
			case 'rename':
				return new RenameFileTask($task);
			case 'unlink':
				return new RemoveFileTask($task);
			default:
				return new Task($task);
		}
	}
	
	public function execute() {
        // @TODO Remove those globals!
		global $sleep_before_task, $frozen_directories, $current_task_id;

		$task = $this->task;
		if ($task === FALSE) {
			$this->idle();
			return;
		}
		$current_task_id = $task->id;

		# Postpone tasks in frozen directories until a --thaw command is received
		if ($task->complete != 'thawed') {
			foreach ($frozen_directories as $frozen_directory) {
				if (mb_strpos("$task->share/$task->full_path", $frozen_directory) === 0) {
					Log::set_action($task->action);
					Log::debug("Now working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> $task->share/$task->additional_info" : ''));
					Log::debug("  This directory is frozen. Will postpone this task until it is thawed.");
					$this->task->complete = 'frozen';
					$this->postpone('frozen');
					$this->archive();
					return;
				}
			}
		}

		if (($key = array_search($task->id, $sleep_before_task)) !== FALSE) {
			Log::set_action('sleep');
			Log::debug("Only locked files operations pending... Sleeping.");
			$this->sleep();
			$sleep_before_task = array();
		}

		Log::set_action($task->action);
		Log::info("Now working on task ID $task->id: $task->action " . clean_dir("$task->share/$task->full_path") . ($task->action == 'rename' ? " -> $task->share/$task->additional_info" : ''));
	}
	
	public static function getNext($incl_md5=FALSE, $update_idle=TRUE) {
		if (isset($next_task)) {
			$task = $next_task;
			unset($GLOBALS['next_task']);
			return Task::instantiate($task);
		}

		if (!empty(Task::$result_set)) {
			$task = DB::fetch_object($Task::$result_set);
			if ($task !== FALSE) {
				return Task::instantiate($task);
			}
			DB::free_result($Task::$result_set);
		}

		$query = "SELECT id, action, share, full_path, additional_info, complete FROM tasks WHERE complete IN ('yes', 'thawed')" . (!$incl_md5 ? " AND action != 'md5'" : "") . " ORDER BY id ASC LIMIT 20";
		Task::$result_set = DB::query($query) or Log::critical("Can't query tasks: " . DB::error());
		$task = DB::fetch_object(Task::$result_set);

		if ($task === FALSE && $update_idle) {
			// No more complete = yes|thawed; let's look for complete = 'idle' tasks.
			$query = "UPDATE tasks SET complete = 'yes' WHERE complete = 'idle'";
			DB::query($query) or Log::critical("Can't update idle tasks to complete tasks: " . DB::error());
			$task = Task::getNext($incl_md5, DONT_UPDATE_IDLE);
		}
		return Task::instantiate($task);
	}
	
	public static function simplify() {
		Log::set_action('simplify_tasks');
		Log::debug("Simplifying pending tasks.");

		// Remove locked write tasks
		$query = "SELECT share, full_path FROM tasks WHERE action = 'write' and complete = 'no'";
		$result = DB::query($query) or Log::critical("Can't select locked write tasks: " . DB::error());
		while ($row = DB::fetch_object($result)) {
			$query = sprintf("DELETE FROM tasks WHERE action = 'write' and complete = 'yes' AND share = '%s' AND full_path = '%s'",
				DB::escape_string($row->share),
				DB::escape_string($row->full_path)
			);
			DB::query($query) or Log::critical("Can't delete duplicate of locked write task: " . DB::error());
		}
	}
	
	private function idle() {
		Log::set_action('sleep');
		Log::debug("Nothing to do... Sleeping.");

		DB::repair_tables();

		$query = "SELECT * from tasks WHERE action = 'md5' AND complete = 'no' LIMIT 1";
		$result = DB::query($query) or Log::critical("Can't query tasks for incomplete md5: " . DB::error());
		if ($row = DB::fetch_object($result)) {
			$num_worker_threads = (int) trim(exec("ps x | grep '/usr/bin/greyhole --md5-worker' | grep -v grep | wc -l"));
			if ($num_worker_threads == 0) {
				Log::debug("Will spawn new worker threads to work on incomplete checksums calculations.");
				foreach ($GLOBALS['storage_pool_drives'] as $sp_drive) {
					spawn_thread('md5-worker', array($sp_drive));
				}
			}
		}
		DB::free_result($result);

		// Email any unsent fsck reports found in /usr/share/greyhole/
		foreach (array('fsck_checksums.log', 'fsck_files.log') as $log_file) {
			$log = new FSCKLogFile($log_file);
			$log->emailAsRequired();
		}

		$this->sleep();
	}

	private function sleep() {
		sleep(Log::$level == DEBUG ? 10 : (Log::$level == TEST || PERF ? 1 : 600));
		DB::free_result(Task::$result_set);
		Task::$result_set = null;
		$GLOBALS['locked_files'] = array();
	}

    public function postpone($complete='yes') {
    	$task_id = $this->task->id;
    	global $sleep_before_task;
    	$query = sprintf("INSERT INTO tasks (action, share, full_path, additional_info, complete) SELECT action, share, full_path, additional_info, '%s' FROM tasks WHERE id = %d",
    		DB::escape_string($complete),
    		$task_id
    	);
    	DB::query($query) or Log::critical("Error inserting postponed task: " . DB::error());
    	$sleep_before_task[] = DB::insert_id();
    }

	private function archive() {
	    global $sleep_before_task;
	    if ($this->$task->action != 'write' && $this->$task->action != 'rename') {
			$sleep_before_task = array();
		}
		
		$query = sprintf("INSERT INTO tasks_completed SELECT * FROM tasks WHERE id = %d", $this->task->id);
		$worked = DB::query($query);
		if (!$worked) {
			// Let's try a second time... This is kinda important!
			DB::connect();
			DB::query($query) or Log::critical("Can't insert in tasks_completed: " . DB::error());
		}

		$query = sprintf("DELETE FROM tasks WHERE id = %d", $this->task->id);
		DB::query($query) or Log::critical("Can't delete from tasks: " . DB::error());
	}

    function hasOption($option) {
    	return (mb_strpos($this->task->additional_info, $option)) !== FALSE ? TRUE : FALSE;
    }
}
?>
