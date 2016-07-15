<?php
ini_set("memory_limit","512M");
ini_set("max_execution_time", 300);
$config['host'] = "127.0.0.1";       // redis 服务器 IP  192.168.204.26
$config['port'] = "6379";            // redis 服务器 端口
$config['time'] = 3600;              // redis 过期时间
$config['path'] = "/tmp/xdebug";     // 删除文件目录
define("REDIS_HOST",$config['host']);
define("REDIS_PORT",$config['port']);
define("REDIS_TIME",$config['time']);
define("FILE_PATH",$config['path']);
?>