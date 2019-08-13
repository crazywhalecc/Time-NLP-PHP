<?php


namespace time_nlp;


use DateTime;
use DateTimeZone;

class TimeUnit
{
    /**
     * @var bool
     */
    private $no_year;
    private $exp_time;
    /**
     * @var TimeNormalizer
     */
    private $normalizer;
    /**
     * @var TimePoint
     */
    public $tp;
    /**
     * @var TimePoint
     */
    private $tp_origin;
    /**
     * @var bool
     */
    private $is_first_time_solve_context;
    /**
     * @var bool
     */
    private $is_all_day_time;
    /**
     * @var int
     */
    public $time;

    /**
     * TimeUnit constructor.
     * @param $exp_time
     * @param TimeNormalizer $normalizer
     * @param TimePoint $context_tp
     */
    public function __construct($exp_time, TimeNormalizer $normalizer, TimePoint $context_tp) {
        $this->no_year = false;
        $this->exp_time = $exp_time;
        $this->normalizer = $normalizer;
        $this->tp = new TimePoint();
        $this->tp_origin = $context_tp;
        $this->is_first_time_solve_context = true;
        $this->is_all_day_time = true;
        $this->time = time();//在Python版本里这里是Arrow对象，由于PHP没有类似对象的操作，故在这里直接使用能表达最精确时间的时间戳
        $this->timeNormalization();
    }

    private function timeNormalization() {
        $this->normSetYear();
        $this->normSetMonth();
        $this->normSetDay();
        $this->normDetMonthFuzzyDay();
        //$this->normSetBaseRelated();
        $this->normSetCurRelated();
        $this->normSetHour();
        $this->normSetMinute();
        $this->normSetSecond();
        $this->normSetSpecial();
        $this->normSetSpanRelated();
        $this->normSetHoliday();
        $this->modifyTimeBase();
        $this->tp_origin = clone $this->tp;

        $flag = true;
        for ($i = 0; $i < 4; ++$i) {
            if ($this->tp->tunit[$i] != -1)
                $flag = false;
        }
        if ($flag)
            $this->normalizer->is_time_span = true;
        if ($this->normalizer->is_time_span) {
            $days = 0;
            if ($this->tp->tunit[0] > 0)
                $days += 365 * $this->tp->tunit[0];
            if ($this->tp->tunit[1] > 0)
                $days += 30 * $this->tp->tunit[1];
            if ($this->tp->tunit[2] > 0)
                $days += $this->tp->tunit[2];
            $tunit = $this->tp->tunit;
            for ($i = 3; $i < 6; ++$i) {
                if ($this->tp->tunit[$i] < 0) $tunit[$i] = 0;
            }
            $seconds = $tunit[3] * 3600 + $tunit[4] * 60 + $tunit[5];
            if ($seconds == 0 && $days == 0) {
                $this->normalizer->invalid_span = true;
            }
            $this->normalizer->time_span = $this->genSpan($days, $seconds);
        }
    }

    /**
     * 年-规范化方法--该方法识别时间表达式单元的年字段
     */
    private function normSetYear() {
        //一位数表示的年份
        $rule = "/(?<![0-9])[0-9]{1}(?=年)/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $year = intval($match[0][0]);
            $this->tp->tunit[0] = $year;
        }
        //两位数表示的年份
        $rule = "/[0-9]{2}(?=年)/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $year = intval($match[0][0]);
            $this->tp->tunit[0] = $year;
        }
        //三位数表示的年份
        $rule = "/(?<![0-9])[0-9]{3}(?=年)/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $year = intval($match[0][0]);
            $this->tp->tunit[0] = $year;
        }
        //四位数表示的年份
        $rule = "/[0-9]{4}(?=年)/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $year = intval($match[0][0]);
            $this->tp->tunit[0] = $year;
        }
    }

    /**
     * 月-规范化方法--该方法识别时间表达式单元的月字段
     */
    private function normSetMonth() {
        $rule = "/((10)|(11)|(12)|([1-9]))(?=月)/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[1] = intval($match[0][0]);
            //处理倾向于未来时间的情况
            $this->preferFuture(1);
        }
    }

    /**
     * 如果用户选项是倾向于未来时间，检查checkTimeIndex所指的时间是否是过去的时间，如果是的话，将大一级的时间设为当前时间的+1。
     * 如在晚上说“早上8点看书”，则识别为明天早上;
     * 12月31日说“3号买菜”，则识别为明年1月的3号。
     * @param $check_time_index
     */
    private function preferFuture($check_time_index) {
        //1. 检查被检查的时间级别之前，是否没有更高级的已经确定的时间，如果有，则不进行处理.
        for ($i = 0; $i < $check_time_index; ++$i) {
            if ($this->tp->tunit[$i] != -1) return;
        }
        //2. 根据上下文补充时间
        $this->checkContextTime($check_time_index);
        //3. 根据上下文补充时间后再次检查被检查的时间级别之前，是否没有更高级的已经确定的时间，如果有，则不进行倾向处理.
        for ($i = 0; $i < $check_time_index; ++$i) {
            if ($this->tp->tunit[$i] != -1) return;
        }
        //4. 确认用户选项
        if (!$this->normalizer->is_prefer_future) return;
        //5. 获取当前时间，如果识别到的时间小于当前时间，则将其上的所有级别时间设置为当前时间，并且其上一级的时间步长+1
        $time_arr = explode("-", $this->normalizer->time_base);
        $cur = $this->normalizer->time_base;
        $cur_unit = intval($time_arr[$check_time_index]);

        if ($this->tp->tunit[0] == -1) $this->no_year = true;
        else $this->no_year = false;
        if ($cur_unit < $this->tp->tunit[$check_time_index]) return;
        //准备增加的时间单位是被检查的时间的上一级，将上一级时间+1
        $cur = $this->addTime($cur, $check_time_index - 1);
        $time_arr = explode("-", $cur);
        for ($i = 0; $i < $check_time_index; ++$i) {
            $this->tp->tunit[$i] = intval($time_arr[$i]);
        }
    }

    /**
     * 根据上下文时间补充时间信息
     * @param $check_time_index
     */
    private function checkContextTime($check_time_index) {
        for ($i = 0; $i < $check_time_index; ++$i) {
            if ($this->tp->tunit[$i] == -1 && $this->tp_origin->tunit[$i] != -1) {
                $this->tp->tunit[$i] = $this->tp_origin->tunit[$i];
            }
        }
        //在处理小时这个级别时，如果上文时间是下午的且下文没有主动声明小时级别以上的时间，则也把下文时间设为下午
        if ($this->is_first_time_solve_context === true &&
            $check_time_index == 3 &&
            $this->tp_origin->tunit[$check_time_index] >= 12 &&
            $this->tp->tunit[$check_time_index] < 12)
            $this->tp->tunit[$check_time_index] += 12;
        $this->is_first_time_solve_context = false;
    }

    private function addTime($cur, $fore_unit) {
        $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur, new DateTimeZone("Asia/Shanghai"));
        switch ($fore_unit) {
            case 0:
                $dt->modify("+1 year");
                break;
            case 1:
                $dt->modify("+1 month");
                break;
            case 2:
                $dt->modify("+1 day");
                break;
            case 3:
                $dt->modify("+1 hour");
                break;
            case 4:
                $dt->modify("+1 minute");
                break;
            case 5:
                $dt->modify("+1 second");
                break;
        }
        $cur = $dt->format("Y-n-j-H-i-s");
        return $cur;
    }

    private function modify($cur, $modify) {
        $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur, new DateTimeZone("Asia/Shanghai"));
        $dt->modify($modify);
        return $dt->format("Y-n-j-H-i-s");
    }

    /**
     * 日-规范化方法：该方法识别时间表达式单元的日字段
     */
    private function normSetDay() {
        $rule = "/((?<!\d))([0-3][0-9]|[1-9])(?=(日|号))/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[2] = intval($match[0][0]);
            //处理倾向于未来时间的情况
            $this->preferFuture(2);
            $this->_check_time($this->tp->tunit);
        }
    }

    /**
     * 检查未来时间点
     * @param array $parse 解析出来的list
     */
    private function _check_time(array $parse) {
        $time_arr = explode("-", $this->normalizer->time_base);
        if ($this->no_year) {
            if ($parse[1] == intval($time_arr[1])) {
                if ($parse[2] > intval($time_arr[2])) {
                    $parse[0] = $parse[0] - 1;
                }
            }
            $this->no_year = false;
        }
    }

    /**
     * 月-日 兼容模糊写法：该方法识别时间表达式单元的月、日字段
     */
    private function normDetMonthFuzzyDay() {
        $rule = "/((10)|(11)|(12)|([1-9]))(月|\\.|\\-)([0-3][0-9]|[1-9])/u";
        preg_match_all($rule, $this->exp_time, $match);
        if ($match[0] != []) {
            $match_str = $match[0][0];
            $p = "/(月|\\.|\\-)/u";
            preg_match_all($p, $match_str, $m, PREG_OFFSET_CAPTURE);
            if ($m[0] != []) {
                $split_index = $m[0][0][1];
                $month = substr($match_str, 0, $split_index);
                $day = substr($match_str, $split_index);
                $day = mb_substr($day, 1);//PHP没有切片，而Unicode长不为1，所以需要从match中获取的pos精确分割中文等多unicode
                $this->tp->tunit[1] = intval($month);
                $this->tp->tunit[2] = intval($day);
                //处理倾向于未来时间的情况
                $this->preferFuture(1);
            }
            $this->_check_time($this->tp->tunit);
        }
    }

    /**
     * 设置当前时间相关的时间表达式
     */
    private function normSetCurRelated() {
        $cur = $this->normalizer->time_base;
        $flag = [false, false, false];

        if (mb_strpos($this->exp_time, "前年") !== false) {
            $flag[0] = true;
            $cur = $this->modify($cur, "-2 years");
        }
        if (mb_strpos($this->exp_time, "去年") !== false) {
            $flag[0] = true;
            $cur = $this->modify($cur, "-1 year");
        }
        if (mb_strpos($this->exp_time, "今年") !== false) {
            $flag[0] = true;
        }
        if (mb_strpos($this->exp_time, "明年") !== false) {
            $flag[0] = true;
            $cur = $this->modify($cur, "+1 year");
        }
        if (mb_strpos($this->exp_time, "后年") !== false) {
            $flag[0] = true;
            $cur = $this->modify($cur, "+2 years");
        }
        preg_match_all("/上*上(个)?月/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[1] = true;
            $cur = $this->modify($cur, "-" . mb_substr_count($this->exp_time, "上") . " month");
        }
        preg_match_all("/(本|这个)月/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[1] = true;
        }
        preg_match_all("/下*下(个)?月/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[1] = true;
            $cur = $this->modify($cur, "+" . mb_substr_count($this->exp_time, "下") . " month");
        }
        preg_match_all("/大*大前天/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
            $cur = $this->modify($cur, "-" . (mb_substr_count($this->exp_time, "大") + 2) . " day");
        }
        preg_match_all("/(?<!大)前天/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
            $cur = $this->modify($cur, "-2 day");
        }
        if (mb_strpos($this->exp_time, "昨") !== false) {
            $flag[2] = true;
            $cur = $this->modify($cur, "-1 day");
        }
        preg_match_all("/今(?!年)/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
        }
        preg_match_all("/明(?!年)/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
            $cur = $this->modify($cur, "+1 day");
        }
        preg_match_all("/(?<!大)后天/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
            $cur = $this->modify($cur, "+2 day");
        }
        preg_match_all("/大*大后天/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $flag[2] = true;
            $cur = $this->modify($cur, "+" . (mb_substr_count($this->exp_time, "大") + 2) . " day");
        }
        //星期相关的预测, 因为PCRE正则表达式的断言不支持非固定长度，故硬核解析字符串了
        if (($pos = mb_strpos($this->exp_time, "上上")) !== false) {
            if (mb_substr($this->exp_time, $pos + 2, 1) == "周") {
                $week = $this->isAToB(($num = mb_substr($this->exp_time, $pos + 3, 1)), 1, 7) ? intval($num) : 1;
                $flag[2] = true;
                $week -= 1;
                $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
                $ot = $dt->format("N") - 1;
                $span = $week - $ot;
                $cur = $this->modify($cur, "-" . mb_substr_count($this->exp_time, "上") . " week");
                $cur = $this->modify($cur, ($span >= 0 ? ("+" . $span . " day") : ("-" . $span . " day")));
            } elseif (mb_substr($this->exp_time, $pos + 2, 2) == "星期") {
                $week = $this->isAToB(($num = mb_substr($this->exp_time, $pos + 5, 1)), 1, 7) ? intval($num) : 1;
                $flag[2] = true;
                $week -= 1;
                $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
                $ot = $dt->format("N") - 1;
                $span = $week - $ot;
                $cur = $this->modify($cur, "-" . mb_substr_count($this->exp_time, "上") . " week");
                $cur = $this->modify($cur, ($span >= 0 ? ("+" . $span . " day") : ("-" . $span . " day")));
            }
        }
        if ((($pos = mb_strpos($this->exp_time, "上周")) !== false ||
                ($pos = mb_strpos($this->exp_time, "上星期")) !== false) &&
            mb_substr($this->exp_time, $pos - 1, 1) != "上") {
            $flag[2] = true;
            if ($this->isAToB(
                ($num = mb_substr($this->exp_time,
                    (mb_substr($this->exp_time, $pos, 3) == "上星期" ? $pos + 3 : $pos + 2),
                    1)),
                1,
                7)) {
                $week = intval($num);
            } else {
                $week = 1;
            }
            $week -= 1;
            $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
            $ot = $dt->format("N") - 1;
            $span = $week - $ot;
            $cur = $this->modify($cur, "-1 week");
            $cur = $this->modify($cur, ($span >= 0 ? ("+" . $span . " day") : ("-" . $span . " day")));
        }

        //TODO: 下周，下下周，周x 的处理，稍后再说

        if ($flag[0] || $flag[1] || $flag[2]) {
            $this->tp->tunit[0] = intval($this->getYear($cur));
        }
        if ($flag[1] || $flag[2]) {
            $this->tp->tunit[1] = intval($this->getMonth($cur));
        }
        if ($flag[2]) {
            $this->tp->tunit[2] = intval($this->getDay($cur));
        }
    }

    private function isAToB($num, $a, $b) {
        return !is_numeric($num) ? false : $num >= $a && $num <= $b;
    }

    private function getYear($cur) {
        $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
        return $dt->format("Y");
    }

    private function getMonth($cur) {
        $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
        return $dt->format("n");
    }

    private function getDay($cur) {
        $dt = DateTime::createFromFormat("Y-n-j-H-i-s", $cur);
        return $dt->format("j");
    }

    /**
     * 时-规范化方法：该方法识别时间表达式单元的时字段
     */
    private function normSetHour() {
        preg_match_all("/(?<!(星期))([0-2]?[0-9])(?=(点|时))/u", $this->exp_time, $match);
        if ($match[0] == []) preg_match_all("/(?<!(周))([0-2]?[0-9])(?=(点|时))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[3] = intval($match[0][0]);
            $this->checkSubHour();
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        } else {
            $this->checkSubHour();
        }
    }

    private function checkSubHour() {
        if (mb_strpos($this->exp_time, "凌晨") !== false) {
            if ($this->tp->tunit[3] == -1) $this->tp->tunit[3] = RangeTimeEnum::DAY_BREAK;
            elseif ($this->tp->tunit[3] <= 23 && $this->tp->tunit[3] >= 12) $this->tp->tunit[3] -= 12;
            elseif ($this->tp->tunit[3] == 0) $this->tp->tunit[3] = 12;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
        preg_match_all("/早上|早晨|早间|晨间|今早|明早|早|清晨/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($this->tp->tunit[3] == -1)
                $this->tp->tunit[3] = RangeTimeEnum::EARLY_MORNING;
            elseif ($this->tp->tunit[3] >= 12 && $this->tp->tunit[3] <= 23)
                $this->tp->tunit[3] -= 12;
            elseif ($this->tp->tunit[3] == 0)
                $this->tp->tunit[3] = 12;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
        if (mb_strpos($this->exp_time, "上午") !== false) {
            if ($this->tp->tunit[3] == -1)
                $this->tp->tunit[3] = RangeTimeEnum::MORNING;
            elseif ($this->tp->tunit[3] >= 12 && $this->tp->tunit[3] <= 23)
                $this->tp->tunit[3] -= 12;
            elseif ($this->tp->tunit[3] == 0)
                $this->tp->tunit[3] = 12;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
        preg_match_all("/(中午)|(午间)|白天/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($this->tp->tunit[3] >= 0 && $this->tp->tunit[3] <= 10)
                $this->tp->tunit[3] += 12;
            if ($this->tp->tunit[3] == -1)
                $this->tp->tunit[3] = RangeTimeEnum::NOON;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
        preg_match_all("/(下午)|(午后)|(pm)|(PM)/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($this->tp->tunit[3] >= 0 && $this->tp->tunit[3] <= 11)
                $this->tp->tunit[3] += 12;
            if ($this->tp->tunit[3] == -1)
                $this->tp->tunit[3] = RangeTimeEnum::NOON;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
        preg_match_all("/晚上|夜间|夜里|今晚|明晚|晚|夜里/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($this->tp->tunit[3] >= 0 && $this->tp->tunit[3] <= 11)
                $this->tp->tunit[3] += 12;
            elseif ($this->tp->tunit == 12)
                $this->tp->tunit[3] = 0;
            elseif ($this->tp->tunit[3] == -1)
                $this->tp->tunit[3] = RangeTimeEnum::LATE_NIGHT;
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        }
    }

    /**
     * 分-规范化方法：该方法识别时间表达式单元的分字段
     */
    private function normSetMinute() {
        preg_match_all("/([0-9]+(?=分(?!钟)))|((?<=((?<!小)[点时]))[0-5]?[0-9](?!刻))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($match[0][0] != '') {
                $this->tp->tunit[4] = intval($match[0][0]);
                $this->is_all_day_time = false;
            }
        }
        preg_match_all("/(?<=[点时])[1一]刻(?!钟)/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[4] = 15;
            $this->is_all_day_time = false;
        }
        preg_match_all("/(?<=[点时])半/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[4] = 30;
            $this->preferFuture(4);
            $this->is_all_day_time = false;
        }
        preg_match_all("/(?<=[点时])[3三]刻(?!钟)/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[4] = 45;
            $this->is_all_day_time = false;
        }
    }

    /**
     * 添加了省略“秒”说法的时间：如17点15分32
     */
    private function normSetSecond() {
        preg_match_all("/([0-9]+(?=秒))|((?<=分)[0-5]?[0-9])/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->tp->tunit[5] = intval($match[0][0]);
            $this->is_all_day_time = false;
        }
    }

    /**
     * 特殊形式的规范化方法-该方法识别特殊形式的时间表达式单元的各个字段
     */
    private function normSetSpecial() {
        //Problem: Python版这里使用了非固定长度的断言，PCRE不支持。
        //但发现好像这里原来的(?<!(周|星期))这处断言似乎是没什么影响的，就去掉了。
        //但说起来逻辑可以改得更简单一点，减少一些重复的代码。这里挖个坑,TODO.
        preg_match_all("/(晚上|夜间|夜里|今晚|明晚|晚|夜里|下午|午后)([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]/u", $this->exp_time, $match);
        if ($match[0] != []) {
            preg_match_all("/([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]/u", $this->exp_time, $match);
            $tmp_target = $match[0][0];
            $tmp_parser = explode(":", $tmp_target);
            if (intval($tmp_parser[0]) >= 0 && intval($tmp_parser[0]) <= 11)
                $this->tp->tunit[3] = intval($tmp_parser[0]) + 12;
            else
                $this->tp->tunit[3] = intval($tmp_parser[0]);
            $this->tp->tunit[4] = intval($tmp_parser[1]);
            $this->tp->tunit[5] = intval($tmp_parser[2]);
            $this->preferFuture(3);
            $this->is_all_day_time = false;
        } else {
            preg_match_all("/(晚上|夜间|夜里|今晚|明晚|晚|夜里|下午|午后)([0-2]?[0-9]):[0-5]?[0-9]/u", $this->exp_time, $match);
            if ($match[0] != []) {
                preg_match_all("/([0-2]?[0-9]):[0-5]?[0-9]/u", $this->exp_time, $match);
                $tmp_target = $match[0][0];
                $tmp_parser = explode(":", $tmp_target);
                if (intval($tmp_parser[0]) >= 0 && intval($tmp_parser[0]) <= 11)
                    $this->tp->tunit[3] = intval($tmp_parser[0]) + 12;
                else
                    $this->tp->tunit[3] = intval($tmp_parser[0]);
                $this->tp->tunit[4] = intval($tmp_parser[1]);
                $this->preferFuture(3);
                $this->is_all_day_time = false;
            }
        }
        if ($match[0] != []) {
            //周和星期的不同长度断言在pcre中不能使，所以直接判断周/期
            preg_match_all("/(?<!(周|期))([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9](PM|pm|p\.m)/u", $this->exp_time, $match);
            if ($match[0] != []) {
                preg_match_all("/([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]/u", $this->exp_time, $match);
                $tmp_target = $match[0][0];
                $tmp_parser = explode(":", $tmp_target);
                if (intval($tmp_parser[0]) >= 0 && intval($tmp_parser[0]) <= 11)
                    $this->tp->tunit[3] = intval($tmp_parser[0]) + 12;
                else
                    $this->tp->tunit[3] = intval($tmp_parser[0]);

                $this->tp->tunit[4] = intval($tmp_parser[1]);
                $this->tp->tunit[5] = intval($tmp_parser[2]);
                $this->preferFuture(3);
                $this->is_all_day_time = false;
            } else {
                preg_match_all("/(?<!(周|期))([0-2]?[0-9]):[0-5]?[0-9](PM|pm|p.m)/u", $this->exp_time, $match);
                if ($match[0] != []) {
                    preg_match_all("/([0-2]?[0-9]):[0-5]?[0-9]/u", $this->exp_time, $match);
                    $tmp_parser = explode(":", $match[0][0]);
                    if (intval($tmp_parser[0]) >= 0 && intval($tmp_parser[0]) <= 11)
                        $this->tp->tunit[3] = intval($tmp_parser[0]) + 12;
                    else
                        $this->tp->tunit[3] = intval($tmp_parser[0]);
                    $this->tp->tunit[4] = intval($tmp_parser[1]);
                    $this->preferFuture(3);
                    $this->is_all_day_time = false;
                }
            }
        }
        if ($match[0] != []) {
            preg_match_all("/(?<!(星期|晚上|夜间|夜里|今晚|明晚|夜里|下午|午后))([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]/u", $this->exp_time, $match1);
            preg_match_all("/(?<!(周|晚))([0-2]?[0-9]):[0-5]?[0-9]:[0-5]?[0-9]/u", $this->exp_time, $match2);
            if ($match1[0] != [] && $match2[0] != [] && $match1[0][0] == $match2[0][0]) {
                $tmp_parser = explode(":", $match1[0][0]);
                $this->tp->tunit[3] = intval($tmp_parser[0]);
                $this->tp->tunit[4] = intval($tmp_parser[1]);
                $this->tp->tunit[5] = intval($tmp_parser[2]);
                $this->preferFuture(3);
                $this->is_all_day_time = false;
            } else {
                preg_match_all("/(?<!(星期|晚上|夜间|夜里|今晚|明晚|夜里|下午|午后))([0-2]?[0-9]):[0-5]?[0-9]/u", $this->exp_time, $match1);
                preg_match_all("/(?<!(周|晚))([0-2]?[0-9]):[0-5]?[0-9]/u", $this->exp_time, $match2);
                if ($match1[0] != [] && $match2[0] != [] && $match1[0][0] == $match2[0][0]) {
                    $tmp_parser = explode(":", $match1[0][0]);
                    $this->tp->tunit[3] = intval($tmp_parser[0]);
                    $this->tp->tunit[4] = intval($tmp_parser[1]);
                    $this->preferFuture(3);
                    $this->is_all_day_time = false;
                }
            }
        }
        //这里是对年份表达的极好方式
        preg_match_all("/[0-9]?[0-9]?[0-9]{2}-((10)|(11)|(12)|([1-9]))-((?<!\d))([0-3][0-9]|[1-9])/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $tmp_parser = explode("-", $match[0][0]);
            $this->tp->tunit[0] = intval($tmp_parser[0]);
            $this->tp->tunit[1] = intval($tmp_parser[1]);
            $this->tp->tunit[2] = intval($tmp_parser[2]);
        }
        preg_match_all("/[0-9]?[0-9]?[0-9]{2}\/((10)|(11)|(12)|([1-9]))\/((?<!\d))([0-3][0-9]|[1-9])/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $tmp_parser = explode("/", $match[0][0]);
            $this->tp->tunit[0] = intval($tmp_parser[0]);
            $this->tp->tunit[1] = intval($tmp_parser[1]);
            $this->tp->tunit[2] = intval($tmp_parser[2]);
        }
        //TODO: 添加12/31/2019 等
        /* /((10)|(11)|(12)|([1-9]))/((?<!\\d))([0-3][0-9]|[1-9])/[0-9]?[0-9]?[0-9]{2}/u */
        /* /[0-9]?[0-9]?[0-9]{2}\\.((10)|(11)|(12)|([1-9]))\\.((?<!\\d))([0-3][0-9]|[1-9])/u */
    }

    /**
     * 设置时间长度相关的时间表达式
     */
    private function normSetSpanRelated() {
        preg_match_all("/\d+(?=个月(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $month = intval($match[0][0]);
            $this->tp->tunit[1] = $month;
        }

        preg_match_all("/\d+(?=天(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $day = intval($match[0][0]);
            $this->tp->tunit[2] = $day;
        }

        preg_match_all("/\d+(?=(个)?小时(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $hour = intval($match[0][0]);
            $this->tp->tunit[3] = $hour;
        }
        preg_match_all("/\d+(?=分钟(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $minute = intval($match[0][0]);
            $this->tp->tunit[4] = $minute;
        }
        preg_match_all("/\d+(?=秒钟(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $second = intval($match[0][0]);
            $this->tp->tunit[5] = $second;
        }
        preg_match_all("/\d+(?=(个)?(周|星期|礼拜)(?![以之]?[前后]))/u", $this->exp_time, $match);
        if ($match[0] != []) {
            $this->normalizer->is_time_span = true;
            $week = intval($match[0][0]);
            if ($this->tp->tunit[2] == -1)
                $this->tp->tunit[2] = 0;
            $this->tp->tunit[2] += intval($week * 7);
        }
    }

    private function normSetHoliday() {
        $rule = "(情人节)|(母亲节)|(青年节)|(教师节)|(中元节)|(端午)|(劳动节)|(7夕)|(建党节)|(建军节)|(初13)|(初14)|(初15)|";
        $rule .= "(初12)|(初11)|(初9)|(初8)|(初7)|(初6)|(初5)|(初4)|(初3)|(初2)|(初1)|(中和节)|(圣诞)|(中秋)|(春节)|(元宵)|";
        $rule .= "(航海日)|(儿童节)|(国庆)|(植树节)|(元旦)|(重阳节)|(妇女节)|(记者节)|(立春)|(雨水)|(惊蛰)|(春分)|(清明)|(谷雨)|";
        $rule .= "(立夏)|(小满 )|(芒种)|(夏至)|(小暑)|(大暑)|(立秋)|(处暑)|(白露)|(秋分)|(寒露)|(霜降)|(立冬)|(小雪)|(大雪)|";
        $rule .= "(冬至)|(小寒)|(大寒)";
        preg_match_all("/$rule/u", $this->exp_time, $match);
        if ($match[0] != []) {
            if ($this->tp->tunit[0] == -1)
                $this->tp->tunit[0] = intval(explode("-", $this->normalizer->time_base)[0]);
            $holi = $match[0][0];
            if (mb_strpos($holi, "节") === false) {
                $holi .= "节";
            }
            if (isset($this->normalizer->holi_solar[$holi])) {
                $date = explode("-", $this->normalizer->holi_solar[$holi]);
            } elseif (isset($this->normalizer->holi_lunar[$holi])) {
                $date = explode("-", $this->normalizer->holi_lunar[$holi]);
                $ls_converter = new LunarSolarConverter();
                $lunar = new Lunar($this->tp->tunit[0], intval($date[0]), intval($date[1]), false);
                $solar = $ls_converter->lunarToSolar($lunar);
                $this->tp->tunit[0] = $solar->solar_year;
                $date[0] = $solar->solar_month;
                $date[1] = $solar->solar_day;
            } else {
                $holi = trim($holi, "节");
                if (in_array($holi, ['小寒', '大寒']))
                    $this->tp->tunit[0] += 1;
                $date = $this->china_24_st($this->tp->tunit[0], $holi);
            }
            $this->tp->tunit[1] = intval($date[0]);
            $this->tp->tunit[2] = intval($date[1]);
        }
    }

    /**
     * 二十世纪和二十一世纪，24节气计算
     *
     * @param $year :年份
     * @param $china_st :节气
     * @return array
     */
    private function china_24_st($year, $china_st) {
        if (19 == intval($year / 100) || ($year == 2000)) {
            // 20世纪 key值
            $st_key = [6.11, 20.84, 4.6295, 19.4599, 6.3826, 21.4155, 5.59, 20.888, 6.318, 21.86, 6.5, 22.2, 7.928,
                23.65, 8.35, 23.95, 8.44, 23.822, 9.098, 24.218, 8.218, 23.08, 7.9, 22.6];
        } else {
            // 21世纪 key值
            $st_key = [5.4055, 20.12, 3.87, 18.73, 5.63, 20.646, 4.81, 20.1, 5.52, 21.04, 5.678, 21.37, 7.108, 22.83,
                7.5, 23.13, 7.646, 23.042, 8.318, 23.438, 7.438, 22.36, 7.18, 21.94];
        }
        //二十四节气字典-- key值, 月份，(特殊年份，相差天数)...
        $solar_terms = [
            '小寒' => [$st_key[0], '1', [2019, -1], [1982, 1]],
            '大寒' => [$st_key[1], '1', [2082, 1]],
            '立春' => [$st_key[2], '2', [null, 0]],
            '雨水' => [$st_key[3], '2', [2026, -1]],
            '惊蛰' => [$st_key[4], '3', [null, 0]],
            '春分' => [$st_key[5], '3', [2084, 1]],
            '清明' => [$st_key[6], '4', [null, 0]],
            '谷雨' => [$st_key[7], '4', [null, 0]],
            '立夏' => [$st_key[8], '5', [1911, 1]],
            '小满' => [$st_key[9], '5', [2008, 1]],
            '芒种' => [$st_key[10], '6', [1902, 1]],
            '夏至' => [$st_key[11], '6', [null, 0]],
            '小暑' => [$st_key[12], '7', [2016, 1], [1925, 1]],
            '大暑' => [$st_key[13], '7', [1922, 1]],
            '立秋' => [$st_key[14], '8', [2002, 1]],
            '处暑' => [$st_key[15], '8', [null, 0]],
            '白露' => [$st_key[16], '9', [1927, 1]],
            '秋分' => [$st_key[17], '9', [null, 0]],
            '寒露' => [$st_key[18], '10', [2088, 0]],
            '霜降' => [$st_key[19], '10', [2089, 1]],
            '立冬' => [$st_key[20], '11', [2089, 1]],
            '小雪' => [$st_key[21], '11', [1978, 0]],
            '大雪' => [$st_key[22], '12', [1954, 1]],
            '冬至' => [$st_key[23], '12', [2021, -1], [1918, -1]]
        ];

        if (in_array($china_st, ['小寒', '大寒', '立春', '雨水'])) {
            $flag_day = intval(($year % 100) * 0.2422 + $solar_terms[$china_st][0]) - intval(($year % 100 - 1) / 4);
        } else {
            $flag_day = intval(($year % 100) * 0.2422 + $solar_terms[$china_st][0]) - intval(($year % 100) / 4);
        }
        // 特殊年份处理
        foreach ($this->subarray($solar_terms[$china_st], 2) as $special) {
            if ($year == $special[0]) {
                $flag_day += $special[1];
                break;
            }
        }
        return [$solar_terms[$china_st][1], strval($flag_day)];
    }

    function subarray(array $arr, int $start, $length = 0) {
        $p = 0;
        $i = $start;
        $ls = [];
        if ($length > 0) {
            while (isset($arr[$i]) && $p < $length) {
                $ls[] = $arr[$i];
                $i++;
                $p++;
            }
            return $ls;
        } else {
            while (isset($arr[$i])) {
                $ls[] = $arr[$i];
                $i++;
            }
            if ($length < 0) {
                for ($is = 0; $is < $length; $is++) array_pop($ls);
            }
            return $ls;
        }
    }

    /**
     * 该方法用于更新timeBase使之具有上下文关联性
     */
    private function modifyTimeBase() {
        if (!$this->normalizer->is_time_span) {
            if ($this->tp->tunit[0] >= 30 && $this->tp->tunit[0] < 100) {
                $this->tp->tunit[0] = 1900 + $this->tp->tunit[0];
            }
            if ($this->tp->tunit[0] > 0 && $this->tp->tunit[0] < 30) {
                $this->tp->tunit[0] = 2000 + $this->tp->tunit[0];
            }
            $time_grid = explode("-", $this->normalizer->time_base);
            $arr = [];
            for ($i = 0; $i < 6; ++$i) {
                if ($this->tp->tunit[$i] == -1) {
                    $arr [] = strval($time_grid[$i]);
                } else {
                    $arr [] = strval($this->tp->tunit[$i]);
                }
            }
            $this->normalizer->time_base = implode("-", $arr);
        }
    }

    private function genSpan($days, $seconds) {
        $day = intval($seconds / (3600 * 24));
        $h = intval(($seconds % (3600 * 24)) / 3600);
        $m = intval((($seconds % (3600 * 24)) % 3600) / 60);
        $s = intval((($seconds % (3600 * 24)) % 3600) % 60);
        return strval($days + $day)." days, ".sprintf("%d:%02d:%02d", $h, $m, $s);
    }
}