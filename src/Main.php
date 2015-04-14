<?php

namespace FindDuplicateFiles;

final class App {
    /**
     * @param array $argv
     */
    static function main(array $argv) {
        $self = new self;
        $args = \Docopt::handle(<<<s
Usage:
  find-duplicate-files [(--filter=<ext:cmd>)...] <path>...
  find-duplicate-files --help|-h
s
            , ['argv' => array_slice($argv, 1)]);

        foreach ($args['--filter'] as $filter) {
            list($ext, $cmd) = explode(':', $filter, 2);
            $self->filters[$ext][] = $cmd;
        }

        print "scanning directory tree...\n";

        $size = 0;
        foreach ($args['<path>'] as $path) {
            $tree1 = Tree::create($path);
            $size += $tree1->size();
            foreach ($tree1->flatten() as $tree)
                $self->files[] = $tree;
        }

        print "found " . number_format(count($self->files)) . " files, " . format_bytes($size) . "\n";

        $self->run();
    }

    /** @var Tree[] */
    private $files;
    /** @var string[][] */
    private $filters = [];
    /** @var string[] */
    private $fileHashes = [];
    /** @var Tree[][] */
    private $duplicates = [];

    private function __construct() { }

    private function pruneFiles() {
        print "searching for possible duplicates...\n";

        /** @var Tree[][] $keys */
        $keys = [];
        foreach ($this->files as $file)
            $keys[$file->key()][] = $file;

        $this->files = [];
        foreach ($keys as $files) {
            if (count($files) > 1) {
                foreach ($files as $file)
                    $this->files[] = $file;
            }
        }

        print number_format(count($this->files)) . " possible duplicates\n";
        print "searching for actual duplicates...\n";
    }

    /**
     * @param \Generator $data
     * @param string $ext
     * @return \Generator
     */
    private function filter(\Generator $data, $ext) {
        if (isset($this->filters[$ext]))
            foreach ($this->filters[$ext] as $cmd)
                $data = Process::pipe($data, $cmd);
        return $data;
    }

    private function readFileData() {
        /** @var File[] $files */
        $files = [];
        foreach ($this->files as $file)
            foreach ($file->flatten() as $file_)
                if ($file_ instanceof File)
                    $files[$file_->path()] = $file_;

        ksort($files, SORT_STRING);

        $size = 0;
        foreach ($files as $file)
            $size += $file->size();

        print "need to scan " . format_bytes($size) . "\n";

        $progress = new Progress($size);
        foreach ($files as $k => $file) {
            $data = $file->read();
            $data = $progress->pipe($data, $file->path());
            $data = $this->filter($data, $file->extension());

            $this->fileHashes[$k] = hash_stream($data);
        }
        print CLEAR . $progress->format();
        print "\n";
    }

    private function run() {
        if (!$this->filters)
            $this->pruneFiles();

        $this->readFileData();

        foreach ($this->files as $file)
            $this->duplicates[$file->hash($this->fileHashes)][] = $file;

        $this->runReport();
    }

    private function sorted() {
        $sizes = [];
        foreach ($this->duplicates as $hash => $files) {
            if (count($files) > 1)
                $sizes[$hash] = $this->amountDuplicated($hash);
        }
        arsort($sizes, SORT_NUMERIC);
        return array_keys($sizes);
    }

    /**
     * @param string $hash
     */
    private function verify($hash) {
        $files =& $this->duplicates[$hash];
        foreach ($files as $k => &$file) {
            $path = $file->path();
            if (!$file->exists()) {
                print "\"$path\" no longer exists\n";
                unset($files[$k]);
            } else {
                $file  = $file->rebuild();
                $hash2 = $file->hash($this->fileHashes);
                if ($hash2 !== $hash) {
                    print "\"$path\" hash has changed\n";
                    unset($files[$k]);
                    $this->duplicates[$hash2][] = $file;
                }
            }
        }
        $files = array_values($files);
    }

    /**
     * @param string $hash
     * @return int
     */
    private function amountDuplicated($hash) {
        $count = 0;
        $size  = 0;
        foreach ($this->duplicates[$hash] as $file) {
            $count++;
            $size += $file->size();
        }
        return $size - ($size / $count);
    }

    private function runReport() {
        $sorted = $this->sorted();

        $i = 0;
        while ($sorted) {
            $num   = count($sorted);
            $i     = ($i + $num) % $num;
            $hash  = $sorted[$i];
            $count = count($this->duplicates[$hash]);

            $duplicated = format_bytes($this->amountDuplicated($hash));

            print "\n";
            print ($i + 1) . "/$num: [$hash] ($count copies, $duplicated duplicated)\n";

            $this->verify($hash);
            $files = $this->duplicates[$hash];

            if (count($files) <= 1) {
                array_splice($sorted, $i, 1);
                continue;
            }

            $options = [];
            foreach ($files as $k => $file)
                $options[$k + 1] = "Keep only \"{$file->path()}\"";
            $options['D'] = 'Delete ALL';
            $options['n'] = 'Next duplicate';
            $options['p'] = 'Previous duplicate';
            $options['q'] = 'Quit';

            $choice = read_option($options);

            if ($choice === 'n') {
                $i++;
            } else if ($choice === 'p') {
                $i--;
            } else if ($choice === 'q') {
                print "quit\n";
                return;
            } else if ($choice === 'D') {
                foreach ($files as $file)
                    $file->delete();
                array_splice($sorted, $i, 1);
            } else if (is_numeric($choice) && isset($files[$choice - 1])) {
                foreach ($files as $k => $file)
                    if ($k !== ($choice - 1))
                        $file->delete();
                array_splice($sorted, $i, 1);
            } else {
                throw new \Exception;
            }
        }
        print "done\n";
    }
}
