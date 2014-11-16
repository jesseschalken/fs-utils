#!/usr/bin/php
<?php

namespace FindDuplicateFiles;

require_once __DIR__ . '/vendor/autoload.php';

function formatBytes($bytes) {
    $f = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
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

    /**
     * @param \Traversable $bytes
     * @param string       $path
     * @return \Generator
     */
    function thread(\Traversable $bytes, $path) {
        $this->printProgress($path);
        foreach ($bytes as $data) {
            $this->add(strlen($data));
            $this->printProgress($path);
            yield $data;
        }
    }
}

class Hashes {
    /** @var File[][] */
    private $hashes = [];
    private $sizes = [];

    function add(File $file, Progress $progress) {
        $hash = hash($file->contents($progress, $this));
        $hash = "[{$file->type()}] $hash";

        $this->hashes[$hash][] = $file;
        $this->sizes[$hash]    = $file->size();
        return $hash;
    }

    /**
     * @param string $hash
     * @return File[]
     */
    function files($hash) {
        return $this->hashes[$hash];
    }

    function sorted() {
        $sizes = [];
        foreach ($this->hashes as $hash => $files)
            if (count($files) > 1)
                $sizes[$hash] = $this->amountDuplicated($hash);
        arsort($sizes, SORT_NUMERIC);
        return array_keys($sizes);
    }

    function totalSize($hash) {
        return $this->sizes[$hash] * count($this->hashes[$hash]);
    }

    function verify($hash) {
        $size = $this->sizes[$hash];
        $files =& $this->hashes[$hash];
        foreach ($files as $k => $file) {
            if (!$file->exists() || $file->size() != $size)
                unset($files[$k]);
        }
        $files = array_values($files);
    }

    function amountDuplicated($hash) {
        $dupes = count($this->hashes[$hash]) - 1;
        $size  = $this->sizes[$hash];

        return $dupes * $size;
    }
}

function runReport(Hashes $hashes, $limit = null) {
    $sorted = $hashes->sorted();
    if ($limit !== null)
        $sorted = array_slice($sorted, 0, (int)$limit);

    $i = 0;
    while (isset($sorted[$i])) {
        $num   = count($sorted);
        $hash  = $sorted[$i];
        $hashes->verify($hash);
        $files = $hashes->files($hash);
        $count = count($files);
        $index = ($i+1) . "/$num";

        if ($count <= 1) {
            array_splice($sorted, $i, 1);
            continue;
        }

        $duplicated = $hashes->amountDuplicated($hash);
        $duplicated = formatBytes($duplicated);

        print "$index: $hash ($count copies, $duplicated duplicated)\n";

        $options = [];
        foreach ($files as $k => $file)
            $options[$k + 1] = "Keep only \"{$file->path()}\"";
        $options['D'] = 'Delete ALL';
        $options['n'] = 'Next duplicate';
        $options['p'] = 'Previous duplicate';
        $options['q'] = 'Quit';

        $choice = readOption($options);

        if ($choice === 'n') {
            $i = ($i + $num + 1) % $num;
        } else if ($choice === 'p') {
            $i = ($i + $num - 1) % $num;
        } else if ($choice === 'q') {
            print "quit\n";
            return;
        } else if ($choice === 'D') {
            foreach ($files as $file)
                $file->delete();
            array_splice($sorted, $i, 1);
        } else if (is_numeric($choice) && isset($files[$choice - 1])) {
            foreach ($files as $k => $file)
                if ($k !== ($choice - 1))
                    $file->delete();
            array_splice($sorted, $i, 1);
        } else {
            throw new \Exception;
        }
    }
    print "done\n";
}

function readOption(array $options) {
    while (true) {
        print "Please select an option:\n";
        foreach ($options as $k => $v)
            print "  $k: $v\n";
        print "> ";
        $line = fgets(STDIN);
        $line = substr($line, 0, -1);
        if (isset($options[$line]))
            return $line;
    }
    throw new \Exception;
}

function main() {
    ini_set('memory_limit', '-1');
    $args = \Docopt::handle(<<<s
find-duplicate-files

Usage:
  find-duplicate-files [--limit=LIMIT] <path>...
s
    );

    $paths = $args['<path>'];
    $limit = $args['--limit'];

    /** @var File[] $files */
    $files = [];
    $size  = 0;
    foreach ($paths as $path) {
        $file = new File($path);
        $size += $file->size(function ($path) {
            printReplace("scanning $path");
        });
        $files[] = $file;
    }
    printReplace();

    print "found " . formatBytes($size) . "\n";

    $progress = new Progress($size);
    $hashes   = new Hashes;
    foreach ($files as $file)
        $hashes->add($file, $progress);

    printReplace();
    runReport($hashes, $limit);
}

main();
