# Time-NLP-PHP
Time-NLP的PHP版本 中文时间表达词转换

## 简介
Time-NLP 的 PHP 版本

Time-NLP 的 python3 版本 https://github.com/zhanzecheng/Time_NLP

Time-NLP 的 Java 版本 https://github.com/shinyke/Time-NLP

## 使用
```bash
composer require crazywhalecc/time-nlp:dev-master
```

## 功能说明
本项目不依赖其他第三方库，运行环境推荐PHP7-cli以上。
```bash
php test.php
php test.php --debug
# debug模式会sleep等待显示解析过程
```
输入示例：
```
>>> 明天上午
<<< {"type":"timestamp","timestamp":"2019-08-14 10:00:00"}

>>> 明天上午11点叫我起床
<<< {"type":"timestamp","timestamp":"2019-08-14 11:00:00"}

......更多示例可参考Python和Java版
```
