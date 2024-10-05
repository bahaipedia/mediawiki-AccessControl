<?php

use MediaWiki\MediaWikiServices;

class AccessControlHooks {

	private const TAG_CONTENT_ARRAY = 'AccessControlTagContentArray';
	private const TABLE = 'access_control';
	private const C_PAGE = 'ac_page_id';
	private const C_TAG_CONTENT = 'ac_tag_content';

	/**
	 * @var array
	 * @phan-var array<string,mixed>
	 */
	private static $cache = [];

	/**
	 * @var array
	 * @phan-var array<string,bool>
	 *
	 * Format: [ 'pageName1' => true, ... ]
	 * This is only used if $wgAccessControlAllowTextSnippetInSearchResultsForAll is true,
	 * which allows restricted pages to appear in search results.
	 *
	 * This array will contain the list of all restricted pages (which current user can't read)
	 * that were just shown to current user in the search results.
	 */
	private static $restrictedSearchResults = [];

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 *
	 * @param Parser $parser
	 * @throws MWException
	 */
	public static function accessControlExtension( Parser $parser ) {
		/* This the hook function adds the tag <accesscontrol> to the wiki parser */
		$parser->setHook( 'accesscontrol', [ __CLASS__, 'doControlUserAccess' ] );
	}

	/**
	 * Function called by accessControlExtension
	 * @param string $input
	 * @param string[] $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function doControlUserAccess( string $input, array $args, Parser $parser, PPFrame $frame ) {
		$parserOutput = $parser->getOutput();
		$data = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY ) ?: [];
		$inputArray = explode( ',', $input );
		$inputArray = array_map( 'trim', $inputArray );
		$data = array_merge( $data, $inputArray );
		$data = array_unique( $data );
		$parserOutput->setExtensionData( self::TAG_CONTENT_ARRAY, $data );

		return self::displayGroups();
	}

	/**
	 * @param User $user
	 * @param array|null $tagContentArray
	 * @param string $actionName
	 * @return Status
	 * @throws MWException
	 */
	private static function canUserDoAction( User $user, ?array $tagContentArray, string $actionName ): Status {
		// Return true by default
		$return = Status::newGood( true );
		$nosearch = false;

		if ( $tagContentArray ) {
			// For backward compatibility
			if ( count( $tagContentArray ) === 1 ) {
				$tagContentArray = explode( ',', $tagContentArray[0] );
				$tagContentArray = array_map( 'trim', $tagContentArray );
			}

			$i = array_search( '(nosearch)', $tagContentArray, true );
			if ( $i !== false ) {
				$nosearch = true;
				array_splice( $tagContentArray, $i, 1 );
			}
		}

		if ( !$tagContentArray ) {
			// No restrictions
			return $return;
		}

		if ( self::getConfigValue( 'AdminCanReadAll' ) &&
			in_array( 'sysop', $user->getGroups(), true )
		) {
			// Admin can read all
			return $return;
		}

		$userName = $user->isAnon() ? '*' : $user->getName();
		$fullAccess = true;
		$readAccess = true;
		$searchAccess = true;
		foreach ( $tagContentArray as $tagContent ) {
			$status = self::accessControl( $tagContent );
			if ( !$status->isGood() ) {
				$return->merge( $status );
			}
			$users = $status->getValue();
			$fullAccess = $fullAccess && $users[0] &&
				( in_array( $userName, $users[0], true ) || $userName !== '*' && in_array( '*', $users[0], true ) );
			$readAccess = $fullAccess ||
				( $readAccess && $users[1] &&
					( in_array( $userName, $users[1], true ) || $userName !== '*' && in_array( '*', $users[1], true ) )
				);
			$searchAccess = $readAccess ||
				( $searchAccess && $users[2] &&
					( in_array( $userName, $users[2], true ) || $userName !== '*' && in_array( '*', $users[2], true ) )
				);
		}

		if ( $fullAccess ) {
			// User has full access
			return $return;
		}

		if ( $actionName === 'search' ) {
			if ( $searchAccess ) {
				// Allowed.
				return $return;
			}

			if ( $nosearch ) {
				// Inform the caller that $wgAccessControlAllowTextSnippetInSearchResultsForAll
				// should be ignored for this page.
				$return->warning( 'accesscontrol-nosearch' );
			}
		}

		if ( $readAccess ) {
			// User has read access
			if ( $actionName === 'view' || $actionName === 'read' ) {
				// This is view action
				return $return;
			}
		}

		// Return false
		$return->setResult( true, false );
		return $return;
	}

	/**
	 * Checks page restriction
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @throws MWException
	 */
	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ) {
		$tagContentArray = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY );
		$user = $out->getUser();
		$context = $out->getContext();
		$actionName = Action::getActionName( $context );

		$status = self::canUserDoAction( $user, $tagContentArray, $actionName );
		if ( !$status->getValue() ) {
			// User has no access
			$parserOutput->setText( wfMessage( 'accesscontrol-info-box', $parserOutput->getRootText() )->parse() );
		}
		if ( !$status->isGood() ) {
			$text = $parserOutput->getRawText();
			$text = Html::rawElement( 'div', [ 'class' => 'error' ], $status->getHTML() ) . "\n$text";
			$parserOutput->setText( $text );
		}
	}

	/**
	 * @param string $accessList
	 * @return Status
	 * @throws MWException
	 */
	private static function accessControl( string $accessList ): Status {
		$accessGroup = [ [], [], [] ];
		$return = Status::newGood();
		if ( strpos( $accessList, '(search)' ) !== false ) {
			$accessList = trim( str_replace( '(search)', '', $accessList ) );
			$status = self::makeGroupArray( $accessList );
			if ( !$status->isGood() ) {
				$return->merge( $status );
			}
			if ( $status->isOK() ) {
				$group = $status->getValue();
				$accessGroup[2] = array_merge( $accessGroup[2], $group[0] );
				$accessGroup[2] = array_merge( $accessGroup[2], $group[1] );
				$accessGroup[2] = array_merge( $accessGroup[2], $group[2] );
			}
		} elseif ( strpos( $accessList, '(ro)' ) !== false ) {
			$accessList = trim( str_replace( '(ro)', '', $accessList ) );
			$status = self::makeGroupArray( $accessList );
			if ( !$status->isGood() ) {
				$return->merge( $status );
			}
			if ( $status->isOK() ) {
				$group = $status->getValue();
				$accessGroup[1] = array_merge( $accessGroup[1], $group[0] );
				$accessGroup[1] = array_merge( $accessGroup[1], $group[1] );
				$accessGroup[2] = array_merge( $accessGroup[2], $group[2] );
			}
		} else {
			$accessList = trim( $accessList );
			$status = self::makeGroupArray( $accessList );
			if ( !$status->isGood() ) {
				$return->merge( $status );
			}
			if ( $status->isOK() ) {
				$group = $status->getValue();
				$accessGroup[0] = array_merge( $accessGroup[0], $group[0] );
				$accessGroup[1] = array_merge( $accessGroup[1], $group[1] );
				$accessGroup[2] = array_merge( $accessGroup[2], $group[2] );
			}
		}

		$return->setResult( true, $accessGroup );
		return $return;
	}

	/**
	 * Function returns array with two lists.
	 * First is list full access users.
	 * Second is list readonly users.
	 * @param string $accessList
	 * @return Status
	 * @throws MWException
	 */
	private static function makeGroupArray( string $accessList ): Status {
		static $cache = [];

		if ( isset( $cache[$accessList] ) ) {
			return $cache[$accessList];
		}

		$usersWrite = [];
		$usersReadonly = [];
		$usersSearch = [];
		$status = self::getUsersFromPages( $accessList );
		if ( !$status->isOK() ) {
			return $status;
		}

		$users = $status->getValue();
		foreach ( array_keys( $users ) as $user ) {
			switch ( $users[$user] ) {
				case 'read':
					$usersReadonly[] = $user;
					break;
				case 'edit':
					$usersWrite[] = $user;
					break;
				case 'search':
					$usersSearch[] = $user;
					break;
			}
		}

		$return = [ $usersWrite, $usersReadonly, $usersSearch ];
		$status->setResult( true, $return );
		$cache[$accessList] = $status;
		return $status;
	}

	/**
	 * Shows info about a protection this the page at the accesscontrol place
	 * @return string
	 */
	private static function displayGroups() {
		$text = wfMessage( 'accesscontrol-info' )->text();
		$attribs = [
			'id' => 'accesscontrol',
			'style' => 'text-align:center; color:#BA0000; font-size:8pt;',
		];
		return Html::element( 'p', $attribs, $text );
	}

	/**
	 * @param string $group
	 * @return Status
	 * @throws MWException
	 */
	private static function getUsersFromPages( string $group ): Status {
		/* Extracts the allowed users from the userspace access list */
		$allow = [];
		try {
			$gt = Title::newFromTextThrow( $group );
		} catch ( MalformedTitleException $e ) {
			$status = Status::newFatal( $e->getMessageObject() );
			$status->error( 'accesscontrol-wrong-group-title', $group );
			return $status;
		}
		if ( !$gt->exists() ) {
			return Status::newFatal( 'accesscontrol-group-does-not-exist', $gt->getFullText() );
		}
		// Article::fetchContent() is deprecated.
		// Replaced by WikiPage::getContent()
		$groupPage = WikiPage::factory( $gt );
		$allowedUsers = ContentHandler::getContentText( $groupPage->getContent() );
		$groupPage = null;
		$usersAccess = explode( "\n", $allowedUsers );
		foreach ( $usersAccess as $userEntry ) {
			$userItem = trim( $userEntry );
			if ( $userItem && $userItem[0] === '*' ) {
				$user = trim( mb_substr( $userItem, 1 ) );
				if ( strpos( $userItem, '(search)' ) !== false ) {
					$user = trim( str_replace( '(search)', "", $user ) );
					$allow[$user] = 'search';
				} elseif ( strpos( $userItem, '(ro)' ) !== false ) {
					$user = trim( str_replace( '(ro)', "", $user ) );
					$allow[$user] = 'read';
				} else {
					$allow[$user] = 'edit';
				}
			}
		}
		return Status::newGood( $allow );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param string &$result
	 * @return bool
	 * @throws MWException
	 */
	public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		static $requestChecked = false;

		if ( !$requestChecked ) {
			// We need to check this once only
			$requestChecked = true;

			$context = RequestContext::getMain();
			$requestTitle = $context->getTitle();
			if ( $requestTitle ) {
				$requestUser = $context->getUser();
				$tagContentArray = self::getRestrictionForTitle( $requestTitle, $requestUser );
				if ( !self::canUserDoAction( $user, $tagContentArray, 'fullAccess' )->getValue() ) {
					// User has no full access
					global $wgActions;
					$wgActions['edit'] = false;
					$wgActions['history'] = false;
					$wgActions['submit'] = false;
					$wgActions['info'] = false;
					$wgActions['raw'] = false;
					$wgActions['delete'] = false;
					$wgActions['revert'] = false;
					$wgActions['revisiondelete'] = false;
					$wgActions['rollback'] = false;
					$wgActions['markpatrolled'] = false;
					if ( !self::canUserDoAction( $user, $tagContentArray, 'read' )->getValue() ) {
						// User has no read access
						$wgActions['view'] = false;
					}
				}
			}
		}

		if ( $action === 'read' && RequestContext::getMain()->getTitle()->isSpecial( 'Search' ) ) {
			$action = 'search';
		}

		$tagContentArray = self::getRestrictionForTitle( $title, $user );
		$status = self::canUserDoAction( $user, $tagContentArray, $action );
		$isAllowed = $status->getValue();

		// Special handling for search.
		if ( !$isAllowed && $action === 'search' &&
			self::getConfigValue( 'AccessControlAllowTextSnippetInSearchResultsForAll' ) &&
			!$status->hasMessage( 'accesscontrol-nosearch' )
		) {
			// If $wgAccessControlAllowTextSnippetInSearchResultsForAll is true (default: false),
			// then permission errors won't prevent this page from being shown in search results.
			// However, we might want to style these restricted results differently (in ShowSearchHit hook).
			self::$restrictedSearchResults[$title->getFullText()] = true;
			return true;
		}

		if ( !$isAllowed ) {
			$result = [ 'accesscontrol-info-box', $title->getRootText() ];
		}

		return $isAllowed;
	}

	/**
	 * @param SpecialSearch $searchPage
	 * @param SearchResult $result
	 * @param string[] $terms
	 * @param string &$link
	 * @param string &$redirect
	 * @param string &$section
	 * @param string &$extract
	 * @param string &$score
	 * @param string &$size
	 * @param string &$date
	 * @param string &$related
	 * @param string &$html
	 * @return bool|void
	 */
	public static function onShowSearchHit( $searchPage, $result, $terms, &$link,
		&$redirect, &$section, &$extract, &$score, &$size, &$date, &$related, &$html
	) {
		$pageName = $result->getTitle()->getFullText();
		if ( isset( self::$restrictedSearchResults[$pageName] ) ) {
			// User can see this page in search results, but is not allowed to read it.
			// Add a CSS class, so that these restricted results can be styled differently.
			$link = Xml::tags( 'span', [ 'class' => 'mw-ac-restricted-search-result' ], $link );
		}

		return true;
	}

	/**
	 * @param LinksUpdate $linksUpdate
	 */
	public static function onLinksUpdate( LinksUpdate $linksUpdate ) {
		$parserOutput = $linksUpdate->getParserOutput();
		$title = $linksUpdate->getTitle();

		$pageId = $title->getArticleID();
		$tagContentArray = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY );
		self::updateRestrictionInDatabase( $pageId, $tagContentArray );
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @return false|array|null
	 */
	private static function getRestrictionForTitle( Title $title, User $user ) {
		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return null;
		}

		if ( array_key_exists( $pageId, self::$cache ) ) {
			return self::$cache[$pageId];
		}

		$dbr = wfGetDB( DB_REPLICA );
		try {
			$row = $dbr->selectRow(
				self::TABLE,
				'*',
				[ self::C_PAGE => $title->getArticleID() ],
				__METHOD__
			);
		} catch ( Exception $e ) {
			MWDebug::warning( $e->getMessage() );
			$row = false;
		}

		if ( !$row ) {
			// No record in the database
			$page = new Article( $title );
			$return = $page->getParserOutput( null, $user )->getExtensionData( self::TAG_CONTENT_ARRAY );
		} else {
			$tagContent = ( (array)$row )[self::C_TAG_CONTENT];
			$return = $tagContent ? FormatJson::decode( $tagContent, true ) : null;
		}
		self::$cache[$pageId] = $return;
		return $return;
	}

	/**
	 * @param int $pageId
	 * @param array|null $tagContentArray
	 */
	private static function updateRestrictionInDatabase( int $pageId, ?array $tagContentArray ) {
		if ( !$pageId ) {
			return;
		}

		if ( array_key_exists( $pageId, self::$cache ) &&
			self::$cache[$pageId] === $tagContentArray
		) {
			// No changes
			return;
		}
		self::$cache[$pageId] = $tagContentArray;

		if ( $tagContentArray !== null ) {
			$tagContentArray = FormatJson::encode( $tagContentArray );
		}

		$db = wfGetDB( DB_MASTER );
		$index = [
			self::C_PAGE => $pageId,
		];
		$row = [
			self::C_TAG_CONTENT => $tagContentArray,
		];
		try {
			$db->upsert(
				self::TABLE,
				[ $index + $row ],
				[ [ self::C_PAGE ] ],
				$row,
			__METHOD__
			);
		} catch ( Exception $e ) {
			MWDebug::warning( $e->getMessage() );
		}
	}

	/**
	 * This is attached to the MediaWiki 'LoadExtensionSchemaUpdates' hook.
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( self::TABLE, __DIR__ . '/../db_patches/access_control.sql' );
	}

	/**
	 * @param string $name
	 * @return mixed
	 */
	private static function getConfigValue( string $name ) {
		static $cache = [];

		if ( !isset( $cache[$name] ) ) {
			$cache[$name] = MediaWikiServices::getInstance()->getMainConfig()->get( $name );
		}
		return $cache[$name];
	}
}
