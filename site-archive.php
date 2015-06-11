<?php

const CONFIG_FILE = '/path/to/config_file.php';

function test()
{	
	$config = include CONFIG_FILE;
	
	if (!commandExists('mysqldump')) {
		$msg = "Mysqldump command does not exists.\n";
		logError($msg);
		throw new Exception($msg);
	}
	
	if (!commandExists('zip')) {
		$msg = "Zip command does not exists.\n";
		logError($msg);
		throw new Exception($msg);
	}
	
	if (!is_writable($config['options']['tmpFolder'])) {
		$msg = "The temporary folder is not writable.\n";
		logError($msg);
		throw new Exception($msg);
	}
	
	if (!is_writable($config['options']['error-logs'])) {
		$msg = "The error log file is not writable.\n";
		logError($msg);
		throw new Exception($msg);
	}
	
    if (!is_writable($config['options']['success-logs'])) {
		$msg = "The success log file is not writable.\n";
		logError($msg);
		throw new Exception($msg);
	}
}

function run($platform)
{
	$config    = include CONFIG_FILE;
	$backups   = $config['sites'];
	$tmpFolder = $config['options']['tmpFolder'];
	
	$data = $backups[$name]
	$date = date("m-d-y_H-i-s");
		
	backupFiles(
		$data['files']['path'], 
		$data['files']['exclude'], 
		$name,
		$tmpFolder,
		$date
	);
		
	dumpDatabase(
		$data['database']['user'],
		$data['database']['password'],
		$data['database']['name'],
		$tmpFolder,
		$name,
		$date
	);
}

//compress the files
function backupFiles($path, array $exclude, $name, $tmpFolder, $date)
{
	$ds = DIRECTORY_SEPARATOR;
	$zipName = "{$tmpFolder}{$ds}{$name}@{$date}";
	write('Backing up files...');
	//go to the dir to zip;
	$parent = dirname($path);
	$pathParts = explode('/', $path);
	$rootDir = array_pop($pathParts);
	chdir($parent);
	$excludeCommand = '';
	
	if (count($exclude) > 0) {
		$excludeCommand .= '-x';
		
		foreach ($exclude as $folder) {
			$excludeCommand .= " \"{$rootDir}{$ds}{$folder}/*\"";
		}
	}
	
	$command = "zip -r {$zipName} {$rootDir} {$excludeCommand}";
    system($command, $returnCode);
    
    if ($returnCode !== 0) {
        // remove bup dir
        logError("zip exited with code {$returnCode} for {$name}");
    }

    write("Files backup written in {$zipName}");
}

//export the database
function dumpDatabase($user, $password, $database, $tmpFolder, $name, $date)
{
    write("Backing up database {$database}...");
    $ds = DIRECTORY_SEPARATOR;
    $sqlFile = "{$name}@{$date}.sql";
    $backupFile = "{$tmpFolder}{$ds}{$sqlFile}";
    $passwordCommand = '';
    
    if ($password) {
		$passwordCommand = "--password='{$password}'";
	}
	
    $command = 
		"mysqldump --opt --databases " .
		"{$database} -u {$user} {$passwordCommand} > {$backupFile}";    
		
    system($command, $returnCode);
    
    if ($returnCode !== 0) {
        // remove bup dir
        logError("Mysqldump exited with code {$returnCode} for {$name}.");
    }
    
    chdir($tmpFolder);
    system("zip {$sqlFile}.zip {$sqlFile}");
    system("rm {$sqlFile}");
    write("Database backup written in {$backupFile}");
}

//write in the console
function write($messages)
{
    foreach ((array) $messages as $message) {
        echo $message . "\n";
    }
}

function alert($messages)
{
	echo "\033[01;31m";
    write($messages);
    echo "\033[0m";
}

function commandExists($cmd) {
    $returnVal = shell_exec("which $cmd");
    
    return (empty($returnVal) ? false : true);
}

function logSuccess($log)
{
    $config = include CONFIG_FILE;
	$logFile = $config['options']['success-logs'];
	$logLine = date("m-d-y h:i:s") . ': ' . $log . PHP_EOL; 
	file_put_contents($logFile, $logLine, FILE_APPEND);
}

function logError($log)
{
	$config = include CONFIG_FILE;
	$logFile = $config['options']['error-logs'];
	$logLine = date("m-d-y h:i:s") . ': ' . $log . PHP_EOL; 
	file_put_contents($logFile, $logLine, FILE_APPEND);
}
