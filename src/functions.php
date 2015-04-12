<?php

namespace FindDuplicateFiles;

const DIR_SEP = DIRECTORY_SEPARATOR;

/**
 * @param int $bytes
 * @return string
 */
function format_bytes($bytes) {
    $i = (int)log(max(abs($bytes), 1), 1000);
    $f = 'KMGTPEZY';
    $u = $i ? $f[$i - 1] : '';
    return number_format($bytes / pow(1000, $i), 2) . " {$u}B";
}

/**
 * @param \Traversable $s
 * @return string
 */
function hash_stream($s) {
    $h = hash_init('sha1');
    foreach ($s as $x)
        hash_update($h, $x);
    return hash_final($h);
}

/**
 * @param string[] $options
 * @return string
 * @throws \Exception
 */
function read_option(array $options) {
    while (true) {
        print "Please select an option:\n";
        foreach ($options as $k => $v)
            print "  $k: $v\n";
        print "> ";
        $line = trim(fgets(STDIN));
        if (isset($options[$line]))
            return $line;
    }
    throw new \Exception;
}

