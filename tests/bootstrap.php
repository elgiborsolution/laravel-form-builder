<?php

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

$supportFiles = glob(__DIR__ . '/Support/*.php') ?: [];

foreach ($supportFiles as $file) {
    require_once $file;
}
