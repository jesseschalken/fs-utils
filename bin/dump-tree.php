<?php

require_once __DIR__ . '/../vendor/autoload.php';

function dump($parent, $name, $p1 = "", $p2 = "") {
    $path = $parent === null ? $name : $parent . DIRECTORY_SEPARATOR . $name;
    $type = filetype($path);

    $children = $type === 'dir' ? array_diff(scandir($path), ['.', '..']) : [];
    $children = array_values($children);

    if ($type === 'link') {
        $type = "$type: " . readlink($path);
    } else if ($type === 'dir') {
        $type = "$type " . count($children);
    } else if ($type === 'file') {
        $type = "$type " . \FSUtils\format_bytes(filesize($path));
    }

    if ($children && $parent !== null)
        $s = '┐';
    else if ($children)
        $s = '╷';
    else if ($parent !== null)
        $s = '╴';
    else
        $s = '·';
    echo "$p1$s [$type] $name\n";

    $last = count($children) - 1;
    foreach ($children as $k => $child) {
        $p1_ = ($k == $last) ? '╰─' : '├─';
        $p2_ = ($k == $last) ? '  ' : '│ ';
        dump($path, $child, "$p2$p1_", "$p2$p2_");
    }
}

foreach (array_slice($argv, 1) as $p)
    dump(null, $p);

