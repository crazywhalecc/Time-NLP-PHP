<?php


namespace time_nlp;


class TimePoint
{
    /**
     * @var array
     */
    public $tunit;

    public function __construct() {
        $this->tunit = [-1, -1, -1, -1, -1, -1];
    }

    public function __toString() {
        return "{" .implode(", ", $this->tunit)."}";
    }

}