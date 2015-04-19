<?php

namespace TorrentVerify;

const DIR_SEP = DIRECTORY_SEPARATOR;
const CLEAR   = "\r\x1B[2K\x1B[?7l";

/**
 * @param int $bytes
 * @return string
 */
function format_bytes($bytes) {
    $f = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
    $i = (int)floor(log(max(abs($bytes), 1), 1000));
    return number_format($bytes / pow(1000, $i), 2) . " {$f[$i]}B";
}

