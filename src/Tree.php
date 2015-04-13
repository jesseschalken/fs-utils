<?php

namespace FindDuplicateFiles;

abstract class Tree {
    /**
     * @param string $name
     * @param Dir|null $parent
     * @return self
     * @throws \Exception
     */
    static function create($name, Dir $parent = null) {
        $type = filetype($parent ? $parent->join($name) : $name);
        switch ($type) {
            case 'fifo':
                return new Fifo($name, $parent);
            case 'char':
                return new Char($name, $parent);
            case 'dir':
                return new Dir($name, $parent);
            case 'block':
                return new Block($name, $parent);
            case 'link':
                return new Link($name, $parent);
            case 'file':
                return new File($name, $parent);
            case 'socket':
                return new Socket($name, $parent);
            case 'unknown':
                return new Unknown($name, $parent);
            default:
                throw new \Exception("Invalid file type: $type");
        }
    }

    /** @var string */
    private $name;
    /** @var Dir|null */
    private $parent;

    /**
     * @param string $name
     * @param Dir|null $parent
     */
    protected function __construct($name, Dir $parent = null) {
        $this->name   = $name;
        $this->parent = $parent;
    }

    function name() { return $this->name; }

    /** @return string */
    abstract function type();

    /** @return int */
    function size() { return 0; }

    /**
     * @param string[] $fileHashes
     * @return \Generator
     */
    function content(array $fileHashes) { yield ''; }

    final function exists() {
        return file_exists($this->path()) || is_string(@readlink($this->path()));
    }

    function delete() {
        unlink($this->path());
    }

    final function extension() { return pathinfo($this->name, PATHINFO_EXTENSION); }

    function flatten() { yield $this; }

    final function rebuild() {
        return self::create($this->name, $this->parent);
    }

    /**
     * @param string[] $fileHashes
     * @return string
     */
    final function hash(array $fileHashes) {
        return $this->hash_($fileHashes) . ' ' . $this->type();
    }

    /**
     * @param string[] $fileHashes
     * @return string
     */
    protected function hash_(array $fileHashes) {
        return hash_stream($this->content($fileHashes));
    }

    final function key() {
        return $this->type() . ' ' . $this->key_();
    }

    protected function key_() { return ''; }

    final function path() {
        return $this->parent ? $this->parent->join($this->name) : $this->name;
    }
}

class Fifo extends Tree {
    function type() { return 'fifo'; }
}

class Char extends Tree {
    function type() { return 'char'; }
}

class Dir extends Tree {
    /** @var Tree[] */
    private $children = [];

    function __construct($name, Dir $parent = null) {
        parent::__construct($name, $parent);
        foreach (array_diff(scandir($this->path()), ['.', '..']) as $s)
            $this->children[] = Tree::create($s, $this);
    }

    protected function key_() {
        return $this->size() . ' ' . count($this->children) . ' ' . iterator_count($this->flatten());
    }

    function flatten() {
        yield $this;
        foreach ($this->children as $child)
            foreach ($child->flatten() as $child2)
                yield $child2;
    }

    function type() { return 'dir'; }

    function size() {
        $size = 0;
        foreach ($this->children as $child)
            $size += $child->size();
        return $size;
    }

    function delete() {
        foreach ($this->children as $child)
            $child->delete();
        rmdir($this->path());
    }

    /**
     * @param string $path
     * @return string
     */
    function join($path) {
        return $this->path() . DIR_SEP . $path;
    }

    /**
     * @param string[] $fileHashes
     * @return \Generator
     */
    function content(array $fileHashes) {
        foreach ($this->children as $file)
            yield str_pad($file->hash($fileHashes), 48) . " {$file->name()}\n";
    }
}

class Block extends Tree {
    function type() { return 'block'; }
}

class Link extends Tree {
    private $dest;

    function __construct($name, Dir $parent = null) {
        parent::__construct($name, $parent);
        $this->dest = readlink($this->path());
    }

    protected function key_() { return $this->dest; }

    function type() { return 'link'; }

    function content(array $fileHashes) { yield $this->dest; }
}

class File extends Tree {
    private $size;

    function __construct($name, Dir $parent = null) {
        parent::__construct($name, $parent);
        $this->size = filesize($this->path());
    }

    protected function hash_(array $fileHashes) {
        return $fileHashes[$this->path()];
    }

    protected function key_() { return $this->size; }

    function type() { return 'file'; }

    function size() { return $this->size; }

    function read() {
        $f = fopen($this->path(), 'rb');
        while (!feof($f))
            yield fread($f, 1024 * 1024);
        fclose($f);
    }

    function content(array $fileHashes) {
        return $this->read();
    }
}

class Socket extends Tree {
    function type() { return 'socket'; }
}

class Unknown extends Tree {
    function type() { return 'unknown'; }
}
