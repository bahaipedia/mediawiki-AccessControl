<?php

use MediaWiki\Extension\PdfHandler\PdfHandler;
use MediaWiki\FileRepo\File\File;
use MediaWiki\Media\MediaTransformError;
use MediaWiki\Media\MediaTransformOutput;
use MediaWiki\Media\ThumbnailImage;
use MediaWiki\Media\TransformParameterError;

if (
	class_exists( 'PdfHandler' ) &&
	!class_exists( PdfHandler::class )
) {
	class_alias( 'PdfHandler', PdfHandler::class );
}

/**
 * Extension:AccessControl wrapper for PdfHandler.
 *
 * Prevents PDF page rendering (Ghostscript/ImageMagick) and S3 thumbnail uploads
 * when the current user lacks read permission for the File: page or when a
 * restricted PDF is being transformed in an unrestricted page parse context.
 *
 * @phan-suppress-next-line PhanUndeclaredExtendedClass
 */
class AccessControlPdfHandler extends PdfHandler {

	/**
	 * @param File $image
	 * @param string $dstPath
	 * @param string $dstUrl
	 * @param array $params
	 * @return MediaTransformError|MediaTransformOutput|ThumbnailImage|TransformParameterError
	 */
	public function getTransform( $image, $dstPath, $dstUrl, $params ) {
		if ( !AccessControlHooks::getInstance()->canTransformFile( $image, $params ) ) {
			$width = (int)( $params['width'] ?? 0 );
			$height = (int)( $params['height'] ?? 0 );
			// @phan-suppress-next-line PhanUndeclaredMethod
			return $this->doThumbError( $width, $height, 'badaccess-group0' );
		}

		// @phan-suppress-next-line PhanUndeclaredMethod
		return parent::getTransform( $image, $dstPath, $dstUrl, $params );
	}

	/**
	 * @param File $image
	 * @param string $dstPath
	 * @param string $dstUrl
	 * @param array $params
	 * @param int $flags
	 * @return MediaTransformError|MediaTransformOutput|ThumbnailImage|TransformParameterError
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		if ( !AccessControlHooks::getInstance()->canTransformFile( $image, $params ) ) {
			$width = (int)( $params['width'] ?? 0 );
			$height = (int)( $params['height'] ?? 0 );
			// @phan-suppress-next-line PhanUndeclaredMethod
			return $this->doThumbError( $width, $height, 'badaccess-group0' );
		}

		// @phan-suppress-next-line PhanUndeclaredMethod
		return parent::doTransform( $image, $dstPath, $dstUrl, $params, $flags );
	}
}
