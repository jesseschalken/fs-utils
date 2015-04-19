<?php

namespace FSUtils;

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
     * @param Stream $stream
     * @param string $name
     * @return Stream
     */
    function pipe(Stream $stream, $name) {
        return Stream::wrap(function () use ($stream, $name) {
            print CLEAR . $this->format() . ": $name";
            foreach ($stream() as $data) {
                yield $data;
                $this->add(strlen($data));
                print CLEAR . $this->format() . ": $name";
            }
        });
    }

    function finish() {
        print CLEAR . $this->format() . "\n";
    }
}

