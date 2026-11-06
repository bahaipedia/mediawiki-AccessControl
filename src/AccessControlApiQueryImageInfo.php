<?php

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryImageInfo;
use MediaWiki\Context\RequestContext;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\File\BadFileLookup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\ScopedCallback;

/**
 * Extension:AccessControl override for ApiQueryImageInfo (prop=imageinfo).
 *
 * Ensures that unauthenticated or unauthorized users cannot trigger
 * $file->transform() (which renders PDF pages and uploads thumbnails to S3)
 * or retrieve URLs/metadata for restricted File: pages.
 */
class AccessControlApiQueryImageInfo extends ApiQueryImageInfo {

	private RepoGroup $acRepoGroup;

	/**
	 * @param ApiQuery $query
	 * @param string $moduleName
	 * @param string|RepoGroup|null $prefixOrRepoGroup
	 * @param RepoGroup|Language|null $repoGroupOrContentLanguage
	 * @param Language|BadFileLookup|null $contentLanguageOrBadFileLookup
	 * @param BadFileLookup|null $badFileLookupOrUnused
	 */
	public function __construct(
		ApiQuery $query,
		string $moduleName,
		$prefixOrRepoGroup = null,
		$repoGroupOrContentLanguage = null,
		$contentLanguageOrBadFileLookup = null,
		$badFileLookupOrUnused = null
	) {
		parent::__construct(
			$query,
			$moduleName,
			$prefixOrRepoGroup,
			$repoGroupOrContentLanguage,
			$contentLanguageOrBadFileLookup,
			$badFileLookupOrUnused
		);

		$repoGroup = !is_string( $prefixOrRepoGroup )
			? $prefixOrRepoGroup
			: $repoGroupOrContentLanguage;
		$this->acRepoGroup = $repoGroup ?? MediaWikiServices::getInstance()->getRepoGroup();
	}

	/**
	 * Check whether the current user is allowed to read the given File: title.
	 *
	 * @param Title $title
	 * @return bool
	 */
	private function canCurrentUserReadFile( Title $title ): bool {
		if ( !$this->getAuthority()->authorizeRead( 'read', $title ) ) {
			return false;
		}
		return AccessControlHooks::getInstance()->userCanReadTitle( $title, $this->getUser() );
	}

	/** @inheritDoc */
	public function execute() {
		$pageSet = $this->getPageSet();
		$pageIds = $pageSet->getGoodAndMissingTitlesByNamespace();
		$restoreScope = null;

		if ( !empty( $pageIds[NS_FILE] ) ) {
			$filteredFilePages = $pageIds[NS_FILE];
			$modified = false;

			foreach ( array_keys( $filteredFilePages ) as $dbkey ) {
				$titleObj = Title::makeTitleSafe( NS_FILE, $dbkey );
				if ( !$titleObj || !$this->canCurrentUserReadFile( $titleObj ) ) {
					if ( $titleObj ) {
						$this->addWarning(
							[ 'apierror-cannotviewtitle', wfEscapeWikiText( $titleObj->getPrefixedText() ) ],
							'accessdenied'
						);
					}
					unset( $filteredFilePages[$dbkey] );
					$modified = true;
					continue;
				}

				// Also check if this File: title redirects to a restricted File: target.
				$file = $this->acRepoGroup->findFile(
					$titleObj,
					[ 'private' => $this->getAuthority() ]
				);
				if ( $file && $file->getTitle() && !$this->canCurrentUserReadFile( $file->getTitle() ) ) {
					$this->addWarning(
						[ 'apierror-cannotviewtitle', wfEscapeWikiText( $file->getTitle()->getPrefixedText() ) ],
						'accessdenied'
					);
					unset( $filteredFilePages[$dbkey] );
					$modified = true;
				}
			}

			if ( $modified ) {
				$refProp = new \ReflectionProperty( $pageSet, 'mGoodAndMissingPages' );
				$originalPages = $refProp->getValue( $pageSet );
				$updatedPages = $originalPages;
				$updatedPages[NS_FILE] = $filteredFilePages;
				$refProp->setValue( $pageSet, $updatedPages );
				$restoreScope = new ScopedCallback( static function () use ( $refProp, $pageSet, $originalPages ) {
					$refProp->setValue( $pageSet, $originalPages );
				} );
			}
		}

		try {
			parent::execute();
		} finally {
			ScopedCallback::consume( $restoreScope );
		}
	}

	/** @inheritDoc */
	public static function getInfo( $file, $prop, $result, $thumbParams = null, $opts = false ) {
		$title = $file->getTitle();
		if ( $title ) {
			$user = null;
			if ( is_array( $opts ) && isset( $opts['revdelUser'] ) ) {
				$revdelUser = $opts['revdelUser'];
				if ( $revdelUser instanceof User ) {
					$user = $revdelUser;
				} elseif ( $revdelUser instanceof Authority ) {
					$user = MediaWikiServices::getInstance()
						->getUserFactory()
						->newFromUserIdentity( $revdelUser->getUser() );
				}
			}
			$user ??= RequestContext::getMain()->getUser();
			if ( !AccessControlHooks::getInstance()->userCanReadTitle( $title, $user ) ) {
				return [];
			}
		}

		return parent::getInfo( $file, $prop, $result, $thumbParams, $opts );
	}

	/**
	 * @inheritDoc
	 * @param array $params @phan-unused-param
	 */
	public function getCacheMode( $params ) {
		return 'private';
	}
}
