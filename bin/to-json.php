<?php

use PureBencode\Bencode;

error_reporting(-1);
ini_set('display_errors', 'On');

require_once __DIR__ . '/../vendor/autoload.php';

function to_utf8($thing) {
    if (is_string($thing))
        $thing = utf8_encode($thing);

    if (is_array($thing))
        foreach ($thing as &$thing2)
            $thing2 = to_utf8($thing2);

    return $thing;
}

$file    = isset($argv[1]) ? $argv[1] : 'php://stdin';
$bencode = file_get_contents($file);
$decoded = Bencode::decode($bencode);
$json    = json_encode(to_utf8($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
print $json;

