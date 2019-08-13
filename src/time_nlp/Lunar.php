<?php


namespace time_nlp;


class Lunar
{
    public $lunar_year;
    public $lunar_month;
    public $lunar_day;
    public $is_leap;

    public function __construct($lunar_year, $lunar_month, $lunar_day, $is_leap) {
        $this->lunar_year = $lunar_year;
        $this->lunar_month = $lunar_month;
        $this->lunar_day = $lunar_day;
        $this->is_leap = $is_leap;
    }
}