<?php
require_once("loxberry_system.php");
require_once("loxberry_io.php");
require_once("loxberry_log.php");
require_once("loxberry_json.php");
require_once("phpMQTT/phpMQTT.php");
require_once("include/Client.php");
define ("GLOBALCOOKIEFILE", LBPDATADIR."/cookies");


//Start logging
$log = LBLog::newLog(["name" => "Process.php"]);
LOGSTART("Script called.");

//Decide for and run function
$requestedAction = "";
if(isset($_POST["action"])){
	LOGINF("Started from HTTP.");
	$requestedAction = $_POST["action"];
}
if(isset($argv)){
	LOGINF("Started from Cron.");
	$requestedAction = $argv[1];
}

switch ($requestedAction){
	case "poll":
		pollUnifi();
		LOGEND("Processing finished.");
		break;
	default:
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "process.php has been called without parameter.", "error");
		LOGERR("No action has been requested");
		break;
}

//Function definitions
function getconfigasjson($output = false){
	LOGINF("Switched to getconfigasjson");
	
	//Get Config
	$config = new LBJSON(LBPCONFIGDIR."/config.json");
	LOGDEB("Retrieved backend config: ".json_encode($config));

	if($output){
		echo json_encode($config->slave);
		return;
	}else{
		return $config;
	}
}


// Primary action
function pollUnifi(){
	LOGINF("Starting action poll");
	LOGTITLE("pollunifi");

	//Get Config
	$config = getconfigasjson();
	$config = $config->slave;

	if(!isset($config->Main->username) OR $config->Main->username == "" OR !isset($config->Main->password) OR $config->Main->password == ""){
		//Abort, as creds not available.
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "No credentials saved in settings.", "error");
		LOGERR("No credentials saved in settings.");
		return;
	}

	if(!isset($config->Main->sitename) OR $config->Main->sitename == ""){
		//Abort, as site name not available.
		http_response_code(404);
		notify(LBPCONFIGDIR, "wifi-presence-unifi", "No site name saved in settings.", "error");
		LOGERR("No site name saved in settings.");
		return;
	}

	try {
		// Prepare MQTT
		// Get the MQTT Gateway connection details from LoxBerry
		$creds = mqtt_connectiondetails();
		// Create MQTT client
		$client_id = uniqid(gethostname()."_client");
		// Send data via MQTT
		$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
		if(!$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'])){
			http_response_code(404);
			notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: MQTT connection failed", "error");
			LOGERR("MQTT connection failed");
			return;
		}


		// Initialize the UniFi API connection class and log in to the controller and do our thing
		$unifi_connection = new UniFi_API\Client(
			$config->Main->username,
			$config->Main->password,
			$config->Main->url,
			$config->Main->sitename,
			$config->Main->version
		);
		$set_debug_mode = $unifi_connection->set_debug(false);
		LOGDEB("Attempting login...");
		$loginresults = $unifi_connection->login();
		LOGDEB("Login response received");
		if ($loginresults != true) {
			notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: Login to unifi failed", "error");
			LOGERR("Login to unifi failed with response " . $loginresults . " (username: " . $config->Main->username . ")");
			die();
		} else {
			LOGINF("Login to UniFi was successful");
		}

		// Get all clients
		LOGDEB("Fetching UniFi clients...");
		$clients = $unifi_connection->list_clients();

		if (is_array($clients)) {
			LOGINF("Received ". count($clients) . " clients from unifi");
		} else {
			LOGDEB("list_clients returned unexpected result");
		}

		// Get all clients, online and offline
		$clientHistory = $unifi_connection->stat_allusers();

		if (is_array($clientHistory)) {
			LOGINF("Received " . count($clients) . " clients (history) from unifi");
		} else {
			LOGDEB("stat_allusers returned unexpected result");
		}

		// Get all UniFi devices (APs and switches)
		LOGDEB("Fetching UniFi devices...");
		$aps_array = $unifi_connection->list_aps();
		if (is_array($clients)) {
			LOGINF("Received " . count($aps_array) . " devices from unifi");
		} else {
			LOGERR("list_aps returned unexpected result");
			die();
		}

		// Start looping through interesting mac addresses and gather information
		LOGDEB("Starting client loop");
		$uplinkMacList = [];
		foreach ($config->Main->macaddresses as $mac) {
			LOGINF("Searching ". $mac. " in unifi API results");
			$deviceFound = false;
			$foundClient = null;
			foreach ($clients as $client) {
				if ($client->mac === $mac) {
					$deviceFound = true; //We found the mac address in the unifi results
					$foundClient = $client; // For later use
					if ($client->uptime > 1) {
						$online = true;
						if (!in_array($client->ap_mac, $uplinkMacList)) {
							$uplinkMacList[] = $client->ap_mac;
						}
					} else {
						$online = false;
					}
					break; // Stop searching once a matching MAC is found
				}
			}
			if (!$deviceFound) {
				$online = false;
				LOGINF("Client ". $mac. " not found in unifi API results, forcing status offline");
			}



			//prepare some variables for mqtt transmission
			$mqttFriendlyMac = str_replace(':', '-', $mac);
			if ($foundClient->powersave_enabled) {
				$mqttFriendlyPowersaveEnabled = 1;
			} else {
				$mqttFriendlyPowersaveEnabled = 0;
			}
			if ($foundClient->ap_mac !== null) {
				$mqttFriendlyApMac = str_replace(':', '-', $foundClient->ap_mac);
			} else {
				$apMac = "";
			}
			if ($foundClient->disconnect_timestamp !== null) {
				$mqttFriendlyLastDisconnectAgo = time() - $foundClient->disconnect_timestamp;
			} else {
				$mqttFriendlyLastDisconnectAgo = -1;
			}

			if ($foundClient->last_seen !== null) {
				$mqttFriendlyLastSeenAgo = time() - $foundClient->last_seen;
			} else {
				$mqttFriendlyLastSeenAgo = -1;
			}

			if ($foundClient->uptime !== null) {
				$mqttFriendlyUptime = $foundClient->uptime;
			} else {
				$mqttFriendlyUptime = -1;
			}

			if ($foundClient->assoc_time !== null) {
				$mqttFriendlyAssocTimeAgo = time() - $foundClient->assoc_time;
			} else {
				$mqttFriendlyAssocTimeAgo = -1;
			}

			if ($foundClient->latest_assoc_time !== null) {
				$mqttFriendlyLatestAssocTimeAgo = time() - $foundClient->latest_assoc_time;
			} else {
				$mqttFriendlyLatestAssocTimeAgo = -1;
			}

			if ($foundClient->_uptime_by_uap !== null) {
				$mqttFriendlyUptimeByUAP = $foundClient->_uptime_by_uap;
			} else {
				$mqttFriendlyUptimeByUAP = -1;
			}

			if ($foundClient->hostname !== null) {
				$mqttFriendlyHostname = $foundClient->hostname;
			} else {
				$mqttFriendlyHostname = -1;
			}

			if ($foundClient->name !== null) {
				$mqttFriendlyName = $foundClient->name;
			} else {
				$mqttFriendlyName = -1;
			}

			if ($foundClient->essid !== null) {
				$mqttFriendlyEssid = $foundClient->essid;
			} else {
				$mqttFriendlyEssid = -1;
			}

			if ($foundClient->ip !== null) {
				$mqttFriendlyIp = $foundClient->ip;
			} else {
				$mqttFriendlyIp = -1;
			}
			if ($foundClient->satisfaction !== null) {
				$mqttFriendlySatisfaction = $foundClient->satisfaction;
			} else {
				$mqttFriendlySatisfaction = -1;
			}

			LOGDEB("Looking up uplink ap name for device " . $foundClient->ap_mac);
			foreach ($aps_array as $ap) {
				if (isset($ap->ethernet_table[0]->mac) && $ap->ethernet_table[0]->mac === $foundClient->ap_mac) {
					$mqttFriendlyAPName = $ap->name;
					LOGDEB("Uplink ap name for device " . $foundClient->ap_mac . " is " . $mqttFriendlyAPName );
					break;
				} else {
					$mqttFriendlyAPName = "-";
				}
			}

			//MQTT transmission
			if ($online === true) {
				LOGINF("Client ". $mac. " is online");
				$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/online", 1, 0, 1);
			} else {
				LOGINF("Client ". $mac. " is offline");
				$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/online", 0, 0, 1);
			}

			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/powersave_enabled", $mqttFriendlyPowersaveEnabled, 0, 1); // This is either 0 or 1
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/ap_mac", $mqttFriendlyApMac, 0, 1); // This is a MAC
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/ap_name", $mqttFriendlyAPName, 0, 1); // This is a MAC
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/disconnect_ago", $mqttFriendlyLastDisconnectAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/last_seen_ago", $mqttFriendlyLastSeenAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/uptime", $mqttFriendlyUptime, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/uptime_by_uap", $mqttFriendlyUptimeByUAP, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/assoc_time_ago", $mqttFriendlyAssocTimeAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/latest_assoctime_ago", $mqttFriendlyLatestAssocTimeAgo, 0, 1); //These are seconds
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/hostname", $mqttFriendlyHostname, 0, 1); //This is the network hostname
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/name", $mqttFriendlyName, 0, 1); //This is the alias set in unifi
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/essid", $mqttFriendlyEssid, 0, 1); //This is the connected WLAN SSID
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/ip", $mqttFriendlyIp, 0, 1); //This is the connected IP Address
			$mqtt->publish("wifi-presence-unifi/clients/" . $mqttFriendlyMac . "/satisfaction", $mqttFriendlySatisfaction, 0, 1); //This is the Client Satisfaction

		}

		//Starting to loop through APs to publish their state
		LOGDEB("Starting device loop");
		foreach ($aps_array as $ap) {
			if ($ap->type === 'uap') {
				//prepare some variables for mqtt transmission
				$mqttFriendlyMac = str_replace(':', '-', $ap->ethernet_table[0]->mac);
				$mqttFriendlyName = $ap->name;
				$mqttFriendlyClientCount = $ap->num_sta;
				// Check if this AP is the uplink of one of the monitored clients
				if (in_array($ap->mac, $uplinkMacList)) {
					$mqttFriendlyPresence = 1;
				} else {
					$mqttFriendlyPresence = 0;
				}

				$mqtt->publish("wifi-presence-unifi/devices/" . $mqttFriendlyMac . "/name", $mqttFriendlyName, 0, 1);
				$mqtt->publish("wifi-presence-unifi/devices/" . $mqttFriendlyMac . "/clientcount", $mqttFriendlyClientCount, 0, 1);
				$mqtt->publish("wifi-presence-unifi/devices/" . $mqttFriendlyMac . "/presence", $mqttFriendlyPresence, 0, 1);
			}
		}

		$mqtt->close();
	} catch (Exception $e) {
		LOGERR($e->getMessage());
	}
}

?>
