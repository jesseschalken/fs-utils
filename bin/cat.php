#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

foreach (array_slice($argv, 1) as $file)
    \FSUtils\Tree\Tree::create($file)->content([])->output();
