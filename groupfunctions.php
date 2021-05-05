<?php

// Usage : 
// curl -X POST -k -u username:api_key 'https://api.slayercloud.com/groupfunctions.php' --data '{"type" : "<TYPE>","location":["<location1>","<location2>",...]}'
// Types:
// add: adds new group - will be propagated to all active sites
// required JSON:
//		shortname
//		fullname
//	optional JSON:
//		description	
//		comment	
//		location: array of locations with optional quota "[{"name:"a", "quota":{"platform":"quota",...}},{}...]	
//		quota	
// Example - add
//   {
//     "type": "add",
//     "shortname": "t1",
//     "fullname": "t2",
//     "comment": "t3",
//     "location": [
//       {
//         "name": "svl",
//		   "timeout": "0",
//	       "action": "suspend",
//         "quota": {
//           "x": "0",
//           "p": "0"
//         }
//       },
//       {
//         "name": "sv2",
//		   "timeout": "0",
//	       "action": "suspend",
//         "quota": {
//           "x": "0",
//           "z": "0"
//         }
//       }
//     ]
//   }
//
// sync: Syncs master group to location if no locations listed, will be propagated to all sites
// required JSON:
//		shortname
//	optional JSON:
//		location: array of locations and optional quotas/timeouts/actions
//
// update: updates master group - will be propagated to all active sites
// required JSON:
//		shortname
//	optional JSON:
//		newshortname
//		fullname
//		description	
//		comment	
//		location: array of locations and optional quotas/timeouts/actions
//
// setquota: sends quota to specified locations
// required JSON:
//		shortname
//		location: array of locations and optional quotas

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
	$user_id = check_SQL($rows[0],'id');	
	$email = check_SQL($rows[0],'email');	
	$role = check_SQL($rows[0],'role');

// Check for admin authority
	if($role <> "admin") {
		exit_now("{$username} not authorized to modify groups");
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
	$type = check_array($json_array,'type');
	$shortname = check_array($json_array,'shortname');
	$group_id = check_array($json_array,'group_id');
	if(isset($json_array['newshortname'])) {
		$new_shortname = check_array($json_array,'newshortname');
		if($new_shortname == "") {
			$new_shortname = $shortname;
		}
	}
	if(isset($json_array['fullname'])) $fullname = check_array($json_array,'fullname');
	if(isset($json_array['description'])) $description = check_array($json_array,'description');
	if(isset($json_array['comment'])) $comment = check_array($json_array,'comment');
	$location = check_array($json_array,'location');
	
// supported types - check for valid type passed
	$allowed_types = array("add", "update", "sync", "setquota");
	if(!in_array($type, $allowed_types)) {
		$out_text = implode(", ", $allowed_types);
		exit_now("{$type} not a supported group function - supported group functions: {$out_text}");
	}

// Pull info from db for shortname. If type is new, then it is an error if it exists. Otherwise, it is an error if it doesn't	
	$group_details = get_group($conn, $shortname, $group_id);
	if($group_details['status'] == "success" and $type == "add") {
		if($group_id <> "") {
			exit_now("Duplicate shortname {$shortname} found for group add");
		} else {
			exit_now("Group_id {$group_id} already exists - add by group_id not allowed");
		}
	}
	if($group_details['status'] == "error" and $type <> "add") {
		if($shortname <> "") {
			exit_now("Group {$shortname} not found");
		} else {
			if($group_id <> "") {
				exit_now("Group_id {$group_id} not found");
			} else {
				exit_now("No group_id passed");
			}
		}
	} else {
		$group_details['status'] = "success";
	}
	
// Get all valid locations and put in array		$ssql = "SELECT * from location WHERE ({$where}) AND engine <> ''";
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
	}	
	
// Download to all valid locations will occur if type is add or update, or type is synch and location is empty.
	if($type == "add" || $type == "update" || ($type == "sync" && $location == "")) {
		$use_all_valid_locations = true;
	} else {
		$use_all_valid_locations = false;
	}
	
// Get array of location names if location is not empty
// We will also extract any quota info passed, and create an array indexed by the location name that points to the location name and quota. Quota information is attached to the valid_locations instance for a location.
	$location_names = array();
	$invalid_locations = array();
	$new_quota = array();
	$new_timeout = array();
	$new_action = array();
	if($location <> "") {
		if(is_array($location)) {
			for($i=0; $i < count($location); $i++) {
				$location_names[$i] = check_array($location[$i], "name");
				$location_quota[$i] = check_array($location[$i], "quota");
				$location_timeout[$i] = check_array($location[$i], "timeout");
				$location_action[$i] = check_array($location[$i], "action");
				if($location_names[$i] <> "") {
					$location_index[$location_names[$i]] = $i;
				}
				if(isset($valid_locations[$location_names[$i]])) {
					$valid_location_index = $valid_locations[$location_names[$i]];
					if($location_quota[$i] <> "") {
						$new_quota[$valid_location_index] = $location_quota[$i];							// The index of the valid location is contained in $valid_locations[<location name>], which is in $location_names[$i]
					}
					if($location_timeout[$i] <> "") {
						$new_timeout[$valid_location_index] = $location_timeout[$i];
					}
					if($location_action[$i] <> "") {
						$new_action[$valid_location_index] = $location_action[$i];
					}
				}
				if(!isset($valid_locations[$location_names[$i]])) {
					array_push($invalid_locations, $location_names[$i]);
				}
			}
		} else {
			$location_names[0] = $location;
			$location_index[$location_names[0]] = 0;
			if(!isset($valid_locations[$location_names[0]])) {
				$invalid_locations[0] = $location_names[0];
			}
		}
	}
	
	$json_send = array();												// Array to store the data
	$json_send["function_name"] = 'groupmanage';
	
//  Aggregated results will be put in an array and returned at once
	$results_array = array();
	
// Execute master db based on type passed in	
	if($type <> "setquota") {
		switch ($type){
		
		case "add":
			if($shortname == "") {
				exit_now("Add requested without valid shortname (required)");
			}
			if(empty($comment)) {
				$comment = "";
			}
			$group_details = add_group($conn, $shortname, $fullname, $description, $comment);
			break;
		
		case "update":
			$sql_calc = array();
			if(isset($json_array['shortname'])) {
				array_push($sql_calc,"shortname = '{$new_shortname}'");
				$group_details['shortname'] = $new_shortname;
			}
			if(isset($json_array['fullname'])) {
				array_push($sql_calc,"name = '{$fullname}'");
				$group_details['name'] = $fullname;
			}
			if(isset($json_array['description'])) {
				array_push($sql_calc,"description = '{$description}'");
				$group_details['description'] = $description;
			}
			if(isset($json_array['comment'])) {
				array_push($sql_calc,"comment = '{$comment}'");
				$group_details['comment'] = $comment;
			}
			$sql_update = implode(", ", $sql_calc);
			$update_return = update_group($conn, $shortname, $group_details['group_id'], $sql_update);
			$group_details['group_id'] = $update_return['group_id'];
			$group_details['status'] = $update_return['status'];
			break;
			
		case "sync":
			break;
			
		default:
			$group_details = "";
			break;
		}
		// $results_array[$type] = $group_details;
	} else {
		if(empty($new_quota)) {
			exit_now("No quota passed for setquota function");
		}
	}
	$group_id = check_array($group_details, "group_id");
	$status = check_array($group_details, "status");
	unset($group_details['status']);															// Don't need to forward status to the locations
	if($type != "sync") {
		if(!isset($json_array['fullname'])) unset($group_details['fullname']);					// Unset any parameters that weren't passed
		if(!isset($json_array['description'])) unset($group_details['description']);			//	unless the action is to sync
		if(!isset($json_array['comment'])) unset($group_details['comment']);					//
	}
	if(empty($group_id) || $status <> "success") {
		exit_now("Error processing group_id {$group_id} for {$shortname} with status {$status}");
	}
	$json_forward = $group_details;
	$json_forward['type'] = $type;
	
// Log request and get request_id
	$request_id = log_request($conn, $json, $user_id);
	
// All valid locations will be checked and commands issued where required

	for($i=0; $i < count($valid_locations); $i++) {
		if(isset($location_index[$engine_name[$i]])) {
			$location_id = $i;																				// If there is location info, use i as the index, otherwise -1
		} else {
			$location_id = -1;
		}
		$json = $json_forward;																				// start with base json
		if(isset($new_quota[$i])) {																			// if there is quota info and it isn't empty, add to JSON
			if(!empty($new_quota[$i])) {
				$json['quota'] = $new_quota[$i];
			}
		}																			
		if(isset($new_timeout[$i])) {																		// if there is timeout info and it isn't empty, add to JSON
			if(is_numeric($new_timeout[$i])) {
				$json['timeout'] = $new_timeout[$i];
			}
		}																			
		if(isset($new_action[$i])) {																		// if there is action info and it isn't empty, add to JSON
			if(!empty($new_action[$i])) {
				$json['action'] = $new_action[$i];
			}
		}
		if($use_all_valid_locations || (!$use_all_valid_locations && $location_id <> -1)) {
			$json['group_id'] .= substr("00{$engine_id[$i]}",-3);									// convert from group id, to location-specific by add 3 digit location id to end
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
	
	logit("INFO","groupfunctions API completed");	
	
//-------------------------- Functions ----------------------------
function log_request($conn, $json, $user_id) {
	$ssql = "INSERT into requests (json_in, user_id, function, status, status_return, json_sent)
			 VALUES ('{$json}', '{$user_id}', 'groupfunctions', 'new', '', 'see messages')";
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
			 VALUES ('{$request_id}', '{$location_id}', '{$tag}', 'groupmanage.php', '{$json}', '{$status}')";
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

function add_group($conn, $shortname, $fullname, $description, $comment) {
	$return_array = array();
	if($comment <> "") {
		$comment_sql = "'{$comment}'";
	} else {
		$comment_sql = "null";
	}
	$ssql = "INSERT into slayer_api.groups (shortname,name,description,comment)
			VALUES ('{$shortname}', '{$fullname}', '{$description}', {$comment_sql})";
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to add group entry to DB");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to add group {$shortname}");
		$return_array['status'] = "error";
		$group_id = 0;
	} else {
		$return_array['status'] = "success";
		$return_array['group_id'] = $conn->lastInsertId();
		$return_array['shortname'] = $shortname;
		if($fullname <> "") {
			$return_array['name'] = $fullname;
		}
		if($description <> "") {
			$return_array['description'] = $description;
		}
		if($comment <> "") {
			$return_array['comment'] = $comment;
		}
	}
	return $return_array;
}

function update_group($conn, $shortname, $group_id, $sql_update) {
	$return_array = array();
	if($group_id <> 0) {
	// We don't want gaps in the id, so we use a prepared statement to reset the autoincrement before we insert	
		$ssql = "SET @max_id = (SELECT if(isnull(MAX(id)),1,MAX(id)+1) FROM slayer_api.groups);
					SET @sql = CONCAT('ALTER TABLE slayer_api.groups AUTO_INCREMENT = ', @max_id);
					PREPARE st FROM @sql;
					EXECUTE st;
					UPDATE slayer_api.groups SET {$sql_update}
						where id = '{$group_id}'";
		logit("SQL",$ssql);	
		$results=try_conn($conn, $ssql, "", "Failed to update group {$shortname}");
		if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
			logit("ERROR","Failed to update group {$shortname}");
			$return_array['status'] = "error";
			$group_id = 0;
		} else {
			$return_array['status'] = "success";
			$return_array['group_id'] = $group_id;
		}
	} else {
		logit("ERROR","No valid id ({$group_id}) passed for group {$shortname}");
		$return_array['status'] = "error";
		$group_id = 0;
	}
	return $return_array;
}

function get_group($conn, $shortname, $group_id = "") {
	$return_array = array();
	if($group_id <> "") {
		$id = substr($group_id, 0, -3);
		$ssql = "SELECT * from slayer_api.groups WHERE id = '{$id}'";
	} else {
		$ssql = "SELECT * from slayer_api.groups WHERE shortname = '{$shortname}'";
	}
	logit("SQL",$ssql);	
	$results=try_conn($conn, $ssql, "", "Failed to get group id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get group id for {$shortname}");
		$return_array['status'] = "error";
		$group_id = 0;
	} else {
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		if(count($rows) > 0) {
			$group_id = $rows[0]['id'];
			$return_array['status'] = "success";
			$return_array['group_id'] = $rows[0]['id'];
			$return_array['shortname'] = $rows[0]['shortname'];
			$return_array['name'] = $rows[0]['name'];
			$return_array['description'] = $rows[0]['description'];
			$return_array['comment'] = $rows[0]['comment'];
		} else {
			$group_id = 0;
			$return_array['status'] = "error";
			$return_array['group_id'] = "Failed to get group id for {$shortname}";
		}
	}
	return $return_array;
}
?>