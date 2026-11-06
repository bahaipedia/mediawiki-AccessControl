<?php

use MediaWiki\Permissions\GroupPermissionsLookup;

/**
 * GroupPermissionsLookup wrapper used for thumb.php and thumb_handler.php
 * so ThumbnailEntryPoint::maybeDenyAccess() always enforces per-title
 * Authority::authorizeRead( 'read', $imgTitle ) checks via AccessControl.
 */
class AccessControlGroupPermissionsLookup extends GroupPermissionsLookup {

	/** @inheritDoc */
	public function groupHasPermission( string $group, string $permission ): bool {
		if ( $group === '*' && $permission === 'read' ) {
			return false;
		}
		return parent::groupHasPermission( $group, $permission );
	}
}
