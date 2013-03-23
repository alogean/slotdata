<?php
/* ----------------------------------------------------------------------------
  Google_Map.class.php
 ------------------------------------------------------------------------------
  Originaly based on the SPAF_Maps written by martynas@solmetra.com, with google maps API 2.0
	FC: it seems that this class was based on google API v.2
	-> should work until May 19, 2013, cf https://developers.google.com/maps/documentation/javascript/v2/reference
	
	In this version, I try to move to google api v.3
	See info about upgrading v.2 to v.3: https://developers.google.com/maps/articles/v2tov3#overview
	But I did not change the way to create the markers, it still works so far
	-> a trial is in comment: marker = new google.maps.Marker, but it didn't work on the first tests -> to debug
		I am not sure that the changes I made to use v.3 really works, how to know that ?
		So far, we will wait to see if the protos still work after May 19 2013 (last day for v.2 as announced by google)
	-> no more time to spend on this now
	-> see the Demonstrateur fournisseurs d'énergie - googleMap.docx, Google Maps API, From v.2 to v.3
 --------------------------------------------------------------------------- 
  This class seems able to query geoNames, if the results are not provided
	-> We haven't tested this feature as we always provide results from the triple store
 */
 
class Google_Map {
	var $NO_RESULT_MSG = "Aucune station trouv&eacute;" ;

  // {{{
  // !!! EDITABLE CONFIGURATION ===============================================
  var $google_api_key = '';
																// FC: the new google api version doesn't require a key
																//
                                // Your Google Maps API key. Google Maps will 
                                // not display if this is blank or incorrect!
                                // Please note that API Key is bound to a host
                                // (domain name) that you are trying to display 
                                // maps on. A key generated for one domain will
                                // not work on another - even on "localhost"
                                // Get your key free at:
                                // http://www.google.com/apis/maps/signup.html
                                // You will need a Google Account to signup 
                                
  var $width = '600px';         
                                // Width of map area in pixels (px) 
                                // or percents (%)
  
  var $height = '300px';        
                                // Height of map area in pixels (px) 
                                // or percents (%)
  
  // Display the results as overlay or not
  var $show_overlay = true;     
                                // Show overlay markers of the query results 
                                // along with basic info on them (i.e. 
                                // population)
  
	// for google map control
  var $show_control = true;     
                                // Show map manipulation controls (move, zoom)
  
  var $show_type = true;        
                                // Show map type selection (map, satellite, 
                                // etc.)

	// for GeoNames query
  var $max_results = 100;       // A maximum number of results to find 
  var $secondary_search = true; 
                                // If search by specified query produces no 
                                // results, repeat the search without query - 
                                // just the specified country.
  
  var $use_sockets = false;     
                                // true - geocode data will be fetched by 
                                // opening direct socket connection to HTTP port
                                // of the geocode webservice.
                                //
                                // false - function file_get_contents() will be
                                // used. PHP ini value of allow_url_fopen must 
                                // be set to on.
                                

  var $google_api_url = 'http://maps.google.com/maps?file=api&amp;key={key}';
  //var $google_api_url = 'http://maps.google.com/maps?file=api&amp;v=3&amp;key={key}';
  var $geonames_url = 'http://ws.geonames.org/search?q={query}&maxRows={rows}&style=LONG';
  var $query = '';
  var $country = '';
  var $results = '';
  var $default = '';
  var $rayon = 0;
  // }}}
  // {{{
  function Google_Map ($query = 'Sierre', $country = 'CH', $rayon='10') {
    // set query and country
    $this->query = $query;
    $this->country = $country;
		$this->rayon = $rayon;
    
    // set default (if query produces zero results) - default New York
    $this->default = array(
      'name'        => 'New York', 
      'lat'         => '43.00028',
      'lng'         => '-75.50028',
      'geonameId'   => '5128638', 
      'countryCode' => 'US', 
      'countryName' => 'United States', 
      'fcl'         => 'A', 
      'fcode'       => 'ADM1', 
      'fclName'     => 'country, state, region,...', 
      'fcodeName'   => 'first-order administrative division', 
      'population'  => '19274244' 
    );
  }
  // }}}
  // {{{
  function setConfig ($key, $val) {
    $this->$key = $val;
    return true;
  }
	
  // }}}
  // {{{
  function setCountry ($country) {
    $this->country .= ' '.$country;	
    return true;
  }
  // }}}
  // {{{
  function setQuery ($query) {
    $this->query = $query;
    return true;
  }
  // }}}
  // {{{
  function setMaxResults ($max) {
    $this->max_results = $max;
    return true;
  }
  // }}}
  function setResults($results){
	$this->results = $results;
  }
  // {{{
  function getResults () {
    // check if results were already fetched
    if (!is_array($this->results)) {
      $this->fetchResults();
    }
    
    return $this->results;
  }
  // }}}
  // {{{
  function showMap () {
    // check if results were already fetched
    if (!is_array($this->results)) {
		$this->fetchResults();
    }
    
    // get coordinates of the first location
    if (isset($this->results[0])) {
      $current = &$this->results[0];
    }
    else {
      $current = $this->default;
      $this->results[] = $current;
    }
    
    // determine correct zoom level
    $zoom = $this->calcZoom($current);
    
    // prepare url
    $url = str_replace('{key}', $this->google_api_key, $this->google_api_url);
		    
    // start map code
    echo '<script src="'.$url.'" type="text/javascript"></script>'."\r\n".
         '<script type="text/javascript">'."\r\n".
         '//<![CDATA['."\r\n".
         ' var map = null;'."\r\n";
    
    // create a function for adding markers
    echo 'function createMarker(point, descr) {'."\r\n".
         '  var marker = new GMarker(point);'."\r\n".
         '  GEvent.addListener(marker, "click", function() {'."\r\n".
         '    marker.openInfoWindowHtml(descr);'."\r\n".
         '  });'."\r\n".
         '  return marker;'."\r\n".
         '}'."\r\n";

	//  FC adding markers with a green icon
	echo 'function createGreenMarker(point, descr) {'."\r\n".
		' var greenIcon = new GIcon(G_DEFAULT_ICON);'."\r\n".
		' greenIcon.image = "http://www.google.com/intl/en_us/mapfiles/ms/micons/green-dot.png";'."\r\n".
		 ' var markerOptions = { icon:greenIcon };'."\r\n".
         '  var marker = new GMarker(point, markerOptions);'."\r\n".
         '  GEvent.addListener(marker, "click", function() {'."\r\n".
         '    marker.openInfoWindowHtml(descr);'."\r\n".
         '  });'."\r\n".
         '  return marker;'."\r\n".
         '}'."\r\n";
		 
    // begin main function
		
		// bounds could be used to make all markers visible: adding each marker and then centering at the end
		// -> I keep this in the code so far, eventhoug I don't really use it 
    echo 'function SPAF_Maps_load() {'."\r\n".
     	 '  var bounds = new GLatLngBounds();'."\r\n".
    	 '  var centerLatLong = new GLatLng('.$current['lat'].', '.$current['lng'].');'."\r\n".
         '  if (GBrowserIsCompatible()) {'."\r\n";

    // create object and center it  
		//'    map = new google.maps.Map2(document.getElementById("spaf_map"));'."\r\n".
    echo  '    map = new google.maps.Map(document.getElementById("spaf_map"));'."\r\n".
          '    map.setCenter(new GLatLng('.$current['lat'].', '.$current['lng'].'), '.$zoom.');'."\r\n";

    // add controls
		// FC: I tried the GLargeMapControl to have a zoom slider, but the slider doesn't show up ?
		//echo '    map.addControl(new GLargeMapControl());'."\r\n"; 
    if ($this->show_control) {
      echo '    map.addControl(new GSmallMapControl());'."\r\n"; 
    }

    if ($this->show_type) {
      echo '    map.addControl(new GMapTypeControl());'."\r\n";
    }
        
		// add result overlay markers
    if ($this->show_overlay)
		{
      $cnt = sizeof($this->results);	  
	  
			// FC: the first "result" is the reference point
			// so don't show all the details
			// and set its color to green
			if (isset($this->results[0]))
				{
        $location = &$this->results[0];		
        $description = '<strong>'.$this->javaScriptEncode($location['name']).'</strong><br />';
				$description .= 'Lat/Long: '.$location['lat'].'/'.$location['lng'].'<br>';
				$description .= 'Altitude: '. $location['alt'].'<br>';
        // enclose caption with style
        $description = '<span style="color: #000000;">'.$description.'</span>';
				// v.2
        echo ' map.addOverlay(createGreenMarker(new GLatLng('.$location['lat'].', '.$location['lng'].'), \''.$description.'\'));'."\r\n";
				// test add marker for v.3
				//echo ' var marker = new google.maps.Marker({position: new google.maps.LatLng('.$location['lat'].', '.$location['lng'].'), title:"'.$description.'"});' ;
				//echo ' marker.setMap(map);' ;
				echo ' bounds.extend(new GLatLng('.$location['lat'].', '.$location['lng'].'));'."\r\n";
				}

			// the results
			for ($i = 1; $i < $cnt; $i++) 
				{
        $location = &$this->results[$i];		
        $description = '<strong>'.$this->javaScriptEncode($location['name']).'</strong><br />';
				$description .= 'Distance longtitude from '.$this->query.': '. number_format($location['dist'],0).' km<br>';
				$description .= 'Distance altitude from '.$this->query.': '. number_format($location['altDiff'],0).' m<br>';
       
        // enclose caption with style
        $description = '<span style="color: #000000;">'.$description.'</span>';
        echo 'map.addOverlay(createMarker(new GLatLng('.$location['lat'].', '.$location['lng'].'), \''.$description.'\'));'."\r\n";
				echo 'bounds.extend(new GLatLng('.$location['lat'].', '.$location['lng'].'));'."\r\n";
				}
    }
	

	// Center the map
	// So far, I just center on the reference point (which is done here above), as no better solution has been found quickly
  // This trial does work: it makes all the markers visible, but the map is not centered on the reference location
	//echo 'map.setCenter(bounds.getCenter(), map.getBoundsZoomLevel(bounds));'."\r\n";

  // Another idea was to draw a circle around all markers, then center on that circle
	// But the first trials I did to draw a circle use the API version 3 I guess (found out later)
	/*
	echo 'var draw_circle = new google.maps.Circle({'.
        'center: centerLatLong,'.
        'radius: 50000,'.
        'strokeColor: "#FF0000",'.
        'strokeOpacity: 0.8,'.
        'strokeWeight: 2,'.
        'fillColor: "#FF0000",'.
        'fillOpacity: 0.35,'.
        'map: map});' ;
	*/	
	//echo 'var circ = new google.maps.Circle();'."\r\n";		
  //echo 'circ.setRadius(50 * 1609.0);'."\r\n";		
  //echo 'circ.setCenter(centerLatLong);'."\r\n";		
  //echo 'map.setCenter(centerLatLong);'."\r\n";		
  //echo 'map.fitBounds(circ.getBounds());'."\r\n";		
	//echo "google.maps.event.trigger(map,'dragend')";
	
    // end map code
    echo '  }'."\r\n".
         '}'."\r\n".
         '//]]>'."\r\n".
         '</script>'."\r\n";
    
    // put div
    echo '<div id="spaf_map" style="width: '.$this->width.'; height: '.$this->height.'"></div>';
    
    // execute event
    echo '<script type="text/javascript">'."\r\n".
         'window.onload = SPAF_Maps_load;'."\r\n".
         'window.onunload = GUnload;'."\r\n".
         '</script>';
    
	$this->showLocationLink() ;
    return true;
  }
  // }}}
  // {{{
	// FC: show hyper links to the different markers
	// a copy of the showLocationControl() function, but creating options instead of a drop-down combo
  function showLocationLink () {
    // create function
    echo '<script type="text/javascript">'."\r\n".
         'function changeMarker (pos) {'."\r\n".
         '  dta = pos.split(\' \');'."\r\n".
         '  map.setCenter(new GLatLng(dta[0], dta[1]), dta[2]);'."\r\n".
         '}'."\r\n".
         '</script>'."\r\n";

				// With .panTo -> but doesn't work really well when the marker is already visible
				/*
         '  map.panTo(new GLatLng(dta[0], dta[1]));'."\r\n".
         '  map.setZoom(dta[2]);'."\r\n".
				*/
				
    // show options
    $cnt = sizeof($this->results);

	echo "<table id='myTable' class='tablesorter' border='1' style='bordercolor:lightgrey' cellpadding='2' cellspacing='0' width='100%' bgcolor='whitesmoke'>";
			
			/*
			echo "<tr>";
			echo "<td width='20%'>";
			echo "Station" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Lat" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Lng" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Alt" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Distance 2D" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Distance Alt" ; 
			echo "</td>";
			echo "<td width='10%'>";
			echo "Distance 3D" ; 
			echo "</td>";
			echo "</tr>";
			*/
			
			echo "<thead>";
			echo "<tr>";
			echo "<th>";
			echo "Station" ; 
			echo "</th>";
			echo "<th>";
			echo "Latitude" ; 
			echo "</th>";
			echo "<th>";
			echo "Longitude" ; 
			echo "</th>";
			echo "<th>";
			echo "Altitude (m)" ; 
			echo "</th>";
			echo "<th>";
			echo "Distance 2D (km)" ; 
			echo "</th>";
			echo "<th>";
			echo "Distance Altitude (m)" ; 
			echo "</th>";
			echo "<th>";
			echo "Distance 3D (km)" ; 
			echo "</th>";
			echo "</tr>";
			echo "</thead>";
			
			echo "<tbody>";
			
	
    for ($i = 0; $i < $cnt; $i++) {
      $location = &$this->results[$i];	
			
			echo "<tr>";
			echo "<td width='15%'>";
			echo "<a href=\"javascript:changeMarker('".$location['lat'].' '.$location['lng'].' '.$this->calcZoom($location)."');\">".$location['name']."</a>" ; 
			echo "</td>\n";
			echo "<td width='10%'>";
			echo $location['lat'] ; 
			echo "</td>\n";
			echo "<td width='10%'>";
			echo $location['lng'] ; 
			echo "</td>\n";
			echo "<td width='10%'>";
			//echo number_format($location['alt'],0). " m" ; 
			echo $location['alt']%10000000;
			echo "</td>\n";
			echo "<td width='10%'>";
			//echo number_format($location['dist'],0)." km";
			echo $location['dist']%10000000 ;
			echo "</td>\n";
			echo "<td width='10%'>";
			//echo number_format($location['altDiff'],0)." m";
			echo $location['altDiff']%10000000;
			echo "</td>\n";
			echo "<td width='10%'>";
			//echo number_format(sqrt(($location['dist']*$location['dist']) + ($location['altDiff']*$location['altDiff'])),0)." m";
			echo sqrt(($location['dist']*$location['dist']) + ($location['altDiff']/1000*$location['altDiff']/1000))%10000000  ;
			echo "</td>\n";
			
			echo "</tr>";
			
		}
		echo "</tbody>";
		echo "</table>";	

		
    // show a no-results sign
		if ($cnt < 2) // only the reference location
			echo $this->NO_RESULT_MSG;

    return true;
  }
	
  function showLocationControl ($properties = '') {
    // check if results were already fetched
    if (!is_array($this->results)) {
      $this->fetchResults();
    }
    
    // prepent properties with whitespace
    if ($properties != '') {
      $properties = ' '.$properties;
    }
    
    // create function
    echo '<script type="text/javascript">'."\r\n".
         'function changeMarker (pos) {'."\r\n".
         '  dta = pos.split(\' \');'."\r\n".
         '  map.panTo(new GLatLng(dta[0], dta[1]));'."\r\n".
         '  map.setZoom(dta[2]);'."\r\n".
         '}'."\r\n".
         '</script>'."\r\n";
    
    // begin
    echo '<select'.$properties.' onchange="changeMarker(this.options[this.selectedIndex].value);">'."\r\n";
    
    // show options
    $cnt = sizeof($this->results);

    for ($i = 0; $i < $cnt; $i++) {
      $location = &$this->results[$i];	  
      echo '<option value="'.$location['lat'].' '.$location['lng'].' '.$this->calcZoom($location).'">'.$location['name'].' '.$location['lat'].' '.$location['lng'].')</option>'."\r\n"; 
    }
	
    // show a no-results sign
    if ($cnt == 0) {
      echo '<option value="">-- no results --</option>'."\r\n";
    }

    // end
    echo '</select>'."\r\n";
    
    return true;
  }
  // }}}
  // {{{ 
  function calcZoom (&$location) {
    // get primary zoom level based on location type
    switch ($location['fcl']) {
      case 'A':
        $zoom = 5;
        break;
      case 'P':
        $zoom = 10;
        break;
      default:
        $zoom = 8;
        break;
    }
    
    // modify zoom type based on population
    $mod = 0;
    if (isset($location['population'])) {
      $mod = floor($location['population'] / 5000000);
      if ($mod > 2) {
        $mod = 2;
      }
    }
    
    return $zoom - $mod;
  }
  // }}}
  // {{{
  function fetchResults ($repeat = false) {
    // prepare fetch url
    if ($repeat) {
      $url = str_replace(
        array('{query}', '{rows}'),
        array($this->country, $this->max_results),
        $this->geonames_url);
    }
    else {
      $url = str_replace(
        array('{query}', '{rows}'),
        array(urlencode($this->query), $this->max_results),
        $this->geonames_url);
    }
    
    // add country filtering
	
    if ($this->country != '') {
      $url .= '&country='.$this->country;
    }
    
    // fetch url
    if ($this->use_sockets) {
      $xml = $this->fetchUrl($url);
    }
    else {    	
		$url .= "&featureClass=P";
      $xml = file_get_contents($url);
    }
    
    // chech if file was actually fetched
    if ($xml === false) {
      $this->results = array();
      return false;
    }
    
    // parse fetched XML
    
    // get all items
    $this->results = array(); 
    preg_match_all('/<geoname>(.*)<\/geoname>/isU', $xml, $arr, PREG_SET_ORDER);
    
    // parse each individual item
    while (list(, $item) = each($arr)) {
      preg_match_all('/<([a-z]+)>(.*)<\/[a-z]+>/isU', $item[1], $params, PREG_SET_ORDER);
      $location = array();
      while (list(, $param) = each($params)) {
        $location[$param[1]] = $param[2];
      }
      $this->results[] = $location;
    }
    
    // check if search shoud be repeated with less restrictive query
    if (sizeof($this->results) == 0 && $this->secondary_search && !$repeat) {
      $this->fetchResults(true);
    }
    
    return true;
  }
  // }}}
  // {{{
  function javaScriptEncode ($str) {
    $str = str_replace("\\", "\\\\", $str);
    $str = str_replace("'", "\\'", $str);
    $str = str_replace("\r\n", '\r\n', $str);
    $str = str_replace("\n", '\r\n', $str);
    return $str;
  }
  // }}}
  // {{{
  function fetchUrl ($url) {
    // parse URL
    if (!$elements = @parse_url($url)) {
      return '';
    }
    
    // add default port
    if (!isset($elements['port'])) {
      $elements['port'] = 80;
    }
    
    
    // open socket
    $fp = fsockopen($elements['host'], $elements['port'], $errno, $errstr, 20);
    if (!$fp) {
      return '';
    }
    
    // assemble path
    $path = $elements['path'];
    if (isset($elements['query'])) {
      $path .= '?'.$elements['query'];
    }
    
    // assemble HTTP request header
    $request  = "GET $path HTTP/1.1\r\n";
    $request .= "Host: ".$elements['host']."\r\n";
    $request .= "Connection: Close\r\n\r\n";
    
    // send HTTP request header and read output
    $result = '';
    fwrite($fp, $request);
    while (!feof($fp)) {
      $result .= fgets($fp, 128);
    }
    
    // close socket connection
    fclose($fp);
    
    // strip extra text from result
    return preg_replace('/^[^<>]*(<.*>)[^<>]*$/s', '$1', $result);
  }
  // }}}
}   
?>