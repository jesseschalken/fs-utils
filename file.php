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

abstract class AbstractFile {
    const FIFO    = 'fifo';
    const CHAR    = 'char';
    const DIR     = 'dir';
    const BLOCK   = 'block';
    const LINK    = 'link';
    const FILE    = 'file';
    const SOCKET  = 'socket';
    const UNKNOWN = 'unknown';

    static function create($name, Directory $parent = null) {
        $type = filetype($parent ? $parent->join($name) : $name);
        switch ($type) {
            case self::DIR:
                return new Directory($name, $parent);
            case self::FILE:
                return new File($name, $parent);
            case self::LINK:
                return new Link($name, $parent);
            default:
                return new Other($name, $parent, $type);
        }
    }

    /** @var string */
    private $name;
    /** @var Directory|null */
    private $parent;

    /**
     * @param string $name
     * @param Directory $parent
     */
    function __construct($name, Directory $parent = null) {
        $this->name   = $name;
        $this->parent = $parent;
    }

    final function recreate() {
        return self::create($this->name, $this->parent);
    }

    final function delete() {
        $cmd = "rm -rf " . escapeshellarg($this->path());
        printReplace("$cmd\n");
        shell_exec($cmd);
    }

    final function exists() {
        $path = $this->path();
        return file_exists($path) || is_string(@readlink($path));
    }

    abstract function type();

    final function hash(FileData $data = null) {
        $hash = $this->hashImpl($data);
        $type = $this->type();
        return "$hash $type";
    }

    /**
     * @return \Iterator
     */
    function flatten() {
        yield $this;
    }

    final function key() {
        $type = $this->type();
        $key  = $this->keyImpl();
        return $key ? "$type $key" : $type;
    }

    /** @return string */
    abstract function keyImpl();

    function size() { return 0; }

    final function name() { return $this->name; }

    final function path() {
        return $this->parent ? $this->parent->join($this->name) : $this->name;
    }

    final function join($path) {
        return $this->path() . DIRECTORY_SEPARATOR . $path;
    }

    function hashImpl(FileData $data = null) {
        return hash($this->contents($data));
    }

    /**
     * @param FileData $data
     * @return \Generator
     */
    function contents(FileData $data = null) {
        yield '';
    }
}

class Link extends AbstractFile {
    /** @var string */
    private $destination;

    function __construct($name, Directory $parent = null) {
        parent::__construct($name, $parent);
        $this->destination = readlink($this->path());
    }

    function keyImpl() {
        return strlen($this->destination);
    }

    function contents(FileData $data = null) {
        yield $this->destination;
    }

    function type() {
        return self::LINK;
    }
}

class Directory extends AbstractFile {
    /** @var AbstractFile[] */
    private $files = [];

    function __construct($name, Directory $parent = null) {
        parent::__construct($name, $parent);

        $scan = scandir($this->path());
        $scan = array_diff($scan, ['.', '..']);
        foreach ($scan as $s)
            $this->files[] = AbstractFile::create($s, $this);
    }

    function keyImpl() {
        $size  = $this->size();
        $count = count($this->files);
        $total = iterator_count($this->flatten());
        return "$count $total $size";
    }

    function size() {
        $size = 0;
        foreach ($this->files as $file)
            $size += $file->size();
        return $size;
    }

    function contents(FileData $data = null) {
        foreach ($this->files as $file) {
            $name = $file->name();
            $hash = $file->hash($data);
            $hash = str_pad($hash, 48);
            yield "$hash $name\n";
        }
    }

    function flatten() {
        $files = new \AppendIterator;
        $files->append(parent::flatten());
        foreach ($this->files as $file)
            $files->append($file->flatten());
        return $files;
    }

    function type() { return self::DIR; }
}

class File extends AbstractFile {
    private $size;

    function __construct($name, Directory $parent = null) {
        parent::__construct($name, $parent);
        $this->size = filesize($this->path());
    }

    function size() { return $this->size; }

    function type() { return self::FILE; }

    function keyImpl() { return $this->size(); }

    function hashImpl(FileData $data = null) {
        return $data ? $data->hash($this) : parent::hashImpl($data);
    }

    function contents(FileData $data = null) {
        return $this->read();
    }

    function get($offset = 0, $limit = null) {
        return file_get_contents($this->path(), null, null, $offset, $limit);
    }

    function extension() {
        return pathinfo($this->path(), PATHINFO_EXTENSION);
    }

    /**
     * @return \Generator
     */
    function read() {
        $f = fopen($this->path(), 'rb');
        while (!feof($f))
            yield fread($f, 10000000);
        fclose($f);
    }
}

class Other extends AbstractFile {
    private $type;

    function type() {
        return $this->type;
    }

    function keyImpl() { return ''; }

    /**
     * @param string $name
     * @param Directory $parent
     * @param string $type
     */
    function __construct($name, Directory $parent = null, $type) {
        parent::__construct($name, $parent);
        $this->type = $type;
    }
}

