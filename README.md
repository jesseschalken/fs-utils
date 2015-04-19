# torrent-verify

A program to verify torrent files with their downloaded contents

### Installation

1. Install PHP if you haven't already (http://php.net/)
1. Download torrent-verify
   ```
$ curl -OJL https://github.com/jesseschalken/torrent-verify/archive/master.zip
$ unzip torrent-verify-master.zip
$ cd torrent-verify-master
```

2. Install Composer dependencies (https://getcomposer.org/doc/00-intro.md#locally)
    ```
$ curl -sS https://getcomposer.org/installer | php
$ php composer.phar install --prefer-dist
```

### Usage

See:

```
$ ./main.php --help
```

Example:
```
$ ./main.php --data-dir=~/Downloads torrent1.torrent torrent2.torrent
```

