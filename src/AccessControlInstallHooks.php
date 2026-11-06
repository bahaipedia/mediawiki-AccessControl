<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\GroupPermissionsLookup;

class AccessControlInstallHooks {

	/**
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( 'access_control', __DIR__ . '/../db_patches/access_control.sql' );
	}

	/**
	 * Register AccessControl wrappers for PdfHandler and thumb.php / thumb_handler.php
	 * permission checks during service container initialization.
	 *
	 * @param MediaWikiServices $services
	 */
	public function onMediaWikiServices( MediaWikiServices $services ) {
		// @phan-suppress-next-line PhanUndeclaredClassReference
		if ( class_exists( 'PdfHandler' ) || class_exists( \MediaWiki\Extension\PdfHandler\PdfHandler::class ) ) {
			global $wgMediaHandlers;
			if (
				!isset( $wgMediaHandlers['application/pdf'] ) ||
				in_array(
					$wgMediaHandlers['application/pdf'],
					// @phan-suppress-next-line PhanUndeclaredClassReference
					[ 'PdfHandler', \MediaWiki\Extension\PdfHandler\PdfHandler::class ],
					true
				)
			) {
				$wgMediaHandlers['application/pdf'] = AccessControlPdfHandler::class;
			}
		}

		if (
			defined( 'MW_ENTRY_POINT' ) &&
			in_array( MW_ENTRY_POINT, [ 'thumb', 'thumb_handler' ], true )
		) {
			$services->redefineService(
				'GroupPermissionsLookup',
				static function ( MediaWikiServices $container ): GroupPermissionsLookup {
					return new AccessControlGroupPermissionsLookup(
						new ServiceOptions(
							GroupPermissionsLookup::CONSTRUCTOR_OPTIONS,
							$container->getMainConfig()
						)
					);
				}
			);
		}
	}
}
