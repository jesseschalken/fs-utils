#!/usr/bin/php
<?php

namespace FindDuplicateFiles;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * @param string $path
 * @return \Generator
 */
function readFile($path) {
    $f = fopen($path, 'rb');
    while (!feof($f))
        yield fread($f, 102400);
    fclose($f);
}

function formatBytes($bytes) {
    $f = array('', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y');
    $i = (int)floor(log(max(abs($bytes), 1), 1000));
    return number_format($bytes / pow(1000, $i), 2) . " {$f[$i]}B";
}

function printReplace($line = '') {
    static $cols;
    if ($cols === null)
        $cols = (int)`tput cols`;
    if ($cols && strpos($line, "\n") === false)
        $line = substr($line, 0, $cols);
    print "\r\x1B[2K$line";
}

class Progress {
    private $total;
    private $startTime;
    private $done = 0;

    function __construct($total) {
        $this->total     = $total;
        $this->startTime = microtime(true);
    }

    function add($num) {
        $this->done += $num;
    }

    function rate() {
        $done = $this->done;
        return !$done ? 0 : $done / (microtime(true) - $this->startTime);
    }

    function eta() {
        $rate = $this->rate();
        return !$rate ? INF : ($this->total - $this->done) / $rate;
    }

    function formatRate() {
        return formatBytes($this->rate()) . '/s';
    }

    function formatETA() {
        $t = $this->eta();
        if ($t === INF)
            return 'forever';
        $t = (int)$t;
        return sprintf('%02d:%02d:%02d', $t / 3600, $t / 60 % 60, $t % 60);
    }

    function percent() {
        return $this->done / $this->total;
    }

    function formatPercent() {
        return number_format($this->percent() * 100, 2) . '%';
    }

    function formatProgress() {
        $percent = $this->formatPercent();
        $eta     = $this->formatETA();
        $rate    = $this->formatRate();
        return "[$percent, $rate, ETA $eta]";
    }

    function printProgress($note = null) {
        $line = $this->formatProgress();
        if ($note)
            $line .= ": $note";
        printReplace($line);
    }
}

const DIR_SEP = DIRECTORY_SEPARATOR;

/**
 * @param string $path
 * @return int
 */
function getSize($path) {
    printReplace("calculating size: $path");
    $size = 0;
    if (is_dir($path) && !is_link($path)) {
        $scan = scandir($path);
        $scan = array_diff($scan, array('.', '..'));
        foreach ($scan as $s)
            $size += getSize($path . DIR_SEP . $s);
    } else if (is_file($path)) {
        $size += filesize($path);
    }
    return $size;
}

function getHash($path, array &$hashes, Progress $p) {
    $h = hash_init('sha1');

    if (is_dir($path) && !is_link($path)) {
        hash_update($h, "dir\n");
        $scan = scandir($path);
        $scan = array_diff($scan, array('.', '..'));
        foreach ($scan as $s) {
            $hash2 = getHash($path . DIR_SEP . $s, $hashes, $p) ? : str_repeat("\x00", 20);
            hash_update($h, "$hash2 $s\n");
        }
    } else if (is_file($path)) {
        foreach (readFile($path) as $piece) {
            $p->add(strlen($piece));
            hash_update($h, $piece);
            $p->printProgress($path);
        }
    } else {
        return null;
    }

    $hash = hash_final($h, true);

    $hashes[$hash][] = $path;

    return $hash;
}

$f = function () {
    ini_set('memory_limit', '-1');
    $args = \Docopt::handle(<<<s
find-duplicate-files

Usage:
  find-duplicate-files <path>...
s
    );

    $paths = $args['<path>'];

    $size = 0;
    foreach ($paths as $p)
        $size += getSize($p);
    printReplace();

    $progress = new Progress($size);
    $hashes   = array();
    foreach ($paths as $p)
        getHash($p, $hashes, $progress);
    printReplace();

    $counts = array();
    foreach ($hashes as $hash => $paths2) {
        $count = count($paths2);
        if ($count > 1)
            $counts[$hash] = $count;
    }
    asort($counts, SORT_NUMERIC);
    $counts = array_reverse($counts, true);

    foreach ($counts as $hash => $count) {
        print bin2hex($hash) . " ($count copies)\n";
        foreach ($hashes[$hash] as $path)
            print "  $path\n";
        print "\n";
    }
};
$f();
