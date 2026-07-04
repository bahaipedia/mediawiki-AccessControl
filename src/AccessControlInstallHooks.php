<?php

use MediaWiki\Installer\DatabaseUpdater;

class AccessControlInstallHooks {

	/**
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'access_control', __DIR__ . '/../db_patches/access_control.sql' );
	}
}
