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

class LogsCliRunner extends AbstractAnonymousCliRunner {
	public function run() {
		global $greyhole_log_file;
		if (strtolower($greyhole_log_file) == 'syslog') {
			if (gh_is_file('/var/log/syslog')) {
				passthru("tail -f /var/log/syslog | grep --line-buffered Greyhole");
			} else {
				passthru("tail -f /var/log/messages | grep --line-buffered Greyhole");
			}
		} else {
			passthru("tail -f " . escapeshellarg($greyhole_log_file));
		}
	}
}

?>
