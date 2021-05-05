<?php

// Usage : 
// curl -X POST -k -u username:api_key 'https://api.slayercloud.com/listinfomulti.php' --data '{"location":["<location1>","<location2>",...],"type" : "<TYPE>", "email" : "<EMAIL>", "environment_id": "<ENVIRONMENT_ID>"}'

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
	$ssql = "select id, email, role from slayer_api.users where username = '{$username}' and disabled is null";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	if(count($rows) > 0) {
		$user_id = check_SQL($rows[0],'id');	
		$email = check_SQL($rows[0],'email');	
		$role = check_SQL($rows[0],'role');
		$default_location_id = check_SQL($rows[0],'default_location_id');
	} else {
		logit("ERROR","No user id found for username {$username}");
		exit_now("No user id found for username {$username}");
	}

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
	$location = check_array($json_array,'location');
	$email_in = check_array($json_array,"email");
// if the user is slayer, and an email has been passed in, use that. Otherwise, use the email of the person that authenticated	
	if ($role == "admin" and $email != "") {
		$json_array['email'] = $email_in;
	} else {
		$json_array['email'] = $email;
	}
	
	$json_send = array();												// Array to store the data
	$json_send['json_forward'] = $json_array;
	$json_send["function_name"] = 'listinfo';
	
// Build location where from location info passed
	if(empty($location)) {
		if(!empty($email_in)) {
			$where = "";
		} else {
			$where = "(id = '{$default_location_id}') AND ";
		}
	} else {
		$where = "(name = '" . implode("' or name = '", $location) . "') AND ";
	}
	
//  Aggregated results will be put in an array and returned at once
	$results_array = array();
	
// Log request and get request_id
	$request_id = log_request($conn, $json, $user_id);
	
// get location info from db
	$ssql = "SELECT * from location WHERE {$where}engine <> ''";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get location info");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to get location info");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	// The data for any valid locations will be put in an array indexed by the names of the location
	for($i=0; $i < count($rows); $i++) {
		$engine_name[$i] = check_SQL($rows[$i],'name');
		$engine_id[$i] = check_SQL($rows[$i],'id');
		$engine_full_name[$i] = check_SQL($rows[$i],'full_name');
		$message_id = log_message($conn, $request_id, $engine_id[$i], $engine_name[$i], $json, "sent");
		$json_send['json_forward']["location"] = $engine_name[$i];
		$json_send["location_id"] = $engine_id[$i];
		$json_send["message_id"] = $message_id;
		issue_command($username, $api_key, $json_send);
	}

// Check for any invalid locations passed
	if(!empty($location)) {
		for($i=0; $i < count($location); $i++) {
			if(empty($engine_name) or !in_array($location[$i], $engine_name)) {
				$results_array[$location[$i]]['json'] = "{$location[$i]} not a currently valid site";
				$results_array[$location[$i]]['status'] = 'complete';
			}
		} 
	}
	
// Loop and wait for results
// Set timout for responses (in seconds)
	$timeout = 10; 
	for($t=0; $t < $timeout; $t++) {
	// Get all messages that are ready - have responses available
		$ssql = "SELECT m.id as id, m.json_return, l.`name`, l.full_name from messages m
				LEFT JOIN location l on l.id = m.location_id
				WHERE request_id ='{$request_id}' AND status = 'ready'";
		logit("SQL",$ssql);
		$results=try_conn($conn, $ssql, "", "Failed to log message");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to log message");
			return 0;
		}
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		$update_where = "";
		for($i=0; $i < count($rows); $i++) {
			$id = check_SQL($rows[$i],'id');
			$json_return = check_SQL($rows[$i],'json_return');
			$name = check_SQL($rows[$i],'name');
			$full_name = check_SQL($rows[$i],'full_name');
			// $results_array[$name]['status'] = 'complete';
			$results_array[$name] = json_decode($json_return,true);
			// $results_array[$name]['name'] = $full_name;
			if($update_where <> "") $update_where .= " or id = '{$id}'";
			else $update_where .= "id = '{$id}'";
			logit("debug","{$name} - {$json_return}");
		}
	// If any updated, change their status
		if($update_where <> ""){
			 set_message_complete($conn, $update_where);
		}

	// Check if there are any messages that are not complete	
		$ssql = "SELECT count(*) as count from messages WHERE request_id ='{$request_id}' AND status <> 'complete'";
		logit("SQL",$ssql);
		$results=try_conn($conn, $ssql, "", "Failed to log message");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to log message");
			return 0;
		}
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		$count = check_SQL($rows[0],'count');
		if($count == 0) {
			$t = $timeout;
		}
	// wait for 1 second before checking again	
		if($t < $timeout) sleep(1);
	}
// Update any messages that are not complete to failed	
	$ssql = "SELECT m.id as id, l.`name`, l.full_name from messages m
			LEFT JOIN location l on l.id = m.location_id
			WHERE request_id ='{$request_id}' AND status <> 'complete'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log message");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log message");
		return 0;
	}
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$update_where = "";
	for($i=0; $i < count($rows); $i++) {
		$id = check_SQL($rows[$i],'id');
		$name = check_SQL($rows[$i],'name');
		$full_name = check_SQL($rows[$i],'full_name');
		$results_array[$name]['json'] = "{$name} failed to respond";
		$results_array[$name]['status'] = 'error';
		$results_array[$name]['name'] = $full_name;
		if($update_where <> "") $update_where .= " or id = '{$id}'";
		else $update_where .= "id = '{$id}'";
	}
// If any updated, change their status
	if($update_where <> ""){
		 set_message_complete($conn, $update_where, "failed");
	}
// Encode results array and return
	echo json_encode($results_array);
	logit("INFO","listinfomulti API completed");	
	
//-------------------------- Functions ----------------------------
function log_request($conn, $json, $user_id) {
	$ssql = "INSERT into requests (json_in, user_id, function, status)
			 VALUES ('{$json}', '{$user_id}', 'listinfomulti', 'new')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		return 0;
	}	
	return $conn->lastInsertId();
}

function log_message($conn, $request_id, $location_id, $tag, $json, $status) {
// Log the request
	$ssql = "INSERT into messages (request_id, location_id, tag, api_name, json_sent, status)
			 VALUES ('{$request_id}', '{$location_id}', '{$tag}', 'listinfo.php', '{$json}', '{$status}')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log message");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log message");
		return 0;
	}	
	return $conn->lastInsertId();
}

function set_message_complete($conn, $where, $status = "complete") {
	$ssql = "UPDATE messages SET status = '{$status}' WHERE {$where}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log message update");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log message update");
	}	
}	

function issue_command($username, $api_key, $json_array) {
	// Direct the call to the proper engine
	$json_sent = json_encode($json_array);
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'https://127.0.0.1/command_forward.php' --data '{$json_sent}'";
	logit("CMD", $cmdd);
	$return_json = shell_exec("{$cmdd} > /dev/null 2>/dev/null &");
}
?>