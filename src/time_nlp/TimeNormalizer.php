<?php


namespace time_nlp;


class TimeNormalizer
{
    /**
     * @var bool
     */
    public $is_prefer_future;
    /**
     * @var false|string
     */
    private $pattern;
    public $holi_solar;
    public $holi_lunar;
    /**
     * @var bool
     */
    public $is_time_span;
    public $invalid_span;
    /**
     * @var string
     */
    public $time_span;
    private $target;
    public $time_base;
    private $now_time;
    private $old_time_base;
    private $time_token;

    public function __construct($is_prefer_future = true) {
        $this->is_prefer_future = $is_prefer_future;
        $this->init();
    }

    private function init() {
        $this->pattern = file_get_contents(__DIR__."/../../resource/regex.txt");
        $this->holi_solar = json_decode(file_get_contents(__DIR__."/../../resource/holi_solar.json"), true);
        $this->holi_lunar = json_decode(file_get_contents(__DIR__."/../../resource/holi_lunar.json"), true);
    }

    public function _filter($input_query) {
        debug("这里对一些不规范的表达做转换, origin: [$input_query]", 0);
        $input_query = StringPreHandler::numberTranslator($input_query);

        $rule = "/[0-9]月[0-9]/u";
        preg_match_all($rule, $input_query, $match);
        if ($match[0] != []) {
            $index = mb_strpos($input_query, "月");
            $rule = "/日|号/u";
            preg_match_all($rule, mb_substr($input_query, $index), $match);
            if ($match[0] == []) {
                $rule = "/[0-9]月[0-9]+/u";
                preg_match_all($rule, $input_query, $match);
                if ($match[0] != []) {
                    /*
                        end = match.span()[1]
                        input_query = input_query[:end] + '号' + input_query[end:]
                    */
                    $end = mb_strpos($input_query, $match[0][0]);
                    $input_query = mb_substr($input_query, 0, $end) . "号" . mb_substr($input_query, $end);
                }
            }
        }
        if (mb_strpos($input_query, "月") === false) {
            $input_query = str_replace("个", "", $input_query);
        }
        $a = ["中旬", "傍晚", "大年", "五一", "白天", "："];
        $b = ["15号", "午后", "", "劳动节", "早上", ":"];
        $input_query = str_replace($a, $b, $input_query);
        debug("转换完成, target: [$input_query]");
        return $input_query;
    }

    public function parse($target, $time_base = null) {
        debug("正在进入parse函数");
        $this->is_time_span = false;
        $this->invalid_span = false;
        $this->time_span = '';
        $this->target = $this->_filter($target);
        $this->time_base = date("Y-n-j-H-i-s", ($time_base === null ? time() : $time_base));
        $this->now_time = $this->time_base;
        $this->old_time_base = $this->time_base;
        $this->preHandling();
        $this->time_token = $this->__timeEx();
        $dic = [];
        $res = $this->time_token;

        if ($this->is_time_span) {
            if ($this->invalid_span) {
                $dic["error"] = 'no time pattern could be extracted.';
            } else {
                $result = [];
                $dic["type"] = "timedelta";
                $dic["timedelta"] = $this->time_span;
                //echo $dic["timedelta"].PHP_EOL;
                $index = mb_strpos($dic["timedelta"], "days");

                $days = intval(mb_substr($dic["timedelta"], 0, $index - 1));
                $result['year'] = intval($days / 365);
                $result['month'] = intval($days / 30 - $result['year'] * 12);
                $result['day'] = intval($days - $result['year'] * 365 - $result['month'] * 30);
                $index = mb_strpos($dic["timedelta"], ',');
                $time = mb_substr($dic["timedelta"], $index + 1);
                $time = explode(":", $time);
                $result['hour'] = intval($time[0]);
                $result['minute'] = intval($time[1]);
                $result['second'] = intval($time[2]);
                $dic["timedelta"] = $result;
            }
        } else {
            if (count($res) == 0) {
                $dic['error'] = 'no time pattern could be extracted.';
            } elseif (count($res) == 1) {
                $dic["type"] = 'timestamp';
                $dic["timestamp"] = date("Y-m-d H:i:s", $res[0]->time);
            } else {
                $dic['type'] = 'timespan';
                $dic["timespan"] = [date("Y-m-d H:i:s", $res[0]->time), date("Y-m-d H:i:s", $res[1]->time)];
            }
        }
        return json_encode($dic, JSON_UNESCAPED_UNICODE, JSON_PRETTY_PRINT);
    }

    /**
     * 待匹配字符串的清理空白符和语气助词以及大写数字转化的预处理
     */
    private function preHandling() {
        $this->target = StringPreHandler::delKeyword($this->target, "/\\s+/u");
        $this->target = StringPreHandler::delKeyword($this->target, "/[的]+/u");
        $this->target = StringPreHandler::numberTranslator($this->target);
    }

    private function __timeEx() {
        $endline = -1;
        $repointer = 0;
        $temp = [];

        preg_match_all($this->pattern, $this->target, $match, PREG_OFFSET_CAPTURE);
        foreach ($match[0] as $v) {
            $startline = $v[1];
            if ($startline == $endline) {
                $repointer -= 1;
                $temp[$repointer] = $temp[$repointer] . $v[0];
            } else {
                $temp[] = $v[0];
            }
            $endline = $startline + strlen($v[0]);
            $repointer += 1;
        }

        /** @var TimeUnit[] $res */
        $res = [];
        // 时间上下文： 前一个识别出来的时间会是下一个时间的上下文，用于处理：周六3点到5点这样的多个时间的识别，第二个5点应识别到是周六的。
        $context_tp = new TimePoint();
        for ($i = 0; $i < $repointer; ++$i) {
            $res[] = (new TimeUnit($temp[$i], $this, $context_tp));
            $context_tp = $res[$i]->tp;
        }
        $res = $this->__filterTimeUnit($res);
        return $res;
    }

    /**
     * 该方法用于更新timeBase使之具有上下文关联性
     * @param TimeUnit[] $tu_arr
     * @return mixed
     */
    private function __filterTimeUnit($tu_arr) {
        if ($tu_arr == [] || (count($tu_arr) < 1)) {
            return $tu_arr;
        }
        $res = [];
        foreach ($tu_arr as $tu) {
            if ($tu->time != 0) {
                $res[] = $tu;
            }
        }
        return $res;
    }
}