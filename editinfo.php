<?php

// Usage : 
// curl -X POST -k -u username:api_key 'http://api.slayercloud.com/editinfo.php' --data '{"location":"<site>","environment_id":"<environment_id>","library_id":"<library_id>",<edit info JSON>}'

// Set include
	set_include_path('/var/www/html/common/');
	include "consqlp.php";
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
	$ssql = "select id, default_location_id from slayer_api.users where username = '{$username}'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = check_SQL($rows[0],'id');	
	$default_location_id = check_SQL($rows[0],'id');	

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
	$paramter['environment_id'] = check_array($json_array,"environment_id");
	$paramter['library_id'] = check_array($json_array,"library_id");
	
// Before checking the json for the info, validate the location. If no location specified, default is svl.
	if($location == "") {
		$location = "svl";
		$json_array["location"] = "svl";
	}
	
// get location info from db
	$ssql = "SELECT * from location WHERE name = '{$location}'";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get location info");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to get location info");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	if(count($rows) < 1) {
		$json_errors[$error_index++] = "Invalid Location";
		logit("ERROR","Invalid Location.");
	} else {
		$engine_id = check_SQL($rows[0],'id');
		$engine_ip = check_SQL($rows[0],'engine_ip');
		$engine_port = check_SQL($rows[0],'port');
	}
	
// Validate that either environment_id or location_id has been passed and that at least one is numeric
	if(empty($paramter['environment_id']) and empty($paramter['library_id'])) {
		$json_errors[$error_index++] = "No environment or library id";
	} else {
		if(!is_numeric($paramter['environment_id'])) {
			if(!is_numeric($paramter['library_id'])) {
				$json_errors[$error_index++] = "Invalid environment/library id {$paramter['environment_id']} / {$paramter['library_id']}";
			} else {
			// id library id is numeric and environment id is not, update will be for library	
				$function = "editlibraryinfo";
			}
		} else {
		// if environment id is numeric, update will be for environment	
			$function = "editenvironmentinfo";
		}
	}
	
// if all of the fields to update are not set (i.e. not passed) then there is nothing to update	
	$allowed_parameters = array('name', 'description', 'group_id', 'timeout', 'action', 'email', 'domain', 'token');
	$nofields = true;
	foreach($allowed_parameters as $field) {
		if(isset($json_array[$field])) {
			$nofields = false;
		}
	}
	if($nofields) {
		$json_errors[$error_index++] = "No valid data passed to update";
	}

// if there are json errors, exit and return list of errors
	if($json_errors[0] <> "") {
		exit_now($json_errors);
	}
	
// Direct the call to the proper engine
	$json_sent = json_encode($json_array);
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/{$function}.php' --data '{$json_sent}'";
	$cmdd = "http://{$engine_ip}:{$engine_port}/{$function}.php";
	logit("CMD", "Edit info command: {$cmdd} / {$json_sent}");
	// If the info request is listuser, we will intercept the response and add the default site
	// $exec_return =  exec ($cmdd, $return_array, $return_val);
	$exec_return =  curl_post($cmdd, $json_sent, $username, $api_key);
	// echo $return_val . "\n";
	if($exec_return == "404") {
		$return_array = array();
		$return_array['status'] = "error";
		$return_array['details'] = "{$function}.php not found at location {$location}";
		$exec_return = json_encode($return_array);
	}
	echo $exec_return;
	logit("INFO","editinfo API completed");	
?>