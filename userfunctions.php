<?php

// Usage : 
// curl -X POST -k -u username:api_key 'https://api.slayercloud.com/userfunctions.php' --data '{"type" : "<TYPE>",....}'
// Slayer administrative function - only available to users with Admin role
// Types:
// add: adds new user
// required JSON:
//		email - should be w3id
//		username
//	optional JSON:
//		role - role will be left blank (basic role) if not included, or is an invalid role
//		default_location	
//		comment
//		location - array of locations - if present will push user to specified locations
// Example - add
//   {
//     "type": "add",
//     "email": "test@us.ibm.com",
//     "username": "test",
//     "role": "admin",
//     "default_location": "svl",
//     "comment": "comment text"
//   }
//
// disable: disables user - will push to all locations
// required JSON:
//		email - should be w3id
//	optional JSON:
//		comment		
// Example - disable
//   {
//     "type": "disable",
//     "email": "test@us.ibm.com",
//     "comment": "comment text"
//   }
//
// setrole: sets user role - will push to all locations
// required JSON:
//		email - should be w3id
//		role - role will be left blank (basic role) if not included, or is an invalid role
// Example - disable
//   {
//     "type": "setrole",
//     "email": "test@us.ibm.com",
//     "role": ""
//   }
//
// sync: Syncs master user to location
// required JSON:
//		email - should be w3id
//		location
//
// comment: Updates user comment - will push to all locations
// required JSON:
//		email - should be w3id
//		comment	- comment in users
//
// Group administrative function - can be executed by system admins or group admins
//
// updategroup: adds user to group and/or updates group characteristics for location
// required JSON:
//		group_id
//		email - should be w3id
//		location - array of locations - will update user for specified locations
//	optional JSON: will update whatever fields are passed
//		role - role will be left blank (basic role) if not included, or is an invalid role
//		primary
//		comment	- comment in group_members
//
// removegroup: removes user from group
// required JSON:
//		group_id
//		email - should be w3id
//		location - array of locations - will update user for specified locations
//	optional JSON:
//		comment	- comment in group_members

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
	$results=try_conn($conn, $ssql, "", "Failed to get user id for username {$username}");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id for username {$username}");
		exit_now("Failed to get user id for username {$username}");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	if(count($rows) > 0) {
		$user_id = check_SQL($rows[0],'id');	
		$email = check_SQL($rows[0],'email');	
		$role = check_SQL($rows[0],'role');
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
	
	$user = array();
	
// Get type and group_id
	logit("INFO","Reading data from JSON ");
	$type = check_array($json_array,'type');
	$user['group_id'] = check_array($json_array,'group_id');
	
// Identify function types that require admin vs group admin authority
	$admin_types = array('add','disable','setrole','sync','comment');
	$group_types = array('updategroup','removegroup');
	$required_role = "";
	if(in_array($type,$admin_types)) {
		$required_role = "admin";
	} else {
		if(in_array($type,$group_types)) {
			$required_role = "group";
		}
	}
	
	if($required_role == "") {
		$out_text = implode(", ", $admin_types) . ", " . implode(", ", $group_types);
		exit_now("{$type} not a supported user function - supported user functions: {$out_text}");
	}

// Check for proper authority - group role needs to be checked locally
	if($role <> "admin" and $required_role == "admin") {
		exit_now("{$username} not authorized to {$type}");
	} 

// Get remaining variables

	$user['email'] = strtolower(check_array($json_array,'email'));
	$user['username'] = strtolower(check_array($json_array,'username'));
	if(isset($json_array['role'])) $user['role'] = check_array($json_array,'role');
	if(isset($json_array['default_location'])) $user['default_location'] = check_array($json_array,'default_location');
	if(isset($json_array['primary'])) $user['primary'] = check_array($json_array,'primary');
	if(isset($json_array['comment'])) $user['comment'] = check_array($json_array,'comment');
	$location = check_array($json_array,'location');
	
	if($user['email'] == "") {
		exit_now("{$type} requested without valid email (required)");
	}
	
// Pull info from db for email. If type is add, then it is an error if it exists. Otherwise, it is an error if it doesn't	
	$user_details = get_user($conn, $user['email'], $type);
	if($user_details['status'] == "success" and $type == "add") {
		exit_now("Duplicate user email {$user['email']} found for user add");
	}
	if($user_details['status'] == "error" and $type <> "add") {
		exit_now("User with email {$user['email']} not found");
	} else {
		$user_details['status'] = "success";
	}

// Download to all valid locations will occur if type is add or update, or type is synch and location is empty.
	if(in_array($type, array('disable','setrole','comment'))) {
		$use_all_valid_locations = true;
	} else {
		$use_all_valid_locations = false;
	}
	
// Get all valid locations and put in array
	$ssql = "SELECT * from location WHERE engine <> ''";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to get location info");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to get location info");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	// The data for any valid locations will be put in an array indexed by the names of the location
	$valid_locations = array();					// holder for list of valid locations
	for($i=0; $i < count($rows); $i++) {
		$engine_name[$i] = check_SQL($rows[$i],'name');
		$valid_locations[$engine_name[$i]] = $i;
		$engine_id[$i] = check_SQL($rows[$i],'id');
		$engine_full_name[$i] = check_SQL($rows[$i],'full_name');
		$send_to_engine[$i] = $use_all_valid_locations;
	}	
	
// If there is a default location specified, calculate the default location id
	if(!empty($user['default_location'])) {
		if(isset($valid_locations[$user['default_location']])) {
			$user['default_location_id'] = $engine_id[$valid_locations[$user['default_location']]];
		}		
	}
	
// Identify locations to which actions should propagate if location is not empty and type does not propagate to all locations. Send_to_engine array will get set to true if location should receieve info
	$invalid_locations = array();
	if($location <> "" and !$use_all_valid_locations) {
	// location can be passed as an array of locations (using [ and ]) or as a single value that is not an array Both will get accepted	
		if(is_array($location)) {
			for($i=0; $i < count($location); $i++) {
			// Make sure location isn't blank
			// The index of the valid location is contained in $valid_locations[<location name>]
				if($location[$i] <> "") {
					if(isset($valid_locations[$location[$i]])) {
					// if the location name corresponds to a valid location, set send_to_engine for that loaction to true	
						$valid_location_index = $valid_locations[$location[$i]];
						$send_to_engine[$valid_location_index] = true;
					} else {
						array_push($invalid_locations, $location[$i]);
					}
				}
			}
		} else {
			if(!isset($valid_locations[$location])) {
				$invalid_locations[0] = $location;
			} else {
				if(isset($valid_locations[$location])) {
					$valid_location_index = $valid_locations[$location];
					$send_to_engine[$valid_location_index] = true;
				}
			}
		}
	}
	
	$json_send = array();												// Array to store the data
	$json_send["function_name"] = 'usermanage';
	
//  Aggregated results will be put in an array and returned at once
	$results_array = array();

// Validate input based on type passed in	
	switch ($type){

	case "add":
		if($user['username'] == "") {
			exit_now("Add requested without valid username (required)");
		}		
		if(check_username($conn, $user['username'])) {
			exit_now("Duplicate username {$user['username']}");
		}	
		$user['apikey'] = generateStrongPassword(40);
		$results = add_user($conn, $user);
		if($results['status'] == "success") {
			$user = $results['user'];
			$user['action'] = "update";
		} else {
			exit_now("Failed to add user for email {$user['email']}");
		}
		break;
	
	case "disable":
		$results = disable_user($conn, $user['email']);
		if($results !== "success") {
			exit_now("Failed to disable user for email {$user['email']}");
		}
		$user['action'] = "disable";
		break;
		
	case "setrole":
		if(!isset($user['role'])) {
			exit_now("No role passed for email {$user['email']}");
			$user['action'] = "update";
		}
		if(!set_user_field($conn, $user['email'], 'role', $user['role'])) {
			exit_now("Failed to set role {$user['role']} for user with email {$user['email']}");
		}
		break;
		
	case "sync":
		$user = $user_details;
		$user['action'] = "update";
		break;
		
	case "comment":
		if(!isset($user['comment'])) {
			exit_now("No comment passed for email {$user['email']}");
		}
		if(!set_user_field($conn, $user['email'], 'comment', $user['comment'])) {
			exit_now("Failed to set comment {$user['comment']} for user with email {$user['email']}");
		}	
		$user['action'] = "update";	
		break;
		
	case "updategroup":
		if($user['group_id'] == "") {
			exit_now("Valid group id not specified");
		}
		$user['action'] = $type;
		break;
		
	case "removegroup":
		if($user['group_id'] == "") {
			exit_now("Valid group id not specified");
		}
		$user['action'] = $type;
		break;
		
	default:
		break;
	}
	
	$json_forward = $user;
	$json_forward['type'] = $type;

// Log request and get request_id
	$request_id = log_request($conn, $json, $user_id);
	
// All valid locations will be checked and commands issued where required

	for($i=0; $i < count($valid_locations); $i++) {
		$json = $json_forward;																				// start with base json
		if($send_to_engine[$i]) {
			$json['group_id'] = $json['group_id'] . substr("00".$engine_id[$i],-3);
			$json_send['json_forward'] = $json;																// add the json to be forwarded to the send array
			$json = json_encode($json);	
			$message_id = log_message($conn, $request_id, $engine_id[$i], $engine_name[$i], $json, "sent");
			$json_send['json_forward']["location"] = $engine_name[$i];
			$json_send["location_id"] = $engine_id[$i];
			$json_send["message_id"] = $message_id;
			issue_command($username, $api_key, $json_send);
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
			$results_array[$name]['status'] = 'complete';
			$results_array[$name]['name'] = $full_name;
			$results_array[$name]['json'] = json_decode($json_return,true);
			if(empty($results_array[$name]['json'])) $results_array[$name]['json'] = "No valid response received";
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
		 $status = "error";
	} else {
		$status = "complete";
	}
	
// add return info for any invalid locations
	for($i=0; $i < count($invalid_locations); $i++) {
		$results_array[$invalid_locations[$i]]['json'] = "{$invalid_locations[$i]} not a currently valid site";
		$results_array[$invalid_locations[$i]]['status'] = 'complete';
	}
	
// Encode results array and return
	$return_json = json_encode($results_array);
	echo $return_json;
	$ssql = "update requests SET status = '{$status}',
		response = '{$return_json}'
		WHERE id = {$request_id}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request return");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	
	logit("INFO","userfunctions API completed");	
	
//-------------------------- Functions ----------------------------
function log_request($conn, $json, $user_id) {
	$ssql = "INSERT into requests (json_in, user_id, function, status, status_return, json_sent)
			 VALUES ('{$json}', '{$user_id}', 'userfunctions', 'new', '', 'see messages')";
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
			 VALUES ('{$request_id}', '{$location_id}', '{$tag}', 'usermanage.php', '{$json}', '{$status}')";
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

function add_user($conn, $user) {
	$return_array = array();
	if(!isset($user['apikey'])) $user['apikey'] = "";
	if(!isset($user['default_location_id'])) $user['default_location_id'] = 1; 
	if(!isset($user['role'])) $user['role'] = "";
	if(!isset($user['comment'])) {
		$comment = "null";
	} else {
		$comment = "'{$user['comment']}'";
	}
	$ssql = "SET @max_id = (SELECT if(isnull(MAX(id)),1,MAX(id)+1) FROM slayer_api.users);
		SET @sql = CONCAT('ALTER TABLE slayer_api.users AUTO_INCREMENT = ', @max_id);
		PREPARE st FROM @sql;
		EXECUTE st;
		INSERT into slayer_api.users (username,email,apikey,default_location_id,comment,role)
		VALUES ('{$user['username']}','{$user['email']}','{$user['apikey']}','{$user['default_location_id']}',{$comment},'{$user['role']}')
		ON DUPLICATE Key UPDATE username = '{$user['username']}', email = '{$user['email']}', apikey = '{$user['apikey']}', 
								default_location_id = '{$user['default_location_id']}', comment = {$comment}, role = '{$user['role']}', disabled = null";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to add user entry to DB");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to add user for email {$user['email']}");
		$return_array['status'] = "error";
	} else {
		$return_array['status'] = "success";
		$return_array['user'] = $user;
	}
	return $return_array;
}

function disable_user($conn, $email) {
	$date_time = date("Y-m-d H:i:s");
	$ssql = "UPDATE slayer_api.users SET disabled = '{$date_time}' WHERE email = '{$email}'";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to update user entry to DB");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to disable user with email {$email}");
		return "error";
	} else {
		return "success";
	}
}

function set_user_field($conn, $email, $field, $value) {
	$ssql = "UPDATE slayer_api.users SET {$field} = '{$value}' WHERE email = '{$email}'";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to update user entry to DB");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to set {$field} {$value} for user with email {$email}");
		return "error";
	} else {
		return "success";
	}
}

function get_user($conn, $email, $type) {
	$return_array = array();
	if($type <> 'disable') {
		$where = " and disabled is null";
	} else {
		$where = "";
	}
	$ssql = "SELECT * from slayer_api.users WHERE email = '{$email}'{$where}";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to get user info");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user info for {$email}");
		$return_array['status'] = "error";
	} else {
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		if(count($rows) > 0) {
			$return_array['status'] = "success";
			$return_array['username'] = $rows[0]['username'];
			$return_array['email'] = $rows[0]['email'];
			$return_array['apikey'] = $rows[0]['apikey'];
			$return_array['default_location_id'] = $rows[0]['default_location_id'];
			$return_array['role'] = $rows[0]['role'];
			$return_array['comment'] = $rows[0]['comment'];
		} else {
			$return_array['status'] = "error";
			$return_array['username'] = "Failed to get user info for {$email}";
		}
	}
	return $return_array;
}

function check_username($conn, $username) {
	$ssql = "SELECT COUNT(*) AS count from slayer_api.users WHERE username = '{$username}' and disabled is null";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to get user info");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user info for {$username}");
		return true;
	} else {
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		$count = check_SQL($rows[0],'count');
		if($count == 0) {
			return false;
		} else {
			return true;
		}
	}
}
?>