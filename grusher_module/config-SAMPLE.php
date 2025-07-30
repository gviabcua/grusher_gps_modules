<?php
    /***************
     * Grusher
     * MAIN API GPS
     * @gviabcua
     * 
     * RENAME THIS FILE TO config.php
     * 
     **************/
     $localHostIp = '127.0.0.1';
     $grusher_host = "192.168.1.1";
     $grusher_api_key = "key";
     $write_start_script_log = 1;
     $write_gps_log = 1;
     // list of ports finded on internet
     $protocols_ports = [
          "autofon4" => 5079,
          "autofon5" => 5077,
          "autofon7" => 5099,
          "autofon9" => 9109,
          "galileosky" => 5034,
          "gps103" => 5001,
          "gt02a" => 5022,
          "gt06" => 5023,
          "h02" => 5013,
          "meiligao" => 5009,
          "mikrotik" => 5200,
          "ntcb_flex" => 9000,
          "okonavi" => 5098,
          "osmand" => 5055,
          "teltonika" => 5027,
          "tk103" => 5002,
          "wialon" => 5039,
     ];
?>