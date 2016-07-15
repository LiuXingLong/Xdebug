var stack = null;
var search_str = null;
//var set_time = null;
//初始化函数
$(function(){
    //事件代理  树形结构伸展
    $('div.d.main').on('click','span.glyphicon,span.name',function (e) { 
        var cl = $(this).attr("class"); 
        var clp = $(this).prev().attr("class");    
        var parent = $(this).parent().parent().next().attr("class");
        if(parent != null && (parent.split(" "))[0] == "d"){
            /* 已经请求过了 */
            if(cl == "name" && (clp == "glyphicon glyphicon-folder-close hide" || clp == "glyphicon glyphicon-folder-close")){
                //点击函数展开
                $(this).prev().toggleClass('hide');
                $(this).parent().parent().next().toggleClass('hide');
            }else if(cl == "glyphicon glyphicon-folder-close" || cl == "glyphicon glyphicon-folder-close hide"){
                //点击图标展开
                $(this).toggleClass('hide');
                $(this).parent().parent().next().toggleClass('hide');
            }
        }else{
            /* 发送请求获取数据 */
            var fid = $(this).parent().parent().attr("class");
            fid = (fid.split(" "))[1];
            //alert(fid);
            if(cl == "name" && clp == "glyphicon glyphicon-folder-close hide"){
                //点击函数展开
                search_tree(1,fid,$(this));                
                $(this).prev().toggleClass('hide');
            }else if(cl == "glyphicon glyphicon-folder-close hide"){
                //点击图标展开
                search_tree(1,fid,$(this));     
                $(this).toggleClass('hide');
            }          
        }
    }); 

    // 文件结构   
    $('div.main1').on('click','div.dr,span.glyphicon,span.name',function (e) {  
        var cl = $(this).attr("class");
        var clp = $(this).prev().attr("class");
        if(cl == "name"){
            //点击函数进入
            if(clp == "glyphicon glyphicon-folder-close hide"){
                // 进入下级目录
                var cid = $(this).parent().parent().attr("class");    
                cid = (cid.split(" "))[1];
                search_file(1,cid,$('div.main1'));
            }else if(clp == "glyphicon glyphicon-file" && stack == null){ 
                // 进入文件同级目录
                stack = true;
                var uid = $(this).parent().parent().attr("class");    
                uid = (uid.split(" "))[1];
                search_url(3,uid,$('div.main1'));
            }
        }else if(cl == "glyphicon glyphicon-folder-close hide"){   //点击图标进入
            // 进入下级目录
            var cid = $(this).parent().parent().attr("class");    
            cid = (cid.split(" "))[1];
            search_file(1,cid,$('div.main1'));
        }else if(cl == "glyphicon glyphicon-file" && stack == null){
            // 进入文件同级目录
            stack = true;
            var uid = $(this).parent().parent().attr("class");    
            uid = (uid.split(" "))[1];
            search_url(3,uid,$('div.main1'));
        }else if(cl == "glyphicon glyphicon-level-up"){
            // 进入上级目录    大于 0 可向上
            var pid = $('div.dr').next().attr("class");
            pid = (pid.split(" "))[1];  //查找这个id的父亲
            search_file(0,pid,$('div.main1'));
        }else if(cl == "glyphicon glyphicon-arrow-left"){
            //返回搜索结果
            $('div.main1').html(search_str);
            $("#stack_url").html("");
            stack = null;
            $("[data-toggle='tooltip']").tooltip();
        }
    });
    /* 参数伸展 */
    $('body').on('click','span.params, span.return',function (e) {
        if (e.target !== this) return;
        $(this).toggleClass('short');
        e.preventDefault();
        e.stopPropagation();
    });
    //  enter  查找 
    document.onkeydown = function(e){ 
        var ev = document.all ? window.event : e;
        if(ev.keyCode==13) {
            search();
         }
    }
    //开启关闭 xdebug
    if($.cookie('xdebug_status') == 0){
        $("#checkbox").prop("checked",false);
    }else if($.cookie('xdebug_status') == 1){
        $("#checkbox").prop("checked",true);
    }
    $('[for="checkbox"]').click(function(){
        //alert(document.getElementById("checkbox").checked);       
        var xdebug_status;
        if($("#checkbox").prop("checked")){
            xdebug_status = 0;
            $.cookie('xdebug_status', 0, {  path:'/', domain: ''});
        }else{
            xdebug_status = 1;
            $.cookie('xdebug_status', 1, {  path:'/', domain: ''});
        }     
        $.ajax({
            type: "POST",
            url: 'res/index.php',
            data: { flag:4 ,xdebug_status:xdebug_status },
            success: function (data) {
                
            }
        });
    });

    $('.js-example-basic-single').change(function(){
        //clearInterval(set_time);
          value = $(this).select2('val');
          $.cookie('xdebug_select', value, {  path:'/', domain: ''});
        //set_time = setInterval(refresh,5000);
    });

    // select2
    $(".js-example-basic-single").select2();
    refresh();
   //set_time = setInterval(refresh,5000);
});

// 刷新文件列表
function refresh(){
    $(".preloader").show();
    $.ajax({
        type: "POST",
        url: 'res/index.php',
        data: { flag:1 },
        success: function (data) {
            data = eval(data);
            $('#file').html(data[0]);
            $('code').text(data[1]);
            $(".preloader").hide();
            $(".js-example-basic-single").select2();
        }
    });
}

// 下载文件
function download(){
    value = $("#file").val();
    $('code').text("");
    $("#stack_url").html("");
    $('input.search').val("");
    $('div.d.main').html("");
    $('.main1').html("");
    if(value!==null){
        $(".preloader").show();
        $.ajax({
            type: "POST",
            url: 'res/index.php',
            data: { flag:2 ,filename:value},
            success: function (data) {     
		text = value.split("#");
                pattern = new RegExp(/(?:\d{4}-\d{2}-\d{2})|(?:\d{2}:\d{2}:\d{2})/g);
                time = text[1].match(pattern);
                $('code').text(time[0]+"_"+time[1]+"_"+text[2]);
                $('div.d.main').html(data);
                $("[data-toggle='tooltip']").tooltip();
                $(".preloader").hide();
            }
        });
    }
}

// 删除文件
function delete1(){
    var r=confirm("确认删除文件吗？");
    if(r==true){
        $.ajax({
            type: "POST",
            url: 'res/index.php',
            data: { flag:3 },
            success: function (data) {
                refresh();
                $('code').text("");
                $("#stack_url").html("");
                $('input.search').val("");
                $('div.d.main').html("");
                $('.main1').html("");
            }
        });       
    }   
}

// 查询函数
function search(){
    stack = null;
    var search = $('input.search').val().trim();
    $("#stack_url").html("");
    $('div.d.main').html("");
    $('.main1').html("");
    if(search.length >= 1){
        $(".preloader").show();
        $.ajax({
            type: "POST",
            url: 'res/Search.php',
            data: { flag:2 ,search:search},
            success: function (data) { 
                search_str = data;
                $('.main1').html(data);
                $("[data-toggle='tooltip']").tooltip();
                $(".preloader").hide();
            }
        });
    } 
}

// 查询孩子树  flag = 1   
function search_tree(flag,id,jquery){
   $.ajax({
        type: "POST",
        url: 'res/Search.php',
        data: { flag:flag ,id:id},
        success: function (data) { 
            data = eval(data);
            html ='<div class = "d">'+data[0]+'</div>';
            jquery.parent().parent().after(html);
            $("[data-toggle='tooltip']").tooltip(); 
        }
    });
}

// 查询文件   查找上级目录 flag = 0   查找下级目录 flag = 1
function search_file(flag,id,jquery){
    $.ajax({
        type: "POST",
        url: 'res/Search.php',
        data: { flag:flag ,id:id},
        success: function (data) { 
            data = eval(data);
            $("#stack_url").html(data[1]);
            if(flag == 0){
                //查询上一个目录
                if(data[0][0] == ">"){ 
                    html = '<div class="dr"><span class = "glyphicon glyphicon-arrow-left"></span></div'; // 这里没有漏掉的 ‘>’  是为了和后面的数据组合
                }else{
                    html = '<div class="dr"><span class = "glyphicon glyphicon-arrow-left"></span><span class = "glyphicon glyphicon-level-up"></span></div>';
                }
                jquery.html(html+data[0]);  
            }else{
                html = '<div class="dr"><span class = "glyphicon glyphicon-arrow-left"></span><span class = "glyphicon glyphicon-level-up"></span></div>';
                jquery.html(html+data[0]);
            }
            $("[data-toggle='tooltip']").tooltip();    
        }
    });
}

// 切换到文件目录下
function search_url(flag,id,jquery){
    $.ajax({
        type: "POST",
        url: 'res/Search.php',
        data: { flag:flag ,id:id},
        success: function (data) { 
            data = eval(data);
             $("#stack_url").html(data[1]);
            if(data[0][0] == ">"){
                html = '<div class="dr"><span class = "glyphicon glyphicon-arrow-left"></span></div'; // 这里没有漏掉的 ‘>’  是为了和后面的数据组合
            }else{
                html = '<div class="dr"><span class = "glyphicon glyphicon-arrow-left"></span><span class = "glyphicon glyphicon-level-up"></span></div>';
            }
            jquery.html(html+data[0]);
            $("[data-toggle='tooltip']").tooltip();   
        }
    });
}
// 控制台输出 console.log(search);
