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

require_once('includes/CLI/AbstractCliRunner.php');

class MD5WorkerCliRunner extends AbstractCliRunner {
	private $drives;
	
	function __construct($options) {
		$pid = pcntl_fork();
		if ($pid == -1) {
			$this->log("Error spawning child md5-worker!");
			$this->finish(1);
		}
		if ($pid == 0) {
			// Child
			parent::__construct($options);
			if (is_array($this->options['drive'])) {
				$this->drives = $this->options['drive'];
			} else {
				$this->drives = array($this->options['drive']);
			}
		} else {
			// Parent
			echo $pid;
			$this->finish(0);
		}
	}
	
	public function run() {
    	$drives_clause = '';
    	foreach ($this->drives as $drive) {
    		if ($drives_clause != '') {
    			$drives_clause .= ' OR ';
    		}
    		$drives_clause .= sprintf("additional_info LIKE '%s%%'", DB::escape_string($drive));
    	}

    	$query = "SELECT id, share, full_path, additional_info FROM tasks WHERE action = 'md5' AND complete = 'no' AND ($drives_clause) ORDER BY id ASC LIMIT 10";

    	$last_check_time = time();
    	while (TRUE) {
    		$task = FALSE;
    		if (!empty($result_new_tasks)) {
    			$task = DB::fetch_object($result_new_tasks);
    			if ($task === FALSE) {
    				DB::free_result($result_new_tasks);
    				$result_new_tasks = null;
    			}
    		}
    		if ($task === FALSE) {
    			$result_new_tasks = DB::query($query) or Log::critical("Can't query md5 tasks: " . DB::error() . "$query");
    			$task = DB::fetch_object($result_new_tasks);
    		}
    		if ($task === FALSE) {
    			// Nothing new to process

    			// Stop this thread once we have nothing more to do, and fsck completed.
    			$task = Task::getNext();
    			if ($task === FALSE || ($task->action != 'fsck' && $task->action != 'fsck_file')) {
    				Log::debug("MD5 worker thread for " . implode(', ', $this->drives) . " will now exit; it has nothing more to do.");
    				#Log::debug("Current task: " . var_export($task, TRUE));
    				break;
    			}

    			sleep(5);
    			continue;
    		}
    		$last_check_time = time();

    		Log::info("Working on MD5 task ID $task->id: $task->additional_info");
    		$md5 = md5_file($task->additional_info);
    		Log::debug("  MD5 for $task->additional_info = $md5");

    		$update_query = sprintf("UPDATE tasks SET complete = 'yes', additional_info = '%s' WHERE id = $task->id", DB::escape_string("$task->additional_info=$md5"));
    		DB::query($update_query) or Log::critical("Can't update md5 task: " . DB::error());
    	}
	}
}

?>
