<?php
if ($_SERVER['REQUEST_METHOD']!="POST") {
    echo "<script> window.location.href ='../index.html'</script>;";
    exit;
}
if(!isset($_SESSION)) {
    session_start();
}
if(!isset($_SESSION['prefix'])) {
    exit();
} 
require_once './conf.php';
require_once './XDebugParser.php';
$flag = $_POST['flag'];
$search = new Search($_SESSION['prefix'],REDIS_HOST,REDIS_PORT);
if($flag == 1){
	// 查孩子
	$id = $_POST['id'];
  $result = $search -> find_children($id);
  echo json_encode($result);
}else if($flag == 0){
	// 查父亲
	$id = $_POST['id'];
  $result = $search -> find_parent($id);
  echo json_encode($result);
}else if($flag == 2){
	// 查函数
   $name = $_POST['search'];
   $result = $search -> find_func($name);
   echo $result;
}else if($flag == 3){
  // 切换到路径
   $id = $_POST['id'];
   $result = $search -> find_url($id);
   echo json_encode($result);
}