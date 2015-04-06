#!/usr/bin/php
<?php

namespace TorrentVerify;

require_once __DIR__ . '/vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', 'On');
ini_set('memory_limit', '-1');
mb_internal_encoding('UTF-8');

const DIR_SEP = DIRECTORY_SEPARATOR;

function fixWindowsPath($path) {
    $invalids = str_split('<>:"|?*');

    foreach (range(0, 31) as $ord)
        $invalids[] = chr($ord);

    return str_replace($invalids, '_', $path);
}

function readFile($file, $len = 1024000) {
    if (!file_exists($file))
        return;
    $f = fopen($file, 'rb');
    if (!$f)
        return;
    while (!feof($f))
        yield fread($f, $len);
    fclose($f);
}

function zeros($len = 102400) {
    while (true)
        yield str_repeat("\x00", $len);
}

/**
 * @param \Generator[] $generators
 * @return \Generator
 */
function gen_join(array $generators) {
    foreach ($generators as $gen)
        foreach ($gen as $x)
            yield $x;
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

const CLEAR = "\r\x1B[2K";

function recursiveScan($dir) {
    if (is_dir($dir) && !is_link($dir)) {
        $result = array();
        foreach (array_diff(scandir($dir), array('.', '..')) as $file)
            $result = array_merge($result, recursiveScan($dir . DIR_SEP . $file));
        return $result;
    } else {
        return array($dir);
    }
}

class Progress {
    /** @var int */
    private $total;
    /** @var float */
    private $startTime;
    /** @var int */
    private $done = 0;

    /**
     * @param int $total
     */
    function __construct($total) {
        $this->total     = $total;
        $this->startTime = microtime(true);
    }

    /**
     * @param int $num
     */
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

    /**
     * @param $note string|null
     */
    function printProgress($note = null) {
        $line = $this->formatProgress();
        if ($note !== null)
            $line .= ": $note";
        print CLEAR . $line;
    }
}

class TorrentInfo {
    /**
     * @param $torrent string
     * @return self
     */
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
            foreach ($info['files'] as $file)
                $self->files[$name . DIR_SEP . join(DIR_SEP, $file['path'])] = $file['length'];
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

    /**
     * @param string $dataDir
     */
    function checkFiles($dataDir) {
        $problems = array();
        foreach ($this->files as $file => $size) {
            $path = $dataDir . DIR_SEP . $file;

            if (!file_exists($path))
                $problems[] = "missing (" . formatBytes($size) . "): $path";
            else if (!is_readable($path))
                $problems[] = "not readable: $path";
            else if (!is_file($path))
                $problems[] = "not a file: $path";
            else if (filesize($path) != $size)
                $problems[] = "unexpected size " . filesize($path) . " bytes, expected $size bytes: $path";
        }
        if ($problems)
            print CLEAR . "torrent: $this->filename\n  " . join("\n  ", $problems) . "\n";
    }

    /**
     * Pads output with zeros for files which are missing or too short, and
     * prunes files which are too long, so the offset of each file in the
     * output stream stays correct regardless of what's on disk.
     *
     * @param string $dataDir
     * @return \Generator
     */
    function readFiles($dataDir) {
        $chunk = 10 * 1024 * 1024;
        foreach ($this->files as $file => $size) {
            $data = gen_join([readFile($dataDir . DIR_SEP . $file, $chunk), zeros($chunk)]);
            $read = 0;
            foreach ($data as $piece) {
                $piece = substr($piece, 0, $size - $read);
                yield $piece;
                $read += strlen($piece);
                if ($read >= $size)
                    break;
            }
        }
    }

    /**
     * @param string   $dataDir
     * @param Progress $progress
     * @return string[]
     */
    function readPieces($dataDir, Progress $progress) {
        $pieces = array();
        foreach (chunk($this->readFiles($dataDir), $this->pieceSize) as $piece) {
            $progress->printProgress($this->filename);
            $progress->add(strlen($piece));
            $pieces[] = sha1($piece, true);
        }
        return $pieces;
    }

    /**
     * @param string   $dataDir
     * @param Progress $progress
     */
    function checkFileContents($dataDir, Progress $progress) {
        $pieces  = $this->readPieces($dataDir, $progress);
        $matches = 0;
        $string  = '';

        foreach ($this->pieces as $i => $piece) {
            if ($pieces[$i] === $piece) {
                $string .= '=';
                $matches++;
            } else {
                $string .= ' ';
            }
        }
 
        if ($matches != count($this->pieces)) {
            $percent = number_format($matches / count($this->pieces) * 100, 2) . '%';
            $count   = "$matches/" . count($this->pieces);
            $piece   = formatBytes($this->pieceSize);
            print CLEAR . <<<s
torrent: $this->filename
  verification failed
  $percent match ($count pieces, 1 piece = $piece)

  [=] match
  [ ] mismatch


s;
            foreach (str_split($string, 70) as $chunk)
                print "  [$chunk]\n";
            print "\n";
        }
    }
}

function main() {
    $docopt = new \Docopt\Handler;
    $args   = $docopt->handle(<<<'s'
torrent-verify

  Just pass your torrent files as parameters, and specify --data-dir for the
  directory containing the torrent data. Every <torrent> must either end in
  '.torrent' or be a directory to recursively search for files ending in
  '.torrent'.

  Remember to use --windows on Windows.

Usage:
  torrent-verify [options] <torrent>...
  torrent-verify --help|-h

Options:
  --data-dir=<dir>  Directory to look for downloaded torrent data [default: .].
  --windows         Replace characters which Windows disallows in filenames with '_'.
  --orphaned        Report files that exist in --data-dir but not in any torrent file.
  --no-data         Don't verify file data, just file name and size.
s
    );

    $dataDir = $args['--data-dir'];

    /** @var TorrentInfo[] $torrents */
    $torrents = array();
    foreach ($args['<torrent>'] as $name) {
        foreach (recursiveScan($name) as $path)
            if (pathinfo($path, PATHINFO_EXTENSION) === 'torrent')
                $torrents[] = TorrentInfo::parse($path);
    }

    if ($args['--windows']) {
        foreach ($torrents as $torrent)
            $torrent->fixWindowsPaths();
    }

    foreach ($torrents as $torrent)
        $torrent->checkFiles($dataDir);

    if ($args['--orphaned']) {
        $files = array();
        foreach ($torrents as $torrent)
            foreach ($torrent->fileNames() as $file)
                $files[] = $dataDir . DIR_SEP . $file;

        $all = recursiveScan($dataDir);
        print "orphaned files:\n  " . join("\n  ", array_diff($all, $files) ? : array('none')) . "\n";
    }

    if (!$args['--no-data']) {
        $size  = 0.0;
        foreach ($torrents as $torrent)
            $size += $torrent->totalSize();

        $progress = new Progress($size);
        foreach ($torrents as $torrent)
            $torrent->checkFileContents($dataDir, $progress);
        $progress->printProgress();
        print "\n";
    }

    print "done\n";
}

main();


