<?php

namespace FindDuplicateFiles;

/**
 * @param \Traversable $s
 * @return string
 */
function hash($s) {
    $h = hash_init('sha1');
    foreach ($s as $x)
        hash_update($h, $x);
    return hash_final($h);
}

class File {
    const FIFO    = 'fifo';
    const CHAR    = 'char';
    const DIR     = 'dir';
    const BLOCK   = 'block';
    const LINK    = 'link';
    const FILE    = 'file';
    const SOCKET  = 'socket';
    const UNKNOWN = 'unknown';

    /** @var string */
    private $path;

    function __construct($path) {
        $this->path = $path;
    }

    function path() { return $this->path; }

    /**
     * @param \Closure $readFile
     * @param \Closure $readHash
     * @return \Generator
     */
    function readContents(\Closure $readFile = null, \Closure $readHash = null) {
        switch ($this->type()) {
            case self::DIR:
                /** @var File $child */
                foreach ($this->scanDir() as $name) {
                    $file = $this->join($name);
                    $hash = $readHash ? $readHash($file) : $file->readHash($readFile, $readHash);
                    yield "$hash  $name\n";
                }
                break;
            case self::LINK:
                yield readlink($this->path);
                break;
            case self::FILE:
                $contents = $readFile ? $readFile($this) : $this->readFile();
                foreach ($contents as $s)
                    yield $s;
                break;
        }
    }

    /**
     * @param \Closure $readFile
     * @param \Closure $readHash
     * @return string
     */
    function readHash(\Closure $readFile = null, \Closure $readHash = null) {
        $hash = hash($this->readContents($readFile, $readHash));
        $type = str_pad($this->type(), 6);
        return "$hash  $type";
    }
    
    function size(\Closure $f = null) {
        if ($f)
            $f($this->path);
        
        switch ($this->type()) {
            case self::FILE:
                return filesize($this->path);
            case self::DIR:
                $size = 0;
                foreach ($this->scanDir() as $s)
                    $size += $this->join($s)->size($f);
                return $size;
            default:
                return 0;
        }
    }

    /**
     * @return \Generator
     */
    function readFile() {
        $f = fopen($this->path, 'rb');
        while (!feof($f))
            yield fread($f, 10000000);
        fclose($f);
    }

    function name() { return pathinfo($this->path, PATHINFO_BASENAME); }

    function scanDir() {
        $files = scandir($this->path) ?: array();
        $files = array_diff($files, ['.', '..']);
        return $files;
    }

    function type() {
        return filetype($this->path);
    }

    function delete() {
        $cmd = "rm -rf " . escapeshellarg($this->path);
        printReplace("$cmd\n");
        shell_exec($cmd);
    }

    function exists() {
        return file_exists($this->path);
    }
    
    function join($file) {
        return new self($this->path . DIRECTORY_SEPARATOR . $file);
    }
}

