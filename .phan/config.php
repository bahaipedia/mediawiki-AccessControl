<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

# Detect unused method parameters, etc.
$cfg['unused_variable_detection'] = true;

if ( getenv( 'PHAN_CHECK_DEPRECATED' ) ) {
	# Optional: warn about the use of @deprecated methods, etc.
	# Not enabled by default (without PHAN_CHECK_DEPRECATED=1) for backward compatibility.
	$cfg['suppress_issue_types'] = array_filter( $cfg['suppress_issue_types'], static function ( $issue ) {
		return strpos( $issue, 'PhanDeprecated' ) === false;
	} );
}

return $cfg;
