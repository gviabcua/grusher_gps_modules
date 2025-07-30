<?php
    define("WORK_DIR", dirname(__FILE__));
	require_once (WORK_DIR."/config.php");

    $get_devices = @json_decode(getTracarInfoFromApi($TRACCAR_URL. 'devices', $TRACCAR_USERNAME, $TRACCAR_PASSWORD), true);
    $get_positions= @json_decode(getTracarInfoFromApi($TRACCAR_URL. 'positions', $TRACCAR_USERNAME, $TRACCAR_PASSWORD), true);
    $returner = [];
    if(($get_devices != null) and is_array($get_devices) and !empty($get_devices)){
        foreach ($get_devices as $rec){
            if(isset($rec['id'])){
                $returner[$rec['id']]['id'] = $rec['id'];
                $returner[$rec['id']]['uniqueId'] = $rec['uniqueId'];
                $returner[$rec['id']]['lastUpdate'] = isset($rec['lastUpdate']) ? $rec['lastUpdate'] : null;
                if($returner[$rec['id']]['lastUpdate'] != null){
                    $date_t = @date_create($returner[$rec['id']]['lastUpdate']);
                    $returner[$rec['id']]['lastUpdate'] = @date_format($date_t, 'Y-m-d H:i:s');
                }
            }
        }
    }
    if(($get_positions != null) and is_array($get_positions) and !empty($get_positions)){
        foreach ($get_positions as $recp){
            if(isset($recp['deviceId'])){
                $returner[$recp['deviceId']]['lat'] = isset($recp['latitude']) ? $recp['latitude'] : null;
                $returner[$recp['deviceId']]['lon'] = isset($recp['longitude']) ? $recp['longitude'] : null;
                $returner[$recp['deviceId']]['speed'] = isset($recp['speed']) ? $recp['speed'] : null;
            }
        }
    }
    if(!empty($returner)){
        foreach($returner as $rec){
            if(isset($rec['uniqueId']) and isset($rec['lat']) and isset($rec['lon']) and ($rec['lat'] != null) and ($rec['lon'] != null)){
                $request = "&tracker_id=".urlencode($rec['uniqueId']);
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
    function getTracarInfoFromApi($url, $username, $password){
	    $ch = curl_init();
	    $options = [
	        CURLOPT_URL            => $url,
	        CURLOPT_HEADER         => false,
	        CURLOPT_HTTPGET        => true,
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
	        CURLOPT_USERPWD        => $username . ':' . $password,
	        //CURLOPT_FOLLOWLOCATION => $follow_location,
	        CURLOPT_SSL_VERIFYPEER => false,
	        CURLOPT_SSL_VERIFYHOST => false,
	    ];
	    curl_setopt_array($ch, $options);
	    $response = curl_exec($ch);
	    curl_close($ch);
	    return $response;
	}
?>