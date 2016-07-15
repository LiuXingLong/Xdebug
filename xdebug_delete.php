<?php
$config['time'] = 3600;              // 删除时间间隔
$config['path'] = "/tmp/xdebug";     // 删除文件目录
define("FILE_PATH",$config['path']);
define("FILE_TIME",$config['time']);
date_default_timezone_set('Asia/Shanghai');

class deletexdebug
{	
	protected $path;
	protected $time;
	protected $info;
	protected $count;
	public function __construct($path,$time){	
		$this->path = $path;
		$this->time = $time;
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
			preg_match("/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}/",$this->info[$i],$date);
			$date[0] = str_ireplace("T"," ",$date[0]);
			$d1 = new DateTime($date[0]);
			$t1 = $d1->getTimestamp();
			$t2 = time();
			if($t2 - $t1 > $this->time){
				unlink($this->info[$i]);
			}
		}
	}
}
$dxdebug = new deletexdebug(FILE_PATH,FILE_TIME); // 存储的文件目录  自动删除时间  秒
$dxdebug->filedelete();