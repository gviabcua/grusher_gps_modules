<?php
    function clilog($text = ""){
        $log = date("Y-m-d H:i:s") . " - " . $text.PHP_EOL;
        echo $log;
        global $write_start_script_log;
        if($write_start_script_log == 1){
            file_put_contents(WORK_DIR."/logs/start_script_".date("Ymd").".log", $log, FILE_APPEND);
        }
        usleep(50000);
        return true;
    }
    function clilogTracker($text = "", $protocol="XXX"){
        $log = date("Y-m-d H:i:s") . " - " . $text.PHP_EOL;
        echo $log;
        global $write_gps_log;
        if($write_gps_log == 1){
            file_put_contents(WORK_DIR."/logs/raw_".$protocol."_".date("Ymd").".log", $text . "\n", FILE_APPEND);
        }
        usleep(50000);
        return true;
    }

    function isPortAvailable($host, $port, $timeout = 1) {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            fclose($connection); 
            return 0; // not available
        } else {
            return 1; // available
        }
    }
	//sendToGrusher($tracker_id = null, $data = ["lat" => $lat, "lon" => $lon, "last_alive" => $last_alive, "speed" => $speed, "angle" => $angle, "alt" => $alt,"sats" => $sats, ])
	function sendToGrusher($tracker_id = null, $data = []){
		if ($tracker_id == null)return;
		if (!is_array($data))return;
		if (empty($data))return;
		if (!isset($data['lat']))return;
		if (!isset($data['lon']))return;
		global $GRUSHER_URL;
		global $GRUSHER_API_KEY;
		global $GRUSHER_TIMEOUT;
		
		$request = "&tracker_id=".urlencode($tracker_id);
		foreach($data as $key => $value){
			$request = "&".$key."=".urlencode($value);
		}
		$request = $GRUSHER_URL."/api?key=".$GRUSHER_API_KEY."&cat=billing&action=set_gps".$request;
		echo "Sending to Grusher ". $request.PHP_EOL;
		fgc($request, $GRUSHER_TIMEOUT );

		/*
			REQUIRED				NOT REQUIRED
			-------------------------------------
			tracker_id				last_alive (datetime/timestamp ?? )
			lat						speed
			lon						angle
									alt
									battery
									sats
									protocol_name
		*/
	}
	
	function fgc($url, $timeout = 5) {
		$context = stream_context_create([
			'http' => [
				'method'  => 'GET',
				'timeout' => $timeout,
				'header'  => "User-Agent: CustomAgent/1.0\r\n"
			],
			'https' => [
				'method'           => 'GET',
				'timeout'          => $timeout,
				'header'           => "User-Agent: CustomAgent/1.0\r\n",
				'verify_peer'      => false, // Ignoring SSL
				'verify_peer_name' => false, // Ignoring HOST Checking
			]
		]);
		$content = @file_get_contents($url, false, $context);
		return $content !== false ? $content : null;
	}
?>