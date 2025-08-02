<?php
	/************************
	* Grusher
	* MAIN API GPS
	* @gviabcua
	* 
	* RENAME THIS FILE TO config.php
	* 
	*************************/
	$localHostIp = '127.0.0.1';// do not change
	/************************/
	
	$GRUSHER_URL = "http://192.168.1.1";
	$GRUSHER_API_KEY = "key";
	$GRUSHER_TIMEOUT = 2;
	$write_start_script_log = 1;
	$write_gps_log = 1;
	// list of ports finded on internet
	$protocols_ports = [
		//tested & worked 
		"osmand" => 5055, // this is traccar protocol
		"teltonika" => 5027,  //професійні трекери з Литви
		
		//need test
		"gps103" => 5001, //  масові китайські трекери
		"gt06" => 5023, // функціональний, краще підтримується, дорожчий
		"gt02a" => 5022, // популярні недорогі пристрої
		"h02" => 5013,  // простий, дешевший, підійде для базового трекінгу
		"tk103" => 5002, //класика бюджетних трекерів
		
		// Москалі - згенеровано ШІ. Кому потрібно - доробляйте. Я хз чи воно працює
		"autofon5" => 5077,
		"autofon7" => 5099,
		"autofon9" => 9109,
		"galileosky" => 5034,
		
		// NOT DONE - NOT PRESENT
		//"meiligao" => 5009,
		//"mikrotik" => 5200,
		//"ntcb_flex" => 9000,
		//"okonavi" => 5098,
		//"wialon" => 5039,
	];
?>