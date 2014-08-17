<?php

use PureBencode\Bencode;

error_reporting(-1);
ini_set('display_errors', 'On');

require_once __DIR__ . '/../vendor/autoload.php';

array_shift($argv);
$dir = array_shift($argv);
print "dir: $dir\n";

$clear = "\r\x1B[2K";

$j = 1;
$total = count($argv);
foreach ($argv as $torrent) {
    $info = Bencode::decode(file_get_contents($torrent))['info'];

    if (isset($info['name']))
        $name = $info['name'];
    else
        $name = pathinfo($torrent, PATHINFO_FILENAME);

    $files = array();
    if (isset($info['length'])) {
        $files[$name] = $info['length'];
    } else {
        foreach ($info['files'] as $file)
            $files["$name/" . join('/', $file['path'])] = $file['length'];
    }
    $pieceSize = $info['piece length'];
    $pieces = str_split($info['pieces'], 20);
    $buffer = '';
    $total2 = count($files);
    $totalPieces = 0;
    foreach ($files as $size)
        $totalPieces += $size / $pieceSize;
    $totalPieces = ceil($totalPieces);
    $k = 1;
    $i = 1;
    foreach ($files as $name => $size) {
        $path = "$dir/$name";

        print $clear;

        if (!file_exists($path)) {
            print "{$clear}file $path does not exist\n";
            continue;
        }

        if (!is_readable($path)) {
            print "{$clear}file $path is not readable\n";
            continue;
        }

        if (!is_file($path)) {
            print "{$clear}$path is not a file\n";
            continue;
        }

        $filesize = filesize($path);

        if ($filesize != $size) {
            print "\nfile size mismatch. expected $size bytes, got $filesize bytes\n";
        }

        $f = fopen($path, 'r');
        while (!feof($f)) {
            $buffer .= fread($f, $pieceSize);
            while (strlen($buffer) >= $pieceSize) {
                $percent = number_format($i*100/$totalPieces,2);
                print "{$clear}$j/$total, $torrent: $percent% [$i/$totalPieces] $k/$total2, $path";
                $piece = substr($buffer, 0, $pieceSize);
                $buffer = substr($buffer, $pieceSize);
                $hash = array_shift($pieces);
                if (!is_string($hash)) {
                    print "{$clear}$torrent: piece $i/$totalPieces missing\n";
                    continue;
                }
                $hash2 = sha1($piece, true);
                if ($hash !== $hash2) {
                    print "{$clear}$torrent: piece $i/$totalPieces: hash mismatch, expected " . bin2hex($hash) . ", got " . bin2hex($hash2) . "\n";
                }
                $i++;
            }
        }
        fclose($f);
        $k++;
    }
    $j++;
}

print "\n";
