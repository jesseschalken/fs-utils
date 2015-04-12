<?php

namespace FindDuplicateFiles;

class Progress {
    private $total;
    private $start;
    private $done = 0;

    /**
     * @param int $total
     */
    function __construct($total) {
        $this->total = $total;
        $this->start = microtime(true);
    }

    /**
     * @param int $num
     */
    function add($num) {
        $this->done += $num;
    }

    function format() {
        $done    = $this->done;
        $total   = $this->total;
        $passed  = microtime(true) - $this->start;
        $rate    = $passed ? format_bytes($done / $passed) . '/s' : 'infinite';
        $percent = number_format(($total ? $done / $total : 1) * 100, 2) . '%';

        if ($done) {
            $eta = ($total - $done) * ($passed / $done);
            $eta = sprintf('%02d:%02d:%02d', $eta / 3600, $eta / 60 % 60, $eta % 60);
        } else {
            $eta = 'forever';
        }

        return "[$percent, $rate, ETA $eta]";
    }

    /**
     * @param \Traversable $gen
     * @param string $name
     * @return \Generator
     */
    function pipe(\Traversable $gen, $name) {
        $clear = "\r\x1B[2K\x1B[?7l";

        print $clear . $this->format() . ": $name";
        foreach ($gen as $data) {
            yield $data;
            $this->add(strlen($data));
            print $clear . $this->format() . ": $name";
        }
        print $clear . $this->format();
    }
}

