<?php

use MediaWiki\Api\ApiPageSet;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryAllImages;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\File\FileSelectQueryBuilder;
use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\GroupPermissionsLookup;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Extension:AccessControl override for ApiQueryAllImages (list=allimages / generator=allimages).
 *
 * Filters out restricted File: pages that the current user cannot read and emits an API warning.
 */
class AccessControlApiQueryAllImages extends ApiQueryAllImages {

	private GroupPermissionsLookup $acGroupPermissionsLookup;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		RepoGroup $repoGroup,
		GroupPermissionsLookup $groupPermissionsLookup
	) {
		parent::__construct( $query, $moduleName, $repoGroup, $groupPermissionsLookup );
		$this->acGroupPermissionsLookup = $groupPermissionsLookup;
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
		$this->runAccessControlAllImages();
	}

	/** @inheritDoc */
	public function executeGenerator( $resultPageSet ) {
		if ( $resultPageSet->isResolvingRedirects() ) {
			$this->dieWithError( 'apierror-allimages-redirect', 'invalidparammix' );
		}

		$this->runAccessControlAllImages( $resultPageSet );
	}

	/**
	 * @inheritDoc
	 * @param array $params @phan-unused-param
	 */
	public function getCacheMode( $params ) {
		return 'private';
	}

	/**
	 * @param ApiPageSet|null $resultPageSet
	 * @return void
	 */
	private function runAccessControlAllImages( $resultPageSet = null ) {
		$repo = $this->mRepo;
		if ( !$repo instanceof LocalRepo ) {
			$this->dieWithError( 'apierror-unsupportedrepo' );
		}

		$prefix = $this->getModulePrefix();
		$db = $this->getDB();
		$params = $this->extractRequestParams();
		$prop = array_fill_keys( $params['prop'], true );

		$fileQuery = FileSelectQueryBuilder::newForFile( $db )->getQueryInfo();
		$this->addTables( $fileQuery['tables'] );
		$this->addFields( $fileQuery['fields'] );
		$this->addJoinConds( $fileQuery['join_conds'] );

		$ascendingOrder = true;
		if ( $params['dir'] == 'descending' || $params['dir'] == 'older' ) {
			$ascendingOrder = false;
		}

		if ( $params['sort'] == 'name' ) {
			$disallowed = [ 'start', 'end', 'user' ];
			foreach ( $disallowed as $pname ) {
				if ( isset( $params[$pname] ) ) {
					$this->dieWithError(
						[
							'apierror-invalidparammix-mustusewith',
							"{$prefix}{$pname}",
							"{$prefix}sort=timestamp"
						],
						'invalidparammix'
					);
				}
			}
			if ( $params['filterbots'] != 'all' ) {
				$this->dieWithError(
					[
						'apierror-invalidparammix-mustusewith',
						"{$prefix}filterbots",
						"{$prefix}sort=timestamp"
					],
					'invalidparammix'
				);
			}

			if ( $params['continue'] !== null ) {
				$cont = $this->parseContinueParamOrDie( $params['continue'], [ 'string' ] );
				$op = $ascendingOrder ? '>=' : '<=';
				$this->addWhere( $db->expr( 'img_name', $op, $cont[0] ) );
			}

			$from = $params['from'] === null ? null : $this->titlePartToKey( $params['from'], NS_FILE );
			$to = $params['to'] === null ? null : $this->titlePartToKey( $params['to'], NS_FILE );
			$this->addWhereRange( 'img_name', $ascendingOrder ? 'newer' : 'older', $from, $to );

			if ( isset( $params['prefix'] ) ) {
				$this->addWhere(
					$db->expr(
						'img_name',
						IExpression::LIKE,
						new LikeValue( $this->titlePartToKey( $params['prefix'], NS_FILE ), $db->anyString() )
					)
				);
			}
		} else {
			$disallowed = [ 'from', 'to', 'prefix' ];
			foreach ( $disallowed as $pname ) {
				if ( isset( $params[$pname] ) ) {
					$this->dieWithError(
						[
							'apierror-invalidparammix-mustusewith',
							"{$prefix}{$pname}",
							"{$prefix}sort=name"
						],
						'invalidparammix'
					);
				}
			}
			if ( $params['user'] !== null && $params['filterbots'] != 'all' ) {
				$this->dieWithError(
					[ 'apierror-invalidparammix-cannotusewith', "{$prefix}user", "{$prefix}filterbots" ]
				);
			}

			$this->addTimestampWhereRange(
				'img_timestamp',
				$ascendingOrder ? 'newer' : 'older',
				$params['start'],
				$params['end']
			);
			$this->addWhereRange( 'img_name', $ascendingOrder ? 'newer' : 'older', null, null );

			if ( $params['continue'] !== null ) {
				$cont = $this->parseContinueParamOrDie( $params['continue'], [ 'timestamp', 'string' ] );
				$op = ( $ascendingOrder ? '>=' : '<=' );
				$this->addWhere( $db->buildComparison( $op, [
					'img_timestamp' => $db->timestamp( $cont[0] ),
					'img_name' => $cont[1],
				] ) );
			}

			if ( $params['user'] !== null ) {
				if ( isset( $fileQuery['fields']['img_user_text'] ) ) {
					$this->addWhereFld( $fileQuery['fields']['img_user_text'], $params['user'] );
				} else {
					$this->addWhereFld( 'img_user_text', $params['user'] );
				}
			}
			if ( $params['filterbots'] != 'all' ) {
				$this->addTables( 'user_groups' );
				$this->addJoinConds( [ 'user_groups' => [
					'LEFT JOIN',
					[
						'ug_group' => $this->acGroupPermissionsLookup->getGroupsWithPermission( 'bot' ),
						'ug_user = actor_user',
						$db->expr( 'ug_expiry', '=', null )->or( 'ug_expiry', '>=', $db->timestamp() )
					]
				] ] );
				$groupCond = $params['filterbots'] == 'nobots' ? 'NULL' : 'NOT NULL';
				$this->addWhere( "ug_group IS $groupCond" );
			}
		}

		if ( isset( $params['minsize'] ) ) {
			$this->addWhere( 'img_size>=' . (int)$params['minsize'] );
		}

		if ( isset( $params['maxsize'] ) ) {
			$this->addWhere( 'img_size<=' . (int)$params['maxsize'] );
		}

		$sha1 = false;
		if ( isset( $params['sha1'] ) ) {
			$sha1 = strtolower( $params['sha1'] );
			if ( !$this->validateSha1Hash( $sha1 ) ) {
				$this->dieWithError( 'apierror-invalidsha1hash' );
			}
			$sha1 = \Wikimedia\base_convert( $sha1, 16, 36, 31 );
		} elseif ( isset( $params['sha1base36'] ) ) {
			$sha1 = strtolower( $params['sha1base36'] );
			if ( !$this->validateSha1Base36Hash( $sha1 ) ) {
				$this->dieWithError( 'apierror-invalidsha1base36hash' );
			}
		}
		if ( $sha1 ) {
			$this->addWhereFld( 'img_sha1', $sha1 );
		}

		if ( $params['mime'] !== null ) {
			if ( $this->getConfig()->get( MainConfigNames::MiserMode ) ) {
				$this->dieWithError( 'apierror-mimesearchdisabled' );
			}

			$mimeConds = [];
			foreach ( $params['mime'] as $mime ) {
				[ $major, $minor ] = File::splitMime( $mime );
				$mimeConds[] =
					$db->expr( 'img_major_mime', '=', $major )
						->and( 'img_minor_mime', '=', $minor );
			}
			if ( count( $mimeConds ) > 0 ) {
				$this->addWhere( $db->orExpr( $mimeConds ) );
			} else {
				$this->getResult()->addValue( 'query', $this->getModuleName(), [] );
				return;
			}
		}

		$limit = $params['limit'];
		$this->addOption( 'LIMIT', $limit + 1 );

		$res = $this->select( __METHOD__ );

		$titles = [];
		$count = 0;
		$result = $this->getResult();
		foreach ( $res as $row ) {
			if ( ++$count > $limit ) {
				if ( $params['sort'] == 'name' ) {
					$this->setContinueEnumParameter( 'continue', $row->img_name );
				} else {
					$this->setContinueEnumParameter( 'continue', "$row->img_timestamp|$row->img_name" );
				}
				break;
			}

			$titleObj = Title::makeTitleSafe( NS_FILE, $row->img_name );
			if ( !$titleObj || !$this->canCurrentUserReadFile( $titleObj ) ) {
				if ( $titleObj ) {
					$this->addWarning(
						[ 'apierror-cannotviewtitle', wfEscapeWikiText( $titleObj->getPrefixedText() ) ],
						'accessdenied'
					);
				}
				continue;
			}

			if ( $resultPageSet === null ) {
				$file = $repo->newFileFromRow( $row );
				$info = AccessControlApiQueryImageInfo::getInfo( $file, $prop, $result ) +
					[ 'name' => $row->img_name ];
				self::addTitleInfo( $info, $file->getTitle() );

				$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $info );
				if ( !$fit ) {
					if ( $params['sort'] == 'name' ) {
						$this->setContinueEnumParameter( 'continue', $row->img_name );
					} else {
						$this->setContinueEnumParameter( 'continue', "$row->img_timestamp|$row->img_name" );
					}
					break;
				}
			} else {
				$titles[] = $titleObj;
			}
		}

		if ( $resultPageSet === null ) {
			$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'img' );
		} else {
			$resultPageSet->populateFromTitles( $titles );
		}
	}
}
