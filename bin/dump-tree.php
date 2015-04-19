<?php

require_once __DIR__ . '/../vendor/autoload.php';

function mime_type($path) {
    static $finfo;
    if ($finfo === null)
        $finfo = finfo_open();
    return finfo_file($finfo, $path, FILEINFO_MIME_TYPE);
}

function dump($parent, $name, $p1 = "", $p2 = "") {
    $path = $parent === null ? $name : $parent . DIRECTORY_SEPARATOR . $name;
    $type = filetype($path);

    $children = $type === 'dir' ? array_diff(scandir($path), ['.', '..']) : [];
    $children = array_values($children);

    if ($type === 'link') {
        $line = "$name -> " . readlink($path);
    } else if ($type === 'dir') {
        $size = count($children);
        $line = "[$size] $name";
    } else if ($type === 'file') {
        $size = str_pad(\FSUtils\format_bytes(filesize($path)), 9);
        $type = str_pad(mime_type($path), 25);
        $line = "$type $size $name";
    } else {
        $line = "[$type] $name";
    }

    if ($children && $parent !== null)
        $s = '┐';
    else if ($children)
        $s = '╷';
    else if ($parent !== null)
        $s = '╴';
    else
        $s = '·';
    echo "$p1$s $line\n";

    $last = count($children) - 1;
    foreach ($children as $k => $child) {
        $p1_ = ($k == $last) ? '╰─' : '├─';
        $p2_ = ($k == $last) ? '  ' : '│ ';
        dump($path, $child, "$p2$p1_", "$p2$p2_");
    }
}

foreach (array_slice($argv, 1) as $p)
    dump(null, $p);

