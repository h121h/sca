<?php
//Usage : curl -X POST -k -u username:api_key 'http://169.44.167.82/getrequeststatus.php?request=XX'

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
	$results=try_conn($conn, $ssql, "", "Failed to get user id");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get user id");
		exit_now("Failed to get user id");
	}	
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
	$user_id = $rows[0]['id'];
	
// Check for request id in GET data	
	if(!empty($_GET['request'])) {
	// Break the request into job_id and location_id
		$location = ltrim(substr($_GET['request'],-3),"0");
		$job_id = substr($_GET['request'],0,-3);
		if(!is_numeric($location) || !is_numeric($job_id)) {
			logit("ERROR","Invalid request id");
			exit_now("Invalid request id");
		}
		logit("INFO","Status request for request_id {$_GET['request']}");
	// get location info from db
		$ssql = "SELECT * from location WHERE id = '{$location}'";
		logit("SQL","$ssql");
		$results=try_conn($conn, $ssql, "", "Failed to get location info");
		if (gettype($results) == "integer") {
			logit("ERROR","Failed to get location info");
			exit_now("Invalid request id");
		}	
		$rows = $results->fetchAll(PDO::FETCH_BOTH);
		if(count($rows) < 1) {
			logit("ERROR","Location not found - {$location}");
			exit_now("Invalid request id");
		} else {
			$engine_id = $rows[0]['id'];
			$engine_ip = $rows[0]['engine_ip'];
			$engine_port = $rows[0]['port'];
		}	
	// Direct the call to the proper engine
		$json_array = array();
		$json_array["type"] = "buildprogress";
		$json_array["job_id"] = $job_id;
		$json_sent = json_encode($json_array);
		$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/listinfo.php' --data '{$json_sent}'";
		$cmdd_obscured = str_replace($api_key, "<api_key>", $cmdd);
		logit("CMD", "Get request status command: {$cmdd_obscured}");
		$output = shell_exec($cmdd);
		logit("INFO", "Output: $output");
		$json_return=json_decode($output,true);
		$status = check_array($json_return,'status');
		$job_id = check_array($json_return,'job_id');
		$complete = check_array($json_return,'percent_complete');
		$environment_id = check_array($json_return,'environment_id');
		$library_id = check_array($json_return,'library_id');
		$type = check_array($json_return,'type');
		if($complete == "") {
			$status_details = "";
		} else {
			$status_details = "{$type} {$complete} percent complete";
			$progress = $complete;
		}
		if($status == "") {
			$status = "failed";
			$status_details = "Engine failed to respond";
		}
		
	// Log and exit
		$myObj = new stdClass();
		$myObj->status = $status;
		if($status_details <> "") $myObj->details = $status_details;
		if($progress <> "") $myObj->progress = $progress;
		if($environment_id <> "") $myObj->environment_id = $environment_id;
		if($library_id <> "") $myObj->library_id = $library_id;
		$myJSON = json_encode($myObj);
		echo $myJSON;
		logit("INFO","getrequeststatus API completed");
	} else {
		logit("ERROR","No request id passed");
		exit_now("Request id missing");
	}
?>