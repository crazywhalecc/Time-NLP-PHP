<?php


namespace time_nlp;


class StringPreHandler
{
    /**
     * 该方法可以将字符串中所有的用汉字表示的数字转化为用阿拉伯数字表示的数字
     * 如"这里有一千两百个人，六百零五个来自中国"可以转化为
     * "这里有1200个人，605个来自中国"
     * 此外添加支持了部分不规则表达方法
     * 如两万零六百五可转化为20650
     * 两百一十四和两百十四都可以转化为214
     * 一六零加一五八可以转化为160+158
     * 该方法目前支持的正确转化范围是0-99999999
     * 该功能模块具有良好的复用性
     * @param $target
     * @return string
     */
    public static function numberTranslator($target) {
        $pattern = "/[一二两三四五六七八九123456789]万[一二两三四五六七八九123456789](?!(千|百|十))/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $group = $v;
            $s = explode("万", $group);
            $num = 0;
            if (count($s) == 2)
                $num += self::wordToNumber($s[0]) * 10000 + self::wordToNumber($s[1]) * 1000;
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/[一二两三四五六七八九123456789]千[一二两三四五六七八九123456789](?!(百|十))/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $group = $v;
            $s = explode("千", $group);
            $num = 0;
            if (count($s) == 2)
                $num += self::wordToNumber($s[0]) * 1000 + self::wordToNumber($s[1]) * 100;
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/[一二两三四五六七八九123456789]百[一二两三四五六七八九123456789](?!十)/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $group = $v;
            $s = explode("百", $group);
            $num = 0;
            if (count($s) == 2)
                $num += self::wordToNumber($s[0]) * 100 + self::wordToNumber($s[1]) * 10;
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/[零一二两三四五六七八九]/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $target = preg_replace($pattern, self::wordToNumber($v), $target, 1);
        }

        $pattern = "/(?<=[(周)(星期)])[末天日]/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $target = preg_replace($pattern, self::wordToNumber($v), $target, 1);
        }

        $pattern = "/(?<![(周)(星期)])0?[0-9]?十[0-9]?/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $group = $v;
            $s = explode("十", $group);
            $ten = intval($s[0]);
            if ($ten == 0) {
                $ten = 1;
            }
            $unit = intval($s[1]);
            $num = $ten * 10 + $unit;
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/0?[1-9]百[0-9]?[0-9]?/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $group = $v;
            $s = explode("百", $group);
            $num = 0;
            if (count($s) == 1) {
                $hundred = intval($s[0]);
                $num += $hundred * 100;
            } elseif (count($s) == 2) {
                $hundred = intval($s[0]);
                $num += $hundred * 100;
                $num += intval($s[1]);
            }
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/0?[1-9]千[0-9]?[0-9]?[0-9]?/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $s = explode("千", $v);
            $num = 0;
            if (count($s) == 1) {
                $thousand = intval($s[0]);
                $num += $thousand * 1000;
            } elseif (count($s) == 2) {
                $thousand = intval($s[0]);
                $num += $thousand * 1000;
                $num += intval($s[1]);
            }
            $target = preg_replace($pattern, strval($num), $target, 1);
        }

        $pattern = "/[0-9]+万[0-9]?[0-9]?[0-9]?[0-9]?/u";
        preg_match_all($pattern, $target, $match);
        foreach ($match[0] as $v) {
            $s = explode("万", $v);
            $num = 0;
            if (count($s) == 1) {
                $ten_thousand = intval($s[0]);
                $num += $ten_thousand * 10000;
            } elseif (count($s) == 2) {
                $ten_thousand = intval($s[0]);
                $num += $ten_thousand * 10000;
                $num += intval($s[1]);
            }
            $target = preg_replace($pattern, strval($num), $target, 1);
        }
        return $target;
    }

    private static function wordToNumber($v) {
        switch ($v) {
            case '零':
            case '0':
                return 0;
            case '一':
            case '1':
                return 1;
            case '二':
            case '两':
            case '2':
                return 2;
            case '三':
            case '3':
                return 3;
            case '四':
            case '4':
                return 4;
            case '五':
            case '5':
                return 5;
            case '六':
            case '6':
                return 6;
            case '七':
            case '天':
            case '日':
            case '末':
            case '7':
                return 7;
            case '八':
            case '8':
                return 8;
            case '九':
            case '9':
                return 9;
            default:
                return -1;
        }
    }

    /**
     * 该方法删除一字符串中所有匹配某一规则字串
     * 可用于清理一个字符串中的空白符和语气助词
     * @param $target
     * 待处理字符串
     * @param $rules
     * 删除规则
     * @return string
     * 清理工作完成后的字符串
     */
    public static function delKeyword($target, $rules) { return preg_replace($rules, '', $target); }
}