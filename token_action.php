<?php
//Usage : curl -X POST -k -u username:api_key 'http://169.44.167.82/token_action.php' --data '{"token":"<token>", "action": "action"}'

set_include_path('/var/www/html/common/');
include "consqlp.php";
include "commonfunctions.php";

// Only Authentication is token

//Read GET variables for data
	$token = check_GET('token');
	$action = check_GET('action');
	$id = check_GET('id');
	
	$input_errors = array(); $input_errors[0] = "";
	$error_index = 0;

// Validation checks	
	if(empty($token)) $input_errors[$error_index++] = "No token specified";
	if(empty($action)) $input_errors[$error_index++] = "No action specified";
	// if($action == "status" and empty($id)) $input_errors[$error_index++] = "No status id specified";

// Log the request	
	$json_array = new stdClass();
	$json_array->token = $token;
	$json_array->action = $action;
	if(!empty($id)) $json_array->job_id = substr($id, strpos($id, "-") + 1);
	$json = json_encode($json_array);
	$ssql = "INSERT into requests (json_in, user_id, function, status)
			 VALUES ('{$json}', 'token', 'token', 'new')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		exit_now("Currently unable to access database");
	}	
	$request_id = $conn->lastInsertId();

	if(!empty($token)) {
	// get location from token. First 5 digits are the location id.
		$location = intval(substr($token, 0, 5));
		
	// get location info from db
		$ssql = "SELECT * from location WHERE id = '{$location}'";
		logit("SQL",$ssql);
		$results=try_conn($conn, $ssql, "", "Failed to get location info");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to get location info");
			exit_now("Currently unable to access database");
		}	
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		if(count($rows) < 1) {
			$input_errors[$error_index++] = "Invalid token";
			logit("ERROR","Invalid token {$token} - location not valid.");
		} else {
			$engine_id = $rows[0]['id'];
			$engine_ip = $rows[0]['engine_ip'];
			$engine_port = $rows[0]['port'];
		}	
		
		$valid_actions = array("boot", "shutdown", "reboot", "off");
	}
	
	if($input_errors[0] <> "") {
		$myObj = new stdClass();
		$myObj->input_errors = $input_errors;
		$myJSON = json_encode($myObj);
		$ssql = "update requests SET status = 'failed',
				status_return = '{$myJSON}'
				WHERE id = {$request_id}";
		logit("SQL",$ssql);
		$results=try_conn($conn, $ssql, "", "Failed to log request");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to log request");
		}
		exit_now($myObj);
	}

// Direct the call to the proper engine
	$json_sent = $json;
	$ssql = "update requests SET status = 'sent',
			engine_id = '{$engine_id}',
			json_sent = '{$json_sent}'
			WHERE id = {$request_id}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	$cmdd = "curl -X POST -k 'http://{$engine_ip}:{$engine_port}/token_api.php' --data '{$json_sent}'";
	$cmdd_obscured = str_replace($token, "<token>", $cmdd);
	logit("CMD", "Power management command: {$cmdd_obscured}");
	$output = shell_exec($cmdd);
	logit("INFO", "Output: {$output}");
	$json_return=json_decode($output,true);
	$status = check_array($json_return,'status');
	$job_id = check_array($json_return,'job_id');
	$result = check_array($json_return,'result');
	$status_details = check_array($json_return,'details');
	$percent_complete = check_array($json_return,'percent_complete');
	$node_status = check_array($json_return,'node_status');
	if($status == "") {
		$status = "failed";
		$status_details = "Engine failed to respond";
	}
	$ssql = "update requests SET status = '{$status}',
		status_return = '{$status_details}',
		response = '{$status_details}',
		request_id = '{$job_id}'
		WHERE id = {$request_id}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request return");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	
// Log and exit
	$myObj = new stdClass();
	if($status == "success" or $status == "completed" or $status == "in process") {
		// $myObj->status_url = "https://api.slayercloud.com/getrequeststatus.php?request={$status_request_id}";
		$myObj->status = $status;
		if(!empty($result)) $myObj->result = $result;
		if(!empty($details)) $myObj->details = $status_details;
		if($percent_complete != "") $myObj->percent_complete = $percent_complete;
		if($node_status != "") $myObj->node_status = $node_status;
		if(!empty($job_id)) {
			$status_request_id = str_pad($engine_id, 5, "0", STR_PAD_LEFT)."-".$job_id;
			$myObj->status_id = $status_request_id;
		}
	} else {
		$myObj->status = "failed";
		$myObj->details = $status_details;
	}
	$myJSON = json_encode($myObj, JSON_UNESCAPED_SLASHES);
	echo $myJSON;
	logit("INFO","Token API completed");
?>