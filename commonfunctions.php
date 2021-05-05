<?php
global $db;
$enable_sql_logging = true;			// Set to false to cause any logging requests with type 'SQL' to be excluded from all.log (will go to sql.log).
$ssh_parm=" -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=5 ";
$api_key = ""; 			// Placeholder for api key to allow anonymization during logging

function getAuthINLINE() {

        global $conn;

        if (!isset($_SERVER['PHP_AUTH_USER'])) {
                header('WWW-Authenticate: Basic realm="My Realm"');
                header('HTTP/1.0 401 Unauthorized');
                echo 'error';
        } else {
                $username=$_SERVER['PHP_AUTH_USER'];
                $apikey=$_SERVER['PHP_AUTH_PW'];
        }

        if(isset($username) and isset($apikey)) {
                $ssql = "select id from users where lower(username)='" . strtolower($username) ."' and apikey = '{$apikey}' and apikey!='' and disabled is null";
				logit("SQL",$ssql);
                $result=$conn->query($ssql);
        } else {
                return("error");
        }

        if($result->rowcount() > 0 )  {
                return($username);
        } else {
                return("error");
        }
}

function connectMariadb($servername, $username, $password, $db_database) {
try {
    $t_conn = new PDO("mysql:host=$servername;dbname=$db_database", $username, $password);
    // set the PDO error mode to exception
    $t_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $t_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH);
	return $t_conn;
    }
catch(PDOException $e)
    {
    echo "Connection failed: " . $e->getMessage();
	return 0;
    }
}

function try_conn($conn_db, $ssql, $success_text, $error_txt) {
	try {
		$result = $conn_db->query($ssql);
		// set the PDO error mode to exception
		// if($success_text <> "") logit("info", $success_text, "conn");
		return $result;
	}
	catch(PDOException $e) {
		if($error_txt <> "") logit("error", $error_txt . " " . $e->getMessage() . " - SQL " .  $ssql, "error");		
		return 0;
	}
}

function check_value($value) {
	if(!empty($value)) {
		$return = trim($value);
	} else {
		$return = '';
	}
	return $return;
}

function check_array($array, $value1, $value2="") {
	if(isset($array[$value1])) {
		if($value2 === "") $return = $array[$value1];
		else {
			if(isset($array[$value1][$value2])) { 
				$return = $array[$value1][$value2];
			} else $return = '';
		}
	} else {
		$return = '';
	}
	return $return;
}

function check_GET($value) {
	if(!empty($_GET[$value])) {
		$return = trim($_GET[$value]);
	} else {
		$return = '';
	}
	return $return;
}

function check_POST($value) {
	if(!empty($_POST[$value])) {
		$return = trim($_POST[$value]);
	} else {
		$return = '';
	}
	return $return;
}

function check_SQL($array, $value) {
	if(isset($array[$value])) {
		$return = trim($array[$value]);
	} else {
		$return = '';
	}
	return $return;
}

function check_JSON($array, $value, $trim="yes") {
	if(isset($array[$value])) {
		if($trim == "yes") $return = trim($array[$value]);
		else $return = $array[$value];
	} else {
		$return = '';
	}
	return $return;
}

function check_pass($pass="") {
	global $db;
	if($pass == "") $$pass = check_GET('pass');
	if($pass == "") $pass = check_POST('pass');
	if ($pass == $db["fn_password"]) { 
		return true;
	}
	return false;
}

// issue curl command with post data
function curl_post($url, $post_input, $username, $apikey) 
{ 
// post fields will be built from an array. If an array was passed, json encode it, otherwise, use input as is
	$post = array();
	if(is_array($post_input)) {
		$post = json_encode($post_input);
	} else {
		$post = $post_input;
	}
    $defaults = array( 
        CURLOPT_POST => 1, 
        CURLOPT_HEADER => 0, 
        CURLOPT_URL => $url, 
        CURLOPT_FRESH_CONNECT => 1, 
        CURLOPT_RETURNTRANSFER => 1, 
        CURLOPT_FORBID_REUSE => 1, 
        CURLOPT_TIMEOUT => 50,
        CURLOPT_USERPWD => "{$username}:{$apikey}", 
        CURLOPT_POSTFIELDS => $post
    ); 

    $ch = curl_init(); 
    curl_setopt_array($ch, ($defaults)); 
    $result = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if($httpCode == '404') {
		$result = "404";
	}
    curl_close($ch); 
    return $result; 
} 	

// update a task in the db
function update_task($id, $status, $task_state = "") {
	global $conn;
	if(!empty($id)) {
		$ssql = "Update slayer.tasks Set `status` = '{$status}', `task_state` = '{$task_state}'
				 WHERE `id` = '{$id}'";
		//get backtrace data (information about the file that called this function)
		$backtrace = debug_backtrace();
		$current_file = basename($backtrace[0]['file']);
		$current_file_formatted = sprintf("%-27s", "$current_file");
		
		//get the filename and line number that called this (logit) function
		$line_number = $backtrace[0]['line'];
		$line_number_formatted = sprintf("%s%4d", "Line ", $line_number);

		logit("SQL", "{$current_file_formatted} {$line_number_formatted} : Insert task - SQL: {$ssql}", "task_sql");
		// echo "Insert task - SQL: {$ssql}\n";
		$return=try_conn($conn, $ssql, "", "Failed to update task entry in DB");
	}
}

// Put a task in the db
function create_task($job_id, $category, $sequence, $process, $params, $status, $task_state, $prior_task, $process_id) {
	global $conn;
	$ssql = "INSERT into slayer.tasks (job_id,job_cat,sequence,process,parameters,status,task_state,prior_task,process_id)
			 VALUES ('{$job_id}', '{$category}', '{$sequence}', '{$process}', '{$params}', '{$status}', '{$task_state}', '{$prior_task}', '{$process_id}')";
	logit("SQL","$ssql");	
	$results=try_conn($conn, $ssql, "", "Failed to add task entry to DB");
	return $conn->lastInsertId();
}

function sanitize_json($json){
	$pipe_character = strpos($json, '|');
	if($pipe_character !== false){
		//the character | was found
		echo "invalid character (pipe '|') found in json".PHP_EOL;
		$json = str_replace("|","PIPE_CHARACTER",$json);
		logit("info", "Json received: $json");
		logit("error", "Invalid character (pipe '|') found in json...exiting.");
		exit;
	} else {
		//ok
	}
	
	$single_quote_character = strpos($json, "'");
	if($single_quote_character !== false){
		//the character | was found
		$json = str_replace("'","single_quote",$json);
	} else {
		//ok
	}

	//select <something> from <table_name>
	//drop table <table_name>
	//delete from <table_name>
	//update <table_name> set 
	
	return $json;
}

function lockEnvironmentTable($environment_id) {
	global $conn;	
// Check if environment is already locked	
	$ssql="select `lock` from slayer.environment where id = '{$environment_id}'";
	logit("SQL","Locking environment table for {$environment_id} - SQL: {$ssql}");
	$results=try_conn($conn, $ssql, "", "Failed to get environment detail from db");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to get environment detail from db for environment {$environment_id}");
		return "";
	}
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
// Make sure that exactly one environment is returned	
	if(count($rows) <> 1) {
		$quantity_returned = count($rows);
		logit("ERROR","Incorrect number of environments returned for environment {$environment_id} ({$quantity_returned})");
		return "";
	}
// Check if is already locked
	$lock_string = check_SQL($rows[0],"lock");
	if(!empty($lock_string)) {
		logit("ERROR","Environment {$environment_id} already locked");
		return "";
	}
// Not locked, so lock	
	$lock_key=zRandomString();
	$ssql="update slayer.environment SET `lock` = '{$lock_key}'where id = '{$environment_id}'";
	logit("SQL","$ssql");
	$results=try_conn($conn, $ssql, "", "Failed to set environment lock");
// Make sure that the lock was attained
	$locked="no";
	$ssql="select `lock` from slayer.environment where id = '{$environment_id}' and `lock` = '{$lock_key}'";
	logit("SQL","Checking lock for {$environment_id} - SQL: {$ssql}");
	$results=try_conn($conn, $ssql, "", "Failed to check environment lock in db");
	if (gettype($results) == "integer") {												// if the return is an integer, the db read failed
		logit("ERROR","Failed to check environment lock in db for environment {$environment_id}");
		return "";
	}
	$rows = $results->fetchAll(PDO::FETCH_BOTH);
// Make sure that exactly one environment is returned	
	if(count($rows) <> 1) {
		$quantity_returned = count($rows);
		logit("ERROR","Incorrect number of environments returned for environment {$environment_id} ({$quantity_returned})");
		return "";
	}
// Check if is lock is the same that was set
	$lock_string = check_SQL($rows[0],"lock");
	if($lock_key != $lock_string) {
		logit("ERROR","Environment {$environment_id} already locked with different key");
		return "";
	}
	return($lock_key);
}	


function unlockEnvironmentTable($environment_id,$lock_key) {
	global $conn;
	$ssql="update slayer.environment SET `lock` = null where `lock` = '{$lock_key}' and id = '{$environment_id}'";
	logit("SQL","Unlocking environment {$environment_id}: {$ssql}");
	$results=try_conn($conn, $ssql, "", "Failed to set environment lock");
}


function sendPost($url,$params)
{
  $postData = '';
   //create name value pairs seperated by &
   foreach($params as $k => $v) 
   { 
      $postData .= $k . '='.$v.'&'; 
   }
   $postData = rtrim($postData, '&');
 
    $ch = curl_init();  
 
    curl_setopt($ch,CURLOPT_URL,$url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_HEADER, false); 
    curl_setopt($ch, CURLOPT_POST, count($postData));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);    
 
    $output=curl_exec($ch);
 
    curl_close($ch);
    return $output;
 
}

function zappendFile($file,$strline) {		
	file_put_contents($file, $strline, FILE_APPEND);
} 


function zdateTime() {
	date_default_timezone_set('America/Los_Angeles');
	$dt = date_create()->format('Y-m-d H:i:s');
	return $dt;
}

//logs $string_to_log
function logit($type, $string_to_log, $specific_log_file="") {
    global $environment_id;
	global $enable_sql_logging;
	global $api_key;
    //Every call to this function gets logged to /var/log/slayer/all.log, unless otherwise specified
    //If $specific_log_file is supplied, the event is also logged to that file
    
	if(strtolower($type) == "sql" and !$enable_sql_logging) $base_log_file="sql";
    else $base_log_file="all";
	$date_time = date("Y-m-d H:i:s");
    
	//get backtrace data (information about the file that called this function)
	$backtrace = debug_backtrace();
	$current_file = basename($backtrace[0]['file']);
	$current_file_formatted = sprintf("%-27s", "$current_file");
	
    //get the filename and line number that called this (logit) function
    $line_number = $backtrace[0]['line'];
	$line_number_formatted = sprintf("%s%4d", "Line ", $line_number);
    
    //remove linebreaks from $string_to_log
    $string_to_log = trim(preg_replace('/\s+/', ' ', $string_to_log));
	$string_to_log = str_ireplace($api_key,"<api_key>", $string_to_log);
    
	//format the $type ([error   ], [info    ], etc)
	$type_formatted = sprintf("[%-8s]", strtoupper($type));
    
	//generate the full string to send to the log file
    $full_string_to_log = "$date_time : $current_file_formatted $line_number_formatted : $type_formatted $string_to_log".PHP_EOL;
    //write everything to the base log, defaults to "all" log
    $log_file = "/var/log/slayer/{$base_log_file}.log";
    file_put_contents($log_file, $full_string_to_log, FILE_APPEND);

    if($specific_log_file <> "") {
		$log_file = "/var/log/slayer/{$specific_log_file}.log";
		file_put_contents($log_file, $full_string_to_log, FILE_APPEND);
	}
    
}

//generates a random string (most often used for locking rows in a table)
function zRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-!+';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function generateStrongPassword($length = 10, $available_sets = 'luds')
{
	$sets = array();
	if(strpos($available_sets, 'l') !== false)
		$sets[] = 'abcdefghjkmnpqrstuvwxyz';
	if(strpos($available_sets, 'u') !== false)
		$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
	if(strpos($available_sets, 'd') !== false)
		$sets[] = '23456789';
	if(strpos($available_sets, 's') !== false)
		$sets[] = '-+!@#*?';

	$all = '';
	$password = '';
	foreach($sets as $set)
	{
		$password .= $set[array_rand(str_split($set))];
		$all .= $set;
	}

	$all = str_split($all);
	for($i = 0; $i < $length - count($sets); $i++)
		$password .= $all[array_rand($all)];

	$password = str_shuffle($password);

	return $password;
}

function exit_now($str) {
	$myObj = new stdClass();
	$myObj->status = "error";
	$myObj->details = $str;
	$myJSON = json_encode($myObj);
	echo $myJSON;
	exit;
}




function getKvmFromVm($vm_name) {
	global $conn;
	$ssql = "select kvm_id as kvm_host_id from environment where id in (select class_id from node where vm_name ='{$vm_name}')";
	$results =$conn->query($ssql);
	$kvm_host_id="";
	foreach($results as $row) {
		$kvm_host_id= $row['kvm_host_id'];
		return($kvm_host_id);
	}
	if($kvm_host_id=="") {
		return("error");
	}	
}
?>