<?php 
require_once "sesame.class.php";
include 'googleMap.class.php';

/*
* A skeleton prototype that includes:
* - queries to google map webservices, for instance to get a location's elevation
*	- queries to geonames, to get XML or about.rdf files
* - SPARQL queries to a sesame/OWLIM end-point, handled with "sesame.class.php"
*	- Results display on a google map, handled with 'googleMap.class.php'
* - JQuery is used to handle a table of results, with sorting capabilities
*
* The main fonctionality is:
* - Enter a location name in a text box, can be any location
* - from that name, query google maps and geonames
*		to find out lat/long, altitude, etc.
*	- find out interesting locations that are already in the triple store, eventually using OwLIM/geospatial queries
*		for instance, find locations in a radius of X km
*	- display the results on google map, with some information in the info-box
*	- display a list of results under the map
*/

/****
* Some 'constant' parameters
****/
$PAGE_TITLE = "Geonames proto" ;
$FRAME_TITLE = "Recherche des stations m&eacute;t&eacute;o en Suisse par rapport &agrave; une localit&eacute;" ;
$NO_RESULT_MSG = "Aucune station trouv&eacute;" ;
$SPARQL_ENDPOINT = "http://153.109.124.88:8887/openrdf-sesame" ;
$REPOSITORY_NAME = "intBatProto" ;

/****
* READ THE GET PARAMETERS
****/

// FC Default values set to Sierre, used if the city entered by the user is not found in geoNames
$city = isset($_GET['city'])?$_GET['city']:'Sierre';
$rayon = isset($_GET['rayon'])?$_GET['rayon']:'50';
//$distance = isset($_GET['distance']);
$cityLat= '46.29192' ;
$cityLong= '7.53559' ;
$geoNamesID = '2658606';
$cityGNCanton = '2658205' ; // canton du valais dans GN
if(isset($_GET['distance']) != ""){
	$distance = "checked";
}else{
	$distance = "";
}

if(isset($_GET['cantonQuery']) != ""){
	$cantonQuery = "checked";
}else{
	$cantonQuery = "";
}

/****
* GeoNames standard web service query
* to get the lat/long + the geoname ID of the location
****/

// A geoNames webservice query, that returns xml 
// In this example I use name_equals -> look for the precise name
// but we could use 'q' or 'name', see http://www.geonames.org/export/geonames-search.html
$requestGN = new HTTP_Request2("http://api.geonames.org/search?name_equals=".$city."&maxRows=1&username=egov", HTTP_Request2::METHOD_POST);
// Here is the same query to return RDF values: "http://api.geonames.org/search?q=sierre&maxRows=10&type=rdf&username=egov"
// query with a country:&country=CH

$responseGN = $requestGN->send();		

if($responseGN->getStatus() != 200)
		{
			throw new Exception ('Failed to run geoNames search query, HTTP response error: ' . $response->getStatus());
		}
else
	{
		// The geoNames returned XML is one 'geoname' element per answer
		// as I did specify 'maxRows=1', there will be either one answer, or none
		$xmlGN = simplexml_load_string($responseGN->getBody());
		// The $xmlGN already refers to the root node 'geonames'
		if(isset($xmlGN->geoname[0])) // if no result found, the Sierre values will be used
		{
			$cityLat = $xmlGN->geoname[0]->lat;
			$cityLong = $xmlGN->geoname[0]->lng;
			$geoNamesID = $xmlGN->geoname[0]->geonameId ;
		}
		else
			$city = "Sierre" ; // (".$city." non trouvé)" ;
	}

/*
// FC: un premier test non terminé pour trouver le lat/long de google map
// si je veux aller plus loin: tester la nouvelle $url ci-dessous, et se baser sur https://developers.google.com/maps/documentation/geocoding/
// 
$address= "Sion, Switzerland" ;
$url = 'http://maps.googleapis.com/maps/api/geocode/json?address='.$address.'&sensor=true_or_false'
// $url de l'exemple trouvé sur le net -> ici la page reste en attente sauf erreur, mais le $api_key n'était pas déclaré ni spécifié
// $url = 'http://maps.google.com/maps/geo?q='.$address.'&output=json&oe=utf8&sensor=false&key='.$api_key;
$data = @file_get_contents($url);
$jsondata = json_decode($data,true);
if(is_array($jsondata )&& $jsondata ['Status']['code']==200){
  $addressLat = $jsondata ['Placemark'][0]['Point']['coordinates'][0];
  $addressLong = $jsondata ['Placemark'][0]['Point']['coordinates'][1];
}
*/

/****
* GeoNames about.rdf query
* to get the swiss Canton or higher division
****/
// getting the about.rdf from geoNames, and read the gn:parentADM1 that seems correct
// to parse an xml/rdf file with simplexml_load_string is not an easy task, because of the namespaces
/*
// example: 
	<rdf:RDF>
	<gn:Feature rdf:about="http://sws.geonames.org/2658606/">
	<rdfs:isDefinedBy rdf:resource="http://sws.geonames.org/2658606/about.rdf"/>
	<gn:name>Sierre</gn:name>
	<gn:featureClass rdf:resource="http://www.geonames.org/ontology#P"/>
	<gn:featureCode rdf:resource="http://www.geonames.org/ontology#P.PPL"/>
	<gn:parentADM1 rdf:resource="http://sws.geonames.org/2658205/"/>
*/
// one solution, the quick one used her, is to replace the ":" by "_"
// another one would be to use this code for instance: http://www.codeproject.com/Articles/220468/SimpleRDFElement-class-makes-it-easier-to-handle-R

$requestGN = new HTTP_Request2('http://sws.geonames.org/'. $geoNamesID .'/about.rdf', HTTP_Request2::METHOD_POST);
$responseGN = $requestGN->send();		
if($responseGN->getStatus() != 200)
		{
			throw new Exception ('Failed to run geonames about.rdf query, HTTP response error: ' . $response->getStatus());
		}
else
	{
		$newBody = str_replace(':', '_', $responseGN->getBody());
		$xmlGN = simplexml_load_string($newBody);

		if(isset($xmlGN->gn_Feature[0]->gn_parentADM1[0])) // if no result found, the Sierre values will be used
		{
			// get the rdf_about attribute of the node
			$cityGNCanton = $xmlGN->gn_Feature[0]->gn_parentADM1[0]->attributes()["rdf_resource"] ;
			// give back the ':' in the url
			$cityGNCanton = str_replace('_', ':', $cityGNCanton);
			//echo $cityGNCanton ;
		}
		//else
		//	echo "canton not found" ;
	}	

/****
* Google Maps query
* to get the location's altitude
****/
	
$elevation = 0 ;
$requestGN = new HTTP_Request2('http://maps.googleapis.com/maps/api/elevation/xml?locations=' . $cityLat . ',' . $cityLong . '&sensor=false', HTTP_Request2::METHOD_POST);
$responseGN = $requestGN->send();		
if($responseGN->getStatus() != 200)
		{
			throw new Exception ('Failed to run google apis elevation query, HTTP response error: ' . $response->getStatus());
		}
else
	{
		$xmlGN = simplexml_load_string($responseGN->getBody());
		// The $xmlGN already refers to the root node
		if(isset($xmlGN->result[0])) // if no result found, the Sierre values will be used
		{
			$elevation = $xmlGN->result[0]->elevation;
		}
	}	
	
/****
* Sesame Triple Store
* and namespaces
****/	
$store = new Sesame($SPARQL_ENDPOINT, $REPOSITORY_NAME);
$store->addNamespace('ep', 'http://websemantique.ch/onto/energyProvider#');
$store->addNamespace('gn', 'http://www.geonames.org/ontology#');
$store->addNamespace('omgeo', 'http://www.ontotext.com/owlim/geo#');
$store->addNamespace('wgs84', 'http://www.w3.org/2003/01/geo/wgs84_pos#');
$store->addNamespace('rdfs', 'http://www.w3.org/2000/01/rdf-schema#');
	
/****
* SPARQL query
* to find locations that are in the triple store
****/	

// Use the OWLIM geoSpatial functions to compute the distance between lat/long of the origine and lat/long of the energry providers
$SPARQLquery = 'SELECT ?station ?stationLabel ?destLat ?destLong ?destAlt'.
			//'(omgeo:distance('.$cityLat .', '.$cityLong.', ?destLat, ?destLong) as ?dist) (('.$elevation.'-?destAlt) as ?altDiff) '.
			'(omgeo:distance('.$cityLat .', '.$cityLong.', ?destLat, ?destLong) as ?dist) ?destAlt ?altDiff '.
			'WHERE { '.
			'?station a ep:Station;'.			
			'rdfs:label ?stationLabel;'.
			'wgs84:lat ?destLat;'.
			'wgs84:long ?destLong;'.
			'ep:altitude ?destAlt.'.
			//'FILTER( omgeo:distance('.$cityLat .', '.$cityLong.', ?destLat, ?destLong) < '.$rayon.')'.
			'BIND (if('.$elevation.'>?destAlt, '.$elevation.'-?destAlt, ?destAlt-'.$elevation.') AS ?altDiff).';

// according to the interface 'canton' checkbox
// -> query only station that are linked to the canton of the entered city			
if ($cantonQuery != "") // $cantonQuery = "checked"
	$SPARQLquery .= "?station ep:linkedToGeoN <".$cityGNCanton.">." ;

$SPARQLquery .= "}" ;
	
// order by distance: but no more used as the results are now sorted by the user
if ($distance != ""){
	 $SPARQLquery .= " ORDER BY ?altDiff";
}

$result = $store->query($SPARQLquery);						

/****
* Reading the SPARQL results
* In an array of array
* $data_array= the main array that contains each result
* $inside_array = an array of values for one result
****/	

// FC: add the starting point as a first result
// For info: if I use '-' as one of the values, displaying the map does fail
$data_array = array();		
$inside_array = array();
$inside_array['fcl'] = 'P';		
$inside_array['name'] = "_".$city." (ref)" ; // this is the reference point entered by the user -> add a "_" in order to have it on the first line when ordered by name
$inside_array['lat'] = $cityLat;
$inside_array['lng'] = $cityLong;
$inside_array['dist'] = '0';
$inside_array['alt'] =  number_format(floatval($elevation),0);
$inside_array['altDiff'] = '0';
$data_array[] = $inside_array;

if($result->hasRows()){
	$return_array = $result->getRows();
	foreach($return_array as $row){	
		$inside_array = array();
		$inside_array['fcl'] = 'P';		
		$inside_array['name'] = $row['stationLabel'];	
		$inside_array['lat'] = $row['destLat'];
		$inside_array['lng'] = $row['destLong'];
		$inside_array['alt'] = $row['destAlt'];
		$inside_array['dist'] = $row['dist'];
		$inside_array['altDiff'] = abs($row['altDiff']);
		$data_array[] = $inside_array;
	}
}

?>
<!DOCTYPE html 
     PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
     "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<title><?php echo $PAGE_TITLE;?></title>
		<meta http-equiv="content-type" content="text/html; charset=UTF-8"/> 
		
		<!--<meta http-equiv="Content-type" content="text/html; charset=iso-8859-1" />-->
		<link rel="stylesheet" href="jQuery/blue/style.css" type="text/css" />		
		
		<script type="text/javascript" src="jQuery/jquery-latest.js"></script> 
		<script type="text/javascript" src="jQuery/jquery.tablesorter.js"></script> 
		
		<script type="text/javascript">
	
		$(document).ready(function() {  
        // On créer notre tablesorter sur l'id mytable  
        // Et on trie (au départ) par la première colonne  
        $("#myTable").tablesorter({sortList:[[0,0]], widgets: ['zebra']})  
        .tablesorterPager({container: $("#pager")});  
        // On ajoute la pagination sur l'id #pager  
    });  

	</script>
		
	</head>
	<body>
	<table align="center" style="height:'100%'"><tr><td valign="middle" >
	<table border="1" style="bordercolor:lightgrey" cellpadding="3" cellspacing="0" width="100%" bgcolor="whitesmoke">
	<tr>
	<td>
	<fieldset><legend><?php echo $FRAME_TITLE;?></legend>
	<form action="main.php" method="get">
	<table>  
	  <tr>    
	    <td>Localit&eacute; (Suisse)</td><td>: <input type="text" name="city" value="<?php echo $city;?>"></input> ('Sierre' par d&eacute;faut si lieu non trouv&eacute;)</td>
	   </tr>
	   
 <!--
	   <tr>
	   <td>
	   <input type="checkbox" name="distance" value="checked" <?php echo $distance ?> > <label for="distance">Par distance</label>
	   </td>
	   </tr>
-->
	   <tr >
	   <td colspan="2">
	   <input type="checkbox" id="cantonQuery" name="cantonQuery" <?php if ($cantonQuery != "") echo 'checked="checked"' ?> /> <label for="cantonQuery">uniquement les stations liées au canton de cette localit&eacute;</label>
	   </td>
	   </tr>
	   
	  <tr>   
	    <td colspan="2" align="right"> <input type="submit" value="Rechercher"></input></td>
	  </tr>  
	</table>
	</form>
	</fieldset>
	</td>
	</tr>
<tr>
<td>
<?php
// Instantiate the map object
if(isset($data_array)){
	$map = new Google_Map($city, 'CH', $rayon); // Google_Map ($query = 'Sierre', $country = 'CH', $rayon='10') {

	$map->setResults($data_array);

	// Show the map
	$map->showMap();

	// FC: the result list is now displayed from googleMap.class.php, as hyperlinks, and using a JQuery nice table
	// Get results
	//$results = $map->getResults();

	// Show a result list
	/*
	$cnt = sizeof($results);
	
	for ($i = 0; $i < $cnt; $i++) {
	   $location = &$results[$i]; 
	  if (isset($location['provider'])) {    
		echo $location['provider'].' ';  		
		echo '<br />';
	  }

	if ($cnt < 2) // only the reference location
		echo 'Aucun fournisseur trouv&eacute;';
	*/
}
else{
		echo $NO_RESULT_MSG;
}
?>
</td>
</tr>	
	</table>
	</td></tr>
	</table>
	</body>
</html>	