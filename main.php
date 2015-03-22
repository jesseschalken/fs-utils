#!/usr/bin/php
<?php

namespace TorrentVerify;

require_once __DIR__ . '/vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', 'On');
mb_internal_encoding('UTF-8');

const DIR_SEP = DIRECTORY_SEPARATOR;

function fixWindowsPath($path) {
    $invalids = str_split('<>:"|?*');

    foreach (range(0, 31) as $ord)
        $invalids[] = chr($ord);

    return str_replace($invalids, '_', $path);
}

function dump($string) {
    return json_encode($string, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function readFiles(array $files) {
    foreach ($files as $file) {
        $f = fopen($file, 'rb');
        while (!feof($f))
            yield fread($f, 1024000);
        fclose($f);
    }
}

function chunk(\Traversable $data, $length) {
    $buffer = '';
    foreach ($data as $piece) {
        $buffer .= $piece;
        while (strlen($buffer) > $length) {
            yield substr($buffer, 0, $length);
            $buffer = substr($buffer, $length);
        }
    }
    if (strlen($buffer) > 0)
        yield $buffer;
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

function recursiveScan($dir) {
    $result = array();
    $files  = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . DIR_SEP . $file;
        if (is_dir($path) && !is_link($path)) {
            foreach (recursiveScan($path) as $file2) {
                $result[] = $file . DIR_SEP . $file2;
            }
        } else {
            $result[] = $file;
        }
    }
    return $result;
}

function findTorrentFiles($path) {
    if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'torrent') {
        yield $path;
    } else if (is_dir($path)) {
        foreach (array_diff(scandir($path), array('.', '..')) as $file) {
            foreach (findTorrentFiles($path . DIR_SEP . $file) as $path2)
                yield $path2;
        }
    }
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

class TorrentInfo {
    static function parse($torrent) {
        $info = \PureBencode\Bencode::decode(file_get_contents($torrent))['info'];

        if (isset($info['name']))
            $name = $info['name'];
        else
            $name = pathinfo($torrent, PATHINFO_FILENAME);

        $self = new self;

        if (isset($info['length'])) {
            $self->files[$name] = $info['length'];
        } else {
            foreach ($info['files'] as $file) {
                $path               = join(DIR_SEP, $file['path']);
                $path               = $name . DIR_SEP . $path;
                $self->files[$path] = $file['length'];
            }
        }

        $self->pieceSize = $info['piece length'];
        $self->pieces    = str_split($info['pieces'], 20);
        $self->filename  = $torrent;

        return $self;
    }

    /** @var string */
    private $filename;
    /** @var int[] */
    private $files = array();
    /** @var string[] */
    private $pieces = array();
    /** @var int */
    private $pieceSize = 0;

    function fixWindowsPaths() {
        $files = array();
        foreach ($this->files as $path => $size)
            $files[fixWindowsPath($path)] = $size;
        $this->files = $files;
    }

    function fileNames() {
        return array_keys($this->files);
    }

    function totalSize() {
        $size = 0.0;
        foreach ($this->files as $size1)
            $size += $size1;
        return $size;
    }

    function checkFiles($dataDir) {
        $okay = true;
        foreach ($this->files as $file => $size) {
            $path = $dataDir . DIR_SEP . $file;

            if (!file_exists($path)) {
                $this->out("file \"$path\" does not exist");
                $okay = false;
            } else if (!is_readable($path)) {
                $this->out("file \"$path\" is not readable");
                $okay = false;
            } else if (!is_file($path)) {
                $this->out("file \"$path\" is not a file");
                $okay = false;
            } else {
                $filesize = filesize($path);
                if ($filesize != $size) {
                    $this->out("file \"$path\": expected $size bytes, got $filesize bytes");
                    $okay = false;
                }
            }
        }
        return $okay;
    }

    private function out($line) {
        printReplace("\"$this->filename\": $line\n");
    }

    function checkFileContents($dataDir, Progress $progress) {
        $files = array();
        foreach ($this->files as $file => $size)
            $files[] = $dataDir . DIR_SEP . $file;

        $t1 = microtime(true);

        $done = 0;
        $okay = true;
        foreach (chunk(readFiles($files), $this->pieceSize) as $i => $piece) {
            $t2 = microtime(true);
            if (($t2 - $t1) > (1 / 30) || $i == 0) {
                $progress->printProgress($this->filename);
                $t1 = $t2;
            }

            $progress->add(strlen($piece));
            $done += strlen($piece);

            $hash     =& $this->pieces[$i];
            $filehash = sha1($piece, true);

            if (!$hash) {
                $this->out("piece $i onward is missing");
                $okay = false;
                break;
            } else if ($filehash !== $hash) {
                $count = count($this->pieces);
                $this->out("piece $i/$count hash mismatch, expected " . bin2hex($hash) . ", got " . bin2hex($filehash) . "");
                $okay = false;
                break;
            }
        }

        $progress->add($this->totalSize() - $done);

        return $okay;
    }
}

function main() {
    $docopt = new \Docopt\Handler;
    $args   = $docopt->handle(<<<'s'
torrent-verify

Usage:
  torrent-verify verify-data [options] <torrent>...
  torrent-verify missing-files [options] <torrent>...
  torrent-verify orphaned-files [options] <torrent>...
  torrent-verify list-files [options] <torrent>...
  torrent-verify --help|-h

Options:
  --data-dir=<dir>     Directory to look for downloaded torrent data [default: .].
  --windows            Replace characters which Windows disallows in filenames with '_'.
s
    );

    /** @var TorrentInfo[] $torrents */
    $dataDir    = $args['--data-dir'];
    $allFiles   = recursiveScan($dataDir);
    $totalSize  = 0.0;
    $torrents   = array();
    $validFiles = array();
    $isWindows  = $args['--windows'];
    foreach ($args['<torrent>'] as $name) {
        foreach (is_dir($name) ? findTorrentFiles($name) : array($name) as $name2) {
            $torrent = TorrentInfo::parse($name2);
            if ($isWindows)
                $torrent->fixWindowsPaths();
            $torrents[] = $torrent;
            $totalSize += $torrent->totalSize();
            $validFiles = array_merge($validFiles, $torrent->fileNames());
        }
    }

    if ($args['list-files']) {
        foreach ($validFiles as $file)
            print "$file\n";
    }

    if ($args['verify-data']) {
        $formatTotalSize = formatBytes($totalSize);
        $numTorrents     = count($torrents);
        $numFiles        = count($validFiles);
        print "$formatTotalSize, $numTorrents torrents, $numFiles files\n";
        print "\n";

        $progress = new Progress($totalSize);
        foreach ($torrents as $torrent) {
            $okay = $torrent->checkFiles($dataDir);
            if (!$okay)
                $progress->add($torrent->totalSize());
            else
                $torrent->checkFileContents($dataDir, $progress);
        }
        $progress->printProgress();
        print "\n";
        print "done\n";
    }

    if ($args['missing-files']) {
        $missing = array_diff($validFiles, $allFiles) ? : array('none');
        print "missing files:\n  " . join("\n  ", $missing) . "\n";
    }

    if ($args['orphaned-files']) {
        $orphaned = array_diff($allFiles, $validFiles) ? : array('none');
        print "orphaned files:\n  " . join("\n  ", $orphaned) . "\n";
    }
}

main();


