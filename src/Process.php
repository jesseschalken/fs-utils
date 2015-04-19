<?php

namespace FindDuplicateFiles;

class Process {
    /**
     * @param \Generator $input
     * @param string     $cmd
     * @return \Generator
     */
    static function pipe(\Generator $input, $cmd) {
        $proc = new self($cmd);

        foreach ($input as $in) {
            $proc->writeInput($in);
            $proc->runInput();
            yield $proc->readOutput();
        }

        $proc->finish();
        yield $proc->readOutput();
    }

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
        while ($this->pipes)
            $this->run();
    }

    function run() {
        $w = $r = $this->pipes;
        $e = [];
        unset($w[1], $w[2], $r[0]);
        stream_select($r, $w, $e, null);
        foreach (array_replace($r, $w) as $k => $pipe) {
            if ($k == 0) {
                $this->in = substr($this->in, fwrite($pipe, $this->in));
            } else if ($k == 1) {
                $this->out .= stream_get_contents($pipe);
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($this->pipes[$k]);
                }
            } else if ($k == 2) {
                $this->err .= stream_get_contents($pipe);
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($this->pipes[$k]);
                }
            }
        }
    }
}

