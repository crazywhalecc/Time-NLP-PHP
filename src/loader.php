<?php

date_default_timezone_set("Asia/Shanghai");

spl_autoload_register(function ($p) {
    $dir = $p . ".php";
    $dir = str_replace("\\", "/", $dir);
    //echo "[DEBUG] path: ".$dir.PHP_EOL;
    if (!file_exists(__DIR__."/".$dir))
        die("F:Warning: get class path wrongs.$p\nDir: ".$dir);
    require_once __DIR__."/".$dir;
});

$debug_cnt = 0;
global $argv;
$debug_mode = in_array("--debug", $argv) ? true : false;

function debug($msg, $delay = 1) {
    global $debug_cnt, $debug_mode;
    if (!$debug_mode) return;
    ++$debug_cnt;
    $trace = debug_backtrace()[1];
    $trace = basename($trace["file"], ".php") . ":" . $trace["function"];
    echo "\e[38;5;87m[$debug_cnt] [" . $trace . "] " . $msg . "\e[m\n";
    sleep($delay);
}