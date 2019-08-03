<?php


namespace time_nlp;


class TimeNormalizer
{
    /**
     * @var bool
     */
    private $is_prefer_future;
    /**
     * @var false|string
     */
    private $pattern;
    private $holi_solar;
    private $holi_lunar;
    /**
     * @var bool
     */
    private $is_time_span;
    private $invalid_span;
    /**
     * @var string
     */
    private $time_span;
    private $target;
    private $time_base;
    private $now_time;
    private $old_time_base;
    private $time_token;

    public function __construct($is_prefer_future = true) {
        $this->is_prefer_future = $is_prefer_future;
        $this->init();
    }

    private function init() {
        $this->pattern = file_get_contents("../../resource/regex.txt");
        $this->holi_solar = json_decode(file_get_contents("../../resource/holi_solar.json"), true);
        $this->holi_lunar = json_decode(file_get_contents("../../resource/holi_lunar.json"), true);
    }

    public function _filter($input_query) {
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
        return $input_query;
    }

    public function parse($target, $time_base = null) {
        $this->is_time_span = false;
        $this->invalid_span = false;
        $this->time_span = '';
        $this->target = $this->_filter($target);
        $this->time_base = date("Y-n-j-H-i-s", ($time_base === null ? time() : $time_base));
        $this->now_time = $this->time_base;
        $this->old_time_base = $this->time_base;
        $this->preHandling();
        $this->time_token = $this->__timeEx();
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
        foreach($match[0] as $v) {
            $startline = $v[1];
            if($startline == $endline) {
                $repointer -= 1;
                $temp[$repointer] = $temp[$repointer] . $v[0];
            } else {
                $temp[]=$v[0];
            }
            $endline = $startline + strlen($v[0]);
            $repointer += 1;
        }

        $res = [];
        //TODO: __timeEx to be continued.
        return $res;
    }
}