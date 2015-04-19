#!/usr/bin/php
<?php

namespace TorrentVerify;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', 'On');
ini_set('memory_limit', '-1');
mb_internal_encoding('UTF-8');

/**
 * @param string $path
 * @return \Generator
 */
function recursive_scan($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $file) {
            foreach (recursive_scan($path . DIR_SEP . $file) as $f)
                yield $f;
        }
    } else {
        yield $path;
    }
}

function main() {
    $docopt = new \Docopt\Handler;
    $args   = $docopt->handle(<<<'s'
torrent-verify

  Just pass your torrent files as parameters, and specify --data-dir for the
  directory containing the torrent data. Every <torrent> must either end in
  '.torrent' or be a directory to recursively search for files ending in
  '.torrent'.

  Remember to use --windows on Windows.

Usage:
  torrent-verify [options] <torrent>...
  torrent-verify --help|-h

Options:
  --data-dir=<dir>  Directory to look for downloaded torrent data [default: .].
  --windows         Replace characters which Windows disallows in filenames with '_'.
  --orphaned        Report files that exist in --data-dir but not in any torrent file.
  --no-data         Don't verify file data, just file name and size.
s
    );

    $dataDir = $args['--data-dir'];

    /** @var Torrent[] $torrents */
    $torrents = [];
    foreach ($args['<torrent>'] as $name) {
        foreach (recursive_scan($name) as $path)
            if (pathinfo($path, PATHINFO_EXTENSION) === 'torrent')
                $torrents[] = Torrent::parse($path);
    }

    if ($args['--windows']) {
        foreach ($torrents as $torrent)
            $torrent->fixWindowsPaths();
    }

    foreach ($torrents as $torrent)
        $torrent->checkFiles($dataDir);

    if ($args['--orphaned']) {
        $files = [];
        foreach ($torrents as $torrent)
            foreach ($torrent->fileNames() as $file)
                $files[] = $dataDir . DIR_SEP . $file;

        $all = iterator_to_array(recursive_scan($dataDir));
        print "orphaned files:\n  " . join("\n  ", array_diff($all, $files) ? : ['none']) . "\n";
    }

    if (!$args['--no-data']) {
        $size  = 0.0;
        foreach ($torrents as $torrent)
            $size += $torrent->totalSize();

        $progress = new Progress($size);
        foreach ($torrents as $torrent)
            $torrent->checkFileContents($dataDir, $progress);
        $progress->finish();
    }

    print "done\n";
}

main();


