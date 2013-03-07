<?php

	interface CloudProvider {
		 public function getCredentials();
		 public function getPreferencesPanel();
		 public function getDisplaySettingsPanel(XMLElement &$wrapper, array $settings, $errors = null);
	}