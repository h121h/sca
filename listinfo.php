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
		if(isset( $_SERVER['PHP_AUTH_USER'])) {
			$errorname = $_SERVER['PHP_AUTH_USER'];
		} else {
			$errorname = "Unknown";
		}
		logit("user error", "{$errorname} failed authentication");
		exit;
	} else {
		$username = $_SERVER['PHP_AUTH_USER'];
		$api_key = $_SERVER['PHP_AUTH_PW'];
	}
	
// Get UserID
	$ssql = "SELECT u.id, u.default_location_id, l.name, u.role from slayer_api.users u
			LEFT JOIN slayer_api.location l ON l.id = u.default_location_id
			where u.username = '{$username}' and u.disabled is null";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = check_SQL($rows[0],'id');	
	$default_location_id = check_SQL($rows[0],'id');	
	$default_location_name = check_SQL($rows[0],'name');	
	$role = check_SQL($rows[0],'role');	

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
	$type = check_array($json_array,"type");
	$email = check_array($json_array,"email");
	$environment_id = check_array($json_array,"environment_id");
	
//
	if ($type == ''){
		logit("ERROR","No type provided");
		$json_errors[$error_index++] = "No type provided";
	}
	

	
// Before checking the json for the info, validate the location. If no location specified, default is users default location.
// If type is listuser, the requestor has admin privilege, and an email is passed, return the info directly
	if($location == "") {
		if($role == "admin" and $type == "listuser" and !empty($email)) {
			$user_info['user'] = list_user($conn, $email);
			echo json_encode($user_info);
			logit("INFO","listinfo API completed");
			exit;
		} else {
			$location = $default_location_name;
			$json_array["location"] = $default_location_name;
		}
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

// if there are json errors, exit and return list of errors
	if($json_errors[0] <> "") {
		exit_now($json_errors);
	}	
	
// Direct the call to the proper engine
	$json_sent = json_encode($json_array);
	// $cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/listinfo.php' --data '{$json_sent}'";
	// $cmdd_obscured = str_replace($api_key, "<api_key>", $cmdd);
	// logit("CMD", "List info command: {$cmdd_obscured}");
	
	$cmdd = "http://{$engine_ip}:{$engine_port}/listinfo.php";
	logit("CMD", "Edit info command: {$username} / <api_key> / {$cmdd} / {$json_sent}");
	// If the info request is listuser, we will intercept the response and add the default site
	if($type == "listuser" and !empty($email)) {
		// $return_json = shell_exec($cmdd);
		$return_json = curl_post($cmdd, $json_sent, $username, $api_key);
		$json_array = json_decode($return_json, true);
		$user_info = list_user($conn, $email);
		$json_array['user']['default_location'] = $user_info['default_location'];
		$json = json_encode($json_array);
		echo $json;
	} else {
		echo curl_post($cmdd, $json_sent, $username, $api_key);
	}
	logit("INFO","listinfo API completed");	

//------------------------------- Functions ----------------------------------
function list_user($conn, $email) {
	$return_array = array();
	$ssql = "SELECT u.username, u.apikey, l.name as default_location FROM slayer_api.users u
			LEFT JOIN slayer_api.location l ON l.id = u.default_location_id
			WHERE u.email = '{$email}' and disabled is null order by username asc";	
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get user detail from db");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to load user info");
		exit_now("Failed to load user info");
	}
	$users = $results->fetchAll(PDO::FETCH_ASSOC);	
	if(count($users) > 0) {
		$return_array['username'] = check_SQL($users[0], 'username');
		$return_array['apikey'] = check_SQL($users[0], 'apikey');
		$return_array['default_location'] = check_SQL($users[0],'default_location');
	} else {
		$return_array['username'] = "user with email {$email} not found";
	}
	return $return_array;
}	
?>