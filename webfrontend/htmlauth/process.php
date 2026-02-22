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
	// --- NEUER BLOCK FUER CLIENTS START ---
	case "getclients":
		getClientsAsJson();
		break;
	// --- NEUER BLOCK FUER CLIENTS ENDE ---
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

// --- NEUE FUNKTION ZUM ABRUFEN DER GERÄTE ---
function getClientsAsJson() {
	$config = getconfigasjson()->slave;
	
	if(!isset($config->Main->username) || !isset($config->Main->password) || !isset($config->Main->url)){
		http_response_code(400);
		echo json_encode(["error" => "Bitte zuerst Zugangsdaten speichern."]);
		return;
	}

	$unifi_connection = new UniFi_API\Client(
		$config->Main->username, $config->Main->password, 
		$config->Main->url, $config->Main->sitename, $config->Main->version
	);
	
	$unifi_connection->set_debug(false);
	$loginresults = $unifi_connection->login();
	
	if ($loginresults == true) {
		// Hole alle bekannten Geräte (auch offline)
		$clients = $unifi_connection->stat_allusers(); 
		$resultList = [];
		
		if (is_array($clients)) {
			// Zeitstempel für "vor 30 Tagen" berechnen
			$thirtyDaysAgo = time() - (30 * 24 * 60 * 60);

			foreach($clients as $client) {
				// Überspringe das Gerät, wenn es seit über 30 Tagen nicht mehr gesehen wurde
				// (Oder wenn gar kein Zeitstempel existiert)
				if (!isset($client->last_seen) || $client->last_seen < $thirtyDaysAgo) {
					continue; 
				}

				// Name oder Hostname oder (als Fallback) die MAC-Adresse
				$name = isset($client->name) ? $client->name : (isset($client->hostname) ? $client->hostname : $client->mac);
				$resultList[] = [
					"mac" => $client->mac, 
					"name" => $name,
					"sortName" => strtolower($name)
				];
			}
			
			// Alphabetisch sortieren
			usort($resultList, function($a, $b) {
				return strcmp($a['sortName'], $b['sortName']);
			});
		}
		header('Content-Type: application/json');
		echo json_encode($resultList);
	} else {
		http_response_code(500);
		echo json_encode(["error" => "Login fehlgeschlagen. Stimmen die Zugangsdaten?"]);
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

		// --- NEUE RETRY-LOGIK START ---
		$maxRetries = 3;
		$retryDelay = 5; // Wartezeit in Sekunden zwischen den Versuchen
		$loginresults = false;

		for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
			$loginresults = $unifi_connection->login();
			
			if ($loginresults == true) {
				// Login war erfolgreich, wir können die Schleife abbrechen
				break; 
			}

			// Wenn der Login fehlschlug und es noch nicht der letzte Versuch war
			if ($attempt < $maxRetries) {
				LOGWARN("Login to unifi failed (Versuch $attempt von $maxRetries). Warte $retryDelay Sekunden...");
				sleep($retryDelay);
			}
		}
		// --- NEUE RETRY-LOGIK ENDE ---

		LOGDEB("Login response received");
		
		if ($loginresults != true) {
			notify(LBPCONFIGDIR, "wifi-presence-unifi", "wifi-presence-unifi Plugin: Login to unifi failed after $maxRetries attempts", "error");
			LOGERR("Login to unifi failed with response " . $loginresults . " (username: " . $config->Main->username . ") after $maxRetries attempts.");
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

			if ($foundClient->signal !== null) {
				$mqttFriendlySignal = $foundClient->signal;
			} else {
				$mqttFriendlySignal = -1;
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

			// --- NEU: Basis für das MQTT Topic ermitteln ---
			$topicBaseSetting = isset($config->Main->mqtt_topic_base) ? $config->Main->mqtt_topic_base : 'mac';
			$clientTopicBase = $mqttFriendlyMac; // Standard ist MAC			

			// Wenn Name gewünscht ist und auch einer existiert (-1 heißt im Plugin: nicht gefunden)
			if ($topicBaseSetting === 'name' && $mqttFriendlyName !== -1 && $mqttFriendlyName !== "") {
				// Leerzeichen und Sonderzeichen für ein sicheres MQTT-Topic durch Minus ersetzen
				$clientTopicBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', $mqttFriendlyName);
			}
			
			//MQTT transmission
			if ($online === true) {
				LOGINF("Client ". $mac. " is online");
				$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/online", 1, 0, 1);
			} else {
				LOGINF("Client ". $mac. " is offline");
				$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/online", 0, 0, 1);
			}

			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/powersave_enabled", $mqttFriendlyPowersaveEnabled, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/ap_mac", $mqttFriendlyApMac, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/ap_name", $mqttFriendlyAPName, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/disconnect_ago", $mqttFriendlyLastDisconnectAgo, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/last_seen_ago", $mqttFriendlyLastSeenAgo, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/uptime", $mqttFriendlyUptime, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/uptime_by_uap", $mqttFriendlyUptimeByUAP, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/assoc_time_ago", $mqttFriendlyAssocTimeAgo, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/latest_assoctime_ago", $mqttFriendlyLatestAssocTimeAgo, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/hostname", $mqttFriendlyHostname, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/name", $mqttFriendlyName, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/signal", $mqttFriendlySignal, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/satisfaction", $mqttFriendlySatisfaction, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/ESSID", $mqttFriendlyEssid, 0, 1); 
			$mqtt->publish("wifi-presence-unifi/clients/" . $clientTopicBase . "/IP", $mqttFriendlyIp, 0, 1); 

		}

		//Starting to loop through APs to publish their state
		LOGDEB("Starting device loop");
		foreach ($aps_array as $ap) {
			if ($ap->type === 'uap') {
				//prepare some variables for mqtt transmission
				$mqttFriendlyMac = str_replace(':', '-', $ap->ethernet_table[0]->mac);
				$mqttFriendlyName = $ap->name;
				$mqttFriendlyClientCount = $ap->num_sta;
				
				// --- NEU: Basis für AP Topics ermitteln ---
				$topicBaseSetting = isset($config->Main->mqtt_topic_base) ? $config->Main->mqtt_topic_base : 'mac';
				$deviceTopicBase = $mqttFriendlyMac;
				if ($topicBaseSetting === 'name' && !empty($mqttFriendlyName)) {
					$deviceTopicBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', $mqttFriendlyName);
				}

				// Check if this AP is the uplink of one of the monitored clients
				if (in_array($ap->mac, $uplinkMacList)) {
					$mqttFriendlyPresence = 1; 
				} else {
					$mqttFriendlyPresence = 0; 
				}

				$mqtt->publish("wifi-presence-unifi/devices/" . $deviceTopicBase . "/name", $mqttFriendlyName, 0, 1);
				$mqtt->publish("wifi-presence-unifi/devices/" . $deviceTopicBase . "/clientcount", $mqttFriendlyClientCount, 0, 1);
				$mqtt->publish("wifi-presence-unifi/devices/" . $deviceTopicBase . "/presence", $mqttFriendlyPresence, 0, 1);
			}
		}

		$mqtt->close();
	} catch (Exception $e) {
		LOGERR($e->getMessage());
	}
}

?>
