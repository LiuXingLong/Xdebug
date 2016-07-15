# XDebug Trace Tree

This is a simple PHP script to display XDebug traces in a tree like visualization. It can collapse parts
that you're not interested in when analysing the trace, making it much easier to follow the program flow.

Installation is simple. Just clone this dierectory to somewhere in your webserver and it should automatically
list all available trace files.

**Important:** this is meant a personal debugging - it should not be installed on a public webserver (Its passing
full file paths).

## Screenshot

![Screenshot](res/screenshot.png)

## Recommended xdebug.ini setup:

xdebug_delete.php   后台定时删除脚本
xdebug_redis.php    后台解析脚本
./res/conf.php      配置文件


PHP 配置说明

PHP 需安装 redis 和 xdebug 扩展

PHP.ini  xdebug配置

[Xdebug]
zend_extension = "/usr/local/php/xdebug/xdebug.so" // 这配置根据自己的 xdebug.so 路径配置
xdebug.auto_trace= off
xdebug.show_exception_trace=on
xdebug.remote_autostart=on
xdebug.remote_enable= on
xdebug.remote_host=localhost
xdebug.trace_output_dir="/tmp/xdebug/" // 这个配置请保留  是xdebug输出文件的路径  且目录有读写权限
xdebug.trace_output_name=
xdebug.remote_handler=dbgp
xdebug.trace_enable_trigger=on
xdebug.show_mem_delta=on
xdebug.collect_vars=on
xdebug.collect_params=4
xdebug.collect_return=on
xdebug.trace_format=on




nginx.conf  配置

location ~ \.php$ {
        #   root           html;
            fastcgi_pass   127.0.0.1:9000;
        #   fastcgi_index  index.php;
            fastcgi_param  SCRIPT_FILENAME  $document_root$fastcgi_script_name;
            set $php_value "";
            if ($http_cookie ~* "xdebug_status=1") {
                set $php_value "xdebug.trace_output_name=$remote_addr#_'$time_iso8601'_#%R";
                set $php_value "$php_value \n xdebug.auto_trace=on";
            }
            if ($http_cookie ~* "xdebug_status=0") {
                set $php_value "xdebug.trace_output_name=";
                set $php_value "$php_value \n xdebug.auto_trace=off";
            }
            if ($document_uri ~* "xdebug") {
                set $php_value "xdebug.trace_output_name=";
                set $php_value "$php_value \n xdebug.auto_trace=off";
            }
            fastcgi_connect_timeout 600;
            fastcgi_send_timeout 600;
            fastcgi_read_timeout 600;
            fastcgi_param  PHP_VALUE  $php_value;
            include        fastcgi_params;
        }

配置完后重启  nginx 和 php-fpm
