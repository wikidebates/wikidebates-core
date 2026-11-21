<?php

namespace MediaWiki\Extension\Wikidebates;

use CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class AutoParentLink {

	private const LOG_CHANNEL = 'Wikidebates-autoparent';

	private const WIKI_CONFIG = [
		'fr' => [
			'argumentTemplate' => 'Argument',
			'debateTemplate' => 'Débat',
			'initializationParam' => 'initialisation',
			'childPageParam' => 'page',
			'childDisplayTitleParam' => 'titre-affiché',
			'childWarningsParam' => 'avertissements',
			'types' => [
				'Argument pour' => [
					'parameter' => 'arguments-pour',
					'template' => 'Argument pour',
					'order' => 'debate',
					'parentTemplate' => 'Débat',
				],
				'Argument contre' => [
					'parameter' => 'arguments-contre',
					'template' => 'Argument contre',
					'order' => 'debate',
					'parentTemplate' => 'Débat',
				],
				'Justification' => [
					'parameter' => 'justifications',
					'template' => 'Justification',
					'order' => 'argument',
					'parentTemplate' => 'Argument',
				],
				'Objection' => [
					'parameter' => 'objections',
					'template' => 'Objection',
					'order' => 'argument',
					'parentTemplate' => 'Argument',
				],
			],
			'orderDebate' => [
				'sujet',
				'sujet-complet',
				'avancement',
				'avertissements-titre',
				'avertissements-débat',
				'introduction',
				'articles-Wikipédia',
				'arguments-pour',
				'arguments-contre',
				'bibliographie-pour',
				'bibliographie-contre',
				'bibliographie-ni-pour-ni-contre',
				'sitographie-pour',
				'sitographie-contre',
				'sitographie-ni-pour-ni-contre',
				'vidéographie-pour',
				'vidéographie-contre',
				'vidéographie-ni-pour-ni-contre',
				'débats-connexes',
				'rubriques',
				'mots-clés',
				'interlangue',
				'date-création',
			],
			'orderArgument' => [
				'initialisation',
				'nom',
				'avertissements-titre',
				'avertissements-argument',
				'avertissements-résumé',
				'résumé',
				'citations',
				'avertissements-références',
				'références-bibliographiques',
				'références-sitographiques',
				'références-vidéographiques',
				'avertissements-justifications',
				'justifications',
				'avertissements-objections',
				'objections',
				'débat-détaillé',
				'rubriques',
				'mots-clés',
				'interlangue',
				'date-création',
			],
		],
		'en' => [
			'argumentTemplate' => 'Argument',
			'debateTemplate' => 'Debate',
			'initializationParam' => 'initialization',
			'childPageParam' => 'page',
			'childDisplayTitleParam' => 'displayed-title',
			'childWarningsParam' => 'warnings',
			'types' => [
				'Pro argument' => [
					'parameter' => 'pro-arguments',
					'template' => 'Pro argument',
					'order' => 'debate',
					'parentTemplate' => 'Debate',
				],
				'Con argument' => [
					'parameter' => 'con-arguments',
					'template' => 'Con argument',
					'order' => 'debate',
					'parentTemplate' => 'Debate',
				],
				'Justification' => [
					'parameter' => 'justifications',
					'template' => 'Justification',
					'order' => 'argument',
					'parentTemplate' => 'Argument',
				],
				'Objection' => [
					'parameter' => 'objections',
					'template' => 'Objection',
					'order' => 'argument',
					'parentTemplate' => 'Argument',
				],
			],
			'orderDebate' => [
				'topic',
				'complete-topic',
				'progress',
				'title-warnings',
				'debate-warnings',
				'introduction',
				'wikipedia-articles',
				'pro-arguments',
				'con-arguments',
				'pro-bibliography',
				'con-bibliography',
				'bibliography',
				'pro-webliography',
				'con-webliography',
				'webliography',
				'pro-videography',
				'con-videography',
				'videography',
				'related-debates',
				'sections',
				'keywords',
				'creation-date',
			],
			'orderArgument' => [
				'initialization',
				'name',
				'title-warnings',
				'argument-warnings',
				'summary-warnings',
				'summary',
				'quotes',
				'reference-warnings',
				'bibliography',
				'webliography',
				'videography',
				'justification-warnings',
				'justifications',
				'objection-warnings',
				'objections',
				'detailed-debate',
				'sections',
				'keywords',
				'creation-date',
			],
		],
	];

	public static function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		if ( !method_exists( $editResult, 'isNew' ) || !$editResult->isNew() ) {
			return;
		}

		$title = $wikiPage->getTitle();
		$config = self::getWikiConfig();

		if ( $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		$content = $wikiPage->getContent( SlotRecord::MAIN );
		if ( !$content ) {
			self::logProblem( 'Contenu principal absent pour page=' . $title->getPrefixedText() );
			return;
		}

		$text = $content instanceof TextContent ? $content->getText() : null;
		if ( $text === null || $text === '' ) {
			self::logProblem( 'Texte principal vide pour page=' . $title->getPrefixedText() );
			return;
		}

		$trimmedText = ltrim( $text );
		$expectedStart = '{{' . $config['argumentTemplate'];

		if ( substr( $trimmedText, 0, strlen( $expectedStart ) ) !== $expectedStart ) {
			return;
		}

		$childTemplate = self::parseTopLevelTemplate( $text );
		if ( $childTemplate === null ) {
			self::logProblem( 'Impossible de parser le modèle enfant sur page=' . $title->getPrefixedText() );
			return;
		}

		if ( $childTemplate['name'] !== $config['argumentTemplate'] ) {
			self::logProblem(
				'Le modèle principal enfant est inattendu sur page='
				. $title->getPrefixedText()
				. '; attendu=' . $config['argumentTemplate']
				. '; trouvé=' . $childTemplate['name']
			);
			return;
		}

		$initialisation = self::getParameterValue( $childTemplate['parts'], $config['initializationParam'] );
		if ( $initialisation === null || trim( $initialisation ) === '' ) {
			self::logProblem(
				'Paramètre ' . $config['initializationParam'] . ' absent sur page=' . $title->getPrefixedText()
			);
			return;
		}

		$parsedInit = self::parseInitialisation( $initialisation, $config );
		if ( $parsedInit === null ) {
			self::logProblem(
				'Initialisation invalide sur page=' . $title->getPrefixedText() . '; valeur=' . $initialisation
			);
			return;
		}

		$type = $parsedInit['type'];
		$parentId = $parsedInit['parentId'];
		$map = $config['types'][$type];

		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();
		$permissionManager = $services->getPermissionManager();

		$parentPage = $wikiPageFactory->newFromID( $parentId );
		if ( !$parentPage ) {
			self::logProblem(
				'Parent introuvable pour page=' . $title->getPrefixedText() . '; page_id=' . $parentId
			);
			return;
		}

		$parentTitle = $parentPage->getTitle();
		if ( !$parentTitle || !$parentTitle->exists() ) {
			self::logProblem(
				'Parent inexistant pour page=' . $title->getPrefixedText() . '; page_id=' . $parentId
			);
			return;
		}

		if ( $parentTitle->equals( $title ) ) {
			self::logProblem( 'Le parent est la page elle-même pour page=' . $title->getPrefixedText() );
			return;
		}

		if ( !$permissionManager->userCan( 'edit', $user, $parentTitle ) ) {
			self::logProblem(
				'Droits insuffisants pour modifier le parent=' . $parentTitle->getPrefixedText()
				. '; page enfant=' . $title->getPrefixedText()
			);
			return;
		}

		// A bounded retry prevents concurrent additions from silently replacing each other.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				// Do not reuse a WikiPage instance that may cache a stale revision.
				$parentPage = $wikiPageFactory->newFromID( $parentId );
				if ( !$parentPage || !$parentPage->getTitle()->equals( $parentTitle ) ) {
					self::logProblem( 'Parent disparu ou renommé pendant le conflit : ' . $parentId );
					return;
				}
			}
			// Use the revision remembered by PageUpdater as the transformation base.
			// The compare-and-swap check prevents overwriting concurrent parent edits.
			$pageUpdater = $parentPage->newPageUpdater( $user );
			$parentRevision = $pageUpdater->grabParentRevision();
			$parentContent = $parentRevision ? $parentRevision->getContent( SlotRecord::MAIN ) : null;
			if ( !$parentContent ) {
				self::logProblem( 'Contenu parent absent pour parent=' . $parentTitle->getPrefixedText() );
				return;
			}

			$parentText = $parentContent instanceof TextContent ? $parentContent->getText() : null;
			if ( $parentText === null || $parentText === '' ) {
				self::logProblem( 'Texte parent vide pour parent=' . $parentTitle->getPrefixedText() );
				return;
			}

			$expectedParentTemplate = $map['parentTemplate'];

			if ( !preg_match( '/^\{\{' . preg_quote( $expectedParentTemplate, '/' ) . '\b/u', $parentText ) ) {
				self::logProblem(
					'La page parent ne commence pas par le modèle attendu; parent='
					. $parentTitle->getPrefixedText()
					. '; attendu=' . $expectedParentTemplate
				);
				return;
			}

			$parentTemplate = self::parseTopLevelTemplate( $parentText );
			if ( $parentTemplate === null ) {
				self::logProblem( 'Impossible de parser le modèle parent=' . $parentTitle->getPrefixedText() );
				return;
			}

			if ( $parentTemplate['name'] !== $expectedParentTemplate ) {
				self::logProblem(
					'Le modèle principal parent est inattendu; parent='
					. $parentTitle->getPrefixedText()
					. '; attendu=' . $expectedParentTemplate
					. '; trouvé=' . $parentTemplate['name']
				);
				return;
			}

			$parameterName = $map['parameter'];
			$templateName = $map['template'];
			$order = $map['order'] === 'debate' ? $config['orderDebate'] : $config['orderArgument'];
			$childPageName = $title->getText();

			$currentValue = self::getParameterValue( $parentTemplate['parts'], $parameterName );
			if ( $currentValue !== null && self::parameterContainsChild( $currentValue, $templateName, $childPageName, $config ) ) {
				return;
			}

			$childBlock = self::buildChildBlock( $templateName, $childPageName, $config );

			$updatedParts = self::upsertParameterInParts(
				$parentTemplate['parts'],
				$parameterName,
				$childBlock,
				$order
			);

			if ( $updatedParts === null ) {
				self::logProblem(
					'Échec d’insertion dans le paramètre=' . $parameterName
					. '; parent=' . $parentTitle->getPrefixedText()
					. '; enfant=' . $childPageName
				);
				return;
			}

			$newTemplateText = self::joinTemplateParts( $updatedParts );
			$newParentText =
				substr( $parentText, 0, $parentTemplate['start'] ) .
				$newTemplateText .
				substr( $parentText, $parentTemplate['end'] );

			if ( $newParentText === $parentText ) {
				self::logProblem(
					'Aucun changement effectif après modification; parent=' . $parentTitle->getPrefixedText()
					. '; enfant=' . $childPageName
				);
				return;
			}

			$newContent = ContentHandler::makeContent( $newParentText, $parentTitle );

			$pageUpdater->setContent( SlotRecord::MAIN, $newContent );
			$pageUpdater->addTag( 'argument added' );
			$pageUpdater->saveRevision(
				CommentStoreComment::newUnsavedComment(
					self::buildEditSummary( $type, $childPageName, $config )
				)
			);
			if ( $pageUpdater->wasSuccessful() && $pageUpdater->wasRevisionCreated() ) {
				return;
			}
			$status = $pageUpdater->getStatus();
			if ( $status->failedBecauseOfConflict() && $attempt === 0 ) {
				// A different argument may have modified the same parent. Re-read,
				// check whether this child is already present and retry only once.
				self::logProblem( 'Conflit sur le parent, nouvelle tentative : ' . $parentTitle->getPrefixedText() );
				continue;
			}
			self::logProblem(
				'Échec de liaison parent=' . $parentTitle->getPrefixedText()
				. '; enfant=' . $childPageName . '; statut=' . $status->getWikiText()
			);
			return;
		}
	}

	private static function getWikiConfig(): array {
		$lang = MediaWikiServices::getInstance()->getContentLanguage()->getCode();

		if ( isset( self::WIKI_CONFIG[$lang] ) ) {
			return self::WIKI_CONFIG[$lang];
		}

		return self::WIKI_CONFIG['fr'];
	}

	private static function buildEditSummary( string $type, string $title, array $config ): string {
		$key = match ( $type ) {
			'Argument pour', 'Pro argument' => 'pro',
			'Argument contre', 'Con argument' => 'con',
			'Justification' => 'justification',
			default => 'objection'
		};
		$language = $config['debateTemplate'] === 'Débat' ? 'fr' : 'en';
		return wfMessage( 'wikidebates-autoparent-' . $key, $title )->inLanguage( $language )->plain();
	}

	private static function parseInitialisation( string $value, array $config ): ?array {
		$value = trim( $value );
		$parts = explode( '@', $value, 2 );

		if ( count( $parts ) !== 2 ) {
			return null;
		}

		$type = trim( $parts[0] );
		$parentId = (int)trim( $parts[1] );

		if ( $type === '' || $parentId <= 0 ) {
			return null;
		}

		if ( !isset( $config['types'][$type] ) ) {
			return null;
		}

		return [
			'type' => $type,
			'parentId' => $parentId,
		];
	}

	private static function buildChildBlock( string $templateName, string $pageName, array $config ): string {
		return '{{' . $templateName . "\n"
			. '|' . $config['childPageParam'] . '=' . $pageName . "\n"
			. '|' . $config['childDisplayTitleParam'] . '=' . $pageName . "\n"
			. '}}';
	}

	private static function parameterContainsChild(
		string $value,
		string $templateName,
		string $pageName,
		array $config
	): bool {
		$wanted = Title::newFromText( $pageName, NS_MAIN );
		$wantedTemplate = Title::newFromText( $templateName, NS_TEMPLATE );
		if ( !$wanted || !$wantedTemplate ) {
			return false;
		}
		foreach ( TemplateSyntax::templates( $value ) as $template ) {
			$name = Title::newFromText( $template['name'], NS_TEMPLATE );
			if ( !$name || !$name->equals( $wantedTemplate ) ) {
				continue;
			}
			$target = self::getParameterValue( $template['parts'], $config['childPageParam'] );
			// Only the legacy form without an explicit page may use its label.
			if ( $target === null ) {
				$target = self::getParameterValue( $template['parts'], $config['childDisplayTitleParam'] );
			}
			$title = $target === null ? null : Title::newFromText( $target, NS_MAIN );
			if ( $title && $title->equals( $wanted ) ) {
				return true;
			}
		}
		return false;
	}

	private static function upsertParameterInParts(
		array $parts,
		string $parameterName,
		string $childBlock,
		array $order
	): ?array {
		if ( count( $parts ) === 0 ) {
			return null;
		}

		$targetIndex = null;

		foreach ( $parts as $index => $part ) {
			if ( $index === 0 ) {
				continue;
			}

			$eqPos = self::findTopLevelEquals( $part );
			if ( $eqPos === null ) {
				continue;
			}

			$name = trim( substr( $part, 0, $eqPos ) );
			if ( $name === $parameterName ) {
				// MediaWiki uses the last occurrence of a named parameter.
				$targetIndex = $index;
			}
		}

		if ( $targetIndex !== null ) {
			$eqPos = self::findTopLevelEquals( $parts[$targetIndex] );
			if ( $eqPos === null ) {
				return null;
			}

			$part = $parts[$targetIndex];
			$currentRawValue = substr( $part, $eqPos + 1 );
			$insertAt = strlen( rtrim( $part ) );
			$separator = trim( $currentRawValue ) === '' ? '' : "\n";
			$parts[$targetIndex] = substr( $part, 0, $insertAt ) . $separator . $childBlock . substr( $part, $insertAt );

			return $parts;
		}

		$newPart = $parameterName . '=' . $childBlock . "\n";
		$insertAt = self::findInsertionIndex( $parts, $order, $parameterName );
		array_splice( $parts, $insertAt, 0, [ $newPart ] );

		return $parts;
	}

	private static function findInsertionIndex( array $parts, array $order, string $parameterName ): int {
		$targetPos = array_search( $parameterName, $order, true );
		if ( $targetPos === false ) {
			return count( $parts );
		}

		for ( $i = $targetPos + 1; $i < count( $order ); $i++ ) {
			$nextName = $order[$i];

			foreach ( $parts as $partIndex => $part ) {
				if ( $partIndex === 0 ) {
					continue;
				}

				$eqPos = self::findTopLevelEquals( $part );
				if ( $eqPos === null ) {
					continue;
				}

				if ( trim( substr( $part, 0, $eqPos ) ) === $nextName ) {
					return $partIndex;
				}
			}
		}

		return count( $parts );
	}

	private static function getParameterValue( array $parts, string $parameterName ): ?string {
		foreach ( array_reverse( $parts, true ) as $index => $part ) {
			if ( $index === 0 ) {
				continue;
			}

			$eqPos = self::findTopLevelEquals( $part );
			if ( $eqPos === null ) {
				continue;
			}

			if ( trim( substr( $part, 0, $eqPos ) ) === $parameterName ) {
				return trim( substr( $part, $eqPos + 1 ) );
			}
		}

		return null;
	}

	private static function parseTopLevelTemplate( string $text ): ?array {
		return TemplateSyntax::parse( $text );
	}

	private static function joinTemplateParts( array $parts ): string {
		// Keep unrelated whitespace and comments byte-for-byte.
		return $parts ? implode( '|', $parts ) . '}}' : '';
	}

	private static function findTopLevelEquals( string $text ): ?int {
		return TemplateSyntax::equalsOffset( $text );
	}

	private static function logProblem( string $message ): void {
		wfDebugLog( self::LOG_CHANNEL, '[AutoParentLink][PROBLEM] ' . $message );
	}
}
