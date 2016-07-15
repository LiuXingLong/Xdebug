<?php
class XDebugParser
{
    protected $handle;
    public $redis;
    public $prefix;  //前缀
    protected $time; //缓存时间
    protected $data = array(); // $data[1][0]  1的父亲   $data[1]["key"][0] 1key值  $data[1]["key"][1]  1key孩子
    protected $functions = array();

    public function __construct($fileName,$redis_host,$redis_port,$redis_time)
    {
        $this->redis = new Redis();
        $this->redis->connect($redis_host,$redis_port);
        $this->time = $redis_time;     // 设置过期时间为
        $this->prefix = substr(md5($fileName),16)."_";  // 前缀
        //$this->redis->flushAll(); //清空数据
        $this->handle = fopen($fileName, 'r');
        if (!$this->handle) {
            throw new Exception("Can't open '$fileName'");
        }
        $header1 = fgets($this->handle);
        $header2 = fgets($this->handle);
        if (!preg_match('@Version: [23].*@', $header1) || !preg_match('@File format: [2-4]@', $header2)) {
            throw new Exception("This file is not an Xdebug trace file made with format option '1' and version 2 to 4.");
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

    function getTrace()
    {
        return $this->functions;
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
        	
        // 返回数据
        $html = "";
        $count = count($this->data[0]);
        for($i = 1; $i < $count; $i++){
            $html = $html.$this->data[0][$i][0];
        }
        return $html;
    }
    public function __destruct(){
        unset($this->handle,$this->redis,$this->prefix,$this->time,$this->data,$this->functions); //销毁变量、释放内存
    }
}
class Search
{
    protected $name; //搜索函数名
    protected $redis;
    protected $prefix;  //前缀
    protected $func_name;
    protected $func_id;
    protected $func_value;
    public function __construct($prefix,$redis_host,$redis_port)
    {
        $this->func_id = array();
        $this->redis = new Redis();
        $this->prefix = $prefix; //前缀
        $this->redis->connect($redis_host,$redis_port); 
    }
    /* 查函数 */
    function find_func($search){ 
        $flag = 0;
        $this->name = $search;
        $func = $this->redis->smembers($this->prefix."func-name");  // 所有的函数名称
        $count = count($func);        
        //标记是否类方法
        if(stripos($this->name,"->")!==false) {
            $flag = 1; 
            $name1 = str_replace("->","::",$this->name);
        }else if(stripos($this->name,"::")!==false){
            $flag = 2;
            $name1 = str_replace("::","->",$this->name);
        }
        //获取搜到的方法名
        for($i = 0 ; $i < $count; $i++){
            if($flag == 0){
                if(stripos($func[$i],$this->name) === 0 ) { // && stripos($func[$i],"::")===false && stripos($func[$i],"->")===false){
                    //搜方法   只搜方法  搜出类名
                    $this->func_name[] = $func[$i];
                }else if(stripos($func[$i],"::".$this->name)!==false || stripos($func[$i],"->".$this->name)!==false){
                    //搜方法   搜出类方法
                    $this->func_name[] = $func[$i];
                }
            }else{
                if(stripos($func[$i],$this->name)!==false || stripos($func[$i],$name1)!==false){
                    $this->func_name[] = $func[$i];
                }
            }
        }
   
        //获取方法名的 id
        $count = count($this->func_name);
        for($i = 0; $i < $count; $i++){
            $func_id1 = $this->redis->smembers($this->prefix.$this->func_name[$i]);
            $this->func_id = array_merge($this->func_id,$func_id1);
        }
        //获取id的值
        $html = "";
        $count = count($this->func_id);
        for($i = 0; $i < $count; $i++){
            $this->func_value[$i] = unserialize($this->redis->get($this->prefix.$this->func_id[$i]));
            $html = $html.$this->func_value[$i][0];
        }
        return $html;
    }
    /* 查孩子 返回 以$key 为父亲的所有孩子函数 */
    function find_children($key){
        $fun = unserialize($this->redis->get($this->prefix.$key));
        $fun_cid = $fun[2]; //孩子 id
        $count = (int)$this->redis->get($this->prefix.$fun_cid."-c"); // 孩子数量
        $html = "";
        for($i = 1; $i <= $count; $i++){
            $value = unserialize($this->redis->get($this->prefix.$fun_cid."-".$i));
            $html = $html.$value[0]; 
        }
        $result[0] = $html;
        $result[1] = $this->stack($fun_cid."-0",5);
        return $result;
    }
    /*  查父亲  返回 以$key 为父亲的所有同级函数 */
    function find_parent($key){
        $html = "";
        $key = explode('-',$key,2);
        $key = $key[0]."-0";
        $fun_pid = $this->redis->get($this->prefix.$key); // 父亲 id
        $stack = $this->stack($fun_pid,5);
        $fun_pid = explode('-',$fun_pid,2);
        $fun_pid = $fun_pid[0]; // 父亲层 id 
        if($fun_pid == "0"){
            $html = ">";  //表示已经向上到了最高层了
        }
        $count = (int)$this->redis->get($this->prefix.$fun_pid."-c"); // 父亲数量
        for($i = 1; $i <= $count; $i++){
            $value = unserialize($this->redis->get($this->prefix.$fun_pid."-".$i));
            $html = $html.$value[0]; 
        }
        $result[0] = $html;
        $result[1] = $stack;
        return $result;
    }
    /*  切换到路径  返回 $key 同级的所有函数*/
    function find_url($key){
        $stack = $this->stack($key,5);
        $key = explode('-',$key,2);
        $fun_uid = $key[0];  // 获取路劲
        $html = "";
        if($fun_uid == "0"){
            $html = ">"; //切换到的路径为最高层了
        }
        $count = (int)$this->redis->get($this->prefix.$fun_uid."-c");  //获取路劲下孩子数量
        for($i = 1; $i <= $count; $i++){
            $value = unserialize($this->redis->get($this->prefix.$fun_uid."-".$i));
            $html = $html.$value[0]; 
        }
        $result[0] = $html;
        $result[1] = $stack;
        return $result;
    }
    /* 查询堆栈函数  返回ID为 $id 的向上 $count 层堆栈函数 */
    function stack($id,$count){
        $id = explode('-',$id,2);
        $id = $id[0];
        $stack = "";
        for($i = 0 ;$i < $count; $i++){
            if($id == 0) break;
            $pid = $this->redis->get($this->prefix.$id."-0"); // 父亲 id 
            $name = unserialize($this->redis->get($this->prefix.$pid));
            $stack = '》<span id = '.$pid.' style="color:red;font-weight:bold">'.$name[1]."</span>".$stack;
            $pid = explode('-',$pid,2);
            $id = $pid[0];
        }
        return  $stack;
    } 
}
class Delete
{
    protected $ip;
    protected $path;
    protected $info;
    protected $count;
    public function __construct($ip,$path){  
        $this->ip = $ip; 
        $this->path = $path;
    }
    public function wenjian($path){
        $d=dir($path);  
        while(false!==($entry=$d->read())){
           if($entry=="."||$entry==".."||$entry=="\\") continue;
           if(is_dir("$path/$entry")){
              $this->wenjian("$path/$entry");
           }else{
               $entry."\n";        
               $this->info[$this->count]="$path/$entry";
               $this->count++;
           }
        }
        $d->close();
    }
    public function filedelete(){
        $this->count = 0;
        $this->info = array();
        $this->wenjian($this->path);
        for($i = 0; $i < $this->count; $i++){
            if(stripos($this->info[$i],$this->ip)!==false){
                unlink($this->info[$i]);
            }
        }
    }
}
