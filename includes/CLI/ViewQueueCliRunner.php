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

class ViewQueueCliRunner extends AbstractAnonymousCliRunner {
	public function run() {
		global $shares_options;
		
		$shares_names = array_keys($shares_options);
		natcasesort($shares_names);

		$max_share_strlen = max(array_merge(array_map('mb_strlen', $shares_names), array(7)));

		$queues = array();
		$total_num_writes_pending = $total_num_delete_pending = $total_num_rename_pending = $total_num_fsck_pending = 0;
		foreach ($shares_names as $share_name) {
			$result = db_query(sprintf("SELECT COUNT(*) AS num FROM tasks WHERE action = 'write' AND share = '%s' AND complete IN ('yes', 'thawed')", db_escape_string($share_name))) or die("Can't find # of writes in tasks table: " . db_error());
			$row = db_fetch_object($result);
			$num_writes_pending = (int) $row->num;
			$total_num_writes_pending += $num_writes_pending;

			$result = db_query(sprintf("SELECT COUNT(*) AS num FROM tasks WHERE (action = 'unlink' OR action = 'rmdir') AND share = '%s' AND complete IN ('yes', 'thawed')", db_escape_string($share_name))) or die("Can't find # of deletes in tasks table: " . db_error());
			$row = db_fetch_object($result);
			$num_delete_pending = (int) $row->num;
			$total_num_delete_pending += $num_delete_pending;

			$result = db_query(sprintf("SELECT COUNT(*) AS num FROM tasks WHERE action = 'rename' AND share = '%s' AND complete IN ('yes', 'thawed')", db_escape_string($share_name))) or die("Can't find # of renames in tasks table: " . db_error());
			$row = db_fetch_object($result);
			$num_rename_pending = (int) $row->num;
			$total_num_rename_pending += $num_rename_pending;

			$result = db_query(sprintf("SELECT COUNT(*) AS num FROM tasks WHERE (action = 'fsck' OR action = 'fsck_file' OR action = 'md5') AND share = '%s'", db_escape_string($share_name))) or die("Can't find # of fsck in tasks table: " . db_error());
			$row = db_fetch_object($result);
			$num_fsck_pending = (int) $row->num;
			$total_num_fsck_pending += $num_fsck_pending;

			$queues[$share_name] = (object) array(
				'num_writes_pending' => $num_writes_pending,
				'num_delete_pending' => $num_delete_pending,
				'num_rename_pending' => $num_rename_pending,
				'num_fsck_pending' => $num_fsck_pending,
			);
		}
		$queues['Total'] = (object) array(
			'num_writes_pending' => $total_num_writes_pending,
			'num_delete_pending' => $total_num_delete_pending,
			'num_rename_pending' => $total_num_rename_pending,
			'num_fsck_pending' => $total_num_fsck_pending,
		);

		$queues['Spooled'] = (int) exec("ls -1 /var/spool/greyhole | wc -l");

		if (isset($this->options['json'])) {
			echo json_encode($queues);
		} else {
			$this->log("");
			$this->log("Greyhole Work Queue Statistics");
			$this->log("==============================");
			$this->log("");
			$this->log("This table gives you the number of pending operations queued for the Greyhole daemon, per share.");
			$this->log("");

			$col_size = 7;
			foreach ($queues['Total'] as $type => $num) {
				$num = number_format($num, 0);
				if (strlen($num) > $col_size) {
					$col_size = strlen($num);
				}
			}
			$col_format = '%' . $col_size . 's';

			$header = sprintf("%$max_share_strlen"."s  $col_format  $col_format  $col_format  $col_format", '', 'Write', 'Delete', 'Rename', 'Check');
			$this->log($header);

			foreach ($queues as $share_name => $queue) {
				if ($share_name == 'Spooled') continue;
				if ($share_name == 'Total') {
					$this->log(str_repeat('=', $max_share_strlen+2+(4*$col_size)+(3*2)));
				}
				$this->log(sprintf("%-$max_share_strlen"."s", $share_name) . "  "
					. sprintf($col_format, number_format($queue->num_writes_pending, 0)) . "  "
					. sprintf($col_format, number_format($queue->num_delete_pending, 0)) . "  "
					. sprintf($col_format, number_format($queue->num_rename_pending, 0)) . "  "
					. sprintf($col_format, number_format($queue->num_fsck_pending, 0))
				);
			}
			$this->log($header);
			$this->log("");
			$this->log("The following is the number of pending operations that the Greyhole daemon still needs to parse.");
			$this->log("Until it does, the nature of those operations is unknown.");
			$this->log("Spooled operations that have been parsed will be listed above and disappear from the count below.");
			$this->log("");
			$this->log(sprintf("%-$max_share_strlen"."s  ", 'Spooled') . $queues['Spooled']);
			$this->log("");
		}
	}
}

?>
