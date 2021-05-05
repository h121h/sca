<?php

// Usage : 
// curl -X POST -k -u username:api_key 'http://127.0.0.1/listinfo.php' --data '{"type" : "<TYPE>", "email" : "<EMAIL>", "environment_id": "<ENVIRONMENT_ID>"}'
// curl -X POST -k -u username:api_key 'http://127.0.0.1/listinfo.php' --data '{"type" : "listlibrary"}'

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
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = $rows[0]['id'];	

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
	$node_array = array();												// Array to store the data

	$json_errors = array(); $json_errors[0] = "";
	$error_index = 0;

// Get variables
	logit("INFO","Reading data from JSON ");
	$location = check_array($json_array,'location');
	$type = check_array($json_array,'type');
	$environment_id = check_array($json_array,"environment_id");
	//var_dump($json_array);
	
//
	if ($type == ''){
		logit("ERROR","No type provided");
		exit_now("No type provided");
	}
	
// Before checking the json for the info, validate the location. If no location specified, default is svl.
	if($location == "") {
		$location = "svl";
		$json_array["environment"]["location"] = "svl";
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
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/managelock.php' --data '{$json_sent}'";
	$cmdd_obscured = str_replace($api_key, "<api_key>", $cmdd);
	logit("CMD", "Manage lock command: {$cmdd_obscured}");
	echo shell_exec($cmdd);
	logit("INFO","manage lock API completed");	
	
?>