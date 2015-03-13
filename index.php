<?php
/*
Plugin Name: EZ Backup
Plugin URI: http://wordpress.ieonly.com/category/my-plugins/ez-backup/
Author: Eli Scheetz
Author URI: http://wordpress.ieonly.com/category/my-plugins/
Description: Keep your database safe with scheduled backups. Multiple option for off-site backups also available.
Version: 4.15.12
*/
$GLOBALS["ez-backup"] = array("ver" => "4.15.12", "url" => admin_url("options-general.php?page=ez-backup-settings"), "mt" => array("included" => microtime(true)), "settings" => get_option("ez-backup-settings", array()));
/*            ___
 *           /  /\     EZ Backup Main Plugin File
 *          /  /:/     @package EZ Backup
 *         /__/::\
 Copyright \__\/\:\__  © 2015 Eli Scheetz (email: wordpress@ieonly.com)
 *            \  \:\/\
 *             \__\::/ This program is free software; you can redistribute it
 *     ___     /__/:/ and/or modify it under the terms of the GNU General Public
 *    /__/\   _\__\/ License as published by the Free Software Foundation;
 *    \  \:\ /  /\  either version 2 of the License, or (at your option) any
 *  ___\  \:\  /:/ later version.
 * /  /\\  \:\/:/
  /  /:/ \  \::/ This program is distributed in the hope that it will be useful,
 /  /:/_  \__\/ but WITHOUT ANY WARRANTY; without even the implied warranty
/__/:/ /\__    of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
\  \:\/:/ /\  See the GNU General Public License for more details.
 \  \::/ /:/
  \  \:\/:/ You should have received a copy of the GNU General Public License
 * \  \::/ with this program; if not, write to the Free Software Foundation,
 *  \__\/ Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA        */

if (isset($_SERVER["DOCUMENT_ROOT"]) && ($SCRIPT_FILE = str_replace($_SERVER["DOCUMENT_ROOT"], "", isset($_SERVER["SCRIPT_FILENAME"])?$_SERVER["SCRIPT_FILENAME"]:isset($_SERVER["SCRIPT_NAME"])?$_SERVER["SCRIPT_NAME"]:"")) && strlen($SCRIPT_FILE) > strlen("/".basename(__FILE__)) && substr(__FILE__, -1 * strlen($SCRIPT_FILE)) == substr($SCRIPT_FILE, -1 * strlen(__FILE__)))
	die('You are not allowed to call this page directly.<p>You could try starting <a href="/">here</a>.');

function ezbackup_set_backupdir() {
	if (function_exists("current_user_can") && current_user_can("manage_options") && isset($_POST["ez-backup-settings"]["backup_dir"]) && is_dir($_POST["ez-backup-settings"]["backup_dir"]))
		$GLOBALS["ez-backup"]["settings"]["backup_dir"] = $_POST["ez-backup-settings"]["backup_dir"];
	if (!(isset($GLOBALS["ez-backup"]["settings"]["backup_dir"]) && strlen($GLOBALS["ez-backup"]["settings"]["backup_dir"]) && is_dir($GLOBALS["ez-backup"]["settings"]["backup_dir"]) && ((is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'.htaccess') && ($contents = @file_get_contents(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'.htaccess')) && ((preg_match('/(^|\s+)Options(.+?)Indexes/i', $contents, $match) || @file_put_contents(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'.htaccess', "Options -Indexes\n".$contents)))) ||
		@file_put_contents(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'.htaccess', "Options -Indexes")))) {
		$upload = wp_upload_dir();
		$GLOBALS["ez-backup"]["settings"]["backup_dir"] = trailingslashit($upload["basedir"]).'SQL_Backups';
		if (!is_dir($GLOBALS["ez-backup"]["settings"]["backup_dir"]) && !@mkdir($GLOBALS["ez-backup"]["settings"]["backup_dir"]))
			$GLOBALS["ez-backup"]["settings"]["backup_dir"] = $upload["basedir"];
		if (!is_file(trailingslashit($upload["basedir"]).'index.php'))
			@file_put_contents(trailingslashit($upload["basedir"]).'index.php', "<?php\n// Silence is golden.");
	}
	if (!is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'index.php'))
		@file_put_contents(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).'index.php', "<?php\n// Silence is golden.");
}

function ezbackup_get_structure($table, $type='Table') {
	fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], "\n\n--\n-- Table structure for ".strtolower($type)." `$table`\n--\n\n");
	$sql = "SHOW CREATE $type `$table`; ";
	if ($result = mysql_query($sql)) {
		if ($row = mysql_fetch_assoc($result))
			fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], "DROP ".strtoupper($type)." IF EXISTS `$table`;\n/*!40101 SET @saved_cs_client     = @@character_set_client */;\n/*!40101 SET character_set_client = utf8 */;\n".preg_replace('/CREATE .+? VIEW/', 'CREATE VIEW', $row["Create $type"]).";\n/*!40101 SET character_set_client = @saved_cs_client */;");
		mysql_free_result($result);
	} else
		return "/* requires the SHOW VIEW privilege and the SELECT privilege */\n\n";
	return '';
}

function ezbackup_get_data($table) {
	$sql = "SELECT * FROM `$table`;";
	if ($result = mysql_query($sql)) {
		$num_rows = mysql_num_rows($result);
		$num_fields = mysql_num_fields($result);
		$return = 0;
		if ($num_rows > 0) {
			fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], "\n\n--\n-- Dumping data for table `$table`\n--\n\nLOCK TABLES `$table` WRITE;\n/*!40000 ALTER TABLE `$table` DISABLE KEYS */;\n");
			$field_type = array();
			$i = 0;
			$field_list = " (";
			while ($i < $num_fields) {
				$meta = mysql_fetch_field($result, $i);
				array_push($field_type, $meta->type);
				$field_list .= ($i?', ':'')."`$meta->name`";
				$i++;
			}
			$field_list .= ")";
			$field_list = ""; // field_list is not required for insert
			$maxInsertSize = 100000;
			$statementSql = "";
			for ($index = 0; $row = mysql_fetch_row($result); $index++) {
				$return++;
				if (strlen($statementSql) > $maxInsertSize) {
					fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], $statementSql.";\n");
					$statementSql = "";
				}
				if (strlen($statementSql) == 0)
					$statementSql = "INSERT INTO `$table`$field_list VALUES ";
				else
					$statementSql .= ",";
				$statementSql .= "(";
				for ($i = 0; $i < $num_fields; $i++) {
					if (is_null($row[$i]))
						$statementSql .= "null";
					else {
						if ($field_type[$i] == 'int')
							$statementSql .= $row[$i];
						else
							$statementSql .= "'" . mysql_real_escape_string($row[$i]) . "'";
					}
					if ($i < $num_fields - 1)
						$statementSql .= ",";
				}
				$statementSql .= ")";
			}
			if ($statementSql)
				fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], $statementSql.";\n/*!40000 ALTER TABLE `$table` ENABLE KEYS */;\nUNLOCK TABLES;");
		}
		mysql_free_result($result);
	} else
		$return = "SELECT ERROR for `$table`: ".mysql_error()."\n";
	return $return;
}

function ezbackup_db2file($date_format, $backup_type = "manual", $db_name = DB_NAME, $db_host = DB_HOST, $db_user = DB_USER, $db_password = DB_PASSWORD) {
	global $wpdb, $wp_version;
	if (mysql_connect($db_host, $db_user, $db_password)) {
		if (mysql_select_db($db_name)) {
			ezbackup_set_backupdir();
			$db_date = date($date_format);
			if (strpos($db_host, ':')) {
				list($db_host, $db_port) = explode(':', $db_host, 2);
				if (is_numeric($db_port))
					$db_port = '" --port="'.$db_port.'" ';
				else
					$db_port = '" --socket="'.$db_port.'" ';
			} else
				$db_port = '" ';
			$subject = "$backup_type.$db_name.$db_host.sql";
			$filename = "z.$db_date.$subject";
			$backup_file = trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$filename;
			$content = "";
			$return = "";
			$uid = md5(time());
			$message = "\r\n--$uid\r\nContent-type: text/html; charset=\"iso-8859-1\"\r\nContent-Transfer-Encoding: 7bit\r\n\r\n";
			if (!isset($GLOBALS["ez-backup"]["settings"]["backup_method"]) || $GLOBALS["ez-backup"]["settings"]["backup_method"] != 2) {
				$mysqlbasedir = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
				if(substr(PHP_OS,0,3) == 'WIN')
					$backup_command = '"'.(isset($mysqlbasedir->Value)?trailingslashit(str_replace('\\', '/', $mysqlbasedir->Value)).'bin/':'').'mysqldump.exe"';
				else
					$backup_command = (isset($mysqlbasedir->Value)&&is_file(trailingslashit($mysqlbasedir->Value).'bin/mysqldump')?trailingslashit($mysqlbasedir->Value).'bin/':'').'mysqldump';		
				$backup_command .= ' --user="'.$db_user.'" --password="'.$db_password.'" --add-drop-table --skip-lock-tables --host="'.$db_host.$db_port.$db_name;
				if (isset($GLOBALS["ez-backup"]["settings"]["compress_backup"]) && $GLOBALS["ez-backup"]["settings"]["compress_backup"]) {
					$backup_command .= ' | gzip > ';
					$backup_file .= '.gz';
				} else
					$backup_command .= ' -r ';
				passthru($backup_command.'"'.$backup_file.'"', $errors);
				$return = "<div class='".($errors?"error":"updated")."'>Command Line Backup of $subject returned $errors error".($errors!=1?'s':'');
			}
			if ((!isset($GLOBALS["ez-backup"]["settings"]["backup_method"]) || $GLOBALS["ez-backup"]["settings"]["backup_method"] != 1) && (!$return || $errors) && $GLOBALS["ez-backup"]["tmp"]["backup_file"] = fopen($backup_file, 'w')) {
				$server = strtolower(isset($_SERVER["HTTP_HOST"])?$_SERVER["HTTP_HOST"]:(isset($_SERVER["SERVER_NAME"])?$_SERVER["SERVER_NAME"]:$_SERVER["SERVER_ADDR"]));
				$ip = explode("$server", get_option("siteurl")."$server");
				if (!(count($ip) > 2 && strlen($domain = trim($ip[1], " \t\r\n/")) > 0))
					$domain = $_SERVER["SERVER_ADDR"];
				fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], "-- EZ Backup SQL dump ".$GLOBALS["ez-backup"]["ver"].", for $server ($domain)\n--\n-- Host: $db_host    Database: $db_name\n-- ------------------------------------------------------\n-- WordPress version $wp_version\n\n/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n/*!40101 SET NAMES utf8 */;\n/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n/*!40103 SET TIME_ZONE='+00:00' */;\n/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;");
				$sql = "show full tables where Table_Type = 'BASE TABLE'";
				$result = mysql_query($sql);
				$errors = "";
				if (mysql_errno())
					$errors .= "/* SQL ERROR: ".mysql_error()." */\n\n/*$sql*/\n\n";
				else {
					while ($row = mysql_fetch_row($result)) {
						$errors .= ezbackup_get_structure($row[0]);
						if (!is_numeric($rows = ezbackup_get_data($row[0])))
							$errors .= $rows;
					}
					mysql_free_result($result);
					$sql = "show full tables where Table_Type = 'VIEW'";
					if ($result = mysql_query($sql)) {
						while ($row = mysql_fetch_row($result))
							$errors .= ezbackup_get_structure($row[0], "View");
						mysql_free_result($result);
					}
				}
				fwrite($GLOBALS["ez-backup"]["tmp"]["backup_file"], "\n/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n-- Dump completed on $db_date");
				fclose($GLOBALS["ez-backup"]["tmp"]["backup_file"]);
				$return = "<div class='updated'>Backup: $subject Saved";
				$message .= 'A database backup was saved on <a href="'.$GLOBALS["ez-backup"]["url"].'">'.(get_option("blogname"))."</a>.\r\n<p><pre>$errors</pre><p>";
				if (isset($GLOBALS["ez-backup"]["settings"]["compress_backup"]) && $GLOBALS["ez-backup"]["settings"]["compress_backup"]) {
					$zip = new ZipArchive();
					if ($zip->open($backup_file.'.zip', ZIPARCHIVE::CREATE) === true) {
						$zip->addFile($backup_file, $filename);
						$zip->close();
					}
					if (is_file($backup_file) && is_file($backup_file).'.zip') {
						if (@unlink($backup_file))
							$backup_file .= '.zip';
					} else
						$return .= " but not Zipped";
				}
			} elseif (!$return)
				$return = "<div class='error'>Failed to save backup!";
			if (isset($GLOBALS["ez-backup"]["settings"]["backup_db"]["$backup_type"]) && $GLOBALS["ez-backup"]["settings"]["backup_db"]["$backup_type"] > 0) {
				$sql_files = array();
				if ($handle = opendir($GLOBALS["ez-backup"]["settings"]["backup_dir"])) {
					while (false !== ($entry = readdir($handle)))
						if (is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$entry))
							if (strpos($entry, $subject))
								$sql_files[] = "$entry";
					closedir($handle);
					rsort($sql_files);
				}
				$del=0;
				while (count($sql_files)>$GLOBALS["ez-backup"]["settings"]["backup_db"]["$backup_type"])
					if (@unlink(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).array_pop($sql_files)))
						$del++;
				$message .= "\r\nNumber of archives:<li>Deleted: $del</li><li>Kept: ".count($sql_files)."</li><p>";
			}
			if (strlen($GLOBALS["ez-backup"]["settings"]["backup_email"])) {
				$headers = 'From: '.get_option("admin_email")."\r\n";
				$headers .= "MIME-Version: 1.0\r\n";
				$headers .= "Content-Type: multipart/mixed; boundary=\"$uid\"\r\n";
				if (file_exists($backup_file)) {
					$file_size = filesize($backup_file);
					$handle = fopen($backup_file, "rb");
					$content .= "The backup has been attached to this email for your convenience.\r\n\r\n--$uid\r\nContent-Type: application/octet-stream; name=\"".basename($backup_file)."\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"".basename($backup_file)."\"\r\n\r\n".chunk_split(base64_encode(fread($handle, $file_size)), 70, "\r\n");
					fclose($handle);
				}
				if (mail($GLOBALS["ez-backup"]["settings"]["backup_email"], preg_replace('/^<[^>]+?>/', "", $return), $message.$content."\r\n\r\n--$uid--", $headers))
					$return .= " and Sent!";
				else
					mail($GLOBALS["ez-backup"]["settings"]["backup_email"], preg_replace('/^<[^>]+?>/', "", $return), $message.strlen($content)." bytes is too large to attach but you can download it ".ezbackup_link("here", "&ez-backup-download=".basename($backup_file)).".\r\n\r\n--$uid--", $headers);
			}
		} else
			$return = '<div class="error">Database Selection ERROR: '.mysql_error();
	} else
		$return = '<div class="error">Database Connection ERROR: '.mysql_error();
	return $return.'</div>';
}
add_action('ezbackup_db_daily', 'ezbackup_db2file', 10, 2);
add_action('ezbackup_db_hourly', 'ezbackup_db2file', 10, 2);

function ezbackup_query($SQL) {
	global $wpdb;
	$SQLkey = md5($SQL);
	if (!isset($GLOBALS["ez-backup"]["query_times"][$SQLkey])) {
		$GLOBALS["ez-backup"]["query_times"][$SQLkey] = array("time" => microtime(true), "sql" => $SQL, "result" => false, "rows" => 0, "errors" => array());
		foreach (preg_split('/[\s]*[;]+[\r\n]+[;\s]*/', trim($SQL).";\n") as $SQ) {
			if (strlen($SQ)) {
				if (strtoupper(substr($SQ, 0, 7)) == "SELECT " || strtoupper(substr($SQ, 0, 5)) == "SHOW ") {
					$GLOBALS["ez-backup"]["query_times"][$SQLkey]["result"] = $wpdb->get_results($SQ, ARRAY_A);
					$GLOBALS["ez-backup"]["query_times"][$SQLkey]["rows"] = $wpdb->num_rows;
					if ($wpdb->last_error)
						$GLOBALS["ez-backup"]["query_times"][$SQLkey]["errors"][] = $wpdb->last_error;
				} elseif ($SQ) {
					$GLOBALS["ez-backup"]["query_times"][$SQLkey]["rows"] = $wpdb->query($SQ);
					if (strtoupper(substr($SQ, 0, 7)) == "INSERT ")
						$GLOBALS["ez-backup"]["query_times"][$SQLkey]["result"] = $wpdb->insert_id;
					if (strtoupper(substr($SQ, 0, 7)) == "UPDATE ")
						$GLOBALS["ez-backup"]["query_times"][$SQLkey]["result"] = 0;
					if ($wpdb->last_error)
						$GLOBALS["ez-backup"]["query_times"][$SQLkey]["errors"][] = $wpdb->last_error;
				}
			}
		}
		$GLOBALS["ez-backup"]["query_times"][$SQLkey]["time"] = microtime(true) - $GLOBALS["ez-backup"]["query_times"][$SQLkey]["time"];
	}
	return $SQLkey;
}

if (is_admin()) {

	function ezbackup_admin_notices() {
		if (current_user_can("manage_options") && !isset($_REQUEST["ez-backup-settings"]["backup_db"]["daily"]) && !isset($_REQUEST["ez-backup-settings"]["backup_db"]["hourly"])) {$import_settings_array = get_option("ELISQLREPORTS_settings_array", array());
			if (((isset($import_settings_array["daily_backup"]) && $import_settings_array["daily_backup"]) || (wp_next_scheduled('ELISQLREPORTS_daily_backup', array("Y-m-d-H-i-s", 'daily'))) || (isset($import_settings_array["hourly_backup"]) && $import_settings_array["hourly_backup"]) || (wp_next_scheduled('ELISQLREPORTS_hourly_backup', array("Y-m-d-H-i-s", 'hourly')))))
				echo '<div class="update-nag">You have configured My SQL Reports plugin to perform automatic backup jobs. This feature will be phased out soon in lieu of My EZ Backup plugin running the same jobs.<br />'.ezbackup_link("Import the SQL Reports backup jobs into the EZ Backup Setting now and dismiss this message", "&ez-backup-settings[backup_db][daily]=".(isset($import_settings_array["daily_backup"])?$import_settings_array["daily_backup"]:"0")."&ez-backup-settings[backup_db][hourly]=".(isset($import_settings_array["hourly_backup"])?$import_settings_array["hourly_backup"]:"0")).'</div>';
			elseif (!(isset($GLOBALS["ez-backup"]["settings"]["backup_db"]) && is_array($GLOBALS["ez-backup"]["settings"]["backup_db"])))
				echo '<div class="update-nag">You have not yet configured any automatic backup jobs.<br />'.ezbackup_link("Go to EZ Backup Setting now and dismiss this message", "&ez-backup-settings[backup_db][daily]=0&ez-backup-settings[backup_db][hourly]=0").'</div>';
		}
	}
	add_action("admin_notices", "ezbackup_admin_notices");

	function ezbackup_install() {
		global $wp_version;
		if (version_compare($wp_version, "2.6", "<"))
			die(__("Upgrade to %s now!", 'ezbackup'));
	}
	register_activation_hook(__FILE__, "ezbackup_install");

	function ezbackup_deactivation() {
		while (wp_next_scheduled('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily')))
			wp_clear_scheduled_hook('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily'));
		while (wp_next_scheduled('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly')))
			wp_clear_scheduled_hook('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly'));
	}
	register_deactivation_hook(__FILE__, 'ezbackup_deactivation');

	function ezbackup_activation() {
		$GLOBALS["ez-backup"]["settings"] = get_option('ez-backup-settings', array());
		if (isset($GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"]) && $GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"] && !wp_next_scheduled('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily')))
			wp_schedule_event(time(), 'daily', 'ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily'));
		if (isset($GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"]) && $GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"] && !wp_next_scheduled('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly')))
			wp_schedule_event(time(), 'hourly', 'ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly'));
	}
	register_activation_hook(__FILE__, 'ezbackup_activation');

	function ezbackup_enqueue_scripts() {
		wp_enqueue_style('dashicons');
	}
	add_action('wp_enqueue_scripts', 'ezbackup_enqueue_scripts');
	
	function ezbackup_menu() {
		ezbackup_set_backupdir();
		if (current_user_can("manage_options") && isset($_GET["ez-backup-download"]) && is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$_GET["ez-backup-download"]) && ($fp = fopen(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$_GET["ez-backup-download"], 'rb'))) {
			header("Content-Type: application/octet-stream;");
			header('Content-Disposition: attachment; filename="'.$_GET["ez-backup-download"].'"');
			header("Content-Length: ".filesize(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$_GET["ez-backup-download"]));
			fpassthru($fp);
			die();
		}
		add_options_page('EZ Backup Settings', '<span class="dashicons dashicons-backup" style="vertical-align: text-bottom;"></span> EZ Backup', "manage_options", "ez-backup-settings", "ezbackup_settings");
	}
	add_action("admin_menu", "ezbackup_menu");

	function ezbackup_set_plugin_action_links($links_array, $plugin_file) {
		if (strlen($plugin_file) > 10 && $plugin_file == substr(__file__, (-1 * strlen($plugin_file))))
			$links_array = array_merge(array(ezbackup_link("Settings", "#top_title", "admin-settings")), $links_array);
		return $links_array;
	}
	add_filter("plugin_action_links", "ezbackup_set_plugin_action_links", 1, 2);

	function ezbackup_set_plugin_row_meta($links_array, $plugin_file) {
		if (strlen($plugin_file) > 10 && $plugin_file == substr(__file__, (-1 * strlen($plugin_file))))
			$links_array = array_merge($links_array, array(ezbackup_link("Donate", "https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8VWNB5QEJ55TJ", "heart")));
		return $links_array;
	}
	add_filter("plugin_row_meta", "ezbackup_set_plugin_row_meta", 1, 2);

	function ezbackup_link($lText, $lAddress, $lDashicon = "", $lTag = "", $lAnchor = "") {
		if (substr($lAddress, 0, 1) == "&" || substr($lAddress, 0, 1) == "#")
			$lAddress = $GLOBALS["ez-backup"]["url"].$lAddress;
		else
			$lAnchor = ' target="_blank"';
		return ($lTag?"<$lTag>":"").($lAddress?'<a'.$lAnchor.' href="'.$lAddress.'">':"").($lDashicon?'<span class="dashicons dashicons-'.$lDashicon.'"></span>':"").$lText.($lAddress?'</a>':"").($lTag?"</$lTag>":"");
	}

	function ezbackup_box($bTitle, $bContents, $bType = "postbox", $bDashicon = "") {
		$md5 = md5($bTitle);
		$GLOBALS["ez-backup"]["tmp"]["$bType"]["$md5"] = "$bTitle";
		return '
		<div id="box_'.$md5.'" class="'.$bType.'"><h3 title="Click to toggle" onclick="if (typeof '.$bType.'_showhide == \'function\'){'.$bType.'_showhide(\'inside_'.$md5.'\');}else{showhide(\'inside_'.$md5.'\');}" style="cursor: pointer;" class="hndle">'.($bDashicon?'<span id="dashicon_'.$md5.'" class="dashicons dashicons-'.$bDashicon.'" style="position: absolute;"></span><span style="padding-left: 24px;"':"<span").' id="title_'.$md5.'">'.$bTitle.'</span></h3>
			<div id="inside_'.$md5.'" class="inside">
	'.$bContents.'
			</div>
		</div>';
	}

	function ezbackup_display_header($pTitle, $optional_box = array()) {
		global $wp_version;
		$Update_Link = '<div style="text-align: center;"><a href="';
		$new_version = "";
		$current = get_site_transient("update_plugins");
		if (isset($current->response["ez-backup/index.php"]->new_version)) {
			$new_version = sprintf(__("Upgrade to %s now!", 'ezbackup'), $current->response["ez-backup/index.php"]->new_version).'<br /><br />';
			$Update_Link .= wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=ez-backup/index.php'), 'upgrade-plugin_ez-backup/index.php');
		}
		$Update_Link .= "\">$new_version</a></div>";
		echo '<style>
	#ez-backup-right-sidebar {float: right; margin-right: 0px;}
	#admin-model-popup {position: absolute; top: 0; right: 0; width: 150%; height: 100%; background-color: rgba(0, 0, 0, 0.7);}
	#makerestore {position: fixed; top: 50px; right: 50px; margin: 0 auto; background: #FFF; padding: 10px; border-radius: 10px;}
	.metabox-holder h3 {padding: 8px 12px;}
	.postbox {margin-right: 10px;}
	.dashicons {text-decoration: none; vertical-align: middle; width: 20px; height: 20px;}
	.dashicons-dismiss {color: #C00;}
	.dashicons-dismiss:hover {color: #F00;}
	.restore-steps li {font-size: 20px;}
	.ez-backup-field-label {float: left; margin: 6px; text-align: right; width: 110px;}
	.ez-backup-field-label input {width: 120px;}
	.ez-backup-db-files li a {display: none;}
	.ez-backup-db-files li:hover a {display: block;}
	.hndle {cursor: pointer; overflow: hidden; white-space: nowrap;}
</style>
	<h1 id="top_title"><span class="dashicons dashicons-backup" style="vertical-align: middle; width: 30px; height: 30px; font-size: 30px;"></span> '.$pTitle.'</h1>
	<div id="admin-page-container">
		<div id="ez-backup-right-sidebar" style="width: 250px;" class="metabox-holder">'.ezbackup_box(__("Plugin Information", 'ezbackup'), '<ul><li style="float: right; margin: 0;">WordPress: <span class="ez-backup-date">'.$wp_version.'</span></li>
<li>Plugin: <span class="ez-backup-date">'.$GLOBALS["ez-backup"]["ver"].'</span></li>
</ul>'.$Update_Link, "stuffbox", "admin-plugins").ezbackup_box(__("Quick Actions", 'ezbackup'), '<ul class="ez-backup-sidebar-links">'.ezbackup_link("Backup Database Now", "&ez-backup[db_date]=Y-m-d-H-i-s", "backup", "li").ezbackup_link("Repair All Tables in DB", "&ez-backup[db_date]=REPAIR%20All%20Tables", "hammer", "li").ezbackup_link("Restore a Database Backup", "#restoreForm", "migrate", "li", ' onclick="showhide(\'admin-model-popup\', true);"').'</ul>', "stuffbox", "star-filled").ezbackup_box(__("Resources & Links", 'ezbackup'), '<ul style="float: right; margin: 0;" class="ez-backup-sidebar-links">'.ezbackup_link("Plugin FAQs", "https://wordpress.org/plugins/ez-backup/faq/", "editor-help", "li").ezbackup_link("Forum Posts", "https://wordpress.org/support/plugin/ez-backup", "format-chat", "li").ezbackup_link("Plugin Reviews", "https://wordpress.org/support/view/plugin-reviews/ez-backup", "format-quote", "li").'</ul>
<ul class="ez-backup-sidebar-links">'.ezbackup_link("Eli's Plugins", "https://profiles.wordpress.org/scheeeli#content-plugins", "admin-plugins", "li").ezbackup_link("Eli's Blog", "http://wordpress.ieonly.com/category/my-plugins/ez-backup/", "admin-site", "li").ezbackup_link("Email Eli", "mailto:wordpress@ieonly.com", "email-alt", "li").'</ul>'.ezbackup_link("<img height=16 width=16 src='https://spideroak.com/favicon.ico' style='height: 16px; width: 16px; border: none; vertical-align: middle;'>Backup 3GB Free at spideroak.com", "https://spideroak.com/download/referral/fd0d1e6e4596b59373a194e7b95878e7", "", "center"), "stuffbox", "admin-links");
		$js = '
<script type="text/javascript">
function showhide(id) {
	divx = document.getElementById(id);
	if (divx) {
		if (divx.style.display == "none" || arguments[1]) {
			divx.style.display = "block";
			divx.parentNode.className = (divx.parentNode.className+"close").replace(/close/gi,"");
			return true;
		} else {
			divx.style.display = "none";
			return false;
		}
	}
}
function stuffbox_showhide(id) {
	divx = document.getElementById(id);
	if (divx) {
		if (divx.style.display == "none" || arguments[1]) {';
		$else = '
			if (divx = document.getElementById("ez-backup-right-sidebar"))
				divx.style.width = "38px";
			if (divx = document.getElementById("ez-backup-main-section"))
				divx.style.marginRight = "38px";';
		if (isset($GLOBALS["ez-backup"]["tmp"]["stuffbox"]) && is_array($GLOBALS["ez-backup"]["tmp"]["stuffbox"])) {
			foreach ($GLOBALS["ez-backup"]["tmp"]["stuffbox"] as $md5 => $bTitle) {
				$js .= "\nif (divx = document.getElementById('inside_$md5'))\n\tdivx.style.display = 'block';";
				$else .= "\nif (divx = document.getElementById('inside_$md5'))\n\tdivx.style.display = 'none';";
			}
		}
		echo $js.'
			if (divx = document.getElementById("ez-backup-right-sidebar"))
				divx.style.width = "250px";
			if (divx = document.getElementById("ez-backup-main-section"))
				divx.style.marginRight = "250px";
			return true;
		} else {'.$else.'
			return false;
		}
	}
}
function getWindowWidth(min) {
	if (typeof window.innerWidth != "undefined" && window.innerWidth > min)
		min = window.innerWidth;
	else if (typeof document.documentElement != "undefined" && typeof document.documentElement.clientWidth != "undefined" && document.documentElement.clientWidth > min)
		min = document.documentElement.clientWidth;
	else if (typeof document.getElementsByTagName("body")[0].clientWidth != "undefined" && document.getElementsByTagName("body")[0].clientWidth > min)
		min = document.getElementsByTagName("body")[0].clientWidth;
	return min;
}
if (getWindowWidth(750) == 750) 
	setTimeout("stuffbox_showhide(\'inside_'.$md5.'\')", 200);
</script>
		</div>
		<div id="ez-backup-main-section" style="margin-right: 250px;">
			<div class="metabox-holder" style="width: 100%;" id="ez-backup-metabox-container">';
	}

	function ezbackup_settings() {
		global $wpdb;
		ezbackup_set_backupdir();
		ezbackup_display_header("EZ Backup Settings");
		if (isset($_POST["ez-backup-settings"]["backup_method"]) && is_numeric($_POST["ez-backup-settings"]["backup_method"])) {
			$GLOBALS["ez-backup"]["settings"]["backup_method"] = intval($_POST["ez-backup-settings"]["backup_method"]);
			if (isset($_POST["ez-backup-settings"]["compress_backup"]))
				$GLOBALS["ez-backup"]["settings"]["compress_backup"] = 1;
			else
				$GLOBALS["ez-backup"]["settings"]["compress_backup"] = 0;
		} elseif (!isset($GLOBALS["ez-backup"]["settings"]["backup_method"]) || !is_numeric($GLOBALS["ez-backup"]["settings"]["backup_method"]))
			$GLOBALS["ez-backup"]["settings"]["backup_method"] = 0;
		if (!isset($GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"]) || !is_numeric($GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"]))
			$GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"] = 0;
		$import_settings_array = get_option("ELISQLREPORTS_settings_array", array());
		if (isset($_REQUEST["ez-backup-settings"]["backup_db"]["daily"]) && is_numeric($_REQUEST["ez-backup-settings"]["backup_db"]["daily"])) {
			if ($GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"] = intval($_REQUEST["ez-backup-settings"]["backup_db"]["daily"])) {
				if (!wp_next_scheduled('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily')))
					wp_schedule_event(time(), 'daily', 'ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily'));
				if (isset($import_settings_array["daily_backup"]) && $import_settings_array["daily_backup"])
					$import_settings_array["daily_backup"] = 0;
				while (wp_next_scheduled('ELISQLREPORTS_daily_backup', array("Y-m-d-H-i-s", 'daily')))
					wp_clear_scheduled_hook('ELISQLREPORTS_daily_backup', array("Y-m-d-H-i-s", 'daily'));
			} elseif (wp_next_scheduled('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily')))
				wp_clear_scheduled_hook('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily'));
		}
		if (!isset($GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"]) || !is_numeric($GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"]))
			$GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"] = 0;
		if (isset($_REQUEST["ez-backup-settings"]["backup_db"]["hourly"]) && is_numeric($_REQUEST["ez-backup-settings"]["backup_db"]["hourly"])) {
			if ($GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"] = intval($_REQUEST["ez-backup-settings"]["backup_db"]["hourly"])) {
				if (!wp_next_scheduled('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly')))
					wp_schedule_event(time(), 'hourly', 'ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly'));
				if (isset($import_settings_array["hourly_backup"]) && $import_settings_array["hourly_backup"])
					$import_settings_array["hourly_backup"] = 0;
				while (wp_next_scheduled('ELISQLREPORTS_hourly_backup', array("Y-m-d-H-i-s", 'hourly')))
					wp_clear_scheduled_hook('ELISQLREPORTS_hourly_backup', array("Y-m-d-H-i-s", 'hourly'));
			} elseif (wp_next_scheduled('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly')))
				wp_clear_scheduled_hook('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly'));
		}
		if (isset($import_settings_array["hourly_backup"]) && $import_settings_array["hourly_backup"] === 0 && isset($import_settings_array["daily_backup"]) && $import_settings_array["daily_backup"] === 0)
			update_option("ELISQLREPORTS_settings_array", $import_settings_array);
		if (isset($_POST["ez-backup-settings"]["backup_email"]) && (trim($_POST["ez-backup-settings"]["backup_email"]) != $GLOBALS["ez-backup"]["settings"]["backup_email"]))
			$GLOBALS["ez-backup"]["settings"]["backup_email"] = trim($_POST["ez-backup-settings"]["backup_email"]);
		elseif (!isset($GLOBALS["ez-backup"]["settings"]["backup_email"]) || !strlen(trim($GLOBALS["ez-backup"]["settings"]["backup_email"])))
			$GLOBALS["ez-backup"]["settings"]["backup_email"] = "";
		$db_opts = '<form name="settingsForm" method="post" action="'.$GLOBALS["ez-backup"]["url"].'"><table width="100%" border=0><tr><td width="1%" valign="top">Backup&nbsp;Method:</td><td width="99%">';
		foreach (array("Auto-detect", "Command Line (mysqldump)", "PHP (mysql_query)") as $mg => $backup_method)
			$db_opts .= '<div style="float: left; padding: 0 24px 8px 0;"><input type="radio" name="ez-backup-settings[backup_method]" value="'.$mg.'"'.($GLOBALS["ez-backup"]["settings"]["backup_method"]==$mg?' checked':'').' />'.$backup_method.'</div>';
		$db_opts .= '<div style="float: left; padding: 0 24px 8px 0;"><input type="checkbox" name="ez-backup-settings[compress_backup]" value="1"'.(isset($GLOBALS["ez-backup"]["settings"]["compress_backup"]) && $GLOBALS["ez-backup"]["settings"]["compress_backup"]?' checked':'').' />Compress Backup Files</div></td></tr><tr><td width="1%">Save&nbsp;all&nbsp;backups&nbsp;to:</td><td width="99%">'.(isset($_POST["ez-backup-settings"]["backup_dir"])&&!is_dir($_POST["ez-backup-settings"]["backup_dir"])?'<div class="error">Backups can only be saved to a directory that already exists and is writable!</div>':"").'<input style="width: 100%" name="ez-backup-settings[backup_dir]" value="'.$GLOBALS["ez-backup"]["settings"]["backup_dir"].'"></td></tr><tr><td width="1%">Email&nbsp;all&nbsp;backups&nbsp;to:</td><td width="99%"><input style="width: 100%" name="ez-backup-settings[backup_email]" value="'.$GLOBALS["ez-backup"]["settings"]["backup_email"].'"></td></tr></table><br />Automatically make and keep <input size=1 name="ez-backup-settings[backup_db][hourly]" value="'.$GLOBALS["ez-backup"]["settings"]["backup_db"]["hourly"].'"> Hourly and <input size=1 name="ez-backup-settings[backup_db][daily]" value="'.$GLOBALS["ez-backup"]["settings"]["backup_db"]["daily"].'"> Daily backups.<br />';
		if ($next = wp_next_scheduled('ezbackup_db_hourly', array("Y-m-d-H-i-s", 'hourly')))
			$db_opts .= ezbackup_link("next hourly backup: ".date("Y-m-d H:i:s", $next)." (About ".ceil(($next-time())/60)." minute".(ceil(($next-time())/60)==1?'':'s')." from now)", "", "clock", "div");
		if ($next = wp_next_scheduled('ezbackup_db_daily', array("Y-m-d-H-i-s", 'daily')))
			$db_opts .= ezbackup_link("next daily backup: ".date("Y-m-d H:i:s", $next)." (Less than ".ceil(($next-time())/60/60)." hour".(ceil(($next-time())/60/60)==1?'':'s')." from now)", "", "clock", "div");
		$opts = array("Y-m-d-H-i-s" => "Make A New Backup", "DELETE Post Revisions" => array("DELETE FROM wp_posts WHERE `wp_posts`.`post_type` = 'revision'", "DELETE FROM wp_postmeta WHERE `wp_postmeta`.`post_id` NOT IN (SELECT `wp_posts`.`ID` FROM `wp_posts`)", "OPTIMIZE TABLE wp_posts, wp_postmeta"), "DELETE Spam Comments" => array("DELETE FROM wp_comments WHERE `wp_comments`.`comment_approved` = 'spam'", "DELETE FROM wp_commentmeta WHERE `wp_commentmeta`.`comment_id` NOT IN (SELECT `wp_comments`.`comment_ID` FROM `wp_comments`)", "OPTIMIZE TABLE wp_comments, wp_commentmeta"));
		$repair_tables = $wpdb->get_col("show full tables where Table_Type = 'BASE TABLE'");
		if (is_array($repair_tables) && count($repair_tables))
			$opts["REPAIR All Tables"] = array('REPAIR TABLE `'.implode('`, `', $repair_tables).'`');
		if (!(isset($GLOBALS["ez-backup"]["settings"]["DB_HOSTS"]) && is_array($GLOBALS["ez-backup"]["settings"]["DB_HOSTS"])))
			$GLOBALS["ez-backup"]["settings"]["DB_HOSTS"][0] = get_option("ELISQLREPORTS_BACKUP_DB", array("DB_NAME" => DB_NAME, "DB_HOST" => DB_HOST, "DB_USER" => DB_USER, "DB_PASSWORD" => DB_PASSWORD));
		$restoreForm = '<form method="POST" id="restoreForm" name="restoreForm" action="'.$GLOBALS["ez-backup"]["url"].'"><div id="makerestore">'.ezbackup_link("X", "#restoreForm", "dismiss", "", ' onclick="showhide(\'admin-model-popup\');" style="float: right; color: #F00; overflow: hidden; width: 20px; height: 20px;"').'<ol class="restore-steps"><li><span></span>Select a Database Backup File to Restore:</li><select id="ezbackup_dbdate" name="ez-backup[db_date]">';
		$restoreFormEND = '<li><span></span>Enter the Credentials for the Database to be Restored:</li>';
		$local = true;
		foreach ($GLOBALS["ez-backup"]["settings"]["DB_HOSTS"][0] as $db_key => $db_value) {
			$restoreFormEND .= '<div class="ez-backup-field-label">'.$db_key.':</div><input name="'.$db_key;
			if (isset($_POST[$db_key])) {
				$GLOBALS["ez-backup"]["settings"]["DB_HOSTS"][0][$db_key] = $_POST[$db_key];
				$restoreFormEND .= '" readonly="true';
			}
			$restoreFormEND .= '" value="'.$GLOBALS["ez-backup"]["settings"]["DB_HOSTS"][0][$db_key].'"><br style="clear:left" />';
			if (constant($db_key) != $GLOBALS["ez-backup"]["settings"]["DB_HOSTS"][0][$db_key])
				$local = false;
		}
		update_option("ez-backup-settings", $GLOBALS["ez-backup"]["settings"]);
		$restoreFormEND .= 'Warning: This '.($local?'<u>is</u>':'is <u>NOT</u>').' your currently active WordPress database conection info for this site.<br />';
		if (isset($_REQUEST["ez-backup"]["db_date"]) && strlen($_REQUEST["ez-backup"]["db_date"])) {
			if (isset($opts[$_REQUEST["ez-backup"]["db_date"]]) && is_array($opts[$_REQUEST["ez-backup"]["db_date"]])) {
				foreach ($opts[$_REQUEST["ez-backup"]["db_date"]] as $MySQLexec) {
					$SQLkey = ezbackup_query($MySQLexec);
					if ($GLOBALS["ez-backup"]["query_times"][$SQLkey]["errors"])
						echo "<div class='error'>".$GLOBALS["ez-backup"]["query_times"][$SQLkey]["errors"]."</div>";
					else {
						if (preg_match('/ FROM /', $MySQLexec))
							echo preg_replace('/^(.+?) FROM (.+?) .*/', '<div class="updated">\\1 '.$GLOBALS["ez-backup"]["query_times"][$SQLkey]["rows"].' Records from \\2 Succeeded!</div>', $MySQLexec);
						else
							echo "<div class='updated'>$MySQLexec Succeeded!</div>";
					}
				}
			} elseif (is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]["backup_dir"]).$_REQUEST["ez-backup"]["db_date"])) {
				//Restore Backup to the DB with the posted credentials
				if (isset($_POST["db_nonce"]) && wp_verify_nonce($_POST["db_nonce"], $_REQUEST["ez-backup"]["db_date"])) {
					echo ezbackup_db2file("Y-m-d-H-i-s", "pre-restore", $_POST["DB_NAME"], $_POST["DB_HOST"], $_POST["DB_USER"], $_POST["DB_PASSWORD"]);
					$mysqlbasedir = $wpdb->get_row("SHOW VARIABLES LIKE 'basedir'");
					if(substr(PHP_OS,0,3) == "WIN")
						$backup_command = '"'.(isset($mysqlbasedir->Value)?trailingslashit(str_replace('\\', '/', $mysqlbasedir->Value)).'bin/':'').'mysql.exe"';
					else
						$backup_command = (isset($mysqlbasedir->Value)&&is_file(trailingslashit($mysqlbasedir->Value).'bin/mysql')?trailingslashit($mysqlbasedir->Value).'bin/':'').'mysql';
					if (strpos($_POST["DB_HOST"], ':')) {
						list($db_host, $db_port) = explode(':', $_POST["DB_HOST"], 2);
						if (is_numeric($db_port))
							$db_port = '" --port="'.$db_port.'" ';
						else
							$db_port = '" --socket="'.$db_port.'" ';
					} else {
						$db_host = $_POST["DB_HOST"];
						$db_port = '" ';
					}
					$backup_command .= ' --user="'.$_POST['DB_USER'].'" --password="'.$_POST['DB_PASSWORD'].'" --host="'.$db_host.$db_port.$_POST['DB_NAME'];
					if (substr($_REQUEST["ez-backup"]["db_date"], -7) == '.sql.gz') {
						passthru('gunzip -c "'.trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$_REQUEST["ez-backup"]["db_date"].'" | '.$backup_command, $errors);
						echo "<div class='".($errors?"error":"updated")."'>Restore process executed Gzip extraction with $errors error".($errors==1?'':'s').'!</div>';
					} elseif (substr($_REQUEST["ez-backup"]["db_date"], -8) == '.sql.zip') {
						$zip = new ZipArchive;
						if ($zip->open(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$_REQUEST["ez-backup"]["db_date"]) === TRUE) {
							$zip->extractTo(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']));
							$zip->close();
						}
						if (is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).substr($_REQUEST["ez-backup"]["db_date"], 0, -4))) {
							passthru($backup_command.' -e "source '.trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).substr($_REQUEST["ez-backup"]["db_date"], 0, -4).'"', $errors);
							if ($errors) {
								$file_sql = substr($_REQUEST["ez-backup"]["db_date"], 0, -4);
								if ($full_sql = file_get_contents(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$file_sql)) {
									$queries = 0;
									$errors = array();
									$startpos = 0;
									while ($endpos = strpos($full_sql, ";\n", $startpos)) {
										if ($sql = trim(@preg_replace("|/\*.+\*/[;\t ]*|", "", substr($full_sql, $startpos, $endpos - $startpos)).' ')) {
											if (mysql_query($sql))
												$queries++;
											else
												$errors[] = "<li>".mysql_error()."</li>";
										}
										$startpos = $endpos + 2;
									}
									echo "<div class='".($errors?"error":"updated")."'><li>Restore Process executed $queries queries with ".count($errors).' error'.(count($errors)==1?'':'s').'!</li><br>'.implode("\n", $errors).'</div>';
								} else
									echo '<div class="error">Error Reading File:'.trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$file_sql.'</div>';
							} else
								echo "<div class='".($errors?"error":"updated")."'>Restore process executed Zip extraction with $errors error".($errors==1?'':'s').'!</div>';
						} else
							echo '<div class="error">ERROR: Failed to extract Zip Archive!</div>';
					} elseif (substr($_REQUEST["ez-backup"]["db_date"], -4) == '.sql') {
						passthru($backup_command.' -e "source '.trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$_REQUEST["ez-backup"]["db_date"].'"', $errors);
						echo "<div class='".($errors?"error":"updated")."'>Restore process executed MySQL with $errors error".($errors==1?'':'s').'!</div>';
					}
				} else {
					$restoreForm .= '<option value="'.$_REQUEST["ez-backup"]["db_date"].'">RESTORE '.$_REQUEST["ez-backup"]["db_date"].'</option></select>';
					$restoreFormEND .= '<li style="color: #F00;"><span></span>Please Confirm</li><input name="db_nonce" type="checkbox" value="'.wp_create_nonce($_REQUEST["ez-backup"]["db_date"]).'"> Yes, I understand that I will be completely overwriting this database with the backup file.'."<script>\nshowhide('admin-model-popup', true);\n</script>\n";
				}
			} else
				echo ezbackup_db2file($_REQUEST["ez-backup"]["db_date"]);
		} elseif (isset($_GET['ez-backup-delete']) && is_file($delete = trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).str_replace('/', '', str_replace('\\', '', $_GET['ez-backup-delete']))))
			@unlink($delete);
		$sql_files = array();
		if ($handle = opendir($GLOBALS["ez-backup"]["settings"]['backup_dir'])) {
			while (false !== ($entry = readdir($handle)))
				if (is_file(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$entry) && strpos($entry, ".sql"))
					$sql_files[$entry] = round(filesize(trailingslashit($GLOBALS["ez-backup"]["settings"]['backup_dir']).$entry) / 1024, 0)."K";
			closedir($handle);
			krsort($sql_files);
			if (count($sql_files)) {
				$files = "\n<ul class=\"ez-backup-db-files\">\n";
				foreach ($sql_files as $entry => $size)
					$files .= "<li style='clear: left;'>($size) $entry<br />".ezbackup_link("Restore", "#restoreForm", "migrate", "", ' style="float: left;" onclick="document.getElementById(\'ezbackup_dbdate\').value=\''.$entry.'\'; showhide(\'admin-model-popup\', true);"').ezbackup_link("Download", "&ez-backup-download=$entry", "download", "", ' style="float: left; padding: 0 20px;" target="_blank"').ezbackup_link("DELETE", "&ez-backup-delete=$entry", "trash", "", ' style="float: left;"')."</li>\n";
				$files .= "</ul>\n";
			} else
				$files = "\n<b>No backups have yet been made</b>";
		} else
			$files = "\n<b>Could not read files in ".$GLOBALS["ez-backup"]["settings"]['backup_dir']."</b>";
		if (!strpos($restoreForm, "</select>")) {
			foreach ($sql_files as $entry => $size)
				$restoreForm .= "<option value=\"$entry\">$entry ($size)</option>";
			$restoreForm .= '</select>';
		}
		$restoreFormEND .= '<li><span></span><input type="submit" value="Restore Selected Backup to Database"> <input type="button" value="Cancel the Restore" onclick="showhide(\'admin-model-popup\');"></li></ol></div></form>';
		echo ezbackup_box("Database Backup Options", $db_opts.'<input type="submit" value="Save Settings" class="button-primary" style="float: right;"><br /></form>', "postbox", "admin-settings").ezbackup_box("Current Database Backups", "$files", "postbox", "menu")."</div></div></div><div id='admin-model-popup' style='display: none;'>$restoreForm$restoreFormEND</div>";
	}


}