<?php
    define("WORK_DIR", dirname(__FILE__));
    require_once (WORK_DIR."/config.php");
    require_once (WORK_DIR."/functions.php");
    clilog("Starting script...");
    clilog("PROTOCOLS DIR is ".WORK_DIR."/protocols");
    clilog("LOGS DIR is ".WORK_DIR."/logs");
    clilog("Checking PHP path");
    $php_path = trim(shell_exec("which php"));
    if(strlen($php_path) > 6){
        clilog("PHP path is $php_path");
        clilog("Checking PHP bin exist");
        $php_path_check = trim(shell_exec($php_path ."  -version"));
        if (strpos($php_path_check, "Copyright") !== false) {
            clilog("PHP bin checked successfull");
        }else{
            die("PHP bin '$php_path' not correct");
        }
        clilog("Checking protocols");
        if(isset($protocols_ports)){
            if(is_array($protocols_ports) and !empty($protocols_ports)){
                clilog("Available protocols &port: " .http_build_query($protocols_ports,'',', '));
                clilog("");
                clilog("Starting protocols listening");
                foreach($protocols_ports as $protocol => $port){
                    $filepath = WORK_DIR."/protocols/".$protocol.".php";
                    if (file_exists($filepath)) {
                        clilog("Checking ".$protocol." for free port $port...");
                        if(isPortAvailable($localHostIp, $port, 2) == 1){
                            clilog("Starting ".$protocol." in background on port $port...");
                            shell_exec($php_path ." ".WORK_DIR."/protocols/".$protocol.".php -p $port > /dev/null 2>&1 &");
                        }else{
                            clilog("SKIP: port ".$port." already in use");
                        }
                    } else {
                        clilog("ERROR: ".$protocol." not found");
                    }
                }
            }else{
                die("Variable protocols_ports is empty. Uncomment needed protocols");
            }
        }else{
            die("Variable protocols_ports not found in config.php");
        }
    }else{
        die("PHP not found");
    }


?>