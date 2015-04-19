<?php

namespace FSUtils;

class Torrent {
    /**
     * @param $torrent string
     * @return self
     */
    static function parse($torrent) {
        $info = \PureBencode\Bencode::decode(file_get_contents($torrent))['info'];

        if (isset($info['name']))
            $name = $info['name'];
        else
            $name = pathinfo($torrent, PATHINFO_FILENAME);

        $self = new self;

        if (isset($info['length'])) {
            $self->files[$name] = $info['length'];
        } else {
            foreach ($info['files'] as $file)
                $self->files[$name . DIR_SEP . join(DIR_SEP, $file['path'])] = $file['length'];
        }

        $self->pieceSize = $info['piece length'];
        $self->pieces    = str_split($info['pieces'], 20);
        $self->filename  = $torrent;

        return $self;
    }

    /** @var string */
    private $filename;
    /** @var int[] */
    private $files = [];
    /** @var string[] */
    private $pieces = [];
    /** @var int */
    private $pieceSize = 0;

    private function __construct() {}

    function fixWindowsPaths() {
        $files = [];
        foreach ($this->files as $path => $size) {
            $path = str_replace(array_merge(str_split('<>:"|?*'), range("\x00", "\x1F")), '_', $path);
            $files[$path] = $size;
        }
        $this->files = $files;
    }

    function fileNames() {
        return array_keys($this->files);
    }

    function totalSize() {
        $size = 0.0;
        foreach ($this->files as $size1)
            $size += $size1;
        return $size;
    }

    /**
     * @param string $dataDir
     */
    function checkFiles($dataDir) {
        $problems = [];
        foreach ($this->files as $file => $size) {
            $path = $dataDir . DIR_SEP . $file;

            if (!file_exists($path))
                $problems[] = "missing (" . format_bytes($size) . "): $path";
            else if (!is_readable($path))
                $problems[] = "not readable: $path";
            else if (!is_file($path))
                $problems[] = "not a file: $path";
            else if (filesize($path) != $size)
                $problems[] = "unexpected size " . filesize($path) . " bytes, expected $size bytes: $path";
        }
        if ($problems)
            print CLEAR . "torrent: $this->filename\n  " . join("\n  ", $problems) . "\n";
    }

    /**
     * Pads output with zeros for files which are missing or too short, and
     * prunes files which are too long, so the offset of each file in the
     * output stream stays correct regardless of what's on disk.
     *
     * @param string   $dataDir
     * @param Progress $progress
     */
    function checkFileContents($dataDir, Progress $progress) {
        $stream = Stream::empty_();
        foreach ($this->files as $file => $size) {
            $path   = $dataDir . DIR_SEP . $file;
            $data   = file_exists($path) ? Stream::read($path) : Stream::empty_();
            $data   = $data->append(Stream::zeros())->take($size);
            $data   = $progress->pipe($data, $path);
            $stream = $stream->append($data);
        }

        $matches = 0;
        $string  = '';
        foreach ($stream->chunk($this->pieceSize) as $i => $piece) {
            $sha1 = sha1($piece, true);
            if ($this->pieces[$i] === $sha1) {
                $string .= '=';
                $matches++;
            } else {
                $string .= ' ';
            }
        }
 
        if ($matches != count($this->pieces)) {
            $percent = number_format($matches / count($this->pieces) * 100, 2) . '%';
            $count   = "$matches/" . count($this->pieces);
            $piece   = format_bytes($this->pieceSize);
            print CLEAR . <<<s
torrent: $this->filename
  verification failed
  $percent match ($count pieces, 1 piece = $piece)

  [=] match
  [ ] mismatch


s;
            foreach (str_split($string, 70) as $chunk)
                print "  [$chunk]\n";
            print "\n";
        }
    }
}


