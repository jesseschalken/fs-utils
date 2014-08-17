<?php

namespace PureBencode;

use Traversable;

error_reporting(-1);
ini_set('display_errors', 'On');
mb_internal_encoding('UTF-8');

const DIR_SEP = DIRECTORY_SEPARATOR;

require_once __DIR__ . '/../vendor/autoload.php';

function shorten($string) {
    $string = json_encode($string, JSON_UNESCAPED_UNICODE);
    $maxlen = 60;
    if (mb_strlen($string) <= $maxlen)
        return $string;
    $part1 = mb_substr($string, 0, $maxlen / 2);
    $part2 = mb_substr($string, -$maxlen / 2);
    return "$part1...$part2";
}

function readFiles(array $files, $length = 1024) {
    foreach ($files as $file) {
        $f = fopen($file, 'rb');
        while (!feof($f))
            yield fread($f, $length);
        fclose($f);
    }
}

function chunk(Traversable $data, $length) {
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
    return number_format($bytes / pow(1000, $i), 2) . $f[$i] . 'B';
}

class VerifyTorrentData {
    static function run() {
        global $argv;

        $self          = new self;
        $self->dataDir = $argv[1];

        foreach (array_slice($argv, 2) as $name) {
            $self->out("reading " . shorten($name));
            $torrent          = TorrentInfo::parse($name);
            $self->torrents[] = $torrent;
            $self->totalSize += $torrent->totalSize();
        }

        for ($self->i = 0; $self->i < count($self->torrents); $self->i++)
            $self->doTorrent();
    }

    /** @var string */
    private $dataDir;
    /** @var TorrentInfo[] */
    private $torrents;
    private $i;
    private $totalSize = 0.0;
    private $sizeDone = 0.0;

    private function doTorrent() {
        $info        = $this->torrents[$this->i];
        $files       = array();
        $oldSizeDone = $this->sizeDone;

        $this->out("checking files");
        foreach ($info->files as $file => $size) {
            $path = $this->dataDir . DIR_SEP . $file;

            if (!file_exists($path)) {
                $this->out("file $path does not exist\n");
            } else if (!is_readable($path)) {
                $this->out("file $path is not readable\n");
            } else if (!is_file($path)) {
                $this->out("file $path is not a file\n");
            } else {
                $filesize = filesize($path);
                if ($filesize != $size) {
                    $this->out("$path file size mismatch. expected $size bytes, got $filesize bytes\n");
                } else {
                    $files[] = $path;
                }
            }
        }

        if (count($files) == count($info->files)) {
            $count = count($info->pieces);
            $this->out("verifying data");
            foreach (chunk(readFiles($files), $info->pieceSize) as $i => $piece) {
                $this->sizeDone += strlen($piece);
                $hash     =& $info->pieces[$i];
                $filehash = sha1($piece, true);

                if (!$hash) {
                    $this->out("piece $i onward is missing from torrent file\n");
                    break;
                } else if ($filehash !== $hash) {
                    $this->out("piece $i/$count hash mismatch, expected " . bin2hex($hash) . ", got " . bin2hex($filehash) . "\n");
                    break;
                } else {
                    $this->out("verifying data");
                }
            }
        }

        $this->sizeDone = $oldSizeDone + $info->totalSize();
    }

    private function out($string = '') {
        $percent      = number_format($this->sizeDone * 100 / max($this->totalSize, 1), 2) . '%';
        $bytesDone    = formatBytes($this->sizeDone) . ' of ' . formatBytes($this->totalSize);
        $torrentsDone = ($this->i + 1) . '/' . count($this->torrents);

        print "\r\x1B[2K";
        print "[$percent, $bytesDone, $torrentsDone]";

        if ($this->i !== null) {
            $info = $this->torrents[$this->i];
            print " " . shorten($info->filename);
        }

        if ($string)
            print ": $string";
    }
}

class TorrentInfo {
    static function parse($torrent) {
        $info = Bencode::decode(file_get_contents($torrent))['info'];

        if (isset($info['name']))
            $name = $info['name'];
        else
            $name = pathinfo($torrent, PATHINFO_FILENAME);

        $self = new self;

        if (isset($info['length'])) {
            $self->files[$name] = $info['length'];
        } else {
            foreach ($info['files'] as $file) {
                $self->files[$name . DIR_SEP . join(DIR_SEP, $file['path'])] = $file['length'];
            }
        }

        $self->pieceSize = $info['piece length'];
        $self->pieces    = str_split($info['pieces'], 20);
        $self->filename  = $torrent;

        return $self;
    }

    /** @var string */
    public $filename;
    /** @var int[] */
    public $files = array();
    /** @var string[] */
    public $pieces = array();
    /** @var int */
    public $pieceSize = 0;

    function totalSize() {
        $size = 0.0;
        foreach ($this->files as $size1)
            $size += $size1;
        return $size;
    }
}

VerifyTorrentData::run();

