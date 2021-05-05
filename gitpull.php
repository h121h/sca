<?php
logit("info", "git push initiated - engine");
// Get system hostname
	$cmdd1 = "hostname";
	$hostname = trim(shell_exec($cmdd1));

// Determine which branch to pull
	if($hostname == "slapisjcdev1") {
		$branch = "development";
	} else {
		$branch = "master";
	}

// Pull from git
if (!empty($_POST['payload'])) {
	$payload = json_decode($_POST['payload'],true);
	$repository_name = check_array($payload, "repository", "name");
	$ref = check_array($payload, "ref");
	if((($ref == "refs/heads/development" and $branch == "development") or ($ref == "refs/heads/master" and $branch == "master")) and $repository_name == "slayer_api") {
		$cmdd = "cd /var/www/html && sudo -u www-data -H git pull origin {$branch}";
		logit("cmdd", "repository = {$repository_name}, hostname = {$hostname}, branch = {$branch}, command = {$cmdd}");
		$exec_return = shell_exec($cmdd);
		logit("info", "push complete, response = {$exec_return}");
	} else {
		logit("info", "branch: {$ref} not for repository {$repository_name}");
	}
} else {
	echo "not downloaded\n";
    logit("error", "not downloaded - no git payload");
}

//-------------------- Functions --------------------

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

//logs $string_to_log
function logit($type, $string_to_log, $base_log_file="all", $specific_log_file="") {
    //Every call to this function gets logged to /var/log/git/all.log
    //If $specific_log_file is supplied, the event is also logged to that file

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

        //format the $type ([error   ], [info    ], etc)
        $type_formatted = sprintf("[%-8s]", strtoupper($type));

        //generate the full string to send to the log file
        $full_string_to_log = "$date_time : $current_file_formatted $line_number_formatted : $type_formatted $string_to_log".PHP_EOL;
    //write everything to the base log, defaults to "all" log
    $log_file = "/var/log/git/{$base_log_file}.log";
    file_put_contents($log_file, $full_string_to_log, FILE_APPEND);

    if($specific_log_file <> "") {
                $log_file = "/var/log/git/{$specific_log_file}.log";
                file_put_contents($log_file, $full_string_to_log, FILE_APPEND);
        }

}
?>