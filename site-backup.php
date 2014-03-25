<?php

const CONFIG_FILE = '/home/jorge/documents/backup/config-test.php';

function test($subFolder, $max)
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
	
	if (!is_writable($config['options']['logs'])) {
		$msg = "The log file is not writable.\n";
		logError($msg);
		throw new Exception($msg);
	}
	
	if ($subFolder === null || $max === null) {
		alert(['Usage:', 
			"php {$argv[0]} subfolder max",
			'Arguments:',
			' - subfolder: the backup subfolder (ie the cron period)',
			' - max:       the maximum amount of file in the subfolder'
        ]);
        $msg = "Arguments missing";
        logError($msg);
		throw new Exception($msg);
	}
}

function run($subfolder, $max)
{
	$config    = include CONFIG_FILE;
	$backups   = $config['backups'];
	$tmpFolder = $config['options']['tmpFolder'];
	
	foreach ($backups as $name => $data) {
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
		
		store(
			$tmpFolder,
			$config['options']['ftp']['user'],
			$config['options']['ftp']['password'],
			$config['options']['ftp']['server'],
			$name,
			$date,
			$config['options']['ftp']['destination'],
			$subfolder,
			$max
		);
	}
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
			$excludeCommand .= " {$rootDir}{$ds}{$folder}{$ds}*";
		}
	}
	
	$command = "zip -rq {$zipName} {$rootDir} {$excludeCommand}";
    system($command, $returnCode);
    
    if ($returnCode !== 0) {
        // remove bup dir
        write("zip exited with code {$returnCode}");
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
		$passwordCommand = "-p{$password}";
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

//ftp storage on distant server
function store($tmpFolder, $login, $password, $server, $name, $date, $destination, $subfolder, $max)
{
	$ds = DIRECTORY_SEPARATOR;
	$con = ftp_connect($server);
	$filename = $name . '@' . $date;
	
	if (false === $con) {
		logError("unable to connect to the ftp server while uploading {$name}");
	}
	
	$loggedIn = ftp_login($con,  $login,  $password);
	
	if (false === $loggedIn) {
		logError("Unable to log in to the ftp server while uploading {$name}.");
	} else {
		write('Saving data at the ftp server...');
			
		//check if the backup folder already exists
		$listFiles = ftp_nlist($con, $destination);
		$found = false;
		
		foreach ($listFiles as $el) {
			if ($el === $destination . $ds . $name) {
				$found = true;
			} 
		}
		
		$destinationFolder = $destination . $ds . $name;
		
		//create the backup folder
		if (!$found) {
			$success = ftp_mkdir($con, $destinationFolder);
			
			if (!$success) {
				logError("The folder {$destinationFolder} was not created on the remote server."); 
			}
			
			$success = ftp_mkdir($con, "{$destinationFolder}{$ds}{$subfolder}");
			
			if (!$success) {
				logError("The folder {$destinationFolder}{$ds}{$subfolder} was not created on the remote server."); 
			}
			
		} else {
			//checks if the subfolders still exists.
			$listFiles = ftp_nlist($con, $destinationFolder);
			
			foreach ($listFiles as $$el) {
				if ($el === $subfolder) {
					$found = true;
				}
			}
			
			if (!$found) {
				logError("The folder {$subfolder} has been removed. Trying to create it again..."); 
				$success = ftp_mkdir($con, "{$destinationFolder}{$ds}{$subfolder}");
				
				if (!$success) {
					logError("The folder {$destinationFolder}{$ds}{$subfolder} was not created on the remote server."); 
				}
			}
		}
		
		upload($con, $destinationFolder, $filename, $tmpFolder, $subfolder, $max);
		ftp_close($con);
	}
    
    write("ftp backup written in {$destination}{$ds}{$name}");
}

function upload($con, $destinationFolder, $filename, $tmpFolder, $subfolder, $max)
{
	$ds = DIRECTORY_SEPARATOR;
	
	$success = ftp_put(
		$con, 
		"{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}.zip", 
		"{$tmpFolder}{$ds}{$filename}.zip",
		FTP_BINARY
	);
	
	if (!$success) {
		logError("The file {$filename}.zip was not uploaded."); 
	} else {
		unlink("{$tmpFolder}{$ds}{$filename}.zip");
	}
	
	$success = ftp_put(
		$con, 
		"{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}.sql.zip", 
		"{$tmpFolder}{$ds}{$filename}.sql.zip",
		FTP_BINARY
	);
	
	if (!$success) {
		logError("The file {$filename}.sql.zip was not uploaded.");
	} else {
		unlink("{$tmpFolder}{$ds}{$filename}.sql.zip");
	}
	
	$files = ftp_nlist($con, "{$destinationFolder}{$ds}{$subfolder}");
	$old = findOldestFiles($files, "{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}");

	if (count($files) > 2 * $max) {
		foreach ($old as $el) {
			$success = ftp_delete($con, $el);
			
			if (!$success) {
				logError("The file {$el} was not removed.");
			}
		}
	}
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

function logError($log)
{
	$config = include CONFIG_FILE;
	$logFile = $config['options']['logs'];
	$logLine = date("m-d-y h:i:s") . ': ' . $log . PHP_EOL; 
	file_put_contents($logFile, $logLine, FILE_APPEND);
}

//return the 2 oldest files (sql & sources)
function findOldestFiles($files, $basename) 
{
	$dates = [];
	
	foreach ($files as $file) {
		$pattern = "/^[^@]+@([^\.]+)\..+$/";
		preg_match($pattern, $file, $matches);
		$dates[] = $matches[1];
	}
	
	$dates = array_unique($dates);
	$datetime = date_create_from_format('m-d-y_H-i-s', $dates[0]);
	$lowestDate = $datetime->getTimestamp();

	foreach($dates as $date){
		$datetime = date_create_from_format('m-d-y_H-i-s', $dates[0]);
		
		if(strtotime($datetime->getTimestamp()) < $lowestDate){
			$lowestDate = $datetime->getTimestamp();
		}
	}
	
	$lowestDate = date('m-d-y_H-i-s', $lowestDate);
	$ds = DIRECTORY_SEPARATOR;
	$parts = explode('@', $basename);
	
	return array(
		$parts[0] . '@' . $lowestDate . '.zip',
		$parts[0] . '@' . $lowestDate . '.sql.zip',
	);
}

$subFolder = $argv[1];
$max = $argv[2];

test($subFolder, $max);
run($subFolder, $max);
