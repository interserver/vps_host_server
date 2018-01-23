<?php

/**
* Used to detect business code cycle or prolonged obstruction and other issues
* If the business card is found dead, you can open the following declare (remove the // comment), and execute php start.php reload
* Then observe workerman.log for a period of time to see if there is a process_timeout exception
*/
//declare(ticks=1);

/**
* Chat the main logic
* Mainly onMessage onClose
*/
use \GatewayWorker\Lib\Gateway;

class Events {

   /**
	* When there is news
	* @param int $client_id
	* @param mixed $message
	*/
   public static function onMessage($client_id, $message) {
		// debug
		echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
		// Client is passed json data
		$message_data = json_decode($message, true);
		if(!$message_data)
			return ;
		// Depending on the type of business
		switch($message_data['type']) {
			// The client responds to the server's heartbeat
			case 'pong':
				return;
			// Client login message format: {type: login, name: xx, room_id: 1}, added to the client, broadcast to all clients xx into the chat room
			case 'login':
				// Determine whether there is a room number
				if(!isset($message_data['room_id']))
					throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
				// The room number nickname into the session
				$room_id = $message_data['room_id'];
				$client_name = htmlspecialchars($message_data['client_name']);
				$_SESSION['room_id'] = $room_id;
				$_SESSION['client_name'] = $client_name;
				// Get a list of all users in your room
				$clients_list = Gateway::getClientSessionsByGroup($room_id);
				foreach($clients_list as $tmp_client_id=>$item)
					$clients_list[$tmp_client_id] = $item['client_name'];
				$clients_list[$client_id] = $client_name;
				// Broadcast to all clients in the current room, xx into the chat room message {type:login, client_id:xx, name:xx}
				$new_message = array('type'=>$message_data['type'], 'client_id'=>$client_id, 'client_name'=>htmlspecialchars($client_name), 'time'=>date('Y-m-d H:i:s'));
				Gateway::sendToGroup($room_id, json_encode($new_message));
				Gateway::joinGroup($client_id, $room_id);
				// Send the user list to the current user
				$new_message['client_list'] = $clients_list;
				Gateway::sendToCurrentClient(json_encode($new_message));
				return;

			// client speaks message: {type:say, to_client_id:xx, content:xx}
			case 'say':
				// illegal request
				if(!isset($_SESSION['room_id']))
					throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
				$room_id = $_SESSION['room_id'];
				$client_name = $_SESSION['client_name'];
				// private chat
				if($message_data['to_client_id'] != 'all') {
					$new_message = [
						'type'=>'say',
						'from_client_id'=>$client_id,
						'from_client_name' =>$client_name,
						'to_client_id'=>$message_data['to_client_id'],
						'content'=>"<b>对你说: </b>".nl2br(htmlspecialchars($message_data['content'])),
						'time'=>date('Y-m-d H:i:s'),
					];
					Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));
					$new_message['content'] = "<b>你对".htmlspecialchars($message_data['to_client_name'])."说: </b>".nl2br(htmlspecialchars($message_data['content']));
					return Gateway::sendToCurrentClient(json_encode($new_message));
				}
				$new_message = [
					'type'=>'say',
					'from_client_id'=>$client_id,
					'from_client_name' =>$client_name,
					'to_client_id'=>'all',
					'content'=>nl2br(htmlspecialchars($message_data['content'])),
					'time'=>date('Y-m-d H:i:s'),
				];
				return Gateway::sendToGroup($room_id ,json_encode($new_message));
		}
   }

   /**
	* When the client is disconnected
	* @param integer $client_id client id
	*/
   public static function onClose($client_id) {
	   // debug
	   echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
	   // Remove from the client's list of rooms
	   if(isset($_SESSION['room_id'])) {
		   $room_id = $_SESSION['room_id'];
		   $new_message = ['type'=>'logout', 'from_client_id'=>$client_id, 'from_client_name'=>$_SESSION['client_name'], 'time'=>date('Y-m-d H:i:s')];
		   Gateway::sendToGroup($room_id, json_encode($new_message));
	   }
   }

}
