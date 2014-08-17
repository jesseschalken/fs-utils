<?php

namespace PureBencode;

error_reporting(-1);
ini_set('display_errors', 'On');

const DIR_SEP = DIRECTORY_SEPARATOR;

require_once __DIR__ . '/../vendor/autoload.php';

function shorten($string) {
    $string = json_encode($string, JSON_UNESCAPED_UNICODE);
    $maxlen = 60;
    if (strlen($string) <= $maxlen)
        return $string;
    $part1 = substr($string, 0, $maxlen / 2);
    $part2 = substr($string, -$maxlen / 2);
    return "$part1...$part2";
}

class VerifyTorrentData {
    static function run() {
        global $argv;

        $self = new self($argv[1], array_slice($argv, 2));
        $self->_run();
    }

    /** @var string */
    private $dir;
    /** @var string[] */
    private $torrents;

    function __construct($dir, array $torrents) {
        $this->dir      = $dir;
        $this->torrents = $torrents;
    }

    private function _run() {
        $i     = 1;
        $count = count($this->torrents);
        foreach ($this->torrents as $torrent) {
            $string = shorten($torrent);
            $verify = new TorrentVerify(TorrentInfo::parse($torrent), "[$i/$count] $string", $this->dir);
            $verify->run();
            $i++;
        }
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

        return $self;
    }

    /** @var int[] */
    private $files = array();
    /** @var string[] */
    private $pieces = array();
    /** @var int */
    private $pieceSize = 0;

    function files() {
        return array_keys($this->files);
    }

    function fileSize($file) {
        return $this->files[$file];
    }

    function numPieces() {
        return count($this->pieces);
    }

    function hasPiece($i) {
        return isset($this->pieces[$i]);
    }

    function piece($i) {
        return $this->pieces[$i];
    }

    function numFilePieces() {
        $total = 0;
        foreach ($this->files as $size)
            $total += $size / $this->pieceSize;
        return (int)ceil($total);
    }

    function numFiles() {
        return count($this->files);
    }

    function pieceSize() {
        return $this->pieceSize;
    }
}

class TorrentVerify {
    /** @var TorrentInfo */
    private $torrent;
    private $buffer = '';
    private $fileIndex = 0;
    private $pieceIndex = 0;
    private $currentFile;
    private $prefix;
    private $baseDir;
    private $continue = true;

    function __construct(TorrentInfo $torrent, $prefix, $baseDir) {
        $this->torrent = $torrent;
        $this->prefix  = $prefix;
        $this->baseDir = $baseDir;
    }

    function run() {
        foreach ($this->torrent->files() as $file) {
            $this->currentFile = $file;

            if ($this->continue)
                $this->checkFile();

            if ($this->continue)
                $this->processFile();

            $this->fileIndex++;
        }

        if ($this->continue)
            $this->runBuffer(true);
    }

    private function printProgress() {
        $numPieces  = $this->torrent->numPieces();
        $numFiles   = $this->torrent->numFiles();
        $percent    = number_format($this->pieceIndex * 100 / $numPieces, 2);
        $pieceIndex = $this->pieceIndex + 1;
        $fileIndex  = $this->fileIndex + 1;
        $file       = shorten($this->currentFile);
        $this->out("[$percent%, piece $pieceIndex/$numPieces, file $fileIndex/$numFiles] $file");
    }

    private function checkFile() {
        $path = $this->filePath();

        if (!file_exists($path)) {
            $this->out("file $path does not exist\n");
            $this->continue = false;
        } else if (!is_readable($path)) {
            $this->out("file $path is not readable\n");
            $this->continue = false;
        } else if (!is_file($path)) {
            $this->out("file $path is not a file\n");
            $this->continue = false;
        } else {
            $filesize = filesize($path);
            $size     = $this->torrent->fileSize($this->currentFile);
            if ($filesize != $size) {
                $this->out("$path file size mismatch. expected $size bytes, got $filesize bytes\n");
                $this->continue = false;
            }
        }
    }

    private function runBuffer($last) {
        $pieceSize = $this->torrent->pieceSize();
        $numPieces = $this->torrent->numPieces();

        while (($last && $this->buflen() > 0) || $this->buflen() >= $pieceSize) {
            if (!$this->continue)
                break;
            $this->printProgress();
            $piece        = (string)substr($this->buffer, 0, $pieceSize);
            $this->buffer = (string)substr($this->buffer, strlen($piece));
            $pieceIndex   = $this->pieceIndex + 1;

            if (!$this->torrent->hasPiece($this->pieceIndex)) {
                $this->out("piece $pieceIndex/$numPieces is missing\n");
            } else {
                $hash  = $this->torrent->piece($this->pieceIndex);
                $hash2 = sha1($piece, true);

                if ($hash !== $hash2) {
                    $this->out("piece $pieceIndex/$numPieces: hash mismatch, expected " . bin2hex($hash) . ", got " . bin2hex($hash2) . "\n");
                    $this->continue = false;
                }
            }

            $this->pieceIndex++;
        }
    }

    private function out($string = '') {
        print "\r\x1B[2K";
        print $this->prefix;

        if ($string)
            print " $string";
    }

    private function filePath() {
        return $this->baseDir . DIR_SEP . $this->currentFile;
    }

    private function buflen() {
        return strlen($this->buffer);
    }

    private function processFile() {
        $f = fopen($this->filePath(), 'rb');
        while (!feof($f) && $this->continue) {
            $this->buffer .= fread($f, $this->torrent->pieceSize());
            $this->runBuffer(false);
        }
        fclose($f);
    }
}

VerifyTorrentData::run();

