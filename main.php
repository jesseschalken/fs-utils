#!/usr/bin/php
<?php

namespace FindDuplicateFiles;

require_once __DIR__ . '/vendor/autoload.php';

class Process {
    /** @var resource */
    private $proc;
    /** @var resource[] */
    private $pipes;
    /** @var string */
    private $in = '';
    /** @var string */
    private $out = '';
    /** @var string */
    private $err = '';

    function __construct($cmd) {
        $this->proc = proc_open($cmd, [
            0 => ['pipe', 'read'], // stdin
            1 => ['pipe', 'write'], // stdout
            2 => ['pipe', 'write'], // stderr
        ], $this->pipes);

        foreach ($this->pipes as $pipe)
            stream_set_blocking($pipe, 0);
    }

    function __destruct() {
        foreach ($this->pipes as $pipe)
            fclose($pipe);
        proc_close($this->proc);
    }

    function writeInput($in) {
        $this->in .= $in;
    }

    function readOutput() {
        $out = $this->out;
        $this->out = '';
        return $out;
    }

    function readError() {
        $err = $this->err;
        $this->err = '';
        return $err;
    }

    function runInput() {
        while (strlen($this->in) > 0)
            $this->run();
    }

    function finishInput() {
        $this->runInput();
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
    }

    function finish() {
        $this->finishInput();
        while (!feof($this->pipes[1]) || !feof($this->pipes[2]))
            $this->run();
    }

    function run() {
        $w = isset($this->pipes[0]) ? [0 => $this->pipes[0]] : [];
        $r = [1 => $this->pipes[1], 2 => $this->pipes[2]];
        $e = [];
        stream_select($r, $w, $e, null);
        foreach (array_replace($r, $w) as $k => $pipe) {
            if ($k == 0)
                $this->in = substr($this->in, fwrite($pipe, $this->in));
            else if ($k == 1)
                $this->out .= stream_get_contents($pipe);
            else if ($k == 2)
                $this->err .= stream_get_contents($pipe);
        }
    }
}

/**
 * @param \Generator $input
 * @param string     $cmd
 * @return \Generator
 */
function pipe_cmd(\Generator $input, $cmd) {
    $proc = new Process($cmd);

    foreach ($input as $in) {
        $proc->writeInput($in);
        $proc->runInput();
        yield $proc->readOutput();
        fwrite(STDERR, $proc->readError());
    }

    $proc->finish();
    yield $proc->readOutput();
    fwrite(STDERR, $proc->readError());
}

function formatBytes($bytes) {
    $f = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
    $i = (int)floor(log(max(abs($bytes), 1), 1000));
    return number_format($bytes / pow(1000, $i), 2) . " {$f[$i]}B";
}

function printReplace($line = '') {
    static $cols;
    if ($cols === null)
        $cols = (int)`tput cols`;
    if ($cols && strpos($line, "\n") === false)
        $line = substr($line, 0, $cols);
    print "\r\x1B[2K$line";
}

class Progress {
    private $total;
    private $startTime;
    private $done = 0;

    function __construct($total) {
        $this->total     = $total;
        $this->startTime = microtime(true);
    }

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
        return $this->done / ($this->total ?: 1);
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

    function printProgress($note = null) {
        $line = $this->formatProgress();
        if ($note)
            $line .= ": $note";
        printReplace($line);
    }

    /**
     * @param File $file
     * @return \Generator
     */
    function readFile(File $file) {
        $this->printProgress($file->path());
        foreach ($file->read() as $data) {
            $this->add(strlen($data));
            $this->printProgress($file->path());
            yield $data;
        }
    }
}

class Hashes {
    /** @var AbstractFile[][] */
    private $hashes = [];
    private $data;

    function __construct(FileData $data) {
        $this->data = $data;
    }

    function add(AbstractFile $file) {
        $this->hashes[$file->hash($this->data)][] = $file;
    }

    /**
     * @param string $hash
     * @return AbstractFile[]
     */
    function files($hash) {
        return $this->hashes[$hash];
    }

    function sorted() {
        $sizes = [];
        foreach ($this->hashes as $hash => $files) {
            if (count($files) > 1) {
                $sizes[$hash] = $this->amountDuplicated($hash);
            }
        }
        arsort($sizes, SORT_NUMERIC);
        return array_keys($sizes);
    }

    function verify($hash) {
        $files =& $this->hashes[$hash];
        foreach ($files as $k => &$file) {
            $path = $file->path();
            if (!$file->exists()) {
                print "\"$path\" no longer exists\n";
                unset($files[$k]);
            } else {
                $file    = $file->recreate();
                $newHash = $file->hash($this->data);
                if ($newHash !== $hash) {
                    print "\"$path\" hash has changed\n";
                    unset($files[$k]);
                    $this->hashes[$newHash][] = $file;
                }
            }
        }
        $files = array_values($files);
    }

    /**
     * @param string $hash
     * @return int
     */
    function amountDuplicated($hash) {
        $count = 0;
        $size  = 0;
        foreach ($this->hashes[$hash] as $file) {
            $count++;
            $size += $file->size();
        }
        return $size - ($size / $count);
    }
}

function runReport(Hashes $hashes) {
    $sorted = $hashes->sorted();

    $i = 0;
    while (isset($sorted[$i])) {
        $hash       = $sorted[$i];
        $num        = count($sorted);
        $count      = count($hashes->files($hash));
        $duplicated = formatBytes($hashes->amountDuplicated($hash));

        print "\n";
        print ($i + 1) . "/$num: [$hash] ($count copies, $duplicated duplicated)\n";

        $hashes->verify($hash);
        $files = $hashes->files($hash);

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

        $choice = readOption($options);

        if ($choice === 'n') {
            $i = ($i + $num + 1) % $num;
        } else if ($choice === 'p') {
            $i = ($i + $num - 1) % $num;
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

class FileData {
    /** @var string[] */
    private $hashes = [];

    /**
     * @param AbstractFile[] $files
     * @param Filters        $filters
     */
    function __construct(array $files, Filters $filters) {
        /** @var File[] $files2 */
        $files2 = [];
        foreach ($files as $file)
            foreach ($file->flatten() as $file2)
                if ($file2 instanceof File)
                    $files2[$file2->path()] = $file2;

        ksort($files2, SORT_STRING);
        $size = 0;
        foreach ($files2 as $file)
            $size += $file->size();

        print "need to scan " . formatBytes($size) . "\n";

        $progress = new Progress($size);
        foreach ($files2 as $k => $file)
            $this->hashes[$k] = hash($filters->filter($progress->readFile($file), $file->extension()));
        $progress->printProgress();
        print "\n";
    }

    function hash(File $file) {
        return $this->hashes[$file->path()];
    }
}

function readOption(array $options) {
    while (true) {
        print "Please select an option:\n";
        foreach ($options as $k => $v)
            print "  $k: $v\n";
        print "> ";
        $line = fgets(STDIN);
        $line = substr($line, 0, -1);
        if (isset($options[$line]))
            return $line;
    }
    throw new \Exception;
}

class Filters {
    /** @var string[] */
    private $filters = [];

    function add($ext, $cmd) {
        $this->filters[$ext][] = $cmd;
    }

    function filter(\Generator $data, $ext) {
        if (isset($this->filters[$ext]))
            foreach ($this->filters[$ext] as $cmd)
                $data = pipe_cmd($data, $cmd);
        return $data;
    }

    function isEmpty() {
        return !$this->filters;
    }
}

function main() {
    ini_set('memory_limit', '-1');
    $args = \Docopt::handle(<<<s
Usage:
  find-duplicate-files cleanup [(--filter=<ext:cmd>)...] <path>...
  find-duplicate-files read <path>...
  find-duplicate-files key <path>...
  find-duplicate-files --help|-h
s
    );

    if ($args['cleanup']) {
        $filters = new Filters;
        foreach ($args['--filter'] as $filter) {
            list($ext, $cmd) = explode(':', $filter, 2);
            $filters->add($ext, $cmd);
        }

        print "reading filesystem tree...\n";

        /** @var AbstractFile[] $files */
        $files = [];
        foreach ($args['<path>'] as $path)
            foreach (AbstractFile::create($path)->flatten() as $file)
                $files[] = $file;

        $size = 0;
        foreach ($files as $file)
            if ($file instanceof File)
                $size += $file->size();

        print "found " . count($files) . " files, " . formatBytes($size) . "\n";

        if ($filters->isEmpty()) {
            print "searching for possible duplicates...\n";

            $keys = [];
            foreach ($files as $file)
                $keys[$file->key()][] = $file;

            foreach ($keys as $k => $files2)
                if (count($files2) == 1)
                    unset($keys[$k]);

            /** @var AbstractFile[] */
            $files = [];
            foreach ($keys as $files2)
                foreach ($files2 as $file2)
                    $files[] = $file2;

            print count($files) . " possible duplicates\n";
            print "searching for actual duplicates...\n";
        }

        $data   = new FileData($files, $filters);
        $hashes = new Hashes($data);
        foreach ($files as $file)
            $hashes->add($file);

        runReport($hashes);
    }

    if ($args['read']) {
        foreach ($args['<path>'] as $path) {
            foreach (File::create($path)->contents() as $s)
                print $s;
        }
    }

    if ($args['key']) {
        foreach ($args['<path>'] as $path) {
            print File::create($path)->key() . "\n";
        }
    }
}

main();

