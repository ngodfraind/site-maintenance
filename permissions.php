<?php 

const CONFIG_FILE = '/path/to/config.php';
const PERMISSION_FILE = '/path/to/permissions.sh';

$config = include CONFIG_FILE;

foreach ($config['sites'] as $site) {
	$folderName = array_keys($site)[0];
	system("sh " . PERMISSION_FILE . " {$site['owner']} {$site[$folderName]['path']}");
}



