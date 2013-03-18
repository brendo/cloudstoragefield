<?php

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

		protected $objectStore = null;

	/*-------------------------------------------------------------------------
		Symphony:
	-------------------------------------------------------------------------*/

		public function __construct() {
			$this->getCredentials();
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
			// Get any current credentials
			$username = General::sanitize($this->username);
			$api_key = General::sanitize($this->apiKey);

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

			return $panel;
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
				Administration::instance()->Page->pageAlert($e->getMessage());
			}

			// Display available containers
			$options = array();
			while($container = $containers->Next()) {
				$name = $container->name;
				$options[] = array($name, $settings['container'] === $name, $name);
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
			return isset($this->objectStore);
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
			$conn->SetDefaults('ObjectStore', 'cloudFiles', 'DFW', 'publicURL');

			// Set the connection to be ObjectStore
			$this->objectStore = $conn->ObjectStore();

			if(!$this->isConnected()) {
				throw new Exception(__('Could not connect to Rackspace'));
			}

			return true;
		}

		public function getContainers() {
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
				$containers = $this->objectStore->ContainerList();
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
				$container = $this->objectStore->Container($container_name);
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

			$file = $container->DataObject();

			// TODO: Exception handling.
			if($file->Create($file_details, $file_path)) {
				return $file->PublicURL();
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
	}