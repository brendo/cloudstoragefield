<?php

	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	require_once(TOOLKIT . '/fields/field.upload.php');
	require_once __DIR__ . '/../providers/rackspace/cloud.rackspace.php';
	
	class FieldCloudStorage extends FieldUpload {

		protected $rackspace = null;

		public function __construct(){
			parent::__construct();
			$this->_name = __('Cloud Storage');
			$this->rackspace = new Providers_Rackspace();
		}


	/*-------------------------------------------------------------------------
		Setup:
	-------------------------------------------------------------------------*/

		public function createTable(){
			return Symphony::Database()->query("
				CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
				  `id` int(11) unsigned NOT NULL auto_increment,
				  `entry_id` int(11) unsigned NOT NULL,
				  `file` varchar(255) default NULL,
				  `size` int(11) unsigned NULL,
				  `mimetype` varchar(100) default NULL,
				  `meta` varchar(255) default NULL,
				  `url` varchar(250) default NULL,
				  PRIMARY KEY  (`id`),
				  UNIQUE KEY `entry_id` (`entry_id`),
				  KEY `file` (`file`),
				  KEY `mimetype` (`mimetype`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
			");
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public function entryDataCleanup($entry_id, $data=NULL){
			$this->rackspace->deleteFile($data['file'], $this->get('container'));

			return parent::entryDataCleanup($entry_id);
		}

		/**
		 * @author Michael Eichelsdoerfer
		 */
		private static function getUniqueFilename($filename) {
			## since uniqid() is 13 bytes, the unique filename will be limited to ($crop+1+13) characters;
			$crop  = '30';
			return preg_replace("/([^\/]*)(\.[^\.]+)$/e", "substr('$1', 0, $crop).'-'.uniqid().'$2'", $filename);
		}

		/**
		 * @author Michael Eichelsdoerfer
		 */
		private static function getCleanFilename($filename) {
			return preg_replace("/([^\/]*)(\-[a-f0-9]{13})(\.[^\.]+)$/", '$1$3', $filename);
		}

		private function isValidFile($filename, &$message = null) {
			$status = null;

			if ($this->get('validator') != null) {
				$rule = $this->get('validator');

				if (General::validateString($filename, $rule) === false) {
					$message = __('File chosen in ‘%s’ does not match allowable file types for that field.', array(
						$this->get('label')
					));

					$status = self::__INVALID_FIELDS__;
				}
			}

			return $status;
		}

	/*-------------------------------------------------------------------------
		Settings:
	-------------------------------------------------------------------------*/

		public function displaySettingsPanel(XMLElement &$wrapper, $errors = null) {
			Field::displaySettingsPanel($wrapper, $errors);
			$order = $this->get('sortorder');

			// Get Rackspace settings
			$rackspace = new Providers_Rackspace();
			$rackspace->getDisplaySettingsPanel($wrapper, $this->get(), $errors);

			// Validation
			$this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$order.'][validator]', 'upload');

			// Build other consistent settings
			$div = new XMLElement('div', NULL, array('class' => 'two columns'));

			// Unique Filename
			$name = "fields[{$order}][unique_filename]";

			$label = Widget::Label();
			$label->setAttribute('class', 'column');
			$input = Widget::Input($name, 'yes', 'checkbox');

			if ($this->get('unique_filename') == 'yes') $input->setAttribute('checked', 'checked');

			$label->setValue(__('%s Automatically give all files a unique filename', array($input->generate())));

			$div->appendChild(Widget::Input($name, 'no', 'hidden'));
			$div->appendChild($label);

			// Remove on delete
			$name = "fields[{$order}][remove_from_container]";

			$label = Widget::Label();
			$label->setAttribute('class', 'column');
			$input = Widget::Input($name, 'yes', 'checkbox');

			if ($this->get('remove_from_container') == 'yes') $input->setAttribute('checked', 'checked');

			$label->setValue(__('%s Remove file from cloud on entry deletion', array($input->generate())));

			$div->appendChild(Widget::Input($name, 'no', 'hidden'));
			$div->appendChild($label);

			$wrapper->appendChild($div);

			// Standard Symphony options
			$div = new XMLElement('div', NULL, array('class' => 'two columns'));
			$this->appendRequiredCheckbox($div);
			$this->appendShowColumnCheckbox($div);
			$wrapper->appendChild($div);
		}

		public function commit(){
			if(!Field::commit()) return false;

			$id = $this->get('id');

			if($id === false) return false;

			$fields = array();
			$fields['container'] = $this->get('container');
			$fields['remove_from_container'] = $this->get('remove_from_container');
			$fields['unique_filename'] = $this->get('unique_filename');
			$fields['validator'] = $this->get('validator');

			return FieldManager::saveSettings($id, $fields);
		}

	/*-------------------------------------------------------------------------
		Publish:
	-------------------------------------------------------------------------*/

		/**
		 * Majority of this function is taken from the core Upload field.
		 * Note to self. Make core Upload field easier to extend
		 */
		public function checkPostFieldData($data, &$message, $entry_id = null) {
			/**
			 * For information about PHPs upload error constants see:
			 * @link http://php.net/manual/en/features.file-upload.errors.php
			 */
			$message = null;
			if($this->rackspace->doesContainerExist($this->get('container')) === false) {
				$message = __('The container %s doesn\'t exist on Rackspace.', array(
					'<code>' . $this->get('container') . '</code>'
				));

				return self::__INVALID_FIELDS__;
			}

			if (
				empty($data)
				|| (
					is_array($data)
					&& isset($data['error'])
					&& $data['error'] == UPLOAD_ERR_NO_FILE
				)
			) {
				if ($this->get('required') == 'yes') {
					$message = __('‘%s’ is a required field.', array($this->get('label')));

					return self::__MISSING_FIELDS__;
				}

				return self::__OK__;
			}

			// It's not an array, so retain the current data and return
			if(!is_array($data)) return self::__OK__;

			// File is going to be uploaded.
			if ($data['error'] != UPLOAD_ERR_NO_FILE && $data['error'] != UPLOAD_ERR_OK) {
				switch ($data['error']) {
					case UPLOAD_ERR_INI_SIZE:
						$message = __('File chosen in ‘%1$s’ exceeds the maximum allowed upload size of %2$s specified by your host.', array($this->get('label'), (is_numeric(ini_get('upload_max_filesize')) ? General::formatFilesize(ini_get('upload_max_filesize')) : ini_get('upload_max_filesize'))));
						break;

					case UPLOAD_ERR_FORM_SIZE:
						$message = __('File chosen in ‘%1$s’ exceeds the maximum allowed upload size of %2$s, specified by Symphony.', array($this->get('label'), General::formatFilesize($_POST['MAX_FILE_SIZE'])));
						break;

					case UPLOAD_ERR_PARTIAL:
					case UPLOAD_ERR_NO_TMP_DIR:
						$message = __('File chosen in ‘%s’ was only partially uploaded due to an error.', array($this->get('label')));
						break;

					case UPLOAD_ERR_CANT_WRITE:
						$message = __('Uploading ‘%s’ failed. Could not write temporary file to disk.', array($this->get('label')));
						break;

					case UPLOAD_ERR_EXTENSION:
						$message = __('Uploading ‘%s’ failed. File upload stopped by extension.', array($this->get('label')));
						break;
				}

				return self::__ERROR_CUSTOM__;
			}

			// Sanitize the filename
			$data['name'] = Lang::createFilename($data['name']);

			// Get a unique name for the file if it's set
			if ($this->get('unique_filename') === 'yes') {
				$data['name'] = self::getUniqueFilename($data['name']);
			}

			// Check if the filename is valid
			$status = $this->isValidFile($data['name'], $message);
			if(isset($status)) return $status;

			// Check if the file already exists on the container.
			$row = Symphony::Database()->fetchRow(0, "SELECT file FROM `tbl_entries_data_".$this->get('id')."` WHERE `file`='".$data['name']."'");
			if (isset($row['file'])) {
				$message = __('A file with the name %1$s already exists on the container. Please rename the file first, or choose another.', array($data['name']));
				return self::__INVALID_FIELDS__;
			}

			return self::__OK__;
		}

		public function processRawFieldData($data, &$status, &$message=null, $simulate = false, $entry_id = null) {
			$status = self::__OK__;
			$existing_file = null;

			// No file given, save empty data:
			if ($data === null) {
				return array(
					'file' =>		null,
					'mimetype' =>	null,
					'size' =>		null,
					'meta' =>		null,
					'url' =>		null
				);
			}

			// Its not an array, so just retain the current data and return:
			if (is_array($data) === false) {
				$result = array(
					'file' =>		$data,
					'mimetype' =>	null,
					'size' =>		null,
					'meta' =>		null,
					'url' =>		null
				);

				// Grab the existing entry data to preserve the MIME type and size information
				if (isset($entry_id)) {
					$row = Symphony::Database()->fetchRow(0, sprintf(
						"SELECT `file`, `mimetype`, `size`, `meta`, `url` FROM `tbl_entries_data_%d` WHERE `entry_id` = %d",
						$this->get('id'),
						$entry_id
					));

					if (empty($row) === false) {
						$result = $row;
					}
				}

				return $result;
			}

			if ($simulate && is_null($entry_id)) return $data;

			// Check to see if the entry already has a file associated with it:
			if (is_null($entry_id) === false) {
				$row = Symphony::Database()->fetchRow(0, sprintf(
					"SELECT * FROM `tbl_entries_data_%s` WHERE `entry_id` = %d LIMIT 1",
					$this->get('id'),
					$entry_id
				));

				if(strlen($row['file']) !== 0) {
					$existing_file = trim($row['file'], '/');
				}

				// File was removed:
				if (
					$data['error'] == UPLOAD_ERR_NO_FILE
					&& !is_null($existing_file)
				) {
					$this->rackspace->deleteFile($existing_file, $this->get('container'));
				}
			}

			// Do not continue on upload error:
			if ($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) {
				return false;
			}

			// Sanitize the filename
			$data['name'] = Lang::createFilename($data['name']);

			// Get a unique name for the file if it's set
			if ($this->get('unique_filename') === 'yes') {
				$data['name'] = self::getUniqueFilename($data['name']);
			}

			// If browser doesn't send MIME type (e.g. .flv in Safari)
			if (strlen(trim($data['type'])) == 0) {
				$data['type'] = 'application/octet-stream';
			}

			// Attempt to upload the file:
			try {
				$data['url'] = $this->rackspace->uploadFile(
					array(
						'name' => $data['name'],
						'content_type' => $data['type']
					),
					$data['tmp_name'],
					$this->get('container')
				);
			}
			catch (Exception $ex) {
				$message = __(
					'There was an error while trying to upload the file %1$s to the container %2$s. %3$s',
					array(
						'<code>' . $data['name'] . '</code>',
						'<code>' . $this->get('container') . '</code>',
						'<code>' . $ex->getMessage() . '</code>'
					)
				);
				$status = self::__ERROR_CUSTOM__;

				return false;
			}

			// File has been replaced:
			if (
				isset($existing_file)
				&& $existing_file !== $data['name']
			) {
				$this->rackspace->deleteFile($existing_file, $this->get('container'));
			}

			// Meta information
			$meta = self::getMetaInfo($data['url'], $data['type']);

			return array(
				'file' =>		$data['name'],
				'size' =>		$data['size'],
				'mimetype' =>	$data['type'],
				'meta' =>		serialize($meta),
				'url' =>		$data['url']
			);
		}

	/*-------------------------------------------------------------------------
		Output:
	-------------------------------------------------------------------------*/

		public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null){
			$label = Widget::Label($this->get('label'));
			$label->setAttribute('class', 'file');
			if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));

			$span = new XMLElement('span', NULL, array('class' => 'frame'));
			if ($data['file']) {
				$link = Widget::Anchor(preg_replace("![^a-z0-9]+!i", "$0&#8203;", $data['file']), $data['url']);
				$link->setAttribute('target', '_blank');
				$span->appendChild(new XMLElement('span', $link));
			}

			$span->appendChild(
				Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $data['file'], ($data['file'] ? 'hidden' : 'file'))
			);

			$label->appendChild($span);

			if($flagWithError != NULL) $wrapper->appendChild(Widget::Error($label, $flagWithError));
			else $wrapper->appendChild($label);
		}

		public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null){
			// It is possible an array of NULL data will be passed in. Check for this.
			if(!is_array($data) || !isset($data['file']) || is_null($data['file'])){
				return;
			}

			$item = new XMLElement($this->get('element_name'));
			$item->setAttributeArray(array(
				'size' =>	General::formatFilesize($data['size']),
			 	'path' =>	str_replace($data['file'], NULL, $data['url']),
				'type' =>	$data['mimetype']
			));

			$item->appendChild(
				new XMLElement('filename', General::sanitize(basename($data['file'])))
			);
			$item->appendChild(
				new XMLElement('clean-filename', General::sanitize(self::getCleanFilename(basename($data['file']))))
			);

			$m = unserialize($data['meta']);
			if(is_array($m) && !empty($m)){
				$item->appendChild(new XMLElement('meta', NULL, $m));
			}

			$wrapper->appendChild($item);
		}

		public function prepareTableValue($data, XMLElement $link=NULL, $entry_id = null){
			if (!$file = $data['file']) {
				if ($link) return parent::prepareTableValue(null, $link, $entry_id);
				else return parent::prepareTableValue(null, $link, $entry_id);
			}

			if ($link) {
				$link->setValue(basename($file));
				$link->setAttribute('data-path', $file);

				return $link->generate();
			}

			else {
				$link = Widget::Anchor(basename($file), $data['url']);
				$link->setAttribute('data-path', $file);

				return $link->generate();
			}
		}
	
	/*-------------------------------------------------------------------------
		Export:
	-------------------------------------------------------------------------*/

		/**
		 * Give the field some data and ask it to return a value using one of many
		 * possible modes.
		 *
		 * @param mixed $data
		 * @param integer $mode
		 * @param integer $entry_id
		 * @return array|string|null
		 */
		public function prepareExportValue($data, $mode, $entry_id = null) {
			$modes = (object)$this->getExportModes();

			// No file, or the file that the entry is meant to have no
			// longer exists.
			if (!isset($data['file'])) {
				return null;
			}

			if ($mode === $modes->getFilename) {
				return $data['url'];
			}

			if ($mode === $modes->getObject) {
				$object = (object)$data;

				if (isset($object->meta)) {
					$object->meta = unserialize($object->meta);
				}

				return $object;
			}

			if ($mode === $modes->getPostdata) {
				return $data['file'];
			}
		}
	}
