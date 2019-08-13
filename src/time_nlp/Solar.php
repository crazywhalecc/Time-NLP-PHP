<?php


namespace time_nlp;


class Solar
{
    public $solar_year;
    public $solar_month;
    public $solar_day;

    public function __construct($solar_year, $solar_month, $solar_day) {
        $this->solar_year = $solar_year;
        $this->solar_month = $solar_month;
        $this->solar_day = $solar_day;
    }
}