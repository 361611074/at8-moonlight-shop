语言包构建说明
==============

- 本目录的权威来源是 **.po** 文件（已入库）。
- **.mo 不入库**（见 .gitignore），由部署流程在服务器上编译：

    cd wp-content/plugins/at8-moonlight-shop/languages
    for f in *.po; do msgfmt -o "${f%.po}.mo" "$f"; done

- 校验编译产物是否可被 PHP 读取（magic 必须是小端 de 12 04 95）：

    od -A n -t x1 -N 4 at8-moonlight-shop-zh_TW.mo      # 应输出 de 12 04 95

    php -r 'require "wp-load.php";
            $m = new MO();
            $m->import_from_file("languages/at8-moonlight-shop-zh_TW.mo");
            echo count($m->entries);'                 # 应 > 500

为什么 .mo 不入库
------------------
PHP 的 MO 解析器只识别小端序。若入库的 .mo 是大端序，部署会覆盖服务器上
已编译好的正确版本，前台与后台文案会**静默回退**成源码原文（多语言看似失效），
而且 .po 里明明有译文。这类问题排查成本高，故从根源上避免：只提交 .po。
