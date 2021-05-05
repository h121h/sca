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
			 VALUES ('{$json}', '{$user_id}', 'buildenvironment', status = 'new')";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to log request");
	if (gettype($results) == "integer") {
		logit("ERROR","Failed to log request");
		exit_now("Currently unable to access database");
	}	
	$request_id = $conn->lastInsertId();
	$json_errors = array(); $json_errors[0] = "";
	$error_index = 0;

// Get the top level variables	
	$node_count = 0;
	logit("INFO","Reading data from JSON ");
	$location = check_array($json_array,"environment",'location');		
	$environment_name = check_array($json_array,"environment",'environment_name');
	$description = check_array($json_array,"environment",'description');
	$domain = check_array($json_array,"environment",'domain');
	
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
	
	for($i = 0; $i < count($json_array["environment"]["nodes"]); $i++) {								// Parse the node data
	
		$node_def = $json_array["environment"]["nodes"][$i];
		$platform = check_array($node_def,'platform');
		$instance_type = check_array($node_def,'instance_type');
		$cpu = check_array($node_def,'cpu');		
		$memory = check_array($node_def,'memory');		
		$disk = check_array($node_def,'disk');
		$os = check_array($node_def,'os');
		$ports = check_array($node_def,'ports');	
		$public_networking = check_array($node_def,'public_networking');		
		$private_networking = check_array($node_def,'private_networking');		
		$tags = check_array($node_def,'tags');
		if($instance_type == 'floating') {
			$floating = true;
		} else {
			$floating = false;
		}
			
	//validation 
	
		for($j = 0; $j < count($node_def['name']); $j++) {				// Each node definition can have multiple nodes, all identical except for the name
			$node_array[$node_count] = check_array($node_def,'name',$j);
			//name : check if the name is blank or has invalid characters			
				$hostname =$node_array[$node_count];
				if(strlen($hostname)<1) {
					$json_errors[$error_index++] = "Missing hostname for node : $node_count ";
				}
				if(strpos("x" . $hostname,".")>0) {
					$json_errors[$error_index++] = "Node $node_count :  '.' - periods not allowed in hostname";
				}
				$check_hostname = is_valid_domain_name($hostname);
				if($check_hostname != 1) {
					$json_errors[$error_index++] = "Node $node_count : hostname ({$hostname}) {$check_hostname} has invalid characters";
				} else {
					if(strpos("x".trim($hostname), " ") > 0) {
						$json_errors[$error_index++] = "Node $i : hostname cannot contain spaces. ";
					}
				}
				for($k=0; $k < $node_count; $k++) {
					if($hostname == $node_array[$k]) {
						$node_ref = $node_count + 1;
						$json_errors[$error_index++] = "Node {$node_ref} : Duplicate hostname {$hostname}";
					}
				}
			$node_count++;	
		}
				
		// Platform : cannot check for invalid platform until sent to engine
		if($platform == "") $json_array["environment"]["nodes"][$i]['platform'] = 'x';
		
		// Instance : check for invalid instance type
		if($instance_type == "") $json_array["environment"]["nodes"][$i]['instance_type'] = 'vm';
		if($instance_type != 'vm' and $instance_type != 'baremetal' and !$floating) {
				$json_errors[$error_index++] = "Node definition $i : invalid instance_type - {$instance_type}";
		}
		
		if(!$floating) {
			// CPU : check for invalid  should be between 1 - 16. If blank, set to default 2.
			if($cpu == "") $json_array["environment"]["nodes"][$i]['cpu'] = '2';
			if(!((1 <= $cpu) && ($cpu <= 16))) {
				$json_errors[$error_index++] = "Node definition $i : invalid cpu count - {$cpu} ";
			}
			
			// Memory  : check for invalid  should be between 1 - 64. If blank, set to default 4.
			if($memory == "") $json_array["environment"]["nodes"][$i]['memory'] = '4';
			if(!((1 <= $memory) && ($memory <= 64))) {
				$json_errors[$error_index++] = "Node definition $i : invalid memory value - {$memory} ";
			}
		
			// Disk : Check if disk values are between 1 and 1000
			if(!empty($disk)) {
				if(count($disk) > 0) {
					for($k = 0; $k < count($disk); $k++) {
						if(($disk[$k] < 1) || ($disk[$k] > 1000)) {
							$json_errors[$error_index++] = "Node definition $i : invalid disk value for disk $k - {$disk[$k]}";
						}
					}
				}
			}
			
			// OS : Can only check if OS is missing until it is sent to engine
			if($os == "") {
				$json_errors[$error_index++] = "Node definition $i : no operating system specified";
			}
		} else {
			$json_array["environment"]["nodes"][$i]['cpu'] = 0;
			$json_array["environment"]["nodes"][$i]['memory'] = 0;
		}
		
		// Ports - check for invalid ports
		if(!empty($ports)) {
			if(count($ports) > 0) {
				for($k = 0; $k < count($ports); $k++) {
					if(($ports[$k] < 1) || ($ports[$k] > 65000)) {
						$json_errors[$error_index++] = "Node definition $i : invalid value for port - {$ports[$k]}";
					}
				}
			}
		}
				
		//networking
		$public = strtolower(substr($public_networking,0,1));			//convert to lower case and use only a single character
		$private = strtolower(substr($private_networking,0,1));
		if(($public == "" and $private == "") or ($public == "n" and $private == "n")) {
			$json_array["environment"]["nodes"][$i]['public_networking'] = "y";
			$json_array["environment"]["nodes"][$i]['private_networking'] = "n";
		} else {
			if($public != "y" and $public != "n") {
				$json_errors[$error_index++] = "Node definition $i : invalid specification for public network - {$public}";
			}
			if($private != "y" and $private != "n") {
				$json_errors[$error_index++] = "Node definition $i : invalid specification for private network - {$private}";
			}
		}
		
		// tags: Make sure that there are no illegal characters in the tags
		if(!empty($tags)) {
			$tag_check = implode(" ",$tags);					// Implode the tags to a single string to get checked.
			$check_tags = isValidString($tag_check);
			if(!$check_tags) {
				$json_errors[$error_index++] = "Tags, {$tag_check}, include invalid characters";
			}
		}
	}


// Validation for top level variables
// Environment name : check if the name is blank or has invalid characters	
	if(strlen($environment_name)<1) {
		$json_errors[$error_index++] = "Missing environment name";
	} else {
		$check_environment_name = isValidString($environment_name);
		if(!$check_environment_name) {
			$json_errors[$error_index++] = "Environment name, {$environment_name}, has invalid characters";
		}
	}

// Environment description : check if the description has invalid characters	
	$check_description = isValidString($description);
	if($description != "") {
		if(!$check_description) {
			$json_errors[$error_index++] = "Description, {$description}, has invalid characters";
		}
	}

// Environment domain : check if the domain has invalid characters	
	$check_domain = isValidString($domain);
	if($domain != "") {
		if(!$check_domain) {
			$json_errors[$error_index++] = "Domain, {$domain}, has invalid characters";
		}
	}
	
// If errors found, return json with error details	
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
		exit_now("Invalid JSON - {$myJSON}");
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
	$cmdd = "curl -X POST -k -u {$username}:{$api_key} 'http://{$engine_ip}:{$engine_port}/buildenvironment.php' --data '{$json_sent}'";
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