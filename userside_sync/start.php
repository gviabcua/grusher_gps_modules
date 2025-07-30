<?php
    define("WORK_DIR", dirname(__FILE__));
    require_once (WORK_DIR."/config.php");

    $get_tracker_list = fgc($US_URL."/api.php?key=$US_API_KEY&cat=gps&action=get_list", $US_TIMEOUT );
    if($get_tracker_list != null){
        $get_tracker_list = @json_decode($get_tracker_list);
        if(isset($get_tracker_list->Data) and !empty($get_tracker_list->Data)){
            foreach($get_tracker_list->Data as $data){
                if(isset($data->id)){
                    echo "Tracker ". $data->id.PHP_EOL;
                    $get_tracker_data = fgc($US_URL."/api.php?key=$US_API_KEY&cat=gps&action=get_info&id=".$data->id, $US_TIMEOUT );
                    if($get_tracker_data != null){
                        $get_tracker_data = @json_decode($get_tracker_data);
                        if(isset($get_tracker_data->Data) and isset($get_tracker_data->Data->id)){
                            $request = "&tracker_id=".urlencode($get_tracker_data->Data->id);
                            $request .= "&last_alive=".urlencode($get_tracker_data->Data->last_alive);
                            $request .= "&lat=".urlencode($get_tracker_data->Data->lat);
                            $request .= "&lon=".urlencode($get_tracker_data->Data->lon);
                            $request .= "&speed=".urlencode($get_tracker_data->Data->speed);
                            $request = $GRUSHER_URL."/api?key=$GRUSHER_API_KEY&cat=billing&action=set_gps".$request;
                            echo "Sending to Grusher ". $request.PHP_EOL;
                            fgc($request, $GRUSHER_TIMEOUT );
                        }
                    }
                }
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