<?php

	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	/**
	 * @package rackspace
	 */

	define('RAXSDK_CONNECTTIMEOUT', 10);
	define('RAXSDK_TIMEOUT', 60);
	define('RAXSDK_TIMEZONE', Symphony::Configuration()->get('timezone', 'region'));
	require_once __DIR__ . '/../../libs/php-opencloud/lib/rackspace.php';
	require_once __DIR__ . '/../interface.cloudprovider.php';

	/**
	 * Provides the bindings to support uploading Files to the Rackspace
	 * Cloud Files product.
	 */
	Class Providers_Rackspace implements CloudProvider {

		protected $endpoint = 'https://identity.api.rackspacecloud.com/v2.0/';

		protected $username = null;

		protected $apiKey = null;

		protected $regions = array();

		protected $objectStore = array();

		protected $file = null;

	/*-------------------------------------------------------------------------
		Symphony:
	-------------------------------------------------------------------------*/

		public function __construct() {
			$this->getSettings();
		}

		public function getSettings() {
			$details = Symphony::Configuration()->get('cloudstoragefield');

			if(is_array($details)) {
				// Get the available container regions
				if(array_key_exists('rackspace-container-regions', $details)) {
					$this->regions = explode(',', $details['rackspace-container-regions']);
				}
				// Default back to DFW (like the previous version)
				else {
					$this->regions = array('DFW');
				}

				$settings = array(
					'container-regions' => $this->regions
				);

				// Get credentails
				$credentials = $this->getCredentials();

				// Merge the $settings and $credentials if they both exist
				if(is_array($credentials)) {
					return array_merge($settings, $credentials);
				}
				// Otherwise just return the $settings
				else {
					return $settings;
				}
			}
			// No configuration, return `null`
			else {
				return null;
			}
		}

		/**
		 * Returns the credentials to use the Rackspace SDK from the
		 * Symphony configuration file.
		 *
		 * @return array|null
		 *  If no credentials exist, `null` will be returned, otherwise
		 *  it will be an associative array of details
		 */
		public function getCredentials() {
			$details = Symphony::Configuration()->get('cloudstoragefield');

			if(
				is_array($details)
				&& array_key_exists('rackspace-username', $details)
				&& array_key_exists('rackspace-api-key', $details)
			) {
				$this->username = $details['rackspace-username'];
				$this->apiKey = $details['rackspace-api-key'];

				return array(
					'username' => $this->username,
					'api-key' => $this->apiKey
				);
			}
			else {
				return null;
			}
		}

		/**
		 * Builds the UI required so a developer can add Rackspace
		 * details to Symphony's Preferences page.
		 *
		 * @return XMLElement
		 */
		public function getPreferencesPanel() {
			// Get any current settings
			$username = General::sanitize($this->username);
			$api_key = General::sanitize($this->apiKey);
			$regions = $this->regions;

			// Build UI for adding them
			$panel = new XMLElement('fieldset');
			$panel->setAttribute('class', 'settings');
			$panel->appendChild(new XMLElement('legend', __('Rackspace Credentials')));
			$panel->appendChild(
				new XMLElement('p', __('Get a Username and API Key from %s.', array(
						'<a href="http://rackspace.com">Rackspace</a>'
					)),
					array('class' => 'help')
				)
			);

			$div = new XMLElement('div', NULL, array('class' => 'columns two'));

			$label = Widget::Label('Username');
			$label->setAttribute('class', 'column');
			$label->appendChild(Widget::Input('settings[cloudstoragefield][rackspace-username]', $username));
			$div->appendChild($label);

			$label = Widget::Label('API Key');
			$label->setAttribute('class', 'column');
			$label->appendChild(Widget::Input('settings[cloudstoragefield][rackspace-api-key]', $api_key));
			$div->appendChild($label);

			$panel->appendChild($div);

			$div = new XMLElement('div', NULL, array('class' => 'columns two'));

			$label = Widget::Label('Container Regions');
			$label->setAttribute('class', 'column');

			// Available Regions for Containers
			$options = array(
				array(null, false, null),
				array('DFW', in_array('DFW', $regions), 'Dallas (DFW)'),
				array('ORD', in_array('ORD', $regions), 'Chicago (ORD)')
			);
			$label->appendChild(
				Widget::Select('settings[cloudstoragefield][rackspace-container-regions][]', $options,  array('multiple' => 'multiple'))
			);
			$div->appendChild($label);

			$panel->appendChild($div);

			return $panel;
		}

		public function savePreferences(array &$context) {
			$context['settings']['cloudstoragefield']['rackspace-container-regions'] = implode(',', $context['settings']['cloudstoragefield']['rackspace-container-regions']);
		}

		/**
		 * Builds the UI for a developer to edit the field's settings on the
		 * Section Editor.
		 *
		 * @return XMLElement
		 */
		public function getDisplaySettingsPanel(XMLElement &$wrapper, array $settings, $errors = null) {
			$div = new XMLElement('div', NULL, array('class' => 'two columns'));

			// Connect to Rackspace and get the available Containers
			// Result may be cached.
			try {
				$containers = $this->getContainers();
			}
			catch (Exception $e) {
				Administration::instance()->Page->pageAlert(
					__('Cloud Storage Field: %s', array($e->getMessage())), Alert::ERROR
				);
				return false;
			}

			// Display available containers
			$options = array();
			foreach($containers as $region => $region_containers) {
				$opts = array();

				while($container = $region_containers->Next()) {
					$name = $container->name;
					$opts[] = array($name, $settings['container'] === $name, $name);
				}

				// If this region has containers, bring them in.
				if(!empty($opts)) {
					$options[] = array('label' => $region, 'options' => $opts);
				}
			}

			$label = Widget::Label(__('Container'));
			$label->setAttribute('class', 'column');
			$label->appendChild(
				Widget::Select('fields['. $settings['sortorder'] .'][container]', $options)
			);

			if(isset($errors['container'])) {
				$div->appendChild(Widget::Error($label, $errors['container']));
			}
			else {
				$div->appendChild($label);
			}

			$wrapper->appendChild($div);
		}

	/*-------------------------------------------------------------------------
		API:
	-------------------------------------------------------------------------*/

		public function isConnected() {
			return !empty($this->objectStore);
		}

		public function connect() {
			// Are we already connected?
			if($this->isConnected()) {
				return true;
			}

			// No? Ok lets connect to the cloudFile objectStore
			$credentials = array(
				'username' => $this->username,
				'apiKey' => $this->apiKey
			);
			$conn = new OpenCloud\Rackspace($this->endpoint, $credentials);

			// Set the connection to be ObjectStore
			foreach($this->regions as $region) {
				$this->objectStore[$region] = $conn->ObjectStore('cloudFiles', $region, 'publicURL');
			}

			if(!$this->isConnected()) {
				throw new Exception(__('Could not connect to Rackspace'));
			}

			return true;
		}

		public function getContainers() {
			$containers = array();
			$cache_id = md5($this->endpoint . $this->username . $this->apiKey);
			$cache = new Cacheable(Symphony::Database());
			$cachedData = $cache->check($cache_id);
			$validCache = 60; // cache is valid for 60 seconds

			// Execute if the cache doesn't exist, or if it is old.
			if(
				(!is_array($cachedData) || empty($cachedData)) // There's no cache.
				|| (time() - $cachedData['creation']) > $validCache // The cache is old.
			) {
				$this->connect();
				foreach($this->objectStore as $region => $objectStore) {
					$containers[$region] = $objectStore->ContainerList();
				}
				$cache->write($cache_id, serialize($containers), $validCache);
			}
			else {
				$containers = unserialize($cachedData['data']);
			}

			return $containers;
		}

		public function doesContainerExist($container_name, $return_container = false) {
			$this->connect();

			try {
				foreach($this->objectStore as $region => $objectStore) {
					$container = $objectStore->Container($container_name);

					// If we found the container, break
					if($container !== false) break;
				}
			}
			catch (Exception $ex) {
				return false;
			}

			return ($return_container)
				? $container
				: true;
		}

		public function getContainer($container_name) {
			$this->connect();

			if($container = $this->doesContainerExist($container_name, true)) {
				return $container;
			}

			return false;
		}

		public function uploadFile(array $file_details, $file_path, $container_name) {
			$container = $this->getContainer($container_name);

			if(!$container) return false;

			$this->file = $container->DataObject();

			// TODO: Exception handling.
			if($this->file->Create($file_details, $file_path)) {
				return $this->file->PublicURL();
			}
		}

		public function deleteFile($filename, $container_name) {
			if($file = $this->getFile($filename, $container_name)) {
				return $file->Delete();
			}

			return false;
		}

		public function getFile($filename, $container_name) {
			$container = $this->getContainer($container_name);

			if(!$container) return false;

			// TODO: Exception handling.
			try {
				$file = $container->DataObject($filename);
			}
			catch (NoNameError $ex) {
				return false;
			}

			return $file;
		}

		public function File() {
			if(!isset($this->file)) return array();

			return array(
				'ssl' => $this->file->PublicURL('SSL'),
				'streaming' => $this->file->PublicURL('STREAMING'),
				'http' => $this->file->PublicURL()
			);
		}
	}