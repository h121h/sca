<?php

// Usage : 
// curl -X POST -k -u username:api_key 'https:///api.slayercloud.com/listinfomulti.php' --data '{"location":["<location1>","<location2>",...],"type" : "<TYPE>", "email" : "<EMAIL>", "environment_id": "<ENVIRONMENT_ID>"}'

// Set include
	set_include_path('/var/www/html/common/');

	if(!empty($_GET['test'])) {
		$test = true;
		include "consqlp_test.php";
	}
	else {
		$test = false;
		include "consqlp.php";
	}
	
	include "commonfunctions.php";
	
// Authenticate User
	$username  = getAuthINLINE(); 
	if ($username == "error") {
		echo '{"status" : "error" , "details" : "failed authentication"}';
		logit("user error", "failed authentication");
		exit;
	} else {
		$username = $_SERVER['PHP_AUTH_USER'];
		$api_key = $_SERVER['PHP_AUTH_PW'];
	}
	
// Get UserID
	$ssql = "select id from slayer_api.users where username = '{$username}'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = check_SQL($rows[0],'id');	

// Get JSON
	$json = file_get_contents('php://input');
	logit("INFO","JSON : $json");

// Check if JSON is valid
	$check_json = json_decode($json);
	if($check_json === null) {
		exit_now("Invalid JSON ");
	}

// check JSON for SQL injection
	$json = sanitize_json($json);
	$json_array=json_decode($json,true);								// Break the JSON out into an array

// Get variables
	logit("INFO","Reading data from JSON ");
	$location_id = check_array($json_array,'location_id');	
	$message_id = check_array($json_array,"message_id");
	$function_name = check_array($json_array,"function_name");
	$json_forward = check_array($json_array,"json_forward");

	$json_errors = array(); $json_errors[0] = "";
	$error_index = 0;
	
// Validate passed variables
	if(empty($location_id)) {
		logit("ERROR","No location_id specified.");
		$json_errors[$error_index++] = "No location_id specified.";
	}
	
	if(empty($message_id)) {
		logit("ERROR","No message_id specified.");
		$json_errors[$error_index++] = "No message_id specified.";
	}	
	
	if(empty($function_name)) {
		logit("ERROR","No function_name specified.");
		$json_errors[$error_index++] = "No function_name specified.";
	}	
	
	if(empty($json_forward)) {
		logit("ERROR","No json_forward specified.");
		$json_errors[$error_index++] = "No json_forward specified.";
	}
	
// Validation for top level variables

	if($json_errors[0] <> "") {
		$myObj = new stdClass();
		$myObj->status = "error";
		$myObj->json_errors = $json_errors;
		$myJSON = json_encode($myObj);
		if(!empty($message_id)) {
			update_message($conn, $message_id, $myJSON);
		}
		exit_now($json_errors);
	}

// get location info from db
	$ssql = "SELECT * from location WHERE id = '{$location_id}'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get location info");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to get location info");
		$myObj = new stdClass();
		$myObj->status = "error";
		$myObj->json_errors = "invalid location";
		$myJSON = json_encode($myObj);
		update_message($conn, $message_id, $myJSON);
		exit_now("invalid location");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	if(count($rows) < 1) {
		$json_errors[$error_index++] = "Invalid Location";
		logit("ERROR","Invalid Location.");
		$myObj = new stdClass();
		$myObj->status = "error";
		$myObj->json_errors = "invalid location";
		$myJSON = json_encode($myObj);
		update_message($conn, $message_id, $myJSON);
		exit_now("invalid location");
	} else {
		$engine_id = check_SQL($rows[0],'id');
		$engine_ip = check_SQL($rows[0],'engine_ip');
		$engine_port = check_SQL($rows[0],'port');
		$state = check_SQL($rows[0],'state');
	}
	
// if state is not online, report state, else send to engine
	if($state <> "online") {
		logit("info","Site not online.");
		$myObj = new stdClass();
		if(empty($state)) {
			$myObj->status = "error";
			$myObj->json_errors = "invalid state";
			$myJSON = json_encode($myObj);
			update_message($conn, $message_id, $myJSON);
			exit_now("invalid state");
		} else {
			$myObj->status = $state;
			$myObj->details = "site not currently available";
			$myJSON = json_encode($myObj);
			update_message($conn, $message_id, $myJSON);
			echo $myJSON;
			exit;
		}
	}
	
// Direct the call to the proper engine and update message with result
	$output = issue_command($username, $api_key, $engine_ip, $engine_port, $function_name, $json_forward);
	update_message($conn, $message_id, $output);
	logit("INFO","command_forward API completed");	
	
//-------------------------- Functions ----------------------------
function update_message($conn, $message_id, $json) {
// Log the request
	$ssql = "UPDATE messages SET status = 'ready', json_return = '{$json}' WHERE id = '{$message_id}'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log message update");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log message update");
	}	
}	

function issue_command($username, $api_key, $engine_ip, $engine_port, $function, $json_array) {
	// Direct the call to the proper engine
	$json_sent = json_encode($json_array);
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/{$function}.php' --data '{$json_sent}'";
	logit("CMD", $cmdd);
	return shell_exec($cmdd);
}
?>