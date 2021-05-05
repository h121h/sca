<?php
//Usage : 
//  curl -X POST -k -u username:api_key 'https://169.44.167.82/buildenvironment.php' --data '{json}' 

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
	$json_array=json_decode($json,true);							// Break the JSON out into an array
	$node_array = array();
	
// Log the request
	$ssql = "INSERT into requests (json_in, user_id, function, status)
			 VALUES ('{$json}', '{$user_id}', 'buildenvironment', 'new')";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		exit_now("Currently unable to access database");
	}	
	$request_id = $conn->lastInsertId();

// Get the location variable
	$location = check_array($json_array,"environment",'location');
	
// Before checking the json for the nodes, validate the location. If no location specified, default is svl.
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
	
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://127.0.0.1/checkenvironmentjson.php' --data '{$json}'";
	logit("CMD", "Check environment command: {$cmdd}");
	$output = shell_exec($cmdd);
	logit("INFO", "Output: $output");
	$json_return=json_decode($output,true);
	$status = check_array($json_return,'status');	

// If errors found, return json with error details	
	if($status <> "success") {
		$json_errors = check_array($json_return,'details');
		$myObj = new stdClass();
		$myObj->json_errors = $json_errors;
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
	$json_sent = json_encode($json_array);
	$ssql = "update requests SET status = 'sent',
			engine_id = '{$engine_id}',
			json_sent = '{$json_sent}'
			WHERE id = {$request_id}";
	logit("SQL",$ssql);
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to update request on send");
	}
	$cmdd = "curl -X POST -k -u '{$username}:{$api_key}' 'http://{$engine_ip}:{$engine_port}/buildenvironment.php' --data '{$json_sent}'";
	$cmdd_obscured = str_replace($api_key, "<api_key>", $cmdd);
	logit("CMD", "Build environment command: {$cmdd_obscured}");
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
	if(is_array($status_details)) {
		$status_sql = json_encode($status_details);
	} else {
		$status_sql = $status_details;
	}
	$ssql = "update requests SET status = '{$status}',
		status_return = '{$status_sql}',
		response = '{$status_sql}',
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
		$myObj->status = $status;
		$myObj->details = $status_details;
		$myObj->environment_id = $environment_id;
	} else {
		$myObj->status = $status;
		$myObj->details = $status_details;
	}
	$myJSON = json_encode($myObj, JSON_UNESCAPED_SLASHES);
	echo $myJSON;
	logit("INFO","buildenvironment API completed");



//ONLY FUNCTIONS BELOW ------------------------------------------------------------------------------------------------------------

function is_valid_domain_name($domain_name)
{
    return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain_name) //valid chars check
            && preg_match("/^.{1,253}$/", $domain_name) //overall length check
            && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name)   ); //length of each label
}




function isValidString($str) {
    return !preg_match('/[^A-Za-z0-9.#\\-$!]/', str_replace(' ', '', $str));
}


?>