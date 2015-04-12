#!/usr/bin/php
<?php

namespace FindDuplicateFiles;

require_once __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '-1');

global $argv;
App::main($argv);

