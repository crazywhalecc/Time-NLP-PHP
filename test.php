<?php


use time_nlp\TimeNormalizer;

require_once "src/loader.php";

$a = new TimeNormalizer(true);
echo "Initialized successfully!\n";
while (($str = trim(fgets(STDIN))) != "exit") {
    //$as = microtime(true);
    try {
        echo ($ss = $a->parse($str)) . PHP_EOL;
    } catch (Exception $e) {
        echo "Error!\n";
    }
    //echo "用时 ".(microtime(true) - $as)." 秒".PHP_EOL;
}