<?php
// ini_set("display_errors","on");
// error_reporting(E_ALL);
ini_set("memory_limit","256M");
$config['host'] = "127.0.0.1";  // redis 服务器 IP  192.168.204.26
$config['port'] = "6379";            // redis 服务器 端口
$config['path'] = "/tmp/xdebug";     // 扫描文件目录
$config['time'] = 3600;              // redis 过期时间
define("REDIS_HOST",$config['host']);
define("REDIS_PORT",$config['port']);
define("REDIS_TIME",$config['time']);
define("FILE_PATH",$config['path']);
date_default_timezone_set('Asia/Shanghai');

$Redis = new Redis();
$Redis->connect(REDIS_HOST,REDIS_PORT);

class XDebugParser
{
    protected $handle;
    public $redis;
    public $prefix;  //前缀
    public $error;   //报错标志
    protected $time; //缓存时间
    protected $data = array(); // $data[1][0]  1的父亲   $data[1]["key"][0] 1key值  $data[1]["key"][1]  1key孩子
    protected $functions = array();
    public function __construct($fileName,$redis,$time)
    {
        $this->redis = $redis;
        $this->time = $time; // 设置过期时间
        $this->prefix = substr(md5($fileName),16)."_";  // 前缀
        $this->handle = fopen($fileName, 'r');
        $this->error = 0;
        if (!$this->handle) {
            $this->error = 1;
            //throw new Exception("Can't open '$fileName'");
        }else{
       	    $header1 = fgets($this->handle);
            $header2 = fgets($this->handle);
	}
        if (!preg_match('@Version: [23].*@', $header1) || !preg_match('@File format: [2-4]@', $header2)) {
            $this->error = 1;
	    //throw new Exception("This file is not an Xdebug trace file made with format option '1' and version 2 to 4.");
        }
    }

    public function parse()
    {
        $this->redis->set($this->prefix."flag",1);    // 加锁
        $c = 0;
        $size = fstat($this->handle); // 取得统计信息
        $size = $size['size'];
        $read = 0;
        while (!feof($this->handle)) {
            $buffer = fgets($this->handle, 4096);
            $read += strlen($buffer); //文件大小
            $this->parseLine($buffer);
            $c++; // 行数
        }
    }

    function parseLine($line)
    {
        $parts = explode("\t", $line); // 将每行数据分割
        if (count($parts) < 5) {
            return;
        }

        $funcNr = (int) $parts[1];
        $type = $parts[2];

        switch ($type) {
            case '0': // Function enter
                $this->functions[$funcNr] = array();
                $this->functions[$funcNr]['depth'] = (int) $parts[0];
                $this->functions[$funcNr]['time.enter'] = $parts[3];
                $this->functions[$funcNr]['memory.enter'] = $parts[4];
                $this->functions[$funcNr]['name'] = $parts[5];
                $this->functions[$funcNr]['internal'] = !(bool) $parts[6];
                $this->functions[$funcNr]['file'] = $parts[8];
                $this->functions[$funcNr]['line'] = $parts[9];
                if ($parts[7]) {
                    $this->functions[$funcNr]['params'] = array($parts[7]);  // 文件路径
                } else {
                    $this->functions[$funcNr]['params'] = array_slice($parts, 11);
                }

                // these are set later
                $this->functions[$funcNr]['time.exit'] = '';
                $this->functions[$funcNr]['memory.exit'] = '';
                $this->functions[$funcNr]['time.diff'] = '';
                $this->functions[$funcNr]['memory.diff'] = '';
                $this->functions[$funcNr]['return'] = '';
                break;
            case '1': // Function exit
                $this->functions[$funcNr]['time.exit'] = $parts[3];
                $this->functions[$funcNr]['memory.exit'] = $parts[4];
                $this->functions[$funcNr]['time.diff'] = $this->functions[$funcNr]['time.exit'] - $this->functions[$funcNr]['time.enter'];
                $this->functions[$funcNr]['memory.diff'] = $this->functions[$funcNr]['memory.exit'] - $this->functions[$funcNr]['memory.enter'];
                break;
            case 'R'; // Function return
                $this->functions[$funcNr]['return'] = $parts[5];
                break;
        }
        //   depth  name  internal  file  line  params   time.diff    memory.diff
    }
    function getTraceHTML()
    {
        $id = 0;
        $level = 0; 
        $stack = array();
        $fid = null;
        $this->redis->multi(Redis::PIPELINE);
        foreach ($this->functions as $func) {                         
            // depth wrapper
            if ($func['depth'] > $level) {
                for ($i = $level; $i < $func['depth']; $i++) {  
                   array_push($stack,$id);                     
                   if($id==0){                            
                        $this->data[$id][0] = null;  // 孩子的父亲
                        $this->redis->set($this->prefix.$id."-0",0);
                        $this->redis->expire($this->prefix.$id."-0",$this->time);                      
                        // echo '<div class="d main" id="'.$id++.'">';
                   } else{
                        $count = count($this->data[$fid]) - 1;
                        $this->data[$id][0] = $fid."-".$count;  //   孩子的父亲   父亲层 $fid
                        $this->redis->set($this->prefix.$id."-0",$fid."-".$count); //孩子父亲
                        $this->redis->expire($this->prefix.$id."-0",$this->time);

                        $this->data[$fid][$count][2] = $id; // 父亲的孩子
                        $this->data[$fid][$count][0] = str_replace("glyphicon-file","glyphicon-folder-close hide",$this->data[$fid][$count][0]);    
                        $this->redis->set($this->prefix.$fid."-".$count,serialize($this->data[$fid][$count])); //父亲的孩子
                        $this->redis->expire($this->prefix.$fid."-".$count,$this->time);
                        // echo '<div class="d hide" id="'.$id++.'">';
                   }
                   $fid = $id;
                   $id++;
                }
            } elseif ($func['depth'] < $level) {
                for ($i = $func['depth']; $i < $level; $i++) {                         
                    $count = count($this->data[$fid]) - 1;                  
                    $this->redis->set($this->prefix.$fid."-c",$count); // 孩子数量   
                    $this->redis->expire($this->prefix.$fid."-c",$this->time);    
                    // $this->redis->get($fid."-c");                   
                    array_pop($stack);
                    $fid = end($stack);
                    // echo '</div>';
                }
            }  

            $level = $func['depth']; // 当前层数

            $count = count($this->data[$fid]);
            $str = "";

            $class = 'f '.$fid."-".$count;
            if ($func['internal']) {
                $class .= ' i';
            }      
            $str = $str.'<div class="' . $class . '">';

            $str = $str.'<div class="func">';
            $str = $str.'<span class="glyphicon glyphicon-file"></span>';        
            $str = $str.'<span class="name">' . htmlspecialchars($func['name']) . '</span>';
            $str = $str.'(<span class="params short">' . htmlspecialchars(join(",", $func['params'])) . '</span>) ';
            if ($func['return'] !== '') {
                $str = $str.'→ <span class="return short">' . htmlspecialchars($func['return']) . '</span>';
            }
            $str = $str.'</div>';

            $str = $str.'<div class="data">';
            $str = $str.'<span class="glyphicon file-info-sign" data-toggle="tooltip" data-placement="top" title="'.htmlspecialchars($func['file'].':'.$func['line']).'">'.htmlspecialchars(basename($func['file']).':'.$func['line']).'</span>';
            $str = $str.'<span class="timediff">' . sprintf('%f', $func['time.diff']) . '</span>';
            $str = $str.'<span class="memorydiff">' . sprintf('%d', $func['memory.diff']) . '</span>';
            $str = $str.'<span class="time">' . sprintf('%f', $func['time.enter']) . '</span>';
            $str = $str.'</div>';

            $str = $str.'</div>';

            $this->data[$fid][$count][0] = $str;
            $this->data[$fid][$count][1] = $func['name'];

            $this->redis->sadd($this->prefix."func-name",$func['name']);        //所有函数名集合
            $this->redis->sadd($this->prefix.$func['name'],$fid."-".$count);    //函数名对应的下标
            $this->redis->set($this->prefix.$fid."-".$count,serialize($this->data[$fid][$count]));  //单节点值
            $this->redis->expire($this->prefix.$fid."-".$count,$this->time);
        }
        if ($level > 0) {
            for ($i = 0; $i < $level; $i++) {
                $fid = end($stack);
                array_pop($stack);
                $count = count($this->data[$fid]) - 1;                  
                $this->redis->set($this->prefix.$fid."-c",$count); // 孩子数量
                $this->redis->expire($this->prefix.$fid."-c",$this->time);
                // $this->redis->get($fid."-c");
                // echo '</div>';
            }
        }
        //var_dump($this->redis->smembers("func-name"));
        //var_dump($this->redis->smembers("require_once"));
        $this->redis->exec();
        
        //设置集合过期时间
        $func_name = $this->redis->smembers($this->prefix."func-name");
        $count = count($func_name);

        $this->redis->multi(Redis::PIPELINE);
        for($i = 0; $i < $count; $i++){
            $this->redis->expire($this->prefix.$func_name[$i],$this->time); //设置包含id的函数名集合过期时间
        }        
        $this->redis->expire($this->prefix."func-name",$this->time-20); //设置所有函数名的集合过期时间   提前过期 保证其他的未过期
        $this->redis->set($this->prefix."flag",0);    // 解锁
        $this->redis->expire($this->prefix."flag",$this->time); 
        $this->redis->exec();
    }
    public function __destruct(){
        unset($this->handle,$this->redis,$this->prefix,$this->time,$this->data,$this->functions); //销毁变量、释放内存
    }
}

class ScanFile
{   
    protected $path;
    protected $redis;
    protected $file_info;
    protected $file_count;
    public function __construct($path,$redis){   
        $this->path = $path;
        $this->redis = $redis;
    }
    public function fileinfo($path){       
        $d=dir($path);   
        while(false!==($entry=$d->read())){
           if($entry=="."||$entry==".."||$entry=="\\") continue;
           if(is_dir("$path/$entry")){
              $this->fileinfo("$path/$entry");
           }else{
               $entry."\n";        
               $this->file_info[$this->file_count]="$path/$entry";
               $this->file_count++;
           }
        }
        $d->close();
    }
    public function tails($filename,$lines = 10){ 
        $fp = fopen($filename, "r");   
        $pos = -2;   
        $t = " "; 
        $data = "";   
        while ($lines > 0) { 
            while ($t != "\n") { 
                if(fseek($fp, $pos, SEEK_END)==-1){ 
                    $lines = 0; //到达文件头了，不再输出下一行 
                    $t = "\n"; //跳出寻找换行的while                         
                    fseek($fp, $pos+1, SEEK_END); //从bof右移一位回到文件开头    
                }else{ 
                    $t = fgetc($fp);           
                    $pos --; 
                }       
            }       
            $t = " ";       
            $data = fgets($fp);   
            if(stripos($data,"TRACE END")!==false){
                return 1;       
            }
            $lines --;   
        }   
        fclose ($fp);   
        return 0; 
    }
    public function scan(){
        while(true){
            $t1 =  time();
            $this->file_count = 0;
            $this->file_info = array();
            $this->fileinfo($this->path);
            for($i = 0; $i < $this->file_count; $i++){
                $parser = new XDebugParser($this->file_info[$i],$this->redis,REDIS_TIME);
                if($parser->error == 1 || $this->tails($this->file_info[$i]) == 0 ){
                    unset($parser);
                    continue;
                }
                if($parser->redis->exists($parser->prefix."func-name") == 0){  // 没有缓存                
                    if($parser->redis->get($parser->prefix."flag") == 0){ // 没有加锁
	            	$parser->parse();
                    	$parser->getTraceHTML();
                        echo $this->file_info[$i]."\n";
		    }	
                }
            //  echo $this->file_info[$i]."\n";
                unset($parser);
            }
            echo "完成!\n";
            $t2 = time() - $t1;
	    echo $t2."\n";
            break;
            // echo "耗时：".date("Y-m-d H:i:s",$t2 - $t1)."\n";
            // sleep(5);
        }
    }
}
$scanfile = new ScanFile(FILE_PATH,$Redis);
$scanfile->scan();
