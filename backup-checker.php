#!/usr/bin/php5

<?php

$logFile = __DIR__ . '/logs/success-logs';
$hasSucceeded = false;
$error = 'No error specified.';

if (!file_exists($logFile)) {
    $error = "Cannot found log file {$logFile}.";
} else {
    $logLines = file($logFile);

    if (($lineCount = count($logLines)) > 0) {
        do {
            $lastLine = trim(array_pop($logLines));
            $lineCount--;
        } while ($lastLine === '' && $lineCount > 0);

        $regex = '/^' . preg_quote(date('m-d-y')) . '/';

        if (1 !== $hasSucceeded = preg_match($regex, $lastLine)) {
            $error = "No success log found for today backup in {$logFile}.";
        }
    } else {
        $error = "Log file {$logFile} is empty.";
    }
}

if (!$hasSucceeded) {
    file_put_contents('php://stderr', "\n\n{$error}\n");
}

exit($hasSucceeded ? 0 : 1);

