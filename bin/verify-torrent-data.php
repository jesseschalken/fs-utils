<?php

use PureBencode\Bencode;

error_reporting(-1);
ini_set('display_errors', 'On');

require_once __DIR__ . '/../vendor/autoload.php';

array_shift($argv);
$dir = array_shift($argv);
print "dir: $dir\n";

foreach ($argv as $torrent) {
    print "torrent: $torrent\n";
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
    $i = 0;
    foreach ($files as $name => $size) {
        print "  $name\n";
        $path = "$dir/$name";

        if (!file_exists($path)) {
            print "file $path does not exist\n";
            continue;
        }

        if (!is_readable($path)) {
            print "file $path is not readable\n";
            continue;
        }

        if (!is_file($path)) {
            print "$path is not a file\n";
            continue;
        }

        $filesize = filesize($path);

        if ($filesize != $size) {
            print "file size mismatch. expected $size bytes, got $filesize bytes\n";
        }

        $f = fopen($path, 'r');
        while (!feof($f)) {
            $buffer .= fread($f, $pieceSize);
            while (strlen($buffer) >= $pieceSize) {
                $piece = substr($buffer, 0, $pieceSize);
                $buffer = substr($buffer, $pieceSize);
                $hash = array_shift($pieces);
                if (!is_string($hash)) {
                    print "torrent is missing hash for peice #$i\n";
                    continue;
                }
                $hash2 = sha1($piece, true);
                if ($hash !== $hash2) {
                    print "hash mismatch at piece #$i: expected $hash, got $hash2\n";
                }
                $i++;
            }
        }
        fclose($f);
    }
}
