<?php
    define("WORK_DIR", dirname(__FILE__));
	require_once (WORK_DIR."/config.php");

	$get_devices = @json_decode(fgc($MAPON_URL. 'unit/list.json?key='.$MAPON_API_KEY), true);
	$returner = [];
	if(($get_devices != null) and is_array($get_devices) and !empty($get_devices) and isset($get_devices['data']) and isset($get_devices['data']['units'])){
		foreach ($get_devices['data']['units'] as $rec){
			if(isset($rec['unit_id'])){
				$returner[$rec['unit_id']]['id'] = $rec['unit_id'];
				$returner[$rec['unit_id']]['unit_id'] = $rec['unit_id'];
				$returner[$rec['unit_id']]['lastUpdate'] = isset($rec['last_update']) ? $rec['last_update'] : null;
				if($returner[$rec['unit_id']]['lastUpdate'] != null){
					$date_t = @date_create($returner[$rec['unit_id']]['lastUpdate']);
					$returner[$rec['unit_id']]['lastUpdate'] = @date_format($date_t, 'Y-m-d H:i:s');
				}
				$returner[$rec['unit_id']]['lat'] = isset($rec['lat']) ? $rec['lat'] : null;
				$returner[$rec['unit_id']]['lon'] = isset($rec['lng']) ? $rec['lng'] : null;
				$returner[$rec['unit_id']]['speed'] = isset($rec['speed']) ? $rec['speed'] : null;
				
			}
		}
	}
	if(!empty($returner)){
		foreach($returner as $rec){
			if(isset($rec['unit_id']) and isset($rec['lat']) and isset($rec['lon']) and ($rec['lat'] != null) and ($rec['lon'] != null)){
				$request = "&tracker_id=".urlencode($rec['unit_id']);
				$request .= "&last_alive=".urlencode($rec['lastUpdate']);
				$request .= "&lat=".urlencode($rec['lat']);
				$request .= "&lon=".urlencode($rec['lon']);
				$request .= "&speed=".urlencode($rec['speed']);
				$request = $GRUSHER_URL."/api?key=$GRUSHER_API_KEY&cat=billing&action=set_gps".$request;
				echo "Sending to Grusher ". $request.PHP_EOL;
				fgc($request, $GRUSHER_TIMEOUT );
			}
		}
	}

    // functions 
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
        $content = file_get_contents($url, false, $context);
        return $content !== false ? $content : null;
    }
?>