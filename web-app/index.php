<?php
/*
Copyright 2013 Guillaume Boudreau

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

include('includes/common.php');

parse_config();
db_connect() or gh_log(CRITICAL, "Can't connect to $db_options->engine database.");
db_migrate();

header('Content-Type: text/html; charset=utf8');
setlocale(LC_CTYPE, "en_US.UTF-8");

if (isset($_REQUEST['level'])) {
    $level_min = (int) $_REQUEST['level'];
} else {
    $level_min = 1;
}
$level_max = $level_min+1;

if (isset($_REQUEST['path'])) {
    $path = $_REQUEST['path'];
} else {
    $path = '/';
}

$p = explode('/', trim($path, '/'));
$share = array_shift($p);
$full_path = implode('/', $p);
              
if ($level_min > 1) {
	$query = sprintf("SELECT size FROM du_stats WHERE depth = %d AND share = '%s' AND full_path = '%s'",
		$level_min - 1,
		$share,
		$full_path
	);
	$results = db_query($query) or die("SQL error: " . db_error());
	$row = db_fetch_object($results);
	$total_bytes = (float) $row->size;
}

if ($level_min > 1) {
	$query = sprintf("SELECT size, depth, CONCAT('/', share, '/', full_path) AS file_path FROM du_stats WHERE depth = %d AND share = '%s' AND full_path LIKE '%s%%'",
		$level_min,
		$share,
		empty($full_path) ? '' : "$full_path/"
	);
} else {
	$query = sprintf("SELECT size, depth, CONCAT('/', share, '/', full_path) AS file_path FROM du_stats WHERE depth = %d",
		$level_min
	);
}
$results = db_query($query) or die("SQL error: " . db_error());

$total_bytes_subfolders = 0;
$results_rows = array();
while ($row = db_fetch_object($results)) {
	$results_rows[] = $row;
    if ($row->depth == $level_min) {
        $total_bytes_subfolders += (float) $row->size;
    }
}
if ($level_min == 1) {
    $total_bytes = $total_bytes_subfolders;
}
$total_bytes_files = $total_bytes - $total_bytes_subfolders;

function escape($string) {
    return str_replace("'", "\\'", $string);
}
?>
<html>
  <head>
    <title>Greyhole - Disk Usage</title>
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <script type="text/javascript" src="https://www.google.com/jsapi"></script>
    <script type="text/javascript">
      google.load("visualization", "1", {packages:["treemap"]});
      google.setOnLoadCallback(drawChart);
      function drawChart() {
        // Create and populate the data table.
        var data = google.visualization.arrayToDataTable([
          ['Path', 'Parent', 'Size (bytes)', 'Color'],
          ['<?php echo escape($path) ?>', null, <?php echo $total_bytes ?>, <?php echo $total_bytes ?>],
          ['Files', '<?php echo escape($path) ?>', <?php echo $total_bytes_files ?>, <?php echo $total_bytes_files ?>],
          <?php
          foreach ($results_rows as $row) {
              $bytes = (float) $row->size;
              $parent = dirname($row->file_path);
              if ($row->file_path[strlen($row->file_path)-1] == '/') {
                  $row->file_path = substr($row->file_path, 0, strlen($row->file_path)-1);
              }
              echo "['" . escape($row->file_path) . "','".escape($parent)."',$bytes,$bytes],\n";
          }
          ?>
        ]);
        var text_data = [
            {text: '<?php echo escape($path) ?> (<?php echo escape(bytes_to_human($total_bytes, FALSE)) ?>)', path: '<?php echo escape($path) ?>'},
            {text: 'Files (<?php echo escape(bytes_to_human($total_bytes_files, FALSE)) ?>)', path: 'Files'},
        <?php
        foreach ($results_rows as $row) {
            $bytes = (float) $row->size;
            echo "{text:'" . escape($row->file_path) . " (".escape(bytes_to_human($bytes, FALSE)).")', path: '" . escape($row->file_path) . "'},\n";
        }
        ?>
        ];

        // Create and draw the visualization.
        var tree = new google.visualization.TreeMap(document.getElementById('chart_div'));
        tree.draw(data, {
          headerHeight: 15,
          fontColor: 'black'});
                              
        google.visualization.events.addListener(tree, 'onmouseover', function(ev) {
            $('mouseover_text').innerHTML = text_data[ev.row].text;
        });
        google.visualization.events.addListener(tree, 'select', function(ev) {
            if (text_data[tree.getSelection()[0].row].path == '<?php echo escape($path) ?>') {
                if (<?php echo ($level_min) ?> > 1) {
                    window.location.href='<?php echo $_SERVER['SCRIPT_NAME'] ?>?path=' + encodeURIComponent('<?php echo escape(dirname($path)) ?>') + '&level=<?php echo ($level_min-1) ?>';
                }
            } else if (text_data[tree.getSelection()[0].row].path != 'Files') {
                $('chart_div').innerHTML = 'Loading...';
                window.location.href='<?php echo $_SERVER['SCRIPT_NAME'] ?>?path=' + encodeURIComponent(text_data[tree.getSelection()[0].row].path) + '&level=<?php echo ($level_min+1) ?>';
                return false;
            }
        });
      }
      function $(el) {
        return document.getElementById(el);
      }
    </script>
  </head>

  <body style="font-family: Verdana, Arial">
    <center><small>Click a rectangle to dig deeper. Click the grey rectangle to go back up.</small></center><br/>
    <div id="chart_div" style="width: 100%; height: 90%;"></div>
    <div id="mouseover_text"></div>
  </body>
</html>
