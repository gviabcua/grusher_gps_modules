<?php
    $protocol_name =  explode(".", basename(__FILE__))[0];
    define("WORK_DIR", dirname(dirname(__FILE__)));
    require_once (WORK_DIR."/config.php");
    require_once (WORK_DIR."/functions.php");
    clilogTracker("Starting script...", $protocol_name);
    clilogTracker("WORK DIR is ".WORK_DIR, $protocol_name);
    clilogTracker("PROTOCOLS DIR is ".WORK_DIR."/protocols", $protocol_name);
    clilogTracker("LOGS DIR is ".WORK_DIR."/logs", $protocol_name);

    set_time_limit(0); // allow unlimited script loop
    ini_set('max_execution_time', 0);
    ini_set('default_socket_timeout', -1);
    ini_set('max_input_time', -1);

    $host = "0.0.0.0"; // listen all IP
    clilogTracker("Getting port", $protocol_name);   
    $options = getopt("p:");
    if (isset($options['p']) and ((int)$options['p'] > 0) and ((int)$options['p'] < 65536)){
        $port = (int)$options['p']; // port for tracker
    }else{
        clilogTracker("No port, or port not correct", $protocol_name); 
        return;
    }
    // Creating socket
    $socket = stream_socket_server("tcp://$host:$port", $errno, $errstr);
    if (!$socket) {
        clilogTracker("Unable to create socket: $errstr ($errno)", $protocol_name);
        return;
    }
    $log = "Server is started on {$host}:{$port}...";
    clilogTracker($log, $protocol_name);
    while (true) {
        try {
            $conn = stream_socket_accept($socket);
            if ($conn) {
                clilogTracker("New connection established", $protocol_name);
                // Keep the connection open for reading
                while (true) {
                    clilogTracker("Getting data from tracker", $protocol_name);
                    $payload = fread($conn, 1500);  // Read up to 1500 bytes
                    if ($payload === false || $payload === '') {
                        clilogTracker("Connection closed by client", $protocol_name);
                        break;
                    }
                    clilogTracker("RAW: ".bin2hex($payload), $protocol_name);
                    // Process the data
                    processGpsData($conn, bin2hex($payload));
                }
                fclose($conn);
                clilogTracker("Connection closed", $protocol_name);
            }
        } catch (\Exception $e) {
            clilogTracker("Error processing connection: " . $e->getMessage(), $protocol_name);
        }
    }
    fclose($socket);


    function processGpsData($conn, $payload)  {
        $isGt06 = false;
        $rec = $payload;
        $imei = '';
        $tempString = $rec."";
        //verification gt06
        $retTracker = hex_dump($rec."");
        $arCommands = explode(' ',trim($retTracker));
        if(count($arCommands) > 0){
            if($arCommands[0].$arCommands[1] == '7878'){
                $isGt06 = true;
            }
        }
        if($isGt06) {
            $arCommands = explode(' ', $retTracker);
            $tmpArray = array_count_values($arCommands);
            $count = $tmpArray[78];
            $count = $count / 2;
            $tmpArCommand = array();
            if($count >= 1){
                $ar = array();
                for($i=0;$i<count($arCommands);$i++){
                    if(strtoupper(trim($arCommands[$i]))=="78" && isset($arCommands[$i+1]) && strtoupper(trim($arCommands[$i+1])) == "78"){
                        $ar = array();
                        if(strlen($arCommands[$i]) == 4){
                            $ar[] = substr($arCommands[$i],0,2);
                            $ar[] = substr($arCommands[$i],2,2);
                        } else {
                            $ar[] = $arCommands[$i];
                        }
                    } elseif(isset($arCommands[$i+1]) && strtoupper(trim($arCommands[$i+1]))=="78" && strtoupper(trim($arCommands[$i]))!="78" && isset($arCommands[$i+2]) && strtoupper(trim($arCommands[$i+2]))=="78"){
                        if(strlen($arCommands[$i]) == 4){
                            $ar[] = substr($arCommands[$i],0,2);
                            $ar[] = substr($arCommands[$i],2,2);
                        } else {
                            $ar[] = $arCommands[$i];
                        }
                        $tmpArCommand[] = $ar;
                    } elseif($i == count($arCommands)-1){
                        if(strlen($arCommands[$i]) == 4){
                            $ar[] = substr($arCommands[$i],0,2);
                            $ar[] = substr($arCommands[$i],2,2);
                        } else {
                            $ar[] = $arCommands[$i];
                        }
                        $tmpArCommand[] = $ar;
                    } else {
                        if(strlen($arCommands[$i]) == 4){
                            $ar[] = substr($arCommands[$i],0,2);
                            $ar[] = substr($arCommands[$i],2,2);
                        } else {
                            $ar[] = $arCommands[$i];
                        }
                    }
                }
                for($i=0;$i<count($tmpArCommand);$i++) {
                    $arCommands = $tmpArCommand[$i];
                    $sizeData = $arCommands[2];
                    $protocolNumber = strtoupper(trim($arCommands[3]));
                    if($protocolNumber == '01'){
                        $imei = '';
                        for($i=4; $i<12; $i++){
                            $imei = $imei.$arCommands[$i];
                        }
                        $imei = substr($imei,1,15);
                        $conn_imei = $imei;
                        $sendCommands = array();
                        $send_cmd = '78 78 05 01 '.strtoupper($arCommands[12]).' '.strtoupper($arCommands[13]);
                        $newString = '';
                        $newString = chr(0x05).chr(0x01).$rec[12].$rec[13];
                        $crc16 = GetCrc16($newString,strlen($newString));
                        $crc16h = floor($crc16/256);
                        $crc16l = $crc16 - $crc16h*256;
                        $crc = dechex($crc16h).' '.dechex($crc16l);
                        $send_cmd = $send_cmd. ' ' . $crc . ' 0D 0A';
                        $sendCommands = explode(' ', $send_cmd);
                        clilogTracker(" Imei: $imei Got: ".implode(" ",$arCommands));
                        //printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Sent: $send_cmd Length: ".strlen($send_cmd));

                        $send_cmd = '';
                        for($i=0; $i<count($sendCommands); $i++){
                            $send_cmd .= chr(hexdec(trim($sendCommands[$i])));
                        }

                        //fwrite($conn, $send_cmd);
                        //echo "<br/>". bin2hex($send_cmd);
                        //socket_send($socket, $send_cmd, strlen($send_cmd), 0);
                    } else if ($protocolNumber == '12' || $protocolNumber == '22') {
                        //printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Got: ".implode(" ",$arCommands));
                        $dataPosition = hexdec($arCommands[4]).'-'.hexdec($arCommands[5]).'-'.hexdec($arCommands[6]).' '.hexdec($arCommands[7]).':'.hexdec($arCommands[8]).':'.hexdec($arCommands[9]);
                        $gpsQuantity = $arCommands[10];
                        $lengthGps = hexdec(substr($gpsQuantity,0,1));
                        $satellitesGps = hexdec(substr($gpsQuantity,1,1));
                        $latitudeHemisphere = '';
                        $longitudeHemisphere = '';
                        $speed = hexdec($arCommands[19]);
                        //78 78 1f 12 0e 05 1e 10 19 05 c4 01 2c 74 31 03 fa b2 b2 07 18 ab 02 d4 0b 00 b3 00 24 73 00 07 5b 59 0d 0a
                        //18 ab
                        //0001100010101011
                        //01 2b af f6
                        //03 fa 37 88
                        if(isset($arCommands[20]) && isset($arCommands[21])){
                            $course = decbin(hexdec($arCommands[20]));
                            while(strlen($course) < 8) $course = '0'.$course;

                            $status = decbin(hexdec($arCommands[21]));
                            while(strlen($status) < 8) $status = '0'.$status;
                            $courseStatus = $course.$status;

                            $gpsRealTime = substr($courseStatus, 2,1) == '0' ? 'F':'D';
                            $gpsPosition = substr($courseStatus, 3,1) == '0' ? 'F':'L';
                            //$gpsPosition = 'S';
                            $gpsPosition == 'F' ? 'S' : 'N';
                            $latitudeHemisphere = substr($courseStatus, 5,1) == '0' ? 'S' : 'N';
                            $longitudeHemisphere = substr($courseStatus, 4,1) == '0' ? 'E' : 'W';
                        }
                        $latHex = hexdec($arCommands[11].$arCommands[12].$arCommands[13].$arCommands[14]);
                        $lonHex = hexdec($arCommands[15].$arCommands[16].$arCommands[17].$arCommands[18]);

                        $latitudeDecimalDegrees = ($latHex*90)/162000000;
                        $longitudeDecimalDegrees = ($lonHex*180)/324000000;

                        $latitudeHemisphere == 'S' && $latitudeDecimalDegrees = $latitudeDecimalDegrees*-1;
                        $longitudeHemisphere == 'W' && $longitudeDecimalDegrees = $longitudeDecimalDegrees*-1;
                        if(isset($arCommands[30]) && isset($arCommands[30])){
                            //atualizarBemSerial($conn_imei, strtoupper($arCommands[30]).' '.strtoupper($arCommands[31]));
                        } else {
                            clilogTracker('Imei: '.$imei.' Got:'.$retTracker);
                        }
                        $dados = array($gpsPosition,
                            $latitudeDecimalDegrees,
                            $longitudeDecimalDegrees,
                            $latitudeHemisphere,
                            $longitudeHemisphere,
                            $speed,
                            $imei,
                            $dataPosition,
                            'tracker',
                            '',
                            'S',
                            $gpsRealTime);
                        clilogTracker("Lat/Long: ".$latitudeDecimalDegrees.", ".$longitudeDecimalDegrees);
                    }
                }


            }
        }
    }

    
?>
