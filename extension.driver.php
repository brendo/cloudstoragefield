<?php

	require __DIR__ . '/providers/rackspace/cloud.rackspace.php';

	class extension_cloudstoragefield extends Extension {

		public function install() {
			return Symphony::Database()->query("
				CREATE TABLE IF NOT EXISTS `tbl_fields_cloudstorage` (
					`id` int(11) unsigned NOT NULL auto_increment,
					`field_id` int(11) unsigned NOT NULL,
					`validator` varchar(150),
					`container` varchar(255) NOT NULL,
					`remove_from_container` enum('yes','no') DEFAULT 'yes',
					`unique_filename` enum('yes','no') DEFAULT 'no',
					PRIMARY KEY (`id`),
					KEY `field_id` (`field_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
			");
		}

		public function uninstall() {
			return Symphony::Database()->query("DROP TABLE IF EXISTS `tbl_fields_cloudstorage`");
		}

		public function update($previousVersion = false) {
			// Update to 0.2
			if(version_compare($previousVersion, '0.2', '<')) {
				// Change meta column to be TEXT columns
				$field_tables = Symphony::Database()->fetchCol("field_id", "SELECT `field_id` FROM `tbl_fields_cloudstorage`");

				foreach($field_tables as $field) {
					Symphony::Database()->query(sprintf(
						"ALTER TABLE `tbl_entries_data_%d` CHANGE `meta` `meta` TEXT",
						$field
					));
					Symphony::Database()->query(sprintf(
						'OPTIMIZE TABLE `tbl_entries_data_%d`', $field
					));
				}
			}

			return true;
		}

	/*-------------------------------------------------------------------------
		Delegates:
	-------------------------------------------------------------------------*/

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page'	=> '/system/preferences/',
					'delegate'	=> 'Save',
					'callback'	=> 'savePreferences'
				),
			);
		}

		public function appendPreferences($context) {
			// Get details from Rackspace.
			$rackspace = new Providers_Rackspace();
			$rackspace_settings = $rackspace->getPreferencesPanel();

			$context['wrapper']->appendChild($rackspace_settings);
		}

		public function savePreferences(&$context) {
			$rackspace = new Providers_Rackspace();
			$rackspace->savePreferences($context);
		}
	}
