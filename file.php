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

function read_file($path) {
    $f = fopen($path, 'rb');
    while (!feof($f))
        yield fread($f, 10000000);
    fclose($f);
}

abstract class AbstractFile {
    /**
     * @param string    $path
     * @param Directory $parent
     * @param callable  $callback
     * @throws \Exception
     * @return self
     */
    static function create($path, Directory $parent = null, callable $callback = null) {
        $fullPath = $parent ? $parent->concat($path) : $path;
        if ($callback)
            $callback($fullPath);
        $type = filetype($fullPath);
        switch ($type) {
            case 'fifo':
                return new NamedPipe($path, $parent);
            case 'char':
                return new CharacterDevice($path, $parent);
            case 'dir':
                return new Directory($path, $parent, $callback);
            case 'block':
                return new BlockDevice($path, $parent);
            case 'link':
                return new SymbolicLink($path, $parent);
            case 'file':
                return new RegularFile($path, $parent);
            case 'socket':
                return new Socket($path, $parent);
            case 'unknown':
                return new Unknown($path, $parent);
            default:
                throw new \Exception("unknown file type: $type");
        }
    }

    /** @var string */
    private $path;
    /** @var Directory|null */
    private $parent;

    function __construct($path, Directory $parent = null) {
        $this->path   = $path;
        $this->parent = $parent;
    }

    function fullPath() {
        $path   = $this->path;
        $parent = $this->parent;
        return $parent ? $parent->concat($path) : $path;
    }

    function contents(Progress $progress, Hashes $hashes) { yield ''; }

    function count() { return 1; }

    function size() { return 0; }

    function name() { return $this->path; }

    function flatten() { yield $this; }

    function describe() { return "({$this->type()}) {$this->fullPath()}"; }

    abstract function type();
}

class Directory extends AbstractFile {
    /** @var AbstractFile[] */
    private $children = [];
    private $size = 0;
    private $count = 1;

    function __construct($path, Directory $parent = null, callable $callback = null) {
        parent::__construct($path, $parent);

        $scan = scandir($this->fullPath());
        $scan = array_diff($scan, ['.', '..']);
        foreach ($scan as $s) {
            $file = AbstractFile::create($s, $this, $callback);
            $this->size += $file->size();
            $this->count += $file->count();
            $this->children[] = $file;
        }
    }

    function concat($path) {
        return $this->fullPath() . DIRECTORY_SEPARATOR . $path;
    }

    function contents(Progress $progress, Hashes $hashes) {
        foreach ($this->children as $child) {
            $hash = $hashes->add($child, $progress);
            $name = $child->name();
            yield "$hash $name\n";
        }
    }

    function flatten() {
        yield $this;

        foreach ($this->children as $child)
            foreach ($child->flatten() as $file)
                yield $file;
    }

    function count() { return $this->count; }

    function size() { return $this->size; }

    function type() { return 'directory'; }
}

class RegularFile extends AbstractFile {
    private $size;

    function __construct($path, Directory $parent = null) {
        parent::__construct($path, $parent);
        $this->size = filesize($this->fullPath());
    }

    function contents(Progress $progress, Hashes $hashes) {
        $path = $this->fullPath();
        return $progress->thread(read_file($path), $path);
    }

    function size() { return $this->size; }

    function type() { return 'regular file'; }
}

class SymbolicLink extends AbstractFile {
    private $dest;

    function __construct($path, Directory $parent = null) {
        parent::__construct($path, $parent);

        $this->dest = readlink($this->fullPath());
    }

    function contents(Progress $progress, Hashes $hashes) {
        yield $this->dest;
    }

    function type() { return 'symbolic link'; }
}

class NamedPipe extends AbstractFile {
    function type() { return 'named pipe'; }
}

class Socket extends AbstractFile {
    function type() { return 'socket'; }
}

class BlockDevice extends AbstractFile {
    function type() { return 'block device'; }
}

class CharacterDevice extends AbstractFile {
    function type() { return 'character device'; }
}

class Unknown extends AbstractFile {
    function type() { return 'unknown'; }
}
