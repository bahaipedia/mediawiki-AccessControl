<?php

use MediaWiki\Actions\Action;
use MediaWiki\Config\Config;
use MediaWiki\Content\TextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Html\Html;
use Xml;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Json\FormatJson;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Article;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Status\Status;
use MediaWiki\Title\MalformedTitleException;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IConnectionProvider;

class AccessControlHooks {

	private const TAG_CONTENT_ARRAY = 'AccessControlTagContentArray';
	private const TABLE = 'access_control';
	private const C_PAGE = 'ac_page_id';
	private const C_TAG_CONTENT = 'ac_tag_content';

	/**
	 * @var array
	 * @phan-var array<string|int,?mixed>
	 */
	private $cache = [];

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
	private $restrictedSearchResults = [];

	private Config $config;
	private UserGroupManager $userGroupManager;
	private IConnectionProvider $connectionProvider;
	private WikiPageFactory $wikiPageFactory;
	private TitleFactory $titleFactory;

	public function __construct(
		Config $config,
		UserGroupManager $userGroupManager,
		IConnectionProvider $connectionProvider,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory
	) {
		$this->config = $config;
		$this->userGroupManager = $userGroupManager;
		$this->connectionProvider = $connectionProvider;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 *
	 * @param Parser $parser
	 * @throws \MWException
	 */
	public function onParserFirstCallInit( Parser $parser ) {
		/* This the hook function adds the tag <accesscontrol> to the wiki parser */
		$parser->setHook( 'accesscontrol', [ $this, 'doControlUserAccess' ] );
	}

	/**
	 * Function called by accessControlExtension
	 * @param string $input
	 * @param string[] $args @phan-unused-param
	 * @param Parser $parser
	 * @return string
	 */
	public function doControlUserAccess( string $input, array $args, Parser $parser ) {
		$parserOutput = $parser->getOutput();
		$data = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY ) ?: [];
		$inputArray = explode( ',', $input );
		$inputArray = array_map( 'trim', $inputArray );
		$data = array_merge( $data, $inputArray );
		$data = array_unique( $data );
		$parserOutput->setExtensionData( self::TAG_CONTENT_ARRAY, $data );

		return $this->displayGroups();
	}

	/**
	 * @param User $user
	 * @param array|null $tagContentArray
	 * @param string $actionName
	 * @return Status
	 * @throws \MWException
	 */
	private function canUserDoAction( User $user, ?array $tagContentArray, string $actionName ): Status {
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

		if ( $this->config->get( 'AdminCanReadAll' ) ) {
			if ( in_array( 'sysop', $this->userGroupManager->getUserEffectiveGroups( $user ) ) ) {
				// Admin can read all
				return $return;
			}
		}

		$userName = $user->isAnon() ? '*' : $user->getName();
		$fullAccess = true;
		$readAccess = true;
		$searchAccess = true;
		foreach ( $tagContentArray as $tagContent ) {
			$status = $this->accessControl( $tagContent );
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
	 * @throws \MWException
	 */
	public function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ) {
		$tagContentArray = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY );
		$user = $out->getUser();
		$context = $out->getContext();
		$actionName = Action::getActionName( $context );

		$status = $this->canUserDoAction( $user, $tagContentArray, $actionName );
		if ( !$status->getValue() ) {
			// User has no access
			$parserOutput->setRawText(
				$out->msg( 'accesscontrol-info-box', $out->getTitle()->getRootText() )->parse()
			);
		}
		if ( !$status->isGood() ) {
			$text = $parserOutput->getRawText();
			$text = Html::rawElement( 'div', [ 'class' => 'error' ], $status->getMessage()->escaped() ) . "\n$text";
			$parserOutput->setRawText( $text );
		}
	}

	/**
	 * @param string $accessList
	 * @return Status
	 * @throws \MWException
	 */
	private function accessControl( string $accessList ): Status {
		$accessGroup = [ [], [], [] ];
		$return = Status::newGood();
		if ( strpos( $accessList, '(search)' ) !== false ) {
			$accessList = trim( str_replace( '(search)', '', $accessList ) );
			$status = $this->makeGroupArray( $accessList );
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
			$status = $this->makeGroupArray( $accessList );
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
			$status = $this->makeGroupArray( $accessList );
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
	 * @throws \MWException
	 */
	private function makeGroupArray( string $accessList ): Status {
		static $cache = [];

		if ( isset( $cache[$accessList] ) ) {
			return $cache[$accessList];
		}

		$usersWrite = [];
		$usersReadonly = [];
		$usersSearch = [];
		$status = $this->getUsersFromPages( $accessList );
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
	private function displayGroups() {
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
	 * @throws \MWException
	 */
	private function getUsersFromPages( string $group ): Status {
		/* Extracts the allowed users from the userspace access list */
		$allow = [];
		try {
			$gt = $this->titleFactory->newFromTextThrow( $group );
		} catch ( MalformedTitleException $e ) {
			$status = Status::newFatal( $e->getMessageObject() );
			$status->error( 'accesscontrol-wrong-group-title', $group );
			return $status;
		}
		if ( !$gt->exists() ) {
			return Status::newFatal( 'accesscontrol-group-does-not-exist', $gt->getFullText() );
		}

		$groupPage = $this->wikiPageFactory->newFromLinkTarget( $gt );
		$content = $groupPage->getContent();
		if ( !( $content instanceof TextContent ) ) {
			// Non-text page, treat it as empty.
			return Status::newGood( [] );
		}

		$allowedUsers = $content->getText();
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
	 * @throws \MWException
	 */
	public function onGetUserPermissionsErrors( Title $title, User $user, $action, &$result ) {
		static $requestChecked = false;

		if ( !$requestChecked ) {
			// We need to check this once only
			$requestChecked = true;

			$context = RequestContext::getMain();
			$requestTitle = $context->getTitle();
			if ( $requestTitle ) {
				$requestUser = $context->getUser();
				$tagContentArray = $this->getRestrictionForTitle( $requestTitle, $requestUser );
				if ( !$this->canUserDoAction( $user, $tagContentArray, 'fullAccess' )->getValue() ) {
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
					if ( !$this->canUserDoAction( $user, $tagContentArray, 'read' )->getValue() ) {
						// User has no read access
						$wgActions['view'] = false;
					}
				}
			}
		}

		$currentContextTitle = RequestContext::getMain()->getTitle();
		if ( $action === 'read' && $currentContextTitle && $currentContextTitle->isSpecial( 'Search' ) ) {
			$action = 'search';
		}

		$tagContentArray = $this->getRestrictionForTitle( $title, $user );
		$status = $this->canUserDoAction( $user, $tagContentArray, $action );
		$isAllowed = $status->getValue();

		// Special handling for search.
		if ( !$isAllowed && $action === 'search' &&
			$this->config->get( 'AccessControlAllowTextSnippetInSearchResultsForAll' ) &&
			!$status->hasMessage( 'accesscontrol-nosearch' )
		) {
			// If $wgAccessControlAllowTextSnippetInSearchResultsForAll is true (default: false),
			// then permission errors won't prevent this page from being shown in search results.
			// However, we might want to style these restricted results differently (in ShowSearchHit hook).
			$this->restrictedSearchResults[$title->getFullText()] = true;
			return true;
		}

		if ( !$isAllowed ) {
			$result = [ 'accesscontrol-info-box', $title->getRootText() ];
		}

		return $isAllowed;
	}

	/**
	 * @param \SpecialSearch $searchPage @phan-unused-param
	 * @param \SearchResult $result
	 * @param string[] $terms @phan-unused-param
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
	public function onShowSearchHit( $searchPage, $result, $terms, &$link,
		&$redirect, &$section, &$extract, &$score, &$size, &$date, &$related, &$html
	) {
		$pageName = $result->getTitle()->getFullText();
		if ( isset( $this->restrictedSearchResults[$pageName] ) ) {
			// User can see this page in search results, but is not allowed to read it.
			// Add a CSS class, so that these restricted results can be styled differently.
			$link = Xml::tags( 'span', [ 'class' => 'mw-ac-restricted-search-result' ], $link );
		}

		return true;
	}

	/**
	 * @param LinksUpdate $linksUpdate
	 */
	public function onLinksUpdate( LinksUpdate $linksUpdate ) {
		$parserOutput = $linksUpdate->getParserOutput();
		$title = $linksUpdate->getTitle();

		$pageId = $title->getArticleID();
		$tagContentArray = $parserOutput->getExtensionData( self::TAG_CONTENT_ARRAY );
		$this->updateRestrictionInDatabase( $pageId, $tagContentArray );
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @return false|array|null
	 */
	private function getRestrictionForTitle( Title $title, User $user ) {
		$pageId = $title->getArticleID();
		if ( !$pageId ) {
			return null;
		}

		if ( array_key_exists( $pageId, $this->cache ) ) {
			return $this->cache[$pageId];
		}

		$dbr = $this->connectionProvider->getReplicaDatabase();
		try {
			$row = $dbr->selectRow(
				self::TABLE,
				'*',
				[ self::C_PAGE => $title->getArticleID() ],
				__METHOD__
			);
		} catch ( \Exception $e ) {
			\MWDebug::warning( $e->getMessage() );
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
		$this->cache[$pageId] = $return;
		return $return;
	}

	/**
	 * @param int $pageId
	 * @param array|null $tagContentArray
	 */
	private function updateRestrictionInDatabase( int $pageId, ?array $tagContentArray ) {
		if ( !$pageId ) {
			return;
		}

		if ( array_key_exists( $pageId, $this->cache ) &&
			$this->cache[$pageId] === $tagContentArray
		) {
			// No changes
			return;
		}
		$this->cache[$pageId] = $tagContentArray;

		if ( $tagContentArray !== null ) {
			$tagContentArray = FormatJson::encode( $tagContentArray );
		}

		$db = $this->connectionProvider->getPrimaryDatabase();
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
		} catch ( \Exception $e ) {
			\MWDebug::warning( $e->getMessage() );
		}
	}

	/**
	 * This is attached to the MediaWiki 'LoadExtensionSchemaUpdates' hook.
	 * Fired when MediaWiki is updated to allow extensions to update the database
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( self::TABLE, __DIR__ . '/../db_patches/access_control.sql' );
	}
}
