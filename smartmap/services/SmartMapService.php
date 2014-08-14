<?php
namespace Craft;

class SmartMapService extends BaseApplicationComponent
{

	const IP_COOKIE_NAME = 'smartMap_myIp';

	public $settings;

	public $here;
	public $geoInfoSet = false;

	public $cookieData = false;
	public $cacheData = false;

	public $targetCoords; // TEMP: Until P&T "distance" fix

	public $measurementUnit;

	public $defaultZoom = 11;

	// Load geo data
	public function loadGeoData()
	{
		$this->here = array( // Default to empty container array
			'ip'        => false,
			'city'      => false,
			'state'     => false,
			'zipcode'   => false,
			'country'   => false,
			'latitude'  => false,
			'longitude' => false,
		);
		
		if (!craft()->isConsole()) {
			$ipCookie = static::IP_COOKIE_NAME;
			if (array_key_exists($ipCookie, $_COOKIE)) {
				$this->cookieData = json_decode($_COOKIE[$ipCookie], true);
			}
			$this->currentLocation();
		}
	}

	// Append Google API key if exists and enabled
	public function appendGoogleApiKey($prepend = '&')
	{
		$s = $this->settings;
		if ($s['enableService'] && is_array($s['enableService']) && in_array('google', $s['enableService']) && $s['googleApiKey']) {
			return $prepend.'key='.$s['googleApiKey'];
		} else {
			return null;
		}
	}

	// Automatically detect & set current location
	public function currentLocation()
	{
		// Detect IP address
		$ip = $this->_detectMyIp();
		// If IP can't be detected
		if (!$ip) {
			if ($this->cookieData) {
				$ip = $this->cookieData['ip'];
			} else {
				$this->_setGeoData(); // Auto detect IP
			}
		}
		// Set new geo data
		if ($ip && !$this->geoInfoSet) {
			$this->_setGeoData($ip); // Manually set IP
		}
	}

	// Automatically detect IP address from $_SERVER['REMOTE_ADDR']
	private function _detectMyIp()
	{
		$ip = $_SERVER['REMOTE_ADDR'];
		if (('127.0.0.1' == $ip) || (!$this->validIp($ip))) {
			return false;
		} else {
			return $ip;
		}
	}

	// Checks whether IP address is valid
	public function validIp($ip)
	{
		$ipPattern = '/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/';
		return preg_match($ipPattern, $ip);
	}

	// 
	private function _setGeoData($ip = '')
	{
		if (!$this->_matchGeoData($ip)) {
			if (craft()->smartMap_maxMind->available) {
				craft()->smartMap_maxMind->lookupIpData($ip);
			} else {
				craft()->smartMap_freeGeoIp->lookupIpData($ip);
			}
			// Fire an 'onDetectLocation' event
			$eventLocation = $this->cacheData['here'];
			unset($eventLocation['ip']);
			$this->onDetectLocation(new Event($this, array(
				'ip'               => $this->cacheData['here']['ip'],
				'location'         => $eventLocation,
				'detectionService' => $this->cacheData['service'],
				'cacheExpires'     => $this->cacheData['expires'],
			)));
		}
		$this->geoInfoSet = true;
	}

	// Retrieve cached geo information for IP address
	private function _matchGeoData($ip)
	{
		if ($ip) {
			$this->cacheData = craft()->fileCache->get($ip);
			if ($this->cacheData) {
				$this->here = $this->cacheData['here'];
				return true;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	// Set geo information in cookie
	public function setGeoDataCookie($ipSet, $lifespan = 300) // Expires in five minutes
	{
		if (!$ipSet) {
			$this->cookieData = array(
				'ip'      => $this->here['ip'],
				'expires' => time() + $lifespan,
			);
			setcookie(static::IP_COOKIE_NAME, json_encode($this->cookieData), time()+$lifespan, '/');
		}
	}

	// Cache geo information for IP address
	public function cacheGeoData($ip, $geoLookupService, $lifespan = 7776000) // 60*60*24*90 // Expires in 90 days
	{
		if ($ip) {
			$data = array(
				'here'    => $this->here,
				'expires' => time() + $lifespan,
				'service' => $geoLookupService,
			);
			craft()->fileCache->set($ip, $data, $lifespan);
			$this->cacheData = $data;
		}
	}

	// ==================================================== //

	// TEMP: Until P&T "distance" fix
	// Use haversine formula
	private function _haversinePHP($coords_1, $coords_2)
	{
		// Determine unit of measurement
		switch ($this->measurementUnit) {
			case MeasurementUnit::Kilometers:
				$unitVal = 6371;
				break;
			default:
			case MeasurementUnit::Miles:
				$unitVal = 3959;
				break;
		}
		// Set coordinates
		$lat_1 = $coords_1['lat'];
		$lng_1 = $coords_1['lng'];
		$lat_2 = $coords_2['lat'];
		$lng_2 = $coords_2['lng'];
		// Calculate haversine formula
		return ($unitVal * acos(cos(deg2rad($lat_1)) * cos(deg2rad($lat_2)) * cos(deg2rad($lng_2) - deg2rad($lng_1)) + sin(deg2rad($lat_1)) * sin(deg2rad($lat_2))));
	}
	// END TEMP


	// ==================================================== //
	// CALLED VIA SmartMap_AddressFieldType::modifyElementsQuery()
	// ==================================================== //

	// Modify fieldtype query
	public function modifyQuery(DbCommand $query, $params = array())
	{
		// Join with plugin table
		$query->join(SmartMap_AddressRecord::TABLE_NAME, 'elements.id='.craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId');
		// Search by comparing coordinates
		$this->_searchCoords($query, $params);
	}


	// ==================================================== //
	// CALLED VIA FIELDTYPE
	// ==================================================== //

	// Save field to plugin table
	public function saveAddressField(BaseFieldType $field)
	{
		// Get fieldId, elementId, and content
		$elementId = $field->element->id;
		$fieldId   = $field->model->id;
		$content   = $field->element->getContent();

		// Set specified attributes
		$fieldHandle = $field->model->handle;
		$data = $content->getAttribute($fieldHandle);

		// Return false if attribute doesn't exist
		if (!$data) {
			return false;
		}

		// Attempt to load existing record
		$addressRecord = SmartMap_AddressRecord::model()->findByAttributes(array(
			'elementId' => $elementId,
			'fieldId'   => $fieldId,
		));

		// If no record exists, create new record
		if (!$addressRecord) {
			$addressRecord = new SmartMap_AddressRecord;
			$addressRecord->elementId = $elementId;
			$addressRecord->fieldId   = $fieldId;
		}

		// Set record attributes
		$addressRecord->setAttributes($data, false);

		// Save record
		$saved = $addressRecord->save();
		if (!$saved) {
		    $errors = $addressRecord->getErrors();
		}
		return $saved;

	}

	// Retrieves address from 3rd party table
	public function getAddress(BaseFieldType $field)
	{
		// Load record (if exists)
		$addressRecord = SmartMap_AddressRecord::model()->findByAttributes(array(
			'elementId' => $field->element->id,
			'fieldId'   => $field->model->id,
		));

		// Get attributes
		if ($addressRecord) {
			$data = $addressRecord->getAttributes();
			if ($this->targetCoords) {
				$here = $this->targetCoords;
			} else {
				$here = array(
					'lat' => $this->here['latitude'],
					'lng' => $this->here['longitude'],
				);
			}
			$data['distance'] = $this->_haversinePHP($here, $data); // TEMP: Until P&T "distance" fix
		} else {
			$data = SmartMap_AddressRecord::model()->getAttributes();
			$data['distance'] = null;
		}

		return $data;
	}

	// ==================================================== //
	// PRIVATE METHODS
	// ==================================================== //

	// Parse query filter
	private function _parseFilter($params = array())
	{

		if (!is_array($params)) {
			$params = array();
			$api = MapApi::LatLngArray;
			$coords = $this->defaultCoords();
		} else if (!array_key_exists('target', $params)) {
			$api = MapApi::LatLngArray;
			$coords = $this->defaultCoords();
		} else if (is_array($params['target'])) {
			$api = MapApi::LatLngArray;
			if (!$this->isAssoc($params['target']) && count($params['target']) == 2) {
				$lat = $params['target'][0];
				$lng = $params['target'][1];
			} else {
				$lat = $this->findKeyInArray($params['target'],array('latitude','lat'));
				$lng = $this->findKeyInArray($params['target'],array('longitude','lng','lon','long'));
			}
			$coords = array(
				'lat' => $lat,
				'lng' => $lng,
			);
		} else if (is_string($params['target']) || is_numeric($params['target'])) {
			$api = MapApi::GoogleMaps;
		} else {
			// Invalid target
			//  - Throw error here?
			$api = MapApi::LatLngArray;
			$coords = $this->defaultCoords();
		}

		$filter = SmartMap_FilterCriteriaModel::populateModel($params);

		// If page is specified
		if ($filter->page) {
			$filter->offset = ($filter->page * $filter->limit) - $filter->limit;
		}

		switch ($api) {
			case MapApi::LatLngArray:
				$filter->coords = $coords;
				break;
			case MapApi::GoogleMaps:
			default:
				$filter->coords = $this->_geocodeGoogleMapApi($filter->target);
				break;
		}

		$this->targetCoords    = $filter->coords; // TEMP: Until P&T "distance" fix
		$this->measurementUnit = $filter->units;  // TEMP: Until P&T "distance" fix

		return $filter;
	}

	// Search by coordinates
	private function _searchCoords(&$query, $params = array())
	{
		$filter = $this->_parseFilter($params);
		// Implement haversine formula
		$haversine = $this->_haversine(
			$filter->coords['lat'],
			$filter->coords['lng'],
			$filter->units
		);
		// Modify query
		$query
			->addSelect($haversine.' AS distance')
			->having('distance <= '.$filter->range)
		;
	}

	// Use haversine formula
	private function _haversine($lat, $lng)
	{
		// Determine unit of measurement
		switch ($this->measurementUnit) {
			case MeasurementUnit::Kilometers:
				$unitVal = 6371;
				break;
			default:
			case MeasurementUnit::Miles:
				$unitVal = 3959;
				break;
		}
		// Set table reference
		$table = craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME;
		// Calculate haversine formula
		return "($unitVal * acos(cos(radians($lat)) * cos(radians($table.lat)) * cos(radians($table.lng) - radians($lng)) + sin(radians($lat)) * sin(radians($table.lat))))";
	}

	// Get coordinates from Google Maps API
	private function _geocodeGoogleMapApi($target)
	{

		$api  = 'http://maps.googleapis.com/maps/api/geocode/json';
		$api .= '?address='.rawurlencode($target);
		$api .= $this->appendGoogleApiKey();

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $api);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$response = json_decode(curl_exec($ch), true);

		if (empty($response['results'])) {
			return $this->defaultCoords();
		} else {
			return $response['results'][0]['geometry']['location'];
		}

	}

	// Decipher map center & markers based on locations
	public function markerCoords($locations, $options = array())
	{

		if (!$locations || empty($locations)) {
			return array(
				'center'  => $this->defaultCoords(),
				'markers' => array(),
			);
		}

		// If one location, process as an array
		if ($locations && !is_array($locations)) {
			return $this->markerCoords(array($locations), $options);
		}

		// If ElementCriteriaModel, convert to normal array
		if (is_object($locations[0]) && is_a($locations[0], 'Craft\\ElementCriteriaModel')) {
			return $this->markerCoords($locations[0]->find(), $options);
		}

		// Initialize variables
		$markers = array();
		$allLats = array();
		$allLngs = array();
		$fieldHandles = array();

		// If locations are specified
		if (!empty($locations)) {
			// If location is a pair of coordinates
			if (!$this->isAssoc($locations) && count($locations) == 2 && !is_object($locations[0])) {
				$lat = $locations[0];
				$lng = $locations[1];
				$allLats[] = $lat;
				$allLngs[] = $lng;
				$markers[] = array(
					'lat' => $lat,
					'lng' => $lng,
				);
			} else {
				// Find all Smart Map Address field fieldIds
				foreach (craft()->fields->getAllFields() as $field) {
					if ($field->type == 'SmartMap_Address') {
						$fieldHandles[] = $field->handle;
					}
				}
				// Loop through locations
				foreach ($locations as $loc) {
					if (is_object($loc)) {
						// If location is an object
						if (!empty($fieldHandles)) {
							foreach ($fieldHandles as $fieldHandle) {
								if (isset($loc->{$fieldHandle})) {
									$address = $loc->{$fieldHandle};
									if (!empty($address)) {
										$lat = $address['lat'];
										$lng = $address['lng'];
										$markers[] = array(
											'lat'     => (float) $lat,
											'lng'     => (float) $lng,
											'title'   => $loc->title,
											'element' => $loc
										);
										$allLats[] = $lat;
										$allLngs[] = $lng;
									}
								}
							}
						}
					} else if (is_array($loc)) {
						// Else, if location is an array
						if (!$this->isAssoc($loc) && count($loc) == 2 && !is_object($loc[0])) {
							$lat = $loc[0];
							$lng = $loc[1];
							$title = '';
						} else {
							$lat = $this->findKeyInArray($loc, array('latitude','lat'));
							$lng = $this->findKeyInArray($loc, array('longitude','lng','lon','long'));
							$title = (array_key_exists('title',$loc) ? $loc['title'] : '');
						}
						$markers[] = array(
							'lat'     => $lat,
							'lng'     => $lng,
							'title'   => $title,
							'element' => $loc
						);
						$allLats[] = $lat;
						$allLngs[] = $lng;
					}
				}
			}
		}

		// Determine center of map
		if (array_key_exists('center', $options)) {
			// Center is specified in options
			$center = $options['center'];
		} else if (empty($locations) || empty($allLats) || empty($allLngs)) {
			// Error was triggered
			$markers = array();
			if (array_key_exists('target', $options)) {
				$center = $this->targetCoords = $this->_geocodeGoogleMapApi($options['target']);
			} else {
				$center = $this->targetCenter();
			}
		} else {
			// Calculate center of map
			$centerLat = (min($allLats) + max($allLats)) / 2;
			$centerLng = (min($allLngs) + max($allLngs)) / 2;
			$center = array(
				'lat' => round($centerLat, 6),
				'lng' => round($centerLng, 6)
			);
		}

		// Return center point and all markers
		return array(
			'center'  => $center,
			'markers' => $markers,
		);
	}

	/*
	// Search via AJAX
	public function ajaxSearch($params)
	{
		$query = craft()->db->createCommand()
			->select()
			->from('elements');

		// Join with plugin table
		$query->join(SmartMap_AddressRecord::TABLE_NAME, craft()->db->tablePrefix.'elements.id='.craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId');

		// Join with content table
		$query->join('content', craft()->db->tablePrefix.'elements.id='.craft()->db->tablePrefix.'content.elementId');

		// Set query limit
		if (array_key_exists('limit', $params)) {
			$query->limit($params['limit']);
		}

		// Filter by specified section(s)
		if (array_key_exists('section', $params)) {
			if (!is_array($params['section'])) {
				$where = craft()->db->tablePrefix.'sections.fieldId=:fieldId';
				$pdo = array(':fieldId'=>$params['section']);
			} else {
				$i = 0;
				$where = '';
				$pdo = array();
				foreach ($params['section'] as $fieldId) {
					if ($where) {$where .= ' OR ';}
					$where .= craft()->db->tablePrefix.'sections.fieldId=:fieldId'.$i;
					$pdo[':fieldId'.$i] = $fieldId;
					$i++;
				}
			}
			$query
				->join('entries', craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.elementId='.craft()->db->tablePrefix.'entries.id')
				->join('sections', craft()->db->tablePrefix.'entries.sectionId='.craft()->db->tablePrefix.'sections.id')
				->andWhere($where, $pdo)
			;
		}

		/* BUG: Not working properly
		// Filter by specified field(s)
		if (array_key_exists('field', $params)) {
			if (!is_array($params['field'])) {
				$where = craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.fieldId=:fieldId';
				$pdo = array(':fieldId'=>$params['field']);
			} else {
				$i = 0;
				$where = '';
				$pdo = array();
				foreach ($params['field'] as $fieldId) {
					if ($where) {$where .= ' OR ';}
					$where .= craft()->db->tablePrefix.SmartMap_AddressRecord::TABLE_NAME.'.fieldId=:fieldId'.$i;
					$pdo[':fieldId'.$i] = $fieldId;
					$i++;
				}
			}
			$query
				->andWhere($where, $pdo)
			;
		}
		* /

		// Search by comparing coordinates
		$this->_searchCoords($query, $params);

		$query->order('distance');
		$markers = $query->queryAll();
		return $this->markerCoords($markers);
	}
	*/

	// Center coordinates of target
	public function targetCenter($target = false)
	{
		$coords =& $this->targetCoords;
		if (!$coords) {
			if ($target) {
				$coords = $this->_geocodeGoogleMapApi($target);
			} else {
				$coords = $this->defaultCoords();
			}
		}
		return $coords;
	}


	// ==================================================== //
	

	// Use default coordinates
	public function defaultCoords()
	{
		$defaultCoords = array(
			// Point Nemo
			'lat' => -48.876667,
			'lng' => -123.393333,
		);
		if (array_key_exists('latitude', $this->here) && array_key_exists('longitude', $this->here)) {
			$coords = array(
				// Current location
				'lat' => $this->here['latitude'],
				'lng' => $this->here['longitude'],
			);
		} else {
			$coords = $defaultCoords;
		}
		if (!$coords['lat'] && !$coords['lng']) {
			$coords = $defaultCoords;
		}
		return $coords;
	}


	// ==================================================== //
	// HELPER FUNCTIONS
	// ==================================================== //

	// Get the target from an array
	public function findKeyInArray($array, $possibleKeys)
	{
		foreach ($possibleKeys as $key) {
			if (array_key_exists($key, $array)) {
				return $array[$key];
			}
		}
	}

	// Determine if array is associative
	public function isAssoc($array) {
		return (bool) count(array_filter(array_keys($array), 'is_string'));
	}


	// ==================================================== //

	// Events

	/**
	 * Fires an 'onDetectLocation' event.
	 *
	 * @param Event $event
	 */
	public function onDetectLocation(Event $event)
	{
		$this->raiseEvent('onDetectLocation', $event);
	}
	/*
	// Event returns params:
	array(
	    'ip' => '76.94.199.186'
	    'location' => array(
	        'city' => 'Culver City'
	        'state' => 'California'
	        'zipcode' => '90230'
	        'country' => 'United States'
	        'latitude' => 33.995
	        'longitude' => -118.3917
	    )
	    'detectionService' => 'MaxMind'
	    'cacheExpires' => 1413590881
	)
	*/

}