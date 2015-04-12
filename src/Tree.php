<?php

namespace FindDuplicateFiles;

class Tree {
    /** @var string */
    private $name;
    /** @var self|null */
    private $parent;
    /** @var string */
    private $type;
    /** @var int */
    private $size = 0;
    /** @var self[] */
    private $children = [];

    /**
     * @param string $name
     * @param self $parent
     */
    function __construct($name, self $parent = null) {
        $this->name   = $name;
        $this->parent = $parent;

        $this->rebuild();
    }

    /**
     * @param int $bs
     * @return \Generator
     */
    function read($bs) {
        $f = fopen($this->path(), 'rb');
        while (!feof($f))
            yield fread($f, $bs);
        fclose($f);
    }

    function exists() {
        return file_exists($this->path()) || is_string(@readlink($this->path()));
    }

    function delete() {
        foreach ($this->children as $child)
            $child->delete();

        if ($this->type === 'dir')
            rmdir($this->path());
        else
            unlink($this->path());
    }

    function extension() { return pathinfo($this->name, PATHINFO_EXTENSION); }

    function isFile() { return $this->type === 'file'; }

    function flatten() {
        yield $this;
        foreach ($this->children as $child)
            foreach ($child->flatten() as $child_)
                yield $child_;
    }

    function size() {
        $size = $this->size;
        foreach ($this->children as $child)
            $size += $child->size();
        return $size;
    }

    function rebuild() {
        $path = $this->path();

        $this->type     = filetype($path);
        $this->children = [];
        $this->size     = 0;

        if ($this->type === 'dir') {
            foreach (array_diff(scandir($path), ['.','..']) as $s)
                $this->children[] = new self($s, $this);
        } else if ($this->type === 'file') {
            $this->size = filesize($path);
        }

        return $this;
    }

    final function hash(array $fileHashes) {
        $type = $this->type;
        if ($type === 'dir')
            $hash = hash_stream($this->directoryContents($fileHashes));
        else if ($type === 'file')
            $hash = $fileHashes[$this->path()];
        else if ($type === 'link')
            $hash = sha1(readlink($this->path()));
        else
            $hash = sha1('');

        return "$hash $type";
    }

    private function directoryContents(array $fileHashes) {
        foreach ($this->children as $file)
            yield str_pad($file->hash($fileHashes), 48) . " $file->name\n";
    }

    final function key() {
        $type = $this->type;
        if ($type === 'dir')
            $key = $this->size() . ' ' . count($this->children) . ' ' . iterator_count($this->flatten());
        else if ($type === 'file')
            $key = $this->size;
        else if ($type === 'link')
            $key = readlink($this->path());
        else
            $key = '';

        return "$type $key";
    }

    final function path() {
        return $this->parent ? $this->parent->join($this->name) : $this->name;
    }

    /**
     * @param string $path
     * @return string
     */
    final function join($path) {
        return $this->path() . DIR_SEP . $path;
    }
}
