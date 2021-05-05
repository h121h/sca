<?php
//Usage : curl -X POST -k -u 'username:api_key' 'https://api.slayercloud.com/port_map_custom.php' --data '{"add":[{"ip":"<ip>", "port": "port"],"remove":["<port>"]}'

set_include_path('/var/www/html/common/');
include "consqlp.php";
include "commonfunctions.php";

// Only Authentication is token
	if(isset($_SERVER['PHP_AUTH_USER']) and isset($_SERVER['PHP_AUTH_PW'])) {
		$username = $_SERVER['PHP_AUTH_USER'];
		$api_key = $_SERVER['PHP_AUTH_PW'];
		if(!in_array($api_key, array("acJdDySX2KYTX5efQfyxywDDa8cZXw3qnURWvV3z", "B4RU8w9OcqhTW6AnTIThkAyTynMLgzoLTY28Jrzp"))) {
			echo $api_key . "\n";
			exit_now("invalid authentication");
		}
	} else {
		exit_now("authentication not passed");
	}

//Read JSON for data
	$json="";
	$json = file_get_contents('php://input');
	logit("INFO","JSON : $json");
	
// Check json
	if($json == "") {
		logit("ERROR","Invalid JSON - - IT IS EMPTY");
		exit_now("Invalid JSON - - IT IS EMPTY");
	}
			
// check JSON for SQL injection and validity
	$json = sanitize_json($json);
	$json_input = json_decode($json, true);					// Break the JSON out into an array
	if($json_input === null) {
		logit("ERROR","Invalid JSON - {$json}");
		exit_now("Invalid JSON - {$json}");
	}

//Read GET variables for data
	$add = check_array($json_input,"add");
	$remove = check_array($json_input,"remove");
	
	$input_errors = array(); $input_errors[0] = "";
	$error_index = 0;
	
// get correct environment based on api_key / token
	switch($api_key) {
		case "acJdDySX2KYTX5efQfyxywDDa8cZXw3qnURWvV3z": $json_input['environment_id'] = "-1";
														 $network_range = "200";
		break;
		case "B4RU8w9OcqhTW6AnTIThkAyTynMLgzoLTY28Jrzp": $json_input['environment_id'] = "-2";
														 $network_range = "201";
		break;
		default: $json_input['environment_id'] = "0";
	}

// Validation checks	
	// if(empty($add) and empty($remove)) $input_errors[$error_index++] = "No add or remove specified";

// Log the request	
	$json_array = new stdClass();
	if(!empty($add)) {
		for($i = 0; $i < count($add); $i++) {
			if(!empty($add[$i]['target_ip'])) {
				$octets = explode(".",$add[$i]['target_ip']);
				if(!empty($octets[1])) {
					if($octets[1] != $network_range) {
						exit_now("invalid ip: {$add[$i]['target_ip']} for add");
					}
				} else {
					exit_now("invalid ip: {$add[$i]['target_ip']} for add");
				}
			}
		}
		$json_array->add = $add;
	}
	
	if(!empty($remove)) {
		for($i = 0; $i < count($remove); $i++) {
			if(!empty($remove[$i]['target_ip'])) {
				$octets = explode(".",$remove[$i]['target_ip']);
				if(!empty($octets[1])) {
					if($octets[1] != $network_range) {
						exit_now("invalid ip: {$remove[$i]['target_ip']} for remove");
					}
				} else {
					exit_now("invalid ip: {$remove[$i]['target_ip']} for remove");
				}
			}
		}
		$json_array->remove = $remove;
	}
	$json = json_encode($json_array);
	$ssql = "INSERT into requests (json_in, user_id, function, status)
			 VALUES ('{$json}', '{$username}', 'custom_port_map', 'new')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		exit_now("Currently unable to access database");
	}	
	$request_id = $conn->lastInsertId();

// get location from token. Set to 1 for this function.
	$location = 1;
	
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
		$input_errors[$error_index++] = "location not valid";
		logit("ERROR","location not valid.");
	} else {
		$engine_id = $rows[0]['id'];
		$engine_ip = $rows[0]['engine_ip'];
		$engine_port = $rows[0]['port'];
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
	switch($api_key) {
		case "acJdDySX2KYTX5efQfyxywDDa8cZXw3qnURWvV3z": $json_input['environment_id'] = "-1";
		break;
		case "B4RU8w9OcqhTW6AnTIThkAyTynMLgzoLTY28Jrzp": $json_input['environment_id'] = "-2";
		break;
		default: $json_input['environment_id'] = "0";
	}
	$json_input['rd_host_group'] = "a";
	$json_input['gateway_ip'] = "10.0.0.253";
	$json_sent = json_encode($json_input);
	$ssql = "update requests SET status = 'sent',
			engine_id = '{$engine_id}',
			json_sent = '{$json_sent}'
			WHERE id = {$request_id}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	$ssql = "select apikey from users
			where username = 'slayer' and role = 'admin'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed getting user api from db");
	if (gettype($results) != "integer") {
		$api_keys = $results->fetchAll(PDO::FETCH_BOTH);
		if(count($api_keys) > 0) {
			$api_key = check_SQL($api_keys[0],'apikey');
			$cmdd = "curl -X POST -k -u 'slayer:{$api_key}' 'http://{$engine_ip}:{$engine_port}/manage_direct_ports.php' --data '{$json_sent}'";
			$cmdd_obscured = str_replace($api_key, "<api_key>", $cmdd);
			logit("CMD", "Port map command: {$cmdd_obscured}");
			$output = shell_exec($cmdd);
			logit("INFO", "Output: {$output}");
			$json_return=json_decode($output,true);
			$status = check_array($json_return,'status');
			$job_id = check_array($json_return,'job_id');
			$port_map = check_array($json_return,'port_map');
			$status_details = check_array($json_return,'details');
		}
	} else {
		$status = "failed";
		$status_details = "unable to get proper authentication";
	}
	if($status == "") {
		$status = "failed";
		$status_details = "Engine failed to respond";
	}
	$ssql = "update requests SET status = '{$status}',
		status_return = '{$output}',
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
		if(!empty($port_map)) $myObj->port_map = $port_map;
	} else {
		if(!empty($port_map)) {
			$myObj->port_map = $port_map;
		} else {
			$myObj->details = $status_details;
		}
	}
	$myJSON = json_encode($myObj, JSON_UNESCAPED_SLASHES);
	echo $myJSON;
	logit("INFO","Custom port map API completed");
?>