<html><head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <title>workerman-chat PHP Chat Websocket (HTLM5 / Flash) + PHP multi-process socket real-time push technology</title>
  <link href="/css/bootstrap.min.css" rel="stylesheet">
	<link href="/css/jquery-sinaEmotion-2.1.0.min.css" rel="stylesheet">
	<link href="/css/style.css" rel="stylesheet">

  <script type="text/javascript" src="/js/swfobject.js"></script>
  <script type="text/javascript" src="/js/web_socket.js"></script>
  <script type="text/javascript" src="/js/jquery.min.js"></script>
	<script type="text/javascript" src="/js/jquery-sinaEmotion-2.1.0.min.js"></script>

  <script type="text/javascript">
	if (typeof console == "undefined") {    this.console = { log: function (msg) {  } };}
	// If the browser does not support websocket, will use this flash automatically simulate websocket protocol, this process is transparent to developers
	WEB_SOCKET_SWF_LOCATION = "/swf/WebSocketMain.swf";
	// Open flash websocket debug
	WEB_SOCKET_DEBUG = true;
	var ws, name, client_list={};

	// Connect to the server
	function connect() {
	   // create websocket
	   ws = new WebSocket("ws://"+document.domain+":7272");
	   // When the socket connection is open, enter the user name
	   ws.onopen = onopen;
	   // When there is a message according to the type of message shows different information
	   ws.onmessage = onmessage;
	   ws.onclose = function() {
		  console.log("连接关闭，定时重连");
		  connect();
	   };
	   ws.onerror = function() {
		  console.log("出现错误");
	   };
	}

	// When there is a message according to the type of message shows different information......
	function onopen() {
		if(!name) {
			show_prompt();
		}
		// log in
		var login_data = '{"type":"login","client_name":"'+name.replace(/"/g, '\\"')+'","room_id":"<?php echo isset($_GET['room_id']) ? $_GET['room_id'] : 1?>"}';
		console.log("websocket handshake successfully, send login data: "+login_data);
		ws.send(login_data);
	}

	// When the server sends a message
	function onmessage(e) {
		console.log(e.data);
		var data = JSON.parse(e.data);
		switch(data['type']){
			// Server ping client
			case 'ping':
				ws.send('{"type":"pong"}');
				break;;
			// Log in to update the user list
			case 'login':
				//{"type":"login","client_id":xxx,"client_name":"xxx","client_list":"[...]","time":"xxx"}
				say(data['client_id'], data['client_name'],  data['client_name']+' 加入了聊天室', data['time']);
				if(data['client_list']) {
					client_list = data['client_list'];
				} else {
					client_list[data['client_id']] = data['client_name'];
				}
				flush_client_list();
				console.log(data['client_name']+"登录成功");
				break;
			// speaking
			case 'say':
				//{"type":"say","from_client_id":xxx,"to_client_id":"all/client_id","content":"xxx","time":"xxx"}
				say(data['from_client_id'], data['from_client_name'], data['content'], data['time']);
				break;
			// User exits to update user list
			case 'logout':
				//{"type":"logout","client_id":xxx,"time":"xxx"}
				say(data['from_client_id'], data['from_client_name'], data['from_client_name']+' 退出了', data['time']);
				delete client_list[data['from_client_id']];
				flush_client_list();
		}
	}

	// enter your name
	function show_prompt(){
		name = prompt('Enter your name:', '');
		if(!name || name=='null'){
			name = 'Guests';
		}
	}

	// submit the conversation
	function onSubmit() {
	  var input = document.getElementById("textarea");
	  var to_client_id = $("#client_list option:selected").attr("value");
	  var to_client_name = $("#client_list option:selected").text();
	  ws.send('{"type":"say","to_client_id":"'+to_client_id+'","to_client_name":"'+to_client_name+'","content":"'+input.value.replace(/"/g, '\\"').replace(/\n/g,'\\n').replace(/\r/g, '\\r')+'"}');
	  input.value = "";
	  input.focus();
	}

	// Refresh user list box
	function flush_client_list(){
		var userlist_window = $("#userlist");
		var client_list_slelect = $("#client_list");
		userlist_window.empty();
		client_list_slelect.empty();
		userlist_window.append('<h4>Online Users</h4><ul>');
		client_list_slelect.append('<option value="all" id="cli_all">Everyone</option>');
		for(var p in client_list){
			userlist_window.append('<li id="'+p+'">'+client_list[p]+'</li>');
			client_list_slelect.append('<option value="'+p+'">'+client_list[p]+'</option>');
		}
		$("#client_list").val(select_client_id);
		userlist_window.append('</ul>');
	}

	// Speaking
	function say(from_client_id, from_client_name, content, time){
		// Analysis of Sina microblogging picture
		content = content.replace(/(http|https):\/\/[\w]+.sinaimg.cn[\S]+(jpg|png|gif)/gi, function(img){
			return "<a target='_blank' href='"+img+"'>"+"<img src='"+img+"'>"+"</a>";}
		);
		// resolve the url
		content = content.replace(/(http|https):\/\/[\S]+/gi, function(url){
			if(url.indexOf(".sinaimg.cn/") < 0)
				return "<a target='_blank' href='"+url+"'>"+url+"</a>";
			else
				return url;
		}
		);
		$("#dialog").append('<div class="speech_item"><img src="http://lorempixel.com/38/38/?'+from_client_id+'" class="user_icon" /> '+from_client_name+' <br> '+time+'<div style="clear:both;"></div><p class="triangle-isosceles top">'+content+'</p> </div>').parseEmotion();
	}

	$(function(){
		select_client_id = 'all';
		$("#client_list").change(function(){
			 select_client_id = $("#client_list option:selected").attr("value");
		});
		$('.face').click(function(event){
			$(this).sinaEmotion();
			event.stopPropagation();
		});
	});


  </script>
</head>
<body onload="connect();">
	<div class="container">
		<div class="row clearfix">
			<div class="col-md-1 column">
			</div>
			<div class="col-md-6 column">
			   <div class="thumbnail">
				   <div class="caption" id="dialog"></div>
			   </div>
			   <form onsubmit="onSubmit(); return false;">
					<select style="margin-bottom:8px" id="client_list">
						<option value="all">Everyone</option>
					</select>
					<textarea class="textarea thumbnail" id="textarea"></textarea>
					<div class="say-btn">
						<input type="button" class="btn btn-default face pull-left" value="Expression" />
						<input type="submit" class="btn btn-default" value="Posted" />
					</div>
			   </form>
			   <div>
			   &nbsp;&nbsp;&nbsp;&nbsp;<b>Room List:</b>(Currently in &nbsp; room<?php echo isset($_GET['room_id'])&&intval($_GET['room_id'])>0 ? intval($_GET['room_id']):1; ?>）<br>
			   &nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=1">Room 1</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=2">Room 2</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=3">Room 3</a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="/?room_id=4">Room 4</a>
			   <br><br>
			   </div>
			   <p class="cp">PHP multi-process + Websocket (HTML5 / Flash) + PHP Socket real-time push technology&nbsp;&nbsp;&nbsp;&nbsp;Powered by <a href="http://www.workerman.net/workerman-chat" target="_blank">workerman-chat</a></p>
			</div>
			<div class="col-md-3 column">
			   <div class="thumbnail">
				   <div class="caption" id="userlist"></div>
			   </div>
			   <a href="http://workerman.net:8383" target="_blank"><img style="width:252px;margin-left:5px;" src="/img/workerman-todpole.png"></a>
			</div>
		</div>
	</div>
</body>
</html>
