#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

ini_set('memory_limit', '-1');

\FSUtils\FindDuplicates::main($argv);

