# fs-utils

Tools to find duplicate files and directory trees and verify files download from BitTorrent.

## Installation

1. Install PHP if you haven't already (http://php.net/)
1. Download fs-utils
   ```
$ curl -OJL https://github.com/jesseschalken/fs-utils/archive/master.zip
$ unzip fs-utils-master.zip
$ cd fs-utils-master
```

2. Install Composer dependencies (https://getcomposer.org/doc/00-intro.md#locally)
    ```
$ curl -sS https://getcomposer.org/installer | php
$ php composer.phar install --prefer-dist
```

## Usage

### `bin/find-duplicates.php`

Scans the given files and directories recursively in search of duplicate files and directory trees, and allows you to interactively remove them.

See
```
$ ./bin/find-duplicates.php --help
```

### `bin/cat.php`

Dumps the contents of the given paths as used for the purpose of finding duplicates (`bin/find-duplicates.php`). For files it will just read the contents. For links it will print the destination. For directories it will print the name, type and hash of each thing inside the directory, in alphabetical order.

```
$ ./bin/cat.php <FILE/DIRECTORY/LINK/>...
```

### `bin/torrent-verify.php`

Verifies the given torrent files (`.torrent`) against their downloaded contents.

See:
```
$ ./bin/torrent-verify.php --help
```

Example:
```
$ ./bin/torrent-verify.php --data-dir=~/Downloads torrent1.torrent torrent2.torrent
```

