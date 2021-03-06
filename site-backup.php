<?php

const CONFIG_FILE = '/home/jorge/documents/backup/site-maintenance/config.php';

require_once __DIR__ . '/ftp_connection.php';

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
    
	if ($subFolder === null || $max === null) {
		alert(['Usage:', 
			"php site-backup.php subfolder max",
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
	$backups   = $config['sites'];
	$tmpFolder = $config['options']['tmpFolder'];
    $errors    = array();
	
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
		
        $contype = $config['options']['conn_type'];
        
        if ($contype === 'ftp') {
            $ftpdata = array(
                'host'     => $config['options']['server']['host'],
                'login'    => $config['options']['ftp']['user'],
                'password' => $config['options']['ftp']['password']
            );
        } elseif ($contype === 'sftp') {
            $ftpdata = array(
                'host'     => $config['options']['server']['host'],
                'login'    => $config['options']['sftp']['user'],
                'password' => $config['options']['sftp']['password'],
                'pubkey'   => $config['options']['sftp']['pubkey'],
                'privkey'  => $config['options']['sftp']['privkey']
            );
        } else {
            throw new \Exception($contype . ' is not supported');
        }
        
		$error = store(
			$tmpFolder,
            $contype,
            $ftpdata,
			$name,
			$date,
			$config['options']['server']['destination'],
			$subfolder,
			$max
		);
        
        var_dump($error);
        
        if (!$error) {
            $errors[] = "{$name} backup failed";
        }
	}
    
    return $errors;
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

//ftp storage on distant server
function store($tmpFolder, $contype, $ftpdata, $name, $date, $destination, $subfolder, $max)
{
	$ds = DIRECTORY_SEPARATOR;
	$con = FTPFactory::getConnection($contype, $ftpdata);
	$filename = $name . '@' . $date;
	$isConnected = $con->connect();
	
	if (false === $isConnected) {
		logError("Unable to log in to the ftp server while uploading {$name}.");
        
        return false;
	} else {
		write('Saving data at the ftp server...');
			
		//check if the backup folder already exists
		$listFiles = $con->nlist($destination);
		$found = false;
		
		foreach ($listFiles as $el) {
			if ($el === $destination . $ds . $name) {
				$found = true;
			} 
		}
		
		$destinationFolder = $destination . $ds . $name;
		
		//create the backup folder
		if (!$found) {
			$success = $con->mkdir($destinationFolder);
			
			if (!$success) {
				logError("The folder {$destinationFolder} was not created on the remote server."); 
			}
			
			$success = $con->mkdir("{$destinationFolder}{$ds}{$subfolder}");
			
			if (!$success) {
				logError("The folder {$destinationFolder}{$ds}{$subfolder} was not created on the remote server."); 
			}
			
		} else {
            $found = false;
			//checks if the subfolders still exists.
			$listFiles = $con->nlist($destinationFolder);

			foreach ($listFiles as $el) {
				if ($el === "{$destinationFolder}{$ds}{$subfolder}") {
					$found = true;
				}
			}
			
			if (!$found) {
				logError("The folder {$subfolder} has been removed. Trying to create it again..."); 
				$success = $con->mkdir("{$destinationFolder}{$ds}{$subfolder}");
				
				if (!$success) {
					logError("The folder {$destinationFolder}{$ds}{$subfolder} was not created on the remote server."); 
                    
                    return false;
				}
			}
		}
		
		$error = upload($con, $destinationFolder, $filename, $tmpFolder, $subfolder, $max);
		$con->close();
	}
    
    return $error;
}

function upload($con, $destinationFolder, $filename, $tmpFolder, $subfolder, $max)
{
	$ds = DIRECTORY_SEPARATOR;
	
	$success = $con->put(
		"{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}.zip", 
		"{$tmpFolder}{$ds}{$filename}.zip"
	);
	
    unlink("{$tmpFolder}{$ds}{$filename}.zip");
    
	if (!$success) {
		logError("The file {$filename}.zip was not uploaded."); 
        
        return false;
	}
	
	$success = $con->put(
		"{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}.sql.zip", 
		"{$tmpFolder}{$ds}{$filename}.sql.zip"
	);
	
    unlink("{$tmpFolder}{$ds}{$filename}.sql.zip");
    
	if (!$success) {
		logError("The file {$filename}.sql.zip was not uploaded.");
        
        return false;
	} 
	
	$files = $con->nlist("{$destinationFolder}{$ds}{$subfolder}");
	$old = findOldestFiles($files, "{$destinationFolder}{$ds}{$subfolder}{$ds}{$filename}");

	if (count($files) > 2 * $max) {
		foreach ($old as $el) {
			$success = $con->delete($el);
			
			if (!$success) {
				logError("The file {$el} was not removed.");
			}
		}
	}
    
    return true;
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
		$datetime = date_create_from_format('m-d-y_H-i-s', $date);
		
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
$errors = run($subFolder, $max);

if (0 === count($errors)) {
    logSuccess("The script was successfull !");
}
