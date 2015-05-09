<?php
/*===============================
=            EDIT ME            =
===============================*/
// CHAOS page size, start page and end page
const PAGE_SIZE   = 1000;
const START_PAGE  = 0;
const END_PAGE    = -1;

// DOMAIN where the download url should refer to
const DOMAIN      = 'DOMAIN';   // Without / at the end

// Where the PDF should be stored
const FILE_FOLDER = '../programoversigter'; // Without / at the end


// Elasticsearch index and type
const ES_INDEX = 'programoversigter';
const ES_TYPE  = 'programoversigt';

// Number of tries when getting time outs
const MAX_TRIES = 10;
/*-----  End of EDIT ME  ------*/








require 'vendor/autoload.php'; // Loading ElasticSearch PHP
$params = array();
$params['hosts'] = array (
    'localhost:9200',         // IP + Port
    'localhost',              // Just IP
    'localhost:9201', // Domain + Port
    'localhost',     // Just Domain
    'https://localhost',        // SSL to localhost
    'https://localhost:9200'  // SSL to IP + Port
);

$client = new Elasticsearch\Client($params);

$index = START_PAGE;

echo 'Started!';
while (loadProgramPage($index++)) {
	echo ' ' . ($index * PAGE_SIZE) . ' \n';
	if (END_PAGE > 0 && $index > $end_index) {
		break;
	}
}
echo "\n-------------------------------\n";
echo "Ran from page " . START_PAGE . " to page " . END_PAGE . "\n";
echo "FINISHED: " . (END_PAGE - START_PAGE) . " pages (~" . ((END_PAGE - START_PAGE) * PAGE_SIZE) . " programs)\n";

function loadProgramPage($index) {
	$xml = getXml('http://api.larm.fm/v6/View/Get?view=Search&sort=PubStartDate%2Bdesc&filter=%28Type%3ASchedule%20OR%20Type%3AScheduleNote%29&pageIndex=' . $index . '&pageSize=' . PAGE_SIZE . '&sessionGUID=049da351-b81f-424e-82c4-1162926d3688&format=xml2&userHTTPStatusCodes=False');
	if (!$xml) {
		return true; // temp
	}
	$results = $xml->xpath('/PortalResponse/Body/Results/SearchViewData');
	if (empty($results)) {
		return false;
	}
	foreach ($results as $r) {
		if (checkIfExists($r->Id)) {
			continue;
		}
		if (!loadProgramData((string) $r->Id)) {
			echo "ERROR: " . $r . "\n";
			//break;
		} else {
			echo "Finished program " . (string) $r->Id . "\n\n";
		}
	}
	echo "Finished page " . $index . "\n";
	return true;
}

function loadProgramData($id) {
	$xml = getXml('http://api.larm.fm/v6/View/Get?view=Object&query=' . $id . '&pageIndex=0&pageSize=' . PAGE_SIZE . '&sessionGUID=049da351-b81f-424e-82c4-1162926d3688&format=xml2&userHTTPStatusCodes=False');
	if (!$xml) {
		return false;
	}
	$result = $xml->xpath('/PortalResponse/Body/Results/Result');
	if (empty($result)) {
		return false;
	}
	$new_id = (string) $result[0]->Id;
	if ($new_id != $id) {
		return false;
	}
	$fileurl = (string) $result[0]->Files->Result->URL;

	$metadata = simplexml_load_string($result[0]->Metadatas->Result->MetadataXml);
	$new_title = (string) $metadata->Titel;
	$new_text = (string) $metadata->AllText;
	$new_date = (string) $metadata->Date;
	$new_type = (string) $metadata->Type;
    $new_filename = (string) $metadata->Filename;


    // Donwloading PDF
    /*
	$file_destination = FILE_FOLDER . '/' . $new_filename;
	file_put_contents($file_destination, fopen($fileurl, 'r'));
	*/

    $new_fileurl = DOMAIN . '/' . $new_filename;

	if (insertObject($new_id, $new_title, $new_filename, $new_fileurl, $new_text, $new_date, $new_type)) {
		return true;
	} else {
		var_dump($metadata);
		echo "\n\n";
		var_dump($new_id);
		echo "\n";
		var_dump($new_title);
		echo "\n";
		var_dump($new_filename);
		echo "\n";
		var_dump($new_fileurl);
		echo "\n";
		var_dump($new_text);
		echo "\n";
		var_dump($new_date);
		echo "\n";
		var_dump($new_type);
		echo "\n\n";
		echo "-------------------------\n";
		echo "ERROR: " . $new_id . "\n";
		echo "ERROR: " . $new_title . "\n";
		echo "ERROR: " . $new_filename . "\n";
		echo "ERROR: " . $new_fileurl . "\n";
		echo "ERROR: " . $new_text . "\n";
		echo "ERROR: " . $new_date . "\n";
		echo "ERROR: " . $new_type . "\n";
		echo "-------------------------\n";
		return false;
	}
}

function getXml($url) {
	for ($count = 0; $count < MAX_TRIES; $count++) {
		$xml = @simplexml_load_file($url);
		if ($xml) {
			return $xml;
		}
		echo "TIMEOUT.. Waiting 60 seconds. Attempt " . ($count + 1);
		sleep(10 * ($count + 1));
	}
	return false;
}

function checkIfExists($id) {
	global $client;
	$getParams = array();
    $getParams['index'] = ES_INDEX;
    $getParams['type']  = ES_TYPE;
    $getParams['id']    = $id;
    try {
    	$client->get($getParams);
    } catch (Exception $e) {
    	return false;
    }
    return true;
}

function insertObject($id, $title, $filename, $fileUrl, $text, $date, $type) {
	global $client;
	if (empty($id) || empty($filename) || empty($text) || empty($date) || empty($type)) {
		echo "Empty " . $id . "\n\n";
		return false;
	}
	$indexParams = array();
	$indexParams['id'] 					= $id;
	$indexParams['index'] 				= ES_INDEX;
	$indexParams['type'] 				= ES_TYPE;
	$indexParams['body']['title']  		= $title;
	$indexParams['body']['filename']	= $filename;
	$indexParams['body']['url']			= $fileUrl;
	$indexParams['body']['allText']    	= $text;
	$indexParams['body']['date']    	= $date;
	$indexParams['body']['type']    	= $type;

	$ret = $client->index($indexParams);

	if (empty($ret)) {
		echo "No answer from ES " . $id . "\n";
		return false;
	}
	if ($ret['created'] == false) {
		echo "Already created " . $id . "\n";
	}
	return true;
}

?>
