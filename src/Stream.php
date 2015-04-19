<?php

namespace TorrentVerify;

/**
 * Wraps generator functions
 */
final class Stream implements \IteratorAggregate {
    const CHUNK_SIZE = 102400;

    /**
     * @param string $path
     * @return self
     */
    static function read($path) {
        return self::wrap(function () use ($path) {
            $f = fopen($path, 'rb');
            if (!$f)
                return;
            while (!feof($f))
                yield fread($f, self::CHUNK_SIZE);
            fclose($f);
        });
    }

    static function zeros() {
        return self::wrap(function () {
            while (true)
                yield str_repeat("\x00", self::CHUNK_SIZE);
        });
    }

    static function wrap(\Closure $f) {
        return new self([$f]);
    }

    static function empty_() {
        return new self;
    }

    /** @var \Closure[] */
    private $gens;

    /**
     * @param \Closure[] $gens
     */
    private function __construct(array $gens = []) {
        $this->gens = $gens;
    }

    /**
     * @param int $size
     * @return self
     */
    function chunk($size) {
        return self::wrap(function () use ($size) {
            $buffer = '';
            foreach ($this() as $piece) {
                $buffer .= $piece;
                while (strlen($buffer) > $size) {
                    yield substr($buffer, 0, $size);
                    $buffer = substr($buffer, $size);
                }
            }
            if (strlen($buffer) > 0)
                yield $buffer;
        });
    }

    /**
     * @param string $algo
     * @param bool $raw
     * @return string
     */
    function hash($algo, $raw = false) {
        $h = hash_init($algo);
        foreach ($this() as $s)
            hash_update($h, $s);
        return hash_final($h, $raw);
    }

    /**
     * @param int $size
     * @return self
     */
    function take($size) {
        return self::wrap(function () use ($size) {
            $read = 0;
            foreach ($this() as $piece) {
                $piece = substr($piece, 0, $size - $read);
                yield $piece;
                $read += strlen($piece);
                if ($read >= $size)
                    break;
            }
        });
    }

    function prepend(self $self) {
        return new self(array_merge($self->gens, $this->gens));
    }

    function append(self $self) {
        return new self(array_merge($this->gens, $self->gens));
    }

    function __invoke() {
        foreach ($this->gens as $gen)
            foreach ($gen() as $s)
                yield $s;
    }

    function getIterator() {
        return new \IteratorIterator($this());
    }
}

