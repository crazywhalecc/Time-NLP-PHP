<?php


use time_nlp\TimeNormalizer;

require_once "src/loader.php";

$a = new TimeNormalizer(true);
echo "Initialized successfully!\n";
while (($str = trim(fgets(STDIN))) != "exit") {
    echo $a->parse($str) . PHP_EOL;
}