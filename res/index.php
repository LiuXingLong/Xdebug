<?php
if ($_SERVER['REQUEST_METHOD']!="POST") {
    echo "<script> window.location.href ='../index.html'</script>;";
    exit;
}
if(!isset($_SESSION)) {
    session_start();
}

//xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);

require_once './conf.php';
require_once './XDebugParser.php';
$flag = $_POST['flag'];
$dir = ini_get('xdebug.trace_output_dir');
if($flag == 1){ // 刷新列表
	if (!$dir) {
	    $dir = '/tmp/';
	}
	$dirr = $dir.$_SERVER['REMOTE_ADDR'];
	$files = glob("$dirr*.xt");
	$flag = 0;
	$html = "";
	$count = count($files) - 1;
	for ($i = $count; $i >= 0; $i--) {
	 	if(!empty($_SESSION['file']) && $files[$i] == $_SESSION['file']){
			$flag = 1;
		}
            $text = explode('#',basename($files[$i]),3);
            $pattern = "/(?:\d{4}-\d{2}-\d{2})|(?:\d{2}:\d{2}:\d{2})/";
            preg_match_all($pattern,$text[1],$time);
	    $checked = (basename($files[$i]) ==@$_COOKIE['xdebug_select']) ? 'selected="selected"' : '';   
	    $html = $html.'<option value="' . htmlspecialchars(basename($files[$i])) . '" '.$checked.'>' . htmlspecialchars($time[0][0]."_".$time[0][1]."_".$text[2]) . '</option>';
	}
	if(!empty($_SESSION['file']) && $flag == 0){
	    $_SESSION['file'] = null;
	}
        $filename = "";
        if(!empty($_SESSION['file'])){
            $text = explode('#',basename(@$_SESSION['file']),3);
            $pattern = "/(?:\d{4}-\d{2}-\d{2})|(?:\d{2}:\d{2}:\d{2})/";
            preg_match_all($pattern,$text[1],$time);
            $filename = @$time[0][0]."_".@$time[0][1]."_".@$text[2];
        }
	$result[0] = $html;
	$result[1] = $filename;
	echo json_encode($result);
}else if($flag == 2){ // 下载文件
	if (!empty($_POST['filename'])) {
	    $html = "";
	    $_SESSION['file'] = $dir.$_POST['filename'];  // 全文件路劲
	    $parser = new XDebugParser($_SESSION['file'],REDIS_HOST,REDIS_PORT,REDIS_TIME);
	    if($parser->redis->exists($parser->prefix."func-name") == 0 || $parser->redis->ttl($parser->prefix."func-name") < 600){  // 键不存在 或 失效期时间低于15分钟更新（主要防止时间太少搜索过程中缓存已清理了）        
                $xdebug_flag = $parser->redis->get($parser->prefix."flag");	     
            	if($xdebug_flag == 0){ // 没加锁  自己解析
 	             $parser->parse();
	             $html = $parser->getTraceHTML();
		}else if($xdebug_flag == 1){ // 加锁后台进程在解析   等待解析
		   while(true){
                       if($parser->redis->get($parser->prefix."flag") == 1){ // 还在解析
		       	    usleep(50000);
		       }else{
			   $count = $parser->redis->get($parser->prefix."0-c"); //孩子数量        
                           for($i = 1; $i <= $count; $i++){
                              $value = unserialize($parser->redis->get($parser->prefix."0-".$i));
                              $html = $html.$value[0];
                           }
                           break;
		       } 
		   }
		}
	    }else{
	        $count = $parser->redis->get($parser->prefix."0-c"); //孩子数量        
	        for($i = 1; $i <= $count; $i++){
	            $value = unserialize($parser->redis->get($parser->prefix."0-".$i));
	            $html = $html.$value[0];
	        }
	    }
	    $_SESSION['prefix'] = $parser->prefix;
	    unset($parser);
	    echo $html;
	}
}else if($flag == 3){ // 删除文件
	$delete_file = new Delete($_SERVER['REMOTE_ADDR'],FILE_PATH);
	$delete_file->filedelete();
	unset($_SESSION['prefix']);
}else if($flag == 4){ // 修改cookie
	setcookie("xdebug_status", $_POST['xdebug_status'], time()+1800);
}
/*
$data = xhprof_disable();
include_once "xhprof_lib/utils/xhprof_lib.php";
include_once "xhprof_lib/utils/xhprof_runs.php";
$objXhprofRun = new XHProfRuns_Default();
$run_id = $objXhprofRun->save_run($data, "xhprof");
*/
//var_dump($run_id);
