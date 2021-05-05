<?php
//Usage : curl -X POST -k -u username:api_key 'http://169.44.167.82/deletefromenvironment.php' --data '{"location":"<location>", "node_id": ["XX"]}'

set_include_path('/var/www/html/common/');
include "consqlp.php";
include "commonfunctions.php";

// Authenticate User
	$username  = getAuthINLINE(); 
	if ($username == "error") {
		echo '{"status" : "error" , "request_id" : "na", "details": "failed authentication"}'."\n";
		logit("user error", "failed authentication");
		exit;
	} else {
		$username = $_SERVER['PHP_AUTH_USER'];
		$api_key = $_SERVER['PHP_AUTH_PW'];
	}

// Get UserID
	$ssql = "select id from slayer_api.users where username = '{$username}'";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = $rows[0]['id'];
	
//Read JSON for data
	$json = "";
	$json = file_get_contents('php://input');
	logit("INFO","JSON : $json");

// Check json
	if($json == "") {
		logit("ERROR","Invalid JSON - no JSON passed");
		exit_now("Invalid JSON - no JSON passed");
	}
			
// check JSON for SQL injection and validity
	$json = sanitize_json($json);
	$json_array = json_decode($json, true);					// Break the JSON out into an array
	if($json_array === null) {
		logit("ERROR","Invalid JSON - {$json}");
		exit_now("Invalid JSON - {$json}");
	}

// Log the request
	$ssql = "INSERT into requests (json_in, user_id, function, status)
			 VALUES ('{$json}', '{$user_id}', 'deletefromenvironment', 'new')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		exit_now("Currently unable to access database");
	}	
	$request_id = $conn->lastInsertId();
	$json_errors = array(); $json_errors[0] = "";
	$error_index = 0;

//Get location and node ids from JSON and validate from DB
	$location = check_array($json_array, "location");		
	$node_id = check_array($json_array,'node_id');
	
// Before checking the json for the nodes, validate the location.
	if(empty($location)) {
		logit("ERROR","No location specified.");
		$json_errors[$error_index++] = "No location specified.";
	}
	
	if(empty($node_id)) {
		logit("ERROR","No node ids specified.");
		$json_errors[$error_index++] = "No node ids specified.";
	}	

// Validation for top level variables

	if($json_errors[0] <> "") {
		$myObj = new stdClass();
		$myObj->json_errors = $json_errors;
		$myJSON = json_encode($myObj);
		$ssql = "update requests SET status = 'failed',
				status_return = '{$myJSON}'
				WHERE id = {$request_id}";
		logit("SQL","$ssql");
		$results=try_conn($conn, $ssql, "", "Failed to log request");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to log request");
		}
		exit_now($json_errors);
	}
		
// get location info from db
	$ssql = "SELECT * from location WHERE name = '{$location}'";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to get location info");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to get location info");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	if(count($rows) < 1) {
		$json_errors[$error_index++] = "Invalid Location";
		logit("ERROR","Invalid Location.");
	} else {
		$engine_id = $rows[0]['id'];
		$engine_ip = $rows[0]['engine_ip'];
		$engine_port = $rows[0]['port'];
	}	
		

// Direct the call to the proper engine
	$json_sent = json_encode($json_array);
	$ssql = "update requests SET status = 'sent',
			engine_id = '{$engine_id}',
			json_sent = '{$json_sent}'
			WHERE id = {$request_id}";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/taskdeletefromenvironment.php' --data '{$json_sent}'";
	logit("CMD", "Delete node command: {$cmdd}");
	$output = shell_exec($cmdd);
	logit("INFO", "Output: $output");
	$json_return=json_decode($output,true);
	$status = check_array($json_return,'status');
	$job_id = check_array($json_return,'job_id');
	$status_details = check_array($json_return,'details');
	$environment_id = check_array($json_return,'environment_id');
	if($status == "") {
		$status = "failed";
		$status_details = "Engine failed to respond";
	}
	$ssql = "update requests SET status = '{$status}',
		status_return = '{$status_details}',
		response = '{$status_details}',
		request_id = '{$job_id}'
		WHERE id = {$request_id}";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to log request return");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	
// Log and exit
	$myObj = new stdClass();
	if($status == "success") {
		$status_request_id = $job_id . str_pad($engine_id, 3, "0", STR_PAD_LEFT);
		$myObj->status_url = "https://api.slayercloud.com/getrequeststatus.php?request={$status_request_id}";
		$myObj->status = "success";
		$myObj->details = $status_details;
		$myObj->environment_id = $environment_id;
	} else {
		$myObj->status = "failed";
		$myObj->details = $status_details;
	}
	$myJSON = json_encode($myObj, JSON_UNESCAPED_SLASHES);
	echo $myJSON;
	logit("INFO","deletefromenvironment API completed");
?>