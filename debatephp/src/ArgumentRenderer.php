<?php
namespace MediaWiki\Extension\DebatePHP;

/** Argument renderer. This PHP source is authoritative and maintained directly.
 * See docs/native-php-migration-baseline.md for the behavioural contract.
 */
class ArgumentRenderer {
	use WarningLabels;

	private ?DecodedReferences $decodedReferences = null;
	private ?RenderData $renderData = null;
	private ?FifoCache $cardTitleCache = null;
	private ?FifoCache $nativeSemanticCache = null;
	protected ArgumentContext $context;
	protected $ITEM_SEP;
	protected $FIELD_SEP;
	protected $QUOTE_ITEM_SEP;
	protected $QUOTE_FIELD_SEP;
	protected array $JUSTIFICATION_WARNING_BANNER_KEYS;
	protected array $OBJECTION_WARNING_BANNER_KEYS;
	protected array $QUOTE_WARNING_BANNER_KEYS;
	protected array $REFERENCE_WARNING_BANNER_KEYS;
	protected array $SUMMARY_WARNING_BANNER_KEYS;
	protected ?array $SUMMARY_WARNING_ALIASES;
	protected array $DISPLAYED_TITLE_WARNING_BANNER_KEYS;
	protected $SMW_TITLES_CACHE;
	protected $URLENC_CACHE;
	protected $CARD_CACHE;
	protected array $TITLE_WARNING_BANNER_KEYS;
	protected array $ARGUMENT_WARNING_BANNER_KEYS;
	public function __construct(ArgumentContext $context) {
		$this->context = $context;
		$this->ITEM_SEP = '⟭';
		$this->FIELD_SEP = '⟬';
		$this->QUOTE_ITEM_SEP = '⟭⟭⟭';
		$this->QUOTE_FIELD_SEP = '⟬⟬⟬';
		$this->JUSTIFICATION_WARNING_BANNER_KEYS = ['justifications_to_reorganize'];
		$this->OBJECTION_WARNING_BANNER_KEYS = ['objections_to_reorganize'];
		$this->QUOTE_WARNING_BANNER_KEYS = ['quote_too_short', 'quote_too_long', 'quote_ref_incomplete', 'quote_translated'];
		$this->REFERENCE_WARNING_BANNER_KEYS = ['reference_quality_insufficient', 'reference_incomplete', 'dead_link'];
		$this->SUMMARY_WARNING_BANNER_KEYS = ['summary_disadvantageous', 'summary_to_be_written', 'summary_unclear', 'summary_too_long', 'summary_to_separate', 'summary_style_to_review'];
		$this->SUMMARY_WARNING_ALIASES = null;
		$this->DISPLAYED_TITLE_WARNING_BANNER_KEYS = ['displayed_title_too_long', 'displayed_title_unclear', 'displayed_title_disadvantageous'];
		$this->URLENC_CACHE = $this->cacheMake(2000);
		$this->TITLE_WARNING_BANNER_KEYS = ['title_incomplete', 'title_disadvantageous', 'title_unclear', 'title_too_long'];
		$this->ARGUMENT_WARNING_BANNER_KEYS = ['argument_sensitive', 'argument_fanciful', 'argument_potentially_illegal', 'argument_generated_by_ai'];
	}

	protected function anchorId($s = null) {
		return NativeText::anchorId($s);
	}

	protected function cacheMake($max = null) {
		return new FifoCache($max);
	}

	protected function cacheGet($C = null, $k = null) {
		return $C->get($k);
	}

	protected function cachePut($C = null, $k = null, $v = null) {
		$C->put($k, $v);
	}

	protected function normalizeTooltipText($s = null) {
		return NativeText::tooltip($s);
	}


	protected function escapeAttr($s = null) {
		return NativeText::attribute($s);
	}

	protected function nativeReferenceValues(string $format, ?string $raw): array {
		return ($this->decodedReferences ??= new DecodedReferences)->values($format, $raw);
	}

	/** Native one-based JSON lists; JsonData preserves the historical serialization contract. */
	protected function wkBuildNodesFromNativeItems(array $items, callable $builder): array {
		$out = [];
		foreach ($items as $item) {
			$node = $builder($item);
			if ($node === null) {
				continue;
			}
			$node = SchemaData::reference($node, $this->context);
			if (trim((string)($node['name'] ?? '')) !== '') {
				$out[count($out) + 1] = $node;
			}
		}
		return $out;
	}

	protected function csvValues(?string $text, ?string $separator = null): array {
		return ($this->renderData ??= new RenderData)->csvValues($text, $separator);
	}




	protected function wkSplit2($s = null, $sep = null) {
		return NativeText::splitOnce($s, $sep);
	}

	protected function wkExtractItemsFromSerializedData($raw = null) {
		return SerializedData::argumentItems($raw, true);
	}

	protected function wkEncodePathSegment($s = null) {
		return NativeText::pathSegment($s);
	}

	protected function wkAddDataPath($formName = null, $pageTitle = null) {
		$form = $this->wkEncodePathSegment($this->wkDbKey($formName));
		$page = $this->wkEncodePathSegment($this->wkDbKey($pageTitle));
		return ('Special:AddData/' . $form . '/' . $page);
	}

	protected function addDataLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null, bool $editButton = false) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = $linktext;
		if ((($lt === null) || ($lt === ''))) {
			$lt = '&nbsp;';
		}
		$tt = NativeText::field($tooltip);
		$classes = 'wk-adddata-link navigation-not-searchable noprint';
		if ($editButton) {
			$classes = ($classes . ' bouton-modifier');
		}
		if (($tt !== '')) {
			return sprintf('<span class="%s" data-wk-tooltip="%s">[[%s|%s]]</span>', $classes, $this->escapeAttr($tt), $target, $lt);
		}
		return sprintf('<span class="%s">[[%s|%s]]</span>', $classes, $target, $lt);
	}

	protected function addDataEditLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null) {
		return $this->addDataLink($formName, $pageTitle, $linktext, $tooltip, true);
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodePathSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, ?array $query = null) {
		$formName = NativeText::formValue($formName);
		if ($formName === '') { return ''; }
		$tooltip = $this->normalizeTooltipText(NativeText::formValue($tooltip));
		$href = $this->context->localUrl($this->wkRunQueryPath($formName), $query ?? []) ?? 'nil';
		$attributes = ['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav'];
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', '＋ ' . NativeText::formValue($label), $attributes, true);
	}

	protected function runQueryLink(array $opts = []) {
		$formName = NativeText::field($opts['form'] ?? null);
		$fieldName = NativeText::field($opts['field'] ?? null);
		$value = NativeText::field($opts['value'] ?? null);
		if (((($formName === '') || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = NativeText::field($opts['_title'] ?? null);
		if (($title === '')) {
			$title = $this->wkRunQueryPath($formName);
		}
		$q = [];
		$qkey = NativeText::field($opts['_qkey'] ?? null);
		if (($qkey === '')) {
			$qkey = ($formName . '[' . $fieldName . ']');
		}
		$q[$qkey] = $value;
		$extra = $opts['extra'] ?? [];
		if (is_array($extra)) {
			foreach ($extra as $k => $v) {
				$kk = NativeText::field($k);
				if ((($kk !== '') && ($v !== null))) {
					$vv = NativeText::formValue($v);
					if (($vv !== '')) {
						if (str_contains($kk, '%[')) {
							$q[$kk] = $vv;
						}
						else {
							$q[($formName . '[' . $kk . ']')] = $vv;
						}
					}
				}
			}
		}
		$q['_run'] = '1';
		$url = $this->absUrlFromTitle($title, $q);
		$label = $opts['label'] ?? $value;
		$label = NativeText::formValue($label === false ? $value : $label);
		$tt = $this->normalizeTooltipText(NativeText::field($opts['tooltip'] ?? null));
		$link = ('[' . $url . ' ' . $label . ']');
		if (($tt !== '')) {
			return sprintf('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return sprintf('<span class="wk-adddata-link">%s</span>', $link);
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function pfProp(?array $PF = null, $k = null, $fallback = null) {
		$value = trim(NativeText::field($PF[$k] ?? null), NativeText::ASCII_WHITESPACE);
		return $value !== '' ? $value : ($fallback === false ? '' : ($fallback ?? ''));
	}

	protected function smwPropRef(?array $PF = null, $key = null) {
		if (($key === 'authors')) {
			return $this->pfProp($PF, 'prop_author');
		}
		if (($key === 'article_name')) {
			return $this->pfProp($PF, 'prop_article_name');
		}
		if (($key === 'work_name')) {
			return $this->pfProp($PF, 'prop_work_name');
		}
		if (($key === 'publishing_house')) {
			return $this->pfProp($PF, 'prop_publishing_house');
		}
		if (($key === 'place_of_publication')) {
			return $this->pfProp($PF, 'prop_place_of_publication');
		}
		return '';
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return $this->L($lang, 'subsection_banners', $key, ...$rest);
	}

	protected function buildSummaryAliases($lang = null): array {
		$a = [];
		foreach ($this->SUMMARY_WARNING_BANNER_KEYS as $key) {
			$alias = $this->subMsg($lang, ($key . '_alias'));
			$alias = trim(NativeText::field($alias), NativeText::ASCII_WHITESPACE);
			if (($alias !== '')) {
				foreach ($this->csvValues($alias, ',') as $_ => $one) {
					$one = trim(NativeText::field($one), NativeText::ASCII_WHITESPACE);
					if (($one !== '')) {
						$a[$one] = $key;
					}
				}
			}
		}
		return $a;
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, ?array $opts = null, ?array &$cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$isReorg = (($opts['reorg'] ?? false) === true);
		$linkHtml = '';
		$title = '';
		$text = '';
		if ($isReorg) {
			$formName = NativeText::field($opts['form'] ?? null);
			$tooltip = NativeText::field($opts['tooltip'] ?? null);
			$linkLabel = NativeText::field($opts['link_label'] ?? null);
			if (($linkLabel === '')) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (($formName !== '')) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if (($isReorg && ($linkHtml !== ''))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = trim(NativeText::field($title), NativeText::ASCII_WHITESPACE);
			if (($title !== '')) {
				$title = ($title . '&nbsp;' . $linkHtml);
			}
			else {
				$title = $linkHtml;
			}
		}
		else {
			$title = $this->subMsg($lang, ($key . '_title'), $pv['rawTitle']);
		}
		$text = $this->subMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if ((($title === '') && ($text === ''))) {
			return '';
		}
		$html = ('<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">' . $title . '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">' . '<div class="smwttcontent">' . $text . '</div>' . '</span>' . '</div>');
		$cat = $this->subMsg($lang, ($key . '_category'));
		if ($cats !== null && $cat !== '') {
			$cats[] = ('[[Category:' . $cat . ']]');
		}
		return $html;
	}




	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, ?array &$cats = null, ?array &$out = null) {
		$warnsRef = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsRef === '')) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach ($this->csvValues($warnsRef, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
				if ($fragment !== null) { $out[] = $fragment; }
			}
		}
	}

	protected function wkRenderOneBiblioLi(?array $item = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		$out = [];
		$authors = $item['authors'];
		if (($authors === '')) {
			$authors = $PF['unknown_author'];
		}
		$out[] = $authors;
		$article = $item['article'];
		$work = $item['work'];
		$link = $item['link'];
		if (($article !== '')) {
			if (($link !== '')) {
				$out[] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$out[] = (', « ' . $article . ' »');
			}
		}
		if (($work !== '')) {
			if (($article !== '')) {
				$out[] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (($link !== '')) {
					$out[] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$out[] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		$volume = $item['volume'];
		if (($volume !== '')) {
			$out[] = (', ' . $PF['volume_label'] . '&#160;' . $volume);
		}
		$number = $item['number'];
		if (($number !== '')) {
			$out[] = (', ' . $PF['issue_label'] . '&#160;' . $number);
		}
		$location = $item['location'];
		if (($location !== '')) {
			$out[] = (', ' . $location);
		}
		$page = $item['page'];
		if (($page !== '')) {
			$out[] = (', p.' . $page);
		}
		$publisher = $item['publisher'];
		if (($publisher !== '')) {
			$out[] = (', ' . $publisher);
		}
		$place = $item['place'];
		if (($place !== '')) {
			$out[] = (', ' . $place);
		}
		$date = $item['date'];
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderOneWeblioLi(?array $item = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		$out = [];
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$date = $item['date'];
		$authors = $item['authors'];
		if (($authors !== '')) {
			$out[] = $authors;
		}
		{
			$label = '';
			if (($page !== '')) {
				$label = $page;
			}
			else {
				if (($site !== '')) {
					$label = $site;
				}
			}
			if ((($link !== '') && ($label !== ''))) {
				$out[] = ((($authors !== '') ? ', ' : '') . '[' . $link . ' ' . $label . ']');
			}
			else {
				$out[] = (($authors !== '' ? ', ' : '') . ($page !== '' ? $page : $site));
			}
		}
		if ((($site !== '') && ($page !== ''))) {
			$out[] = (', \'\'' . $site . '\'\'');
		}
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderOneVideoLi(?array $item = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		$out = [];
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		if (($authors !== '')) {
			$out[] = $authors;
		}
		if ((($link !== '') && ($title !== ''))) {
			$out[] = ((($authors !== '') ? ', ' : '') . '[' . $link . ' ' . $title . ']');
		}
		else {
			if (($title !== '')) {
				$out[] = ((($authors !== '') ? ', ' : '') . $title);
			}
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderReferencesFromRaw($rawB = null, $rawW = null, $rawV = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		[$b, $w, $v] = [[], [], []];
		foreach ($this->nativeReferenceValues('bibliography', $rawB) as $_ => $it) {
			$b[] = $this->wkRenderOneBiblioLi($it, $pv, $lang, $cats, $PF);
		}
		foreach ($this->nativeReferenceValues('webliography', $rawW) as $_ => $it) {
			$w[] = $this->wkRenderOneWeblioLi($it, $pv, $lang, $cats, $PF);
		}
		foreach ($this->nativeReferenceValues('videography', $rawV) as $_ => $it) {
			$v[] = $this->wkRenderOneVideoLi($it, $pv, $lang, $cats, $PF);
		}
		if ((((count($b) === 0) && (count($w) === 0)) && (count($v) === 0))) {
			return '';
		}
		$out = implode("\n", [implode("\n", $b), implode("\n", $w), implode("\n", $v)]);
		$out = trim($out, NativeText::ASCII_WHITESPACE);
		return $out;
	}


	protected function smwKeyFromArray(array $q): string {
		return CacheKey::semantic($q);
	}

	protected function makeSMWCache(): ParentRelations {
		return new ParentRelations;
	}

	protected function renderTitleWarnings($pv = null, ?array &$cats = null, $lang = null) {
		$title = NativeText::field($pv['rawTitle']);
		if (($title === '')) {
			return '';
		}
		$out = [];
		$n = 0;
		foreach (preg_split('/[\x09-\x0d ]+/', $title) as $w) {
			$w = trim($w, NativeText::ASCII_WHITESPACE);
			if (($w !== '')) {
				$n = ($n + 1);
				if (($n >= 3)) {
					break;
				}
			}
		}
		if ((($n > 0) && ($n < 3))) {
			$out[count($out) + 1] = ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_too_short_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . ' ' . $this->L($lang, 'text', 'if_needed_msg') . '\'\'\'</div>');
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'title_very_short') . ']]');
		}
		if (((substr($title, -1) === '.') && (substr($title, -4) !== 'etc.'))) {
			$out[count($out) + 1] = ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_ends_with_dot_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . '\'\'\'</div>');
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'title_ends_with_dot') . ']]');
		}
		if (($lang === 'fr')) {
			$prefix3 = substr($title, 0, 3);
			$prefix4 = substr($title, 0, 4);
			$prefix5 = substr($title, 0, 5);
			$prefix6 = substr($title, 0, 6);
			$prefix10 = substr($title, 0, 10);
			$prefix13 = substr($title, 0, 13);
			if (($prefix3 === 'Ça')) {
				$cats[] = '[[Category:Titres d\'arguments commençant par "Cela"]]';
			}
			else {
				if (($prefix3 === 'Sa')) {
					$cats[] = '[[Category:Titres d\'arguments commençant par "Sa"]]';
				}
			}
			$map4 = ['Ces' => 'Titres d\'arguments commençant par "Ces"', 'Cet' => 'Titres d\'arguments commençant par "Cet"', 'Ils' => 'Titres d\'arguments commençant par "Ils"', 'Ses' => 'Titres d\'arguments commençant par "Ses"', 'Son' => 'Titres d\'arguments commençant par "Son"'];
			if (isset($map4[$prefix4])) {
				$cats[] = ('[[Category:' . $map4[$prefix4] . ']]');
			}
			$map5 = ['Cela' => 'Titres d\'arguments commençant par "Cela"', 'Elle' => 'Titres d\'arguments commençant par "Elle"', 'Leur' => 'Titres d\'arguments commençant par "Leur"', 'Leurs' => 'Titres d\'arguments commençant par "Leurs"', 'Non,' => 'Titres d\'arguments commençant par "Non"', 'Nous' => 'Titres d\'arguments commençant par "Nous"', 'Oui,' => 'Titres d\'arguments commençant par "Oui"'];
			if (isset($map5[$prefix5])) {
				$cats[] = ('[[Category:' . $map5[$prefix5] . ']]');
			}
			$map6 = ['Cette' => 'Titres d\'arguments commençant par "Cette"', 'Elles' => 'Titres d\'arguments commençant par "Elles"'];
			if (isset($map6[$prefix6])) {
				$cats[] = ('[[Category:' . $map6[$prefix6] . ']]');
			}
			$map10 = ['C\'est faux' => 'Titres d\'arguments commençant par "C\'est faux"', 'Hors-sujet' => 'Titres d\'arguments commençant par "Hors-sujet"'];
			if (isset($map10[$prefix10])) {
				$cats[] = ('[[Category:' . $map10[$prefix10] . ']]');
			}
			if (($prefix13 === 'Au contraire,')) {
				$cats[] = '[[Category:Titres d\'arguments commençant par "Au contraire"]]';
			}
		}
		return implode('', $out);
	}

	/** Retain the one-based envelope only at the remaining SMW/legacy boundary. */
	/** Trim and deduplicate strings without changing their first-seen order. */
	private function uniqueStrings(iterable $values): array {
		$out = [];
		$seen = [];
		foreach ($values as $value) {
			$value = trim(NativeText::field($value), NativeText::ASCII_WHITESPACE);
			if ($value !== '' && !isset($seen[$value])) {
				$seen[$value] = true;
				$out[] = $value;
			}
		}
		return $out;
	}

	/** Deduplicate page titles, not their raw SMW representations. Preserve first-seen order. */
	protected function uniqueParentTitles(array $titles): array {
		$out = [];
		$seen = [];
		foreach ($titles as $title) {
			$title = $this->canonicalTitle($this->normalizeTitleForCard($title));
			$key = $this->normTitle($title);
			if ($key !== '' && !isset($seen[$key])) {
				$seen[$key] = true;
				$out[] = $title;
			}
		}
		return $out;
	}

	/** Extract the first non-nil/non-false title field, including an explicit empty string. */
	private function semanticRowTitle(mixed $row): mixed {
		foreach (['fulltext', 'page', 1] as $key) {
			$value = $row[$key] ?? null;
			if ($value !== null && $value !== false) {
				return $value;
			}
		}
		return null;
	}

	protected function smwAskTitles(string $condition, string $origin): array {
		$query = [1 => $condition, 2 => 'limit=200', 3 => 'link=none'];
		$key = "TITLES\n" . $this->smwKeyFromArray($query);
		$hit = $this->cacheGet($this->SMW_TITLES_CACHE, $key);
		if ($hit !== null) {
			$this->smwCacheHit($origin, 'titles');
			return $hit;
		}
		$rows = $this->readSemantic($query, $origin)->rows;
		$out = [];
		$seen = [];
		foreach (SemanticReadResult::sequence($rows ?? []) as $row) {
			$title = $this->semanticRowTitle($row);
			if (is_string($title) && $title !== '' && !isset($seen[$title])) {
				$seen[$title] = true;
				$out[] = $title;
			}
		}
		$this->cachePut($this->SMW_TITLES_CACHE, $key, $out);
		return $out;
	}

	protected function normTitle($s = null) {
		$s = NativeText::field($s);
		$s = str_replace('_', ' ', $s);
		return trim($s, NativeText::ASCII_WHITESPACE);
	}

	protected function smwSafeTitle($s = null) {
		$s = $this->canonicalTitle($s);
		if ($s === '') {
			return '';
		}
		// Retain the existing fragment and forbidden-character policy exactly.
		$s = trim(preg_replace('/#[^\n]*$/D', '', $s), NativeText::ASCII_WHITESPACE);
		return strpbrk($s, '[]{}|<>') === false ? $s : '';
	}

	protected function containsTitle($val = null, $target = null): bool {
		$target = $this->normTitle($target);
		if ($target === '' || $val === null) {
			return false;
		}
		$checkOne = function($value) use ($target, &$checkOne): bool {
			if (is_string($value)) {
				return $this->normTitle($value) === $target;
			}
			if (!is_array($value)) {
				return false;
			}
			foreach (['fulltext', 'page', 'name', 'title'] as $key) {
				$title = $value[$key] ?? null;
				if ($title !== null && $title !== false) {
					if (is_string($title) && $this->normTitle($title) === $target) {
						return true;
					}
					break;
				}
			}
			foreach (SemanticReadResult::sequence($value) as $child) {
				if ($checkOne($child)) {
					return true;
				}
			}
			return false;
		};
		return $checkOne($val);
	}

	protected function ensureDebateParents($pv = null, ?ParentRelations $cache = null, ?array $PF = null): void {
		if ($cache === null || $cache->proDebates !== null) {
			return;
		}
		[$cache->proDebates, $cache->conDebates] = $this->findParents(
			$this->smwSafeTitle($pv['rawTitle']),
			NativeText::field($PF['prop_arg_pro']), NativeText::field($PF['prop_arg_con']),
			'pro', 'con', 'debate-parents'
		);
	}

	protected function ensureArgumentParents($pv = null, ?ParentRelations $cache = null, ?array $PF = null): void {
		if ($cache === null || $cache->justificationParents !== null) {
			return;
		}
		[$cache->justificationParents, $cache->objectionParents] = $this->findParents(
			$this->smwSafeTitle($pv['rawTitle']),
			NativeText::field($PF['prop_justif']), NativeText::field($PF['prop_obj']),
			'justif', 'obj', 'argument-parents'
		);
	}

	/** Both relation pairs use the same ordered union and certified-empty fallback policy. */
	private function findParents(
		string $title, string $firstProperty, string $secondProperty,
		string $firstAlias, string $secondAlias, string $origin
	): array {
		if ($title === '' || $firstProperty === '' || $secondProperty === '') {
			return [[], []];
		}
		$firstCondition = '[[' . $firstProperty . '::' . $title . ']]';
		$secondCondition = '[[' . $secondProperty . '::' . $title . ']]';
		$query = [1 => '(' . $firstCondition . ' OR ' . $secondCondition . ' )',
			2 => '?' . $firstProperty . '=' . $firstAlias,
			3 => '?' . $secondProperty . '=' . $secondAlias, 4 => 'limit=200', 5 => 'link=none'];
		$result = $this->readSemantic($query, $origin);
		$first = [];
		$second = [];
		$seenFirst = [];
		$seenSecond = [];
		foreach (SemanticReadResult::sequence($result->rows ?? []) as $row) {
			$page = $this->semanticRowTitle($row);
			if (!is_string($page) || $page === '') {
				continue;
			}
			if ($this->containsTitle($row[$firstAlias] ?? null, $title) && !isset($seenFirst[$page])) {
				$seenFirst[$page] = true;
				$first[] = $page;
			}
			if ($this->containsTitle($row[$secondAlias] ?? null, $title) && !isset($seenSecond[$page])) {
				$seenSecond[$page] = true;
				$second[] = $page;
			}
		}
		if ($first === [] && $second === [] && !$result->completeEmpty) {
			$first = $this->uniqueStrings($this->smwAskTitles($firstCondition, $origin));
			$second = $this->uniqueStrings($this->smwAskTitles($secondCondition, $origin));
		} elseif ($result->completeEmpty) {
			$this->context->skipEmptyParentFallback($origin);
		}
		return [$this->uniqueParentTitles($first), $this->uniqueParentTitles($second)];
	}

	protected function breadcrumbLenByteSafe($ch = null) {
		if (!is_string($ch) || $ch === '') { return 0; }
		$count = 0;
		$offset = 0;
		do {
			$end = strpos($ch, '⟭', $offset);
			$part = $end === false ? substr($ch, $offset) : substr($ch, $offset, $end - $offset);
			if (trim($part, " \t\r\n\v\f") !== '' && ++$count >= 6) { return $count; }
			$offset = $end === false ? 0 : $end + 3;
		} while ($end !== false);
		return $count;
	}

	protected function normalizeTitleForCard($s = null) {
		$s = trim(NativeText::field($s), NativeText::ASCII_WHITESPACE);
		if ($s === '' || ($s[0] !== ':' && strpbrk($s, '[<&') === false)) { return $s; }
		$key = $s;
		$cache = $this->cardTitleCache ??= new FifoCache(200);
		$hit = $cache->get($key);
		if ($hit !== null) { return $hit; }
		$s = preg_replace('/^:[\x09-\x0d ]*/', '', $s);
		if (preg_match('/^\[\[([^\]|]+)\|[^\n]*?\]\]$/D', $s, $match)
			|| preg_match('/^\[\[([^\]]+)\]\]$/D', $s, $match)) { $s = $match[1]; }
		$s = preg_replace('/^:[\x09-\x0d ]*/', '', trim($s, " \t\r\n\v\f"));
		// str_replace applies pairs sequentially, preserving nested entity behavior.
		if (str_contains($s, '&')) {
			$s = str_replace(['&nbsp;', '&#160;', '&amp;', '&quot;', '&#39;', '&lt;', '&gt;'], [' ', ' ', '&', '"', "'", '<', '>'], $s);
		}
		$s = trim($s, " \t\r\n\v\f");
		if (strlen($key) <= 1024) { $cache->put($key, $s); }
		return $s;
	}

	protected function mwUrlEncodePF($s = null) {
		$s = NativeText::field($s);
		$hit = $this->cacheGet($this->URLENC_CACHE, $s);
		if (($hit !== null)) {
			return $hit;
		}
		$out = $this->context->uriEncode($s, 'QUERY');
		$out = str_replace('%20', '+', $out);
		$this->cachePut($this->URLENC_CACHE, $s, $out);
		return $out;
	}

	protected function argumentMapCardLua($targetTitle = null, $currentArgTitle = null, $typeValue = null) {
		$t = $this->normalizeTitleForCard($targetTitle);
		$a = $this->normalizeTitleForCard($currentArgTitle);
		$p2 = $this->mwUrlEncodePF($a);
		$dataAttr = '';
		if ($typeValue !== null && $typeValue !== false && $typeValue !== '') {
			$dataAttr = (' data-' . $typeValue . '="' . $this->escapeAttr($p2) . '"');
		}
		return ('<span class="hover-map"' . $dataAttr . '>' . '[[' . $t . ']]' . '</span>');
	}

	protected function cardRendered($kind = null, $targetTitle = null, $currentArgTitle = null, $typeValue = null) {
		$key = ($kind . '
' . NativeText::field($targetTitle) . '
' . NativeText::field($currentArgTitle) . '
' . NativeText::field($typeValue));
		$cached = $this->cacheGet($this->CARD_CACHE, $key);
		if (($cached !== null)) {
			$this->smwCacheHit('cards', 'card');
			return $cached;
		}
		$rendered = $this->argumentMapCardLua($targetTitle, $currentArgTitle, $typeValue);
		$this->cachePut($this->CARD_CACHE, $key, $rendered);
		return $rendered;
	}

	protected function cardDebateRendered($debateTitle = null, $currentArgTitle = null, $typeValue = null) {
		return $this->cardRendered('debate', $debateTitle, $currentArgTitle, $typeValue);
	}

	protected function cardArgumentRendered($argTitle = null, $currentArgTitle = null, $typeValue = null) {
		return $this->cardRendered('argument', $argTitle, $currentArgTitle, $typeValue);
	}

	protected function ensureDebateCards($pv = null, ?ParentRelations $cache = null, $lang = null, ?array $PF = null) {
		if (($cache === null || ($cache->allDebateCards !== null))) {
			return null;
		}
		$argTitle = $pv['rawTitle'];
		$debateKey = function($d = null) {
		return $this->canonicalTitle($d);
		};
		$linked = [];
		foreach ($cache->proDebates ?? [] as $d) {
			$k = $debateKey($d);
			if (($k !== '')) {
				$linked[$k] = true;
			}
		}
		foreach ($cache->conDebates ?? [] as $d) {
			$k = $debateKey($d);
			if (($k !== '')) {
				$linked[$k] = true;
			}
		}
		$cardsTous = [];
		foreach ($cache->breadcrumbDebates ?? [] as $d) {
			$k = $debateKey($d);
			$typeDebate = ($k !== '' && isset($linked[$k]) ? 'debate' : '');
			$cardsTous[] = $this->cardDebateRendered($d, $argTitle, $typeDebate);
		}
		$cache->allDebateCards = $cardsTous;
		$cardsPour = [];
		foreach ($cache->proDebates ?? [] as $d) {
			$cardsPour[] = $this->cardDebateRendered($d, $argTitle, 'debate');
		}
		$cache->proDebateCards = $cardsPour;
		$cardsContre = [];
		foreach ($cache->conDebates ?? [] as $d) {
			$cardsContre[] = $this->cardDebateRendered($d, $argTitle, 'debate');
		}
		$cache->conDebateCards = $cardsContre;
	}

	protected function ensureArgumentCards($pv = null, ?ParentRelations $cache = null, ?array $PF = null) {
		if (($cache === null || ($cache->justificationCards !== null))) {
			return null;
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$argTitle = $pv['rawTitle'];
		$typeArgument = 'argument';
		$cj = [];
		foreach ($cache->justificationParents ?? [] as $a) {
			$cj[] = $this->cardArgumentRendered($a, $argTitle, $typeArgument);
		}
		$cache->justificationCards = $cj;
		$co = [];
		foreach ($cache->objectionParents ?? [] as $a) {
			$co[] = $this->cardArgumentRendered($a, $argTitle, $typeArgument);
		}
		$cache->objectionCards = $co;
	}

	protected function ensureDot($s = null) {
		$s = NativeText::field($s);
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		if (($s === '')) {
			return $s;
		}
		if ((substr($s, -1) === '.')) {
			return $s;
		}
		return ($s . '.');
	}

	protected function renderParentBanner($iconClass = null, $texte = null) {
		return ('<div class="bandeau-en-tete searchaux wk-icon-before ' . $iconClass . '">' . $this->ensureDot($texte) . '</div>');
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return $this->context->message('Argument.banners', $lang, $key, ...$rest);
	}

	protected function renderMetaWarningHtml(array $params = []) {
		$color = NativeText::field($params['color'] ?? null);
		$icon = NativeText::field($params['icon'] ?? null);
		$title = NativeText::field($params['title'] ?? null);
		$text = NativeText::field($params['text'] ?? null);
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre>' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, ?array &$cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$color = $this->bannerMsg($lang, ($key . '_color'));
		$icon = $this->bannerMsg($lang, ($key . '_icon'));
		$title = $this->bannerMsg($lang, ($key . '_title'), $pv['rawTitle']);
		$text = $this->bannerMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if ((((($color === '') && ($icon === '')) && ($title === '')) && ($text === ''))) {
			return '';
		}
		$html = $this->renderMetaWarningHtml(['color' => $color, 'icon' => $icon, 'title' => $title, 'text' => $text]);
		$cat = $this->bannerMsg($lang, ($key . '_category'));
		if ($cats !== null && $cat !== '') {
			$cats[] = ('[[Category:' . $cat . ']]');
		}
		return $html;
	}

	protected function renderBannersFromArgList($rawList = null, $lookup = null, $pv = null, $lang = null, ?array &$cats = null) {
		$html = [];
		foreach ($this->csvValues($rawList, ',') as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (($key !== '')) {
				$html[] = $this->renderBannerByKey($pv, $lang, $key, $cats);
			}
		}
		return implode('', $html);
	}

	/** Join dense, zero-based rendered parent cards without repeating the final link. */
	protected function joinCards(?array $cards = null, $lang = null): string {
		$cards ??= [];
		if (count($cards) < 2) {
			return $cards[0] ?? '';
		}
		$conjunction = preg_replace('/[\x09-\x0d ]+/', '&#32;', $this->L($lang, 'text', 'list_conjunction_text'));
		$last = array_pop($cards);
		return implode(', ', $cards) . $conjunction . $last;
	}

	protected function renderDebateParentsBoxes($pv = null, ?array &$cats = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null) {
		if (!($this->hasSMW())) {
			return '';
		}
		$this->ensureDebateParents($pv, $cache, $PF);
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$out = [];
		$nTous = count($cache->breadcrumbDebates ?? []);
		if (($nTous > 0)) {
			$msgKey = (($nTous === 1) ? 'parent_used_in_debate_1' : 'parent_used_in_debate_n');
			$texte = sprintf($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache->allDebateCards, $lang)));
			$out[] = $this->renderParentBanner('wk-icon-parent', $texte);
		}
		if (!empty($cache->proDebates)) {
			$msgKey = (count($cache->proDebates) === 1 ? 'parent_is_pro_in_1' : 'parent_is_pro_in_n');
			$texte = sprintf($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache->proDebateCards, $lang)));
			$out[] = $this->renderParentBanner('wk-icon-argument is-pro', $texte);
		}
		if (!empty($cache->conDebates)) {
			$msgKey = (count($cache->conDebates) === 1 ? 'parent_is_con_in_1' : 'parent_is_con_in_n');
			$texte = sprintf($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache->conDebateCards, $lang)));
			$out[] = $this->renderParentBanner('wk-icon-argument is-against', $texte);
		}
		return implode('', $out);
	}

	protected function renderArgumentParentsBoxes($pv = null, ?array &$cats = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null) {
		if (!($this->hasSMW())) {
			return '';
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$this->ensureArgumentCards($pv, $cache, $PF);
		$out = [];
		if (!empty($cache->justificationParents)) {
			$texte = sprintf($PF['parent_is_justification_of'], (': ' . $this->joinCards($cache->justificationCards, $lang)));
			$out[] = $this->renderParentBanner('wk-icon-argument is-pro', $texte);
		}
		if (!empty($cache->objectionParents)) {
			$texte = sprintf($PF['parent_is_objection_to'], (': ' . $this->joinCards($cache->objectionCards, $lang)));
			$out[] = $this->renderParentBanner('wk-icon-argument is-against', $texte);
		}
		return implode('', $out);
	}

	protected function renderDebatesParentsBottom($pv = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null) {
		if (!($this->hasSMW())) {
			return '';
		}
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$n = count($cache->breadcrumbDebates ?? []);
		if (($n === 0)) {
			return '<h2 id="Parent_debates" style="display:none;"></h2>';
		}
		$title = $PF[$n === 1 ? 'parent_debates_title_1' : 'parent_debates_title_n'];
		$lis = [];
		foreach ($cache->allDebateCards ?? [] as $card) {
			$lis[] = ('<li style="position: relative;">' . $card . '</li>');
		}
		return ('<h2 id="Parent_debates" class="wk-icon-before wk-icon-parent">' . $title . '</h2>' . '<ul class="hover-top navigation-not-searchable">' . implode('', $lis) . '</ul>');
	}

	protected function renderInitializationBlock($args = null, $pv = null, ?array &$cats = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null) {
		if (!empty($cache->breadcrumbDebates) || !empty($cache->justificationParents) || !empty($cache->objectionParents)) {
			return '';
		}
		$initRaw = ($args['initialization'] ?? null);
		$initRaw = is_string($initRaw) ? trim($initRaw, NativeText::ASCII_WHITESPACE) : '';
		$orphanBanner = $this->renderBannerByKey($pv, $lang, 'argument_orphan', $cats);
		if ($initRaw === '') {
			return $orphanBanner;
		}
		// Keep the same-day initialization grace period, using the stored revision.
		$publicationDate = $this->context->publicationDate();
		if ($publicationDate === null || substr($publicationDate, 0, 10) === $this->context->currentDate()) {
			return $orphanBanner;
		}
		$cats[] = '[[Category:' . $PF['cat_uninitialized'] . ']]';
		$parts = explode('@', $initRaw, 3);
		$initType = trim($parts[0], NativeText::ASCII_WHITESPACE);
		$parentId = trim($parts[1] ?? '', NativeText::ASCII_WHITESPACE);
		$parentPage = '';
		if (preg_match('/([0-9]+)/', $parentId, $matches) && $this->context->smwAvailable()) {
			$parentId = $matches[1];
			$PF['prop_debate_number'] = $this->L($lang, 'props', 'debate_number');
			$query = [1 => '[[' . $PF['prop_debate_number'] . '::' . $parentId . ']] OR [[' . $PF['prop_arg_number'] . '::' . $parentId . ']]',
				2 => 'limit=1', 3 => 'link=none'];
			try {
				$result = $this->context->readSemantic($query, 'initialization')->rows;
			} catch (\Throwable $error) {
				DatabaseFailure::rethrow($error);
				$result = null;
			}
			if (isset($result[1]) && $result[1] !== false) {
				$title = $this->semanticRowTitle($result[1]);
				if (is_string($title)) {
					$parentPage = trim($title, NativeText::ASCII_WHITESPACE);
				}
			}
		}
		$parentPage = $this->canonicalTitle($parentPage);
		$title = $pv['rawTitle'];
		$proLabel = $this->L($lang, 'forms', 'arg_pro');
		$conLabel = $this->L($lang, 'forms', 'arg_con');
		$justificationLabel = $this->L($lang, 'params', 'justification_type_label');
		$objectionLabel = $this->L($lang, 'params', 'objection_type_label');
		[$formKey, $summaryKey] = match ($initType) {
			$proLabel => ['edit_args_pro', 'init_summary_pro'],
			$conLabel => ['edit_args_con', 'init_summary_con'],
			$justificationLabel => ['justifications', 'init_summary_justif'],
			$objectionLabel => ['objections', 'init_summary_obj'],
			default => [null, null]
		};
		$formName = $formKey === null ? '' : $this->L($lang, 'forms', $formKey);
		if ($formName === '' || $initType === '') {
			return $orphanBanner;
		}
		$prefix = $initType . '[99]';
		$queryParts = [$prefix . '[' . $this->L($lang, 'params', 'page') . ']=' . $title];
		$displayedTitleParam = trim($this->L($lang, 'params', 'displayed_title'), NativeText::ASCII_WHITESPACE);
		if ($displayedTitleParam !== '') {
			$queryParts[] = $prefix . '[' . $displayedTitleParam . ']=' . $title;
		}
		$qs = implode('&', $queryParts);
		$warnTitle = sprintf($PF['init_warning_title'] ?? '%s', $formName);
		$warnIntro = $PF['init_warning_intro'];
		$yesText = $PF['init_yes_finish_label'] ?? $PF['edit'] ?? '';
		$yesTooltip = sprintf($PF['init_yes_tt'] ?? '%s', $parentPage);
		$okText = sprintf($PF['init_ok_text'] ?? '%s', $parentPage);
		$summary = $PF[$summaryKey];
		if ($summary !== '') {
			$summary .= ' : « ' . $title . ' »';
		}
		$params = ['form' => $formName, 'target' => $parentPage, 'query string' => $qs,
			'link text' => $yesText, 'link type' => 'button', 'tooltip' => $yesTooltip, 'ok text' => $okText];
		if ($summary !== '') {
			$params['summary'] = $summary;
		}
		$params['reload'] = true;
		try {
			$auto = $this->context->autoEdit($params);
		} catch (\Throwable $error) {
			DatabaseFailure::rethrow($error);
			$auto = '';
		}

		return ($orphanBanner . '<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $warnTitle . ' [[' . $parentPage . ']].' . '\'\'\'

' . $warnIntro . '
' . $auto . '</div><!-- Fin de warningbox -->');
	}

	protected function ensureBreadcrumbSelection($pv = null, ?ParentRelations $cache = null, ?array &$cats = null, $lang = null, ?array $PF = null): void {
		if ($cache === null || $cache->breadcrumbChains !== null) {
			return;
		}
		$cache->breadcrumbChains = [];
		$cache->breadcrumbDebates = [];
		if (!$this->hasSMW()) {
			return;
		}
		if ($lang === null || $lang === false) {
			$lang = 'fr';
		}
		$title = $this->smwSafeTitle($pv['rawTitle']);
		$propBreadcrumb = NativeText::field($PF['prop_breadcrumb']);
		$propDebateName = NativeText::field($PF['prop_debate_name']);
		$propPro = NativeText::field($PF['prop_arg_pro']);
		$propCon = NativeText::field($PF['prop_arg_con']);
		$propJustif = NativeText::field($PF['prop_justif']);
		$propObj = NativeText::field($PF['prop_obj']);
		if ($title === '' || $propBreadcrumb === '' || $propPro === '' || $propCon === '' || $propJustif === '' || $propObj === '') {
			return;
		}
		$query = [1 => '([[' . $propJustif . '::' . $title . ']] OR [[' . $propObj . '::' . $title
			. ']] OR [[' . $propPro . '::' . $title . ']] OR [[' . $propCon . '::' . $title . ']] )',
			2 => '?' . $propBreadcrumb . '=chain'];
		// Keep the historical query shape when a translation lacks the debate-name property.
		// Its absent slot used to stop the one-based sequence before limit/link options.
		if ($propDebateName !== '') {
			$query[3] = '?' . $propDebateName . '=debateName';
			$query[4] = 'limit=200';
			$query[5] = 'link=none';
		}
		$result = $this->readSemantic($query, 'breadcrumb');
		$best = [];
		foreach (SemanticReadResult::sequence($result->rows ?? []) as $row) {
			$chain = $row['chain'] ?? null;
			foreach (is_array($chain) ? SemanticReadResult::sequence($chain) : [$chain] as $value) {
				if (!is_string($value) || $value === '') {
					continue;
				}
				$parts = [];
				foreach (explode('⟭', $value) as $part) {
					$part = trim($part, NativeText::ASCII_WHITESPACE);
					if ($part !== '') {
						$parts[] = $part;
					}
				}
				if ($parts === []) {
					continue;
				}
				$root = $this->canonicalTitle($this->normalizeTitleForCard($parts[0]));
				$length = count($parts);
				if ($root !== '' && (!isset($best[$root]) || $length < $best[$root]['len'])) {
					$best[$root] = ['root' => $root, 'chain' => implode('⟭', $parts), 'len' => $length];
				}
			}
		}
		$multiple = count($best) > 1;
		$chains = [];
		$debates = [];
		$tooLong = false;
		foreach ($best as $item) {
			if ($multiple && $item['len'] >= 4) {
				continue;
			}
			$chain = $item['chain'] . '⟭' . $title;
			$tooLong = $this->breadcrumbLenByteSafe($chain) > 4 || $tooLong;
			$chains[] = $chain;
			$debates[] = $item['root'];
		}
		if ($tooLong) {
			$cats[] = '[[Category:' . $this->L($lang, 'categories', 'breadcrumb_too_long') . ']]';
		}
		$cache->breadcrumbChains = $this->uniqueStrings($chains);
		$cache->breadcrumbDebates = $this->uniqueParentTitles($debates);
	}

	/** @param list<array{page:string,title:string,warnings:string}> $items */
	protected function wkBuildArgumentData(array $items = []): array {
		$listLines = [];
		$argsVarParts = [];
		$hoverLines = [];
		foreach ($items as $item) {
			$page = $item['page'];
			$title = $item['title'];
			$listLines[] = '* ' . $title;
			$argsVarParts[] = SerializedData::mapItem($page, $title, $this->FIELD_SEP);
			$hoverLines[] = ArgumentMapMarkup::card('[[' . $page . '|' . $title . ']]');
		}
		return [
			'items' => $items,
			'list' => implode("\n", $listLines),
			'argsVar' => implode($this->ITEM_SEP, $argsVarParts),
			'mapHover' => implode("\n", $hoverLines)
		];
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, ?array &$cats = null): array {
		$warnsArg = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsArg === '')) {
			return [];
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
		$hasBanner = false;
		$out = [];
		foreach ($this->csvValues($warnsArg, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key);
				if ($fragment !== null) { $out[] = $fragment; }
				$hasBanner = true;
			}
		}
		if (($hasBanner && ($cats !== null))) {
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]');
		}
		return $out;
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, ?array &$cats = null) {
		$isJustif = ($kind === 'justif');
		$idPrefix = ($isJustif ? 'Justification_' : 'Objection_');
		$page = NativeText::field($it['page']);
		$titleShown = NativeText::field($it['title']);
		if (($titleShown === '')) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = NativeText::field($it['warnings']);
		$banners = $this->renderArgumentItemBanners($warnings, $pv, $lang, $cats);
		return ('<li id="' . $idPrefix . (string)$idx . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . implode('', $banners) . '</div>' . '</li>');
	}

	protected function renderKeywords($args = null, $pv = null, ?array &$cats = null, $lang = null, ?array $PF = null) {
		$out = [];
		$out[count($out) + 1] = ('<div style="font-size: 95%; margin-top: 1em;">' . $PF['keywords_label']);
		$raw = ($args['keywords'] ?? null);
		if ($raw !== null && $raw !== false && $raw !== '') {
			$extra = null;
			if ((($PF['search_type_field'] !== '') && ($PF['search_type_value'] !== ''))) {
				$extra = [$PF['search_type_field'] => $PF['search_type_value']];
			}
			$rq_title = $this->wkRunQueryPath($PF['form_search_by_keywords']);
			$rq_qkey = ($PF['form_search_by_keywords'] . '[' . $PF['keywords_field'] . ']');
			$rendered = [];
			foreach ($this->csvValues($raw, ',') as $_ => $k) {
				$k = trim(NativeText::field($k), NativeText::ASCII_WHITESPACE);
				if (($k !== '')) {
					$chip = $this->runQueryLink(['form' => $PF['form_search_by_keywords'], 'field' => $PF['keywords_field'], 'value' => $k, 'label' => $k, 'tooltip' => $PF['search_by_keyword_tt'], 'extra' => $extra, '_title' => $rq_title, '_qkey' => $rq_qkey]);
					if ($chip !== null && $chip !== false && $chip !== '') {
						$rendered[count($rendered) + 1] = $chip;
					}
					else {
						$rendered[count($rendered) + 1] = $k;
					}
				}
			}
			if ((count($rendered) > 0)) {
				$out[count($out) + 1] = implode(', ', $rendered);
			}
			else {
				$out[count($out) + 1] = ('<strong>' . $PF['none'] . '</strong>');
				$cats[] = ('[[Category:' . $PF['cat_no_keywords'] . ']]');
			}
		}
		else {
			$out[count($out) + 1] = ('<strong>' . $PF['none'] . '</strong>');
			$cats[] = ('[[Category:' . $PF['cat_no_keywords'] . ']]');
		}
		$out[count($out) + 1] = ($this->addDataEditLink($PF['form_edit_keywords'], $pv['rawTitle'], '&nbsp;', $PF['edit_keywords_tooltip']) . '<span style="display: none;">.</span></div>');
		return implode('', $out);
	}

	protected function renderSummary($args = null, $pv = null, ?array &$cats = null, $lang = null, ?array $PF = null, $summaryId = null) {
		$edit = $this->addDataEditLink($PF['form_edit_summary'], $pv['rawTitle'], '&nbsp;', $PF['edit_summary_tooltip']);
		$out = [];
		$out[] = ('<h2' . $summaryId . ' class="section-modifiable wk-icon-before wk-icon-summary">' . $PF['summary'] . $edit . '</h2>');
		{
			$raw = ($args['summary-warnings'] ?? null);
			$raw = is_string($raw) ? trim($raw, NativeText::ASCII_WHITESPACE) : '';
			if (($raw !== '')) {
				$lookup = $this->buildSummaryLabelLookup($lang, $this->SUMMARY_WARNING_BANNER_KEYS);
				$aliases = $this->SUMMARY_WARNING_ALIASES;
				if (($aliases['_lang'] ?? null) !== $lang) {
					$aliases = $this->buildSummaryAliases($lang);
					$aliases['_lang'] = $lang;
					$this->SUMMARY_WARNING_ALIASES = $aliases;
				}
				foreach ($this->csvValues($raw, ',') as $_ => $x) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->SUMMARY_WARNING_BANNER_KEYS, $aliases);
					if (($key !== '')) {
						$out[] = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
					}
				}
			}
		}
		if (($args['summary'] ?? null) !== null && ($args['summary'] ?? null) !== false && ($args['summary'] ?? null) !== '') {
			$out[] = ('<div class=\'argument-summary\'>
' . ($args['summary'] ?? null) . '</div>');
		}
		else {
			$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_summary'] . '</div>');
			$cats[] = ('[[Category:' . $PF['cat_no_summary'] . ']]');
		}
		return implode('', $out);
	}

	protected function renderQuotes($args = null, $pv = null, ?array &$cats = null, $lang = null, ?array $PF = null) {
		$edit = $this->addDataEditLink($PF['form_edit_quotes'], $pv['rawTitle'], '&nbsp;', $PF['edit_quotes_tooltip']);
		$out = [];
		$idAttr = '';
		$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-quote"' . $idAttr . '>' . $PF['quotes'] . $edit . '</h2>');
		$q = ($args['quotes'] ?? null);
		if ($q !== null && $q !== false && $q !== '') {
			$out[] = $q;
		}
		else {
			$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_quotes'] . '</div>');
			$cats[] = ('[[Category:' . $PF['cat_no_quotes'] . ']]');
		}
		return implode('', $out);
	}

	protected function renderDebateDetailed($args = null, $pv = null, ?array &$cats = null, $lang = null, ?array $PF = null) {
		if (($args['dedicated-debate'] ?? null) === null || ($args['dedicated-debate'] ?? null) === false || ($args['dedicated-debate'] ?? null) === '') {
			return '';
		}
		$cats[] = ('[[Category:' . $PF['cat_has_detailed_debate'] . ']]');
		$contentToTransfer = (NativeText::field(($args['justifications'] ?? null)) . NativeText::field(($args['objections'] ?? null)) . NativeText::field(($args['bibliography'] ?? null)) . NativeText::field(($args['webliography'] ?? null)) . NativeText::field(($args['videography'] ?? null)));
		if ((trim($contentToTransfer, NativeText::ASCII_WHITESPACE) !== '')) {
			$cats[] = ('[[Category:' . $PF['cat_content_to_transfer'] . ']]');
		}
		$edit = $this->addDataEditLink($PF['form_edit_detailed_debate'], $pv['rawTitle'], '&nbsp;', sprintf($PF['edit_detailed_debate_tooltip'], $pv['rawTitle']));
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-details">' . $PF['detailed_debate'] . $edit . '</h2>');
		$debat = $this->canonicalTitle(($args['dedicated-debate'] ?? null));
		$mapHtml = '';
		if ($this->hasSMW() && NativeText::field($PF['prop_argument_map'] ?? null) !== '') {
			$debatSafe = $this->smwSafeTitle($debat);
			if (($debatSafe !== '')) {
				$semantic = $this->readSemantic([1 => ('[[' . $debatSafe . ']]'), 2 => ('?' . $PF['prop_argument_map'] . '=map'), 3 => 'limit=1', 4 => 'link=none'], 'cards');
				$res = $semantic->rows;
				$v = $res[1]['map'] ?? null;
				if ($v !== null && $v !== false) {
					if ((is_string($v))) {
						$mapHtml = $v;
					}
					else {
						if ((is_array($v))) {
							foreach (SemanticReadResult::sequence($v) as $_ => $x) {
								if (((is_string($x)) && (trim($x, NativeText::ASCII_WHITESPACE) !== ''))) {
									$mapHtml = $x;
									break;
								}
								else {
									if ((is_array($x))) {
										$ft = $this->semanticRowTitle($x);
										if (((is_string($ft)) && (trim($ft, NativeText::ASCII_WHITESPACE) !== ''))) {
											$mapHtml = $ft;
											break;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		$box = ('<div class="bandeau-debat-detaille">' . '<div style="font-weight: bold;">' . sprintf($PF['arg_is_debate'], $this->context->nowiki($pv['rawTitle'])) . '</div>' . '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert navigation-not-searchable onglet-externe">' . $PF['detailed_debate_label'] . '<span class="onglet-externe">[[ ' . $debat . ' ]]</span>' . '</div></div>');
		$mapBox = '';
		if (($mapHtml !== '')) {
			$mapBox = ('<div class="encart-debat-detaille onglet-externe">' . '<div class="titre-debat-detaille">' . $debat . '</div>' . $mapHtml . '</div>');
		}
		return ($h2 . $box . $mapBox);
	}

	protected function safeRubriqueValue($s = null) {
		$s = trim(NativeText::field($s), NativeText::ASCII_WHITESPACE);
		if (($s === '')) {
			return '';
		}
		if (preg_match('/[\[\]{}|#<>]/', $s)) {
			return '';
		}
		$s = preg_replace('/[\x09-\x0d ]+/', ' ', $s);
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		return $s;
	}

	protected function renderRubriques($args = null, ?array &$cats = null, $lang = null, ?array $PF = null) {
		if (($args['sections'] ?? null) !== null && ($args['sections'] ?? null) !== false && ($args['sections'] ?? null) !== '') {
			$out = [];
			$nOk = 0;
			foreach ($this->csvValues(($args['sections'] ?? null), ',') as $_ => $r) {
				$r = $this->safeRubriqueValue($r);
				if (($r !== '')) {
					$nOk = ($nOk + 1);
					$out[count($out) + 1] = ('[[Category:' . $r . ']]');
				}
			}
			if (($nOk > 0)) {
				return implode('', $out);
			}
			return ('[[Category:' . $PF['cat_no_sections'] . ']]');
		}
		else {
			return ('[[Category:' . $PF['cat_no_sections'] . ']]');
		}
	}


	protected function wkRenderOneQuoteHtml(?array $item = null, $idx = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		$quote = NativeText::field($item['quote']);
		$authors = $item['authors'];
		$article = $item['article'];
		$work = $item['work'];
		$volume = $item['volume'];
		$number = $item['number'];
		$page = $item['page'];
		$location = $item['location'];
		$publisher = $item['publisher'];
		$place = $item['place'];
		$date = $item['date'];
		$link = $item['link'];
		$warnings = $item['warnings'];
		$this->vset('WD_QUOTES_MAX', (string)$idx);
		if (($authors !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_AUTHORS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_AUTHORS', ($cur === '' ? $authors : $cur . ',' . $authors));
		}
		if (($article !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_ARTICLES') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_ARTICLES', ($cur === '' ? $article : $cur . ',' . $article));
		}
		if (($work !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_WORKS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_WORKS', ($cur === '' ? $work : $cur . ',' . $work));
		}
		if (($publisher !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_PUBLISHERS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_PUBLISHERS', ($cur === '' ? $publisher : $cur . ',' . $publisher));
		}
		if (($place !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_PLACES') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_PLACES', ($cur === '' ? $place : $cur . ',' . $place));
		}
		$warnHtml = '';
		if (($warnings !== '')) {
			$lookup = $this->buildSummaryLabelLookup($lang, $this->QUOTE_WARNING_BANNER_KEYS);
			$b = [];
			foreach (explode(',', $warnings) as $x) {
				$x = trim($x, NativeText::ASCII_WHITESPACE);
				if (($x !== '')) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->QUOTE_WARNING_BANNER_KEYS);
					if (($key !== '')) {
						$h = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
						if (($h !== '')) {
							$b[] = $h;
						}
					}
				}
			}
			if ((count($b) > 0)) {
				$warnHtml = ('<div>' . implode('', $b) . '</div>');
			}
		}
		$bq = ('<blockquote data-wk-cite="' . (string)$idx . '">' . $warnHtml . '« ' . $quote . ' »' . '</blockquote>');
		$ref = [];
		$ref[] = ('<div class="reference-citation" data-wk-cite="' . (string)$idx . '">');
		if (($authors !== '')) {
			$ref[] = $authors;
		}
		else {
			$ref[] = $PF['unknown_author'];
			$cats[] = ('[[Category:' . $PF['cat_no_authors'] . ']]');
		}
		if (($article !== '')) {
			if (($link !== '')) {
				$ref[] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$ref[] = (', « ' . $article . ' »');
			}
		}
		if (($work !== '')) {
			if (($article !== '')) {
				$ref[] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (($link !== '')) {
					$ref[] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$ref[] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		if (($volume !== '')) {
			$ref[] = (', ' . $PF['volume_label'] . '&#160;' . $volume);
		}
		if (($number !== '')) {
			$ref[] = (', ' . $PF['issue_label'] . '&#160;' . $number);
		}
		if (($page !== '')) {
			$ref[] = (', p.' . $page);
		}
		if (($location !== '')) {
			$ref[] = (', ' . $location);
		}
		if (($publisher !== '')) {
			$ref[] = (', ' . $publisher);
		}
		if (($place !== '')) {
			$ref[] = (', ' . $place);
		}
		if (($date !== '')) {
			$ref[] = (', ' . $date);
		}
		$ref[] = '</div>';
		$html = ($bq . '
' . implode('', $ref) . '
');
		if ((($idx >= 1) && ($idx <= 4))) {
			$this->vset(('WD_QUOTE_' . (string)$idx), $html);
		}
		return $html;
	}

	protected function wkRenderQuotesFromSerialized($raw = null, $pv = null, $lang = null, ?array &$cats = null, ?array $PF = null) {
		$items = $this->nativeReferenceValues('quotes', $raw);
		if ((count($items) === 0)) {
			return '';
		}
		$out = [];
		foreach ($items as $i => $it) {
			$out[] = $this->wkRenderOneQuoteHtml($it, $i, $pv, $lang, $cats, $PF);
		}
		return implode('', $out);
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null, $maps = null, $rawB = null, $rawW = null, $rawV = null) {
		$props = [];
		$props[$PF['prop_arg_name']] = $pv['rawTitle'];
		$props[$PF['prop_arg_number']] = $pv['pageId'];
		if ($this->hasSMW()) {
			$this->ensureDebateParents($pv, $cache, $PF);
			$this->ensureArgumentParents($pv, $cache, $PF);
			{
				$chains = $this->uniqueStrings(($cache->breadcrumbChains ?? []));
				if ((((count($chains) > 0) && ($PF['prop_breadcrumb'] !== null && $PF['prop_breadcrumb'] !== false)) && ($PF['prop_breadcrumb'] !== ''))) {
					$props[$PF['prop_breadcrumb']] = $chains;
				}
				if ((($PF['prop_parent_debate'] !== null && $PF['prop_parent_debate'] !== false) && ($PF['prop_parent_debate'] !== ''))) {
					$dp = $this->uniqueStrings(($cache->breadcrumbDebates ?? []));
					if ((count($dp) > 0)) {
						$props[$PF['prop_parent_debate']] = $dp;
					}
				}
			}
			{
				$parents = [];
				foreach ($cache->justificationParents ?? [] as $x) {
					$parents[] = $this->canonicalTitle($x);
				}
				foreach ($cache->objectionParents ?? [] as $x) {
					$parents[] = $this->canonicalTitle($x);
				}
				$parents = $this->uniqueStrings($parents);
				if ((((count($parents) > 0) && ($PF['prop_parent_argument'] !== null && $PF['prop_parent_argument'] !== false)) && ($PF['prop_parent_argument'] !== ''))) {
					$props[$PF['prop_parent_argument']] = $parents;
				}
			}
		}
		$justif = $maps['justif'] ?? [];
		$obj = $maps['obj'] ?? [];
		{
			$lst = [];
			foreach ($justif['items'] ?? [] as $it) {
				$t = $this->canonicalTitle($it['page']);
				if (($t !== '')) {
					$lst[] = $t;
				}
			}
			$lst = $this->uniqueStrings($lst);
			if ((((count($lst) > 0) && ($PF['prop_justif'] !== null && $PF['prop_justif'] !== false)) && ($PF['prop_justif'] !== ''))) {
				$props[$PF['prop_justif']] = $lst;
			}
		}
		{
			$lst = [];
			foreach ($obj['items'] ?? [] as $it) {
				$t = $this->canonicalTitle($it['page']);
				if (($t !== '')) {
					$lst[] = $t;
				}
			}
			$lst = $this->uniqueStrings($lst);
			if ((((count($lst) > 0) && ($PF['prop_obj'] !== null && $PF['prop_obj'] !== false)) && ($PF['prop_obj'] !== ''))) {
				$props[$PF['prop_obj']] = $lst;
			}
		}
		$justifSurvol = trim($justif['mapHover'] ?? '', NativeText::ASCII_WHITESPACE);
		$objSurvol = trim($obj['mapHover'] ?? '', NativeText::ASCII_WHITESPACE);
		if (($justifSurvol === '')) {
			$justifSurvol = ('<div class="argument-title--map carte-vide">' . $PF['no_justifications_short'] . '</div>');
		}
		if (($objSurvol === '')) {
			$objSurvol = ('<div class="argument-title--map carte-vide">' . $PF['no_objections_short'] . '</div>');
		}
		if ((($PF['prop_argument_map'] !== null && $PF['prop_argument_map'] !== false) && ($PF['prop_argument_map'] !== ''))) {
			$props[$PF['prop_argument_map']] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $PF['justifications'] . '</div>' . '<div class="wk-arg-map__td">' . $justifSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $PF['objections'] . '</div>' . '<div class="wk-arg-map__td">' . $objSurvol . '</div>' . '</div>' . '</div>');
		}
		{
			$lst = trim($justif['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
			if (((($lst !== '') && ($PF['prop_justif_list'] !== null && $PF['prop_justif_list'] !== false)) && ($PF['prop_justif_list'] !== ''))) {
				$props[$PF['prop_justif_list']] = $lst;
			}
		}
		{
			$lst = trim($obj['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
			if (((($lst !== '') && ($PF['prop_obj_list'] !== null && $PF['prop_obj_list'] !== false)) && ($PF['prop_obj_list'] !== ''))) {
				$props[$PF['prop_obj_list']] = $lst;
			}
		}
		$mc = ($args['keywords'] ?? null);
		if ((($mc !== null && $mc !== false) && ($mc !== ''))) {
			$list = array_values($this->csvValues($mc, ','));
			if ((count($list) > 0)) {
				$props[$PF['prop_keyword']] = $list;
			}
		}
		if (((($args['dedicated-debate'] ?? null) !== null && ($args['dedicated-debate'] ?? null) !== false) && (($args['dedicated-debate'] ?? null) !== ''))) {
			$props[$PF['prop_detailed_debate']] = $this->canonicalTitle(($args['dedicated-debate'] ?? null));
		}
		$quoteProperties = [
			'WD_QUOTES_AUTHORS' => 'authors',
			'WD_QUOTES_ARTICLES' => 'article_name',
			'WD_QUOTES_WORKS' => 'work_name',
			'WD_QUOTES_PUBLISHERS' => 'publishing_house',
			'WD_QUOTES_PLACES' => 'place_of_publication'
		];
		foreach ($quoteProperties as $variable => $property) {
			$raw = trim($this->vget($variable) ?? '', NativeText::ASCII_WHITESPACE);
			if ($raw === '') {
				continue;
			}
			$values = $this->uniqueStrings($this->csvValues($raw, ','));
			if ($values !== []) {
				SemanticProperties::add($props, $this->smwPropRef($PF, $property), $values, true);
			}
		}
		{
			if (((($args['sections'] ?? null) !== null && ($args['sections'] ?? null) !== false) && (($args['sections'] ?? null) !== ''))) {
				$lst = [];
				foreach ($this->csvValues(($args['sections'] ?? null), ',') as $_ => $r) {
					$r = $this->safeRubriqueValue($r);
					if (($r !== '')) {
						$lst[] = $r;
					}
				}
				$lst = $this->uniqueStrings($lst);
				if ((((count($lst) > 0) && ($PF['prop_section'] !== null && $PF['prop_section'] !== false)) && ($PF['prop_section'] !== ''))) {
					$props[$PF['prop_section']] = $lst;
				}
			}
		}
		$this->smwSetSafe($props);
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return $this->context->message('Argument.seo', $lang, $key, ...$rest);
	}


	protected function buildSeoKeywords($args = null, $lang = null) {
		return NativeText::seoKeywords(
			$this->csvValues(($args['keywords'] ?? null), ','),
			$this->csvValues(($args['sections'] ?? null), ',')
		);
	}

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$titleText = NativeText::field($pv['rawTitle']);
		$topic = trim(NativeText::field(($args['keywords'] ?? null)), NativeText::ASCII_WHITESPACE);
		if (($topic === '')) {
			$topic = $titleText;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(['title' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain')), 'title_separator' => sprintf($this->seoL($lang, 'title_separator'), $titleText), 'description' => sprintf($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'author' => $this->seoL($lang, 'author'), 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'type' => 'article', 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']);
		return '';
	}

	protected function wkBuildWikiUrl($lang = null, $prefixKey = null, $labelKey = null) {
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $this->L($lang, 'urls', $labelKey)), 'WIKI'));
	}

	protected function wkBuildWikiUrlFromText($lang = null, $prefixKey = null, $text = null) {
		$text = trim(NativeText::field($text), NativeText::ASCII_WHITESPACE);
		if (($text === '')) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = trim(NativeText::field($titleText), NativeText::ASCII_WHITESPACE);
		if ($titleText === '') {
			return '';
		}
		try {
			return $this->context->fullUrl($titleText) ?? '';
		} catch (\Throwable $error) {
			DatabaseFailure::rethrow($error);
			return '';
		}
	}

	protected function wkBuildWebsiteNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return ['@type' => 'WebSite', '@id' => ($homeUrl . '#website'), 'url' => $homeUrl, 'name' => $siteName];
	}

	protected function wkBuildOrganizationNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return ['@type' => 'Organization', '@id' => ($homeUrl . '#organization'), 'name' => $siteName, 'url' => $homeUrl];
	}

	protected function wkBuildBreadcrumbNode($pv = null, $lang = null) {
		$homeLabel = $this->L($lang, 'urls', 'home_label');
		$homeUrl = $this->L($lang, 'urls', 'home');
		$argsLabel = $this->L($lang, 'urls', 'arguments_label');
		$argsUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'arguments_label');
		return ['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => [1 => ['@type' => 'ListItem', 'position' => 1, 'item' => ['@id' => $homeUrl, 'name' => $homeLabel]], 2 => ['@type' => 'ListItem', 'position' => 2, 'item' => ['@id' => $argsUrl, 'name' => $argsLabel]], 3 => ['@type' => 'ListItem', 'position' => 3, 'item' => ['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']]]]];
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = [];
		$mentions = [];
		$rawKeywords = $this->csvValues(($args['keywords'] ?? ''), ',');
		$stop3 = count($rawKeywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = trim(NativeText::field($rawKeywords[$i]), NativeText::ASCII_WHITESPACE);
			if (($kw !== '')) {
				$node = ['@type' => 'Thing', 'name' => $kw];
				if (($i <= 3)) {
					$about[count($about) + 1] = $node;
				}
				else {
					$mentions[count($mentions) + 1] = $node;
				}
			}
		}
		return [$about, $mentions];
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = [];
		$sections = $this->csvValues(($args['sections'] ?? ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = count($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (($i > 4)) {
				break;
			}
			$section = trim(NativeText::field($sections[$i]), NativeText::ASCII_WHITESPACE);
			if (($section !== '')) {
				$out[count($out) + 1] = ['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => ['@id' => $termSetId]];
			}
		}
		return $out;
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}

	protected function wkAppendAllValidIds(array &$out, $titles = null, $suffix = null, $lang = null) {
		$added = false;
		foreach ($titles ?? [] as $title) {
			$title = $this->canonicalTitle(trim(NativeText::field($title), NativeText::ASCII_WHITESPACE));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(count($out) + 1)] = ['@id' => ($url . $suffix)];
					$added = true;
				}
			}
		}
		return $added;
	}

	protected function wkAppendFirstValidId(array &$out, $titles = null, $suffix = null, $lang = null) {
		foreach ($titles ?? [] as $title) {
			$title = $this->canonicalTitle(trim(NativeText::field($title), NativeText::ASCII_WHITESPACE));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(count($out) + 1)] = ['@id' => ($url . $suffix)];
					return true;
				}
			}
		}
		return false;
	}

	protected function wkBuildClaimIsPartOf($lang = null, ?ParentRelations $cache = null) {
		$out = [1 => ['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'arguments_label')]];
		$debates = [];
		foreach ([$cache->proDebates ?? [], $cache->conDebates ?? []] as $titles) {
			foreach ($titles as $title) {
				$debates[] = $this->canonicalTitle($title);
			}
		}
		if ($this->wkAppendAllValidIds($out, $this->uniqueStrings($debates), '#qa', $lang)) {
			return $out;
		}
		if ($this->wkAppendFirstValidId($out, $cache->justificationParents ?? [], '#claim', $lang)) {
			return $out;
		}
		$this->wkAppendFirstValidId($out, $cache->objectionParents ?? [], '#claim', $lang);
		return $out;
	}

	protected function wkBuildQuoteAuthorValue($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return null;
		}
		$authors = $this->csvValues($raw, ',');
		if ((count($authors) === 0)) {
			return null;
		}
		if ((count($authors) === 1)) {
			return ['@type' => 'Person', 'name' => $authors[1]];
		}
		$out = [];
		foreach ($authors as $one) {
			$one = trim(NativeText::field($one), NativeText::ASCII_WHITESPACE);
			if (($one !== '')) {
				$out[count($out) + 1] = ['@type' => 'Person', 'name' => $one];
			}
		}
		if ((count($out) === 0)) {
			return null;
		}
		return $out;
	}

	protected function wkBuildClaimCitationsFromQuotes($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('quotes', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$authors = $item['authors'];
		$article = $item['article'];
		$work = $item['work'];
		$volume = $item['volume'];
		$number = $item['number'];
		$publisher = $item['publisher'];
		$date = $item['date'];
		$link = $item['link'];
		$node = null;
		if (((($number !== '') && ($article !== '')) && ($work !== ''))) {
			$node = ['@type' => 'Article', 'name' => $article, 'isPartOf' => ['@type' => 'Periodical', 'name' => $work]];
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = ['@type' => 'Book', 'name' => $work];
				if (($article !== '')) {
					$node['description'] = $article;
				}
				if (($volume !== '')) {
					$node['volumeNumber'] = $volume;
				}
				if (($number !== '')) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if ((($link !== '') && ($article !== ''))) {
					$node = ['@type' => 'WebPage', 'name' => $article];
					if (($work !== '')) {
						$node['isPartOf'] = ['@type' => 'WebSite', 'name' => $work];
					}
				}
			}
		}
		if ($node === null) {
			if ($article === '' && $work === '') {
				return null;
			}
			$node = ['@type' => 'CreativeWork', 'name' => $article !== '' ? $article : $work];
			if ($article !== '' && $work !== '') {
				$node['isPartOf'] = ['@type' => 'CreativeWork', 'name' => $work];
			}
			// Preserve ambiguous volume/number as literal identifiers.
			$identifiers = [];
			foreach (['volume' => $volume, 'number' => $number] as $key => $value) {
				if ($value !== '') {
					$identifiers[count($identifiers) + 1] = [
						'@type' => 'PropertyValue', 'propertyID' => $key, 'value' => $value
					];
				}
			}
			if ($identifiers !== []) {
				$node['identifier'] = $identifiers;
			}
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if ($authorValue !== null) {
			$node['author'] = $authorValue;
		}
		if (($publisher !== '')) {
			$node['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		if (($link !== '')) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromBibliography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('bibliography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$authors = $item['authors'];
		$article = $item['article'];
		$work = $item['work'];
		$volume = $item['volume'];
		$number = $item['number'];
		$publisher = $item['publisher'];
		$date = $item['date'];
		$link = $item['link'];
		$node = null;
		if (((($number !== '') && ($article !== '')) && ($work !== ''))) {
			$node = ['@type' => 'Article', 'name' => $article, 'isPartOf' => ['@type' => 'Periodical', 'name' => $work]];
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = ['@type' => 'Book', 'name' => $work];
				if (($article !== '')) {
					$node['description'] = $article;
				}
				if (($volume !== '')) {
					$node['volumeNumber'] = $volume;
				}
				if (($number !== '')) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if ((($link !== '') && ($article !== ''))) {
					$node = ['@type' => 'WebPage', 'name' => $article];
					if (($work !== '')) {
						$node['isPartOf'] = ['@type' => 'WebSite', 'name' => $work];
					}
				}
			}
		}
		if ($node === null) {
			if ($article === '' && $work === '') {
				return null;
			}
			$node = ['@type' => 'CreativeWork', 'name' => $article !== '' ? $article : $work];
			if ($article !== '' && $work !== '') {
				$node['isPartOf'] = ['@type' => 'CreativeWork', 'name' => $work];
			}
			// Preserve ambiguous volume/number as literal identifiers.
			$identifiers = [];
			foreach (['volume' => $volume, 'number' => $number] as $key => $value) {
				if ($value !== '') {
					$identifiers[count($identifiers) + 1] = [
						'@type' => 'PropertyValue', 'propertyID' => $key, 'value' => $value
					];
				}
			}
			if ($identifiers !== []) {
				$node['identifier'] = $identifiers;
			}
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if ($authorValue !== null) {
			$node['author'] = $authorValue;
		}
		if (($publisher !== '')) {
			$node['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		if (($link !== '')) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromWebliography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('webliography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$date = $item['date'];
		if ($page === '' && $site === '') {
			return null;
		}
		$node = ['@type' => 'WebPage', 'name' => $page !== '' ? $page : $site];
		if ($link !== '') {
			$node['url'] = $link;
		}
		if ($page !== '' && $site !== '') {
			$node['isPartOf'] = ['@type' => 'WebSite', 'name' => $site];
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return [];
		}
		$items = $this->nativeReferenceValues('videography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		if ($title === '') {
			return null;
		}
		$node = ['@type' => 'VideoObject', 'name' => $title];
		if ($link !== '') {
			$node['url'] = $link;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if ($authorValue !== null) {
			$node['author'] = $authorValue;
		}
		return $node;
		});
	}


	protected function renderArgumentSchemaJSONLD($args = null, $pv = null, $lang = null, ?ParentRelations $cache = null, ?array $PF = null, $maps = null) {
		$this->ensureDebateParents($pv, $cache, $PF);
		$this->ensureArgumentParents($pv, $cache, $PF);
		$schemaCategories = [];
		$this->ensureBreadcrumbSelection($pv, $cache, $schemaCategories, $lang, $PF);
		$websiteNode = $this->wkBuildWebsiteNode($lang);
		$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
		$organizationNode = $this->wkBuildOrganizationNode($lang);
		[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
		$keywords = $this->wkBuildKeywords($args, $lang);
		$isPartOf = $this->wkBuildClaimIsPartOf($lang, $cache);
		$parts = SchemaData::argumentParts($args, $maps, $pv['pageUrl'], $lang,
			fn(string $title): string => $this->wkFullUrl($this->canonicalTitle($title)), $this->context);
		$citations = [];
		foreach ([
			'wkBuildClaimCitationsFromQuotes' => '_quotes_wikitext',
			'wkBuildClaimCitationsFromBibliography' => 'bibliography',
			'wkBuildClaimCitationsFromWebliography' => 'webliography',
			'wkBuildClaimCitationsFromVideography' => 'videography'
		] as $builder => $field) {
			foreach ($this->$builder((string)($args[$field] ?? '')) as $node) {
				$citations[count($citations) + 1] = $node;
			}
		}
		$pageNode = [
			'@type' => [1 => 'WebPage', 2 => 'LearningResource'],
			'@id' => $pv['pageUrl'],
			'url' => $pv['pageUrl'],
			'name' => $pv['rawTitle'],
			'inLanguage' => $lang,
			'isPartOf' => ['@id' => $this->L($lang, 'urls', 'home') . '#website'],
			'breadcrumb' => ['@id' => $pv['pageUrl'] . '#breadcrumb'],
			'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang),
			'mainEntity' => ['@id' => $pv['pageUrl'] . '#claim']
		] + SchemaData::education($lang, 'argument');
		if ($parts !== []) {
			$pageNode['hasPart'] = $parts;
		}
		// Credit collective page authors without attributing the Claim to them.
		$credit = $this->seoL($lang, 'author');
		$pageNode['creditText'] = $credit;
		$pageNode['author'] = ['@type' => 'Organization', 'name' => $credit];
		$pageNode['publisher'] = ['@id' => ($this->L($lang, 'urls', 'home') . '#organization')];
		$claimNode = [
			'@type' => 'Claim',
			'@id' => $pv['pageUrl'] . '#claim',
			'url' => $pv['pageUrl'],
			'name' => $pv['rawTitle'],
			'inLanguage' => $lang,
			'isPartOf' => $isPartOf,
			'appearance' => ['@id' => $pv['pageUrl']]
		];
		if ((($args['established-name'] ?? null) !== null && ($args['established-name'] ?? null) !== false && (trim(($args['established-name'] ?? null), NativeText::ASCII_WHITESPACE) !== ''))) {
			$claimNode['alternateName'] = ($args['established-name'] ?? null);
		}
		{
			$summary = NativeText::seoPlainText((string)($args['summary'] ?? ''), $this->context);
			if (($summary !== '')) {
				$claimNode['abstract'] = $summary;
			}
		}
		if ((count($about) > 0)) {
			$claimNode['about'] = $about;
		}
		if ((count($mentions) > 0)) {
			$claimNode['mentions'] = $mentions;
		}
		if ((count($keywords) > 0)) {
			$claimNode['keywords'] = $keywords;
		}
		if ((count($citations) > 0)) {
			$claimNode['citation'] = $citations;
		}
		$data = (['@context' => 'https://schema.org', '@graph' => ([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode, 5 => $claimNode])]);
		return $this->context->jsonLdData($data);
	}

	protected function canonicalTitle($s = null) {
		$s = trim(NativeText::field($s), NativeText::ASCII_WHITESPACE);
		if ($s === '') {
			return '';
		}
		$s = preg_replace('/^:[\x09-\x0d ]*/', '', $s);
		$s = preg_replace('/^\[\[[\x09-\x0d ]*/', '', $s);
		$s = preg_replace('/[\x09-\x0d ]*\]\]$/D', '', $s);
		if (preg_match('/^([^\]|]+)\|/', $s, $match)) {
			$s = $match[1];
		}
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		$title = $this->titleNewCached($s);
		$value = $title['prefixedText'] ?? null;
		return $value === null || $value === false ? $s : $value;
	}

	protected function computeArgumentMapsFromData($args = null): array {
		$justifications = $this->wkExtractItemsFromSerializedData(NativeText::field(($args['justifications'] ?? null)));
		$objections = $this->wkExtractItemsFromSerializedData(NativeText::field(($args['objections'] ?? null)));
		return [
			'justif' => $this->wkBuildArgumentData($justifications),
			'obj' => $this->wkBuildArgumentData($objections)
		];
	}

	public function render($frame = null) {
		$this->nativeSemanticCache = null;
		$this->decodedReferences = null;
		$this->renderData = null;
		$this->cardTitleCache = null;
		$this->SUMMARY_WARNING_ALIASES = null;
		$this->vset('WD_QUOTES_AUTHORS', '');
		$this->vset('WD_QUOTES_ARTICLES', '');
		$this->vset('WD_QUOTES_WORKS', '');
		$this->vset('WD_QUOTES_PUBLISHERS', '');
		$this->vset('WD_QUOTES_PLACES', '');
		$this->vset('WD_QUOTES_MAX', '');
		$this->vset('WD_QUOTE_1', '');
		$this->vset('WD_QUOTE_2', '');
		$this->vset('WD_QUOTE_3', '');
		$this->vset('WD_QUOTE_4', '');
		$this->CARD_CACHE = $this->cacheMake(600);
		$this->SMW_TITLES_CACHE = $this->cacheMake(250);
		$args = $this->getArgs($frame);
		// Missing parameters stay absent; explicit "0" is not an empty field.
		foreach (['summary', 'quotes', 'bibliography', 'webliography', 'videography',
			'keywords', 'sections', 'interlanguage', 'dedicated-debate', 'title-warnings',
			'argument-warnings', 'summary-warnings', 'justification-warnings', 'objection-warnings'] as $key) {
			if (!is_string($args[$key] ?? null)) {
				continue;
			}
			$value = trim($args[$key], NativeText::ASCII_WHITESPACE);
			if ($value === '') {
				unset($args[$key]);
			} else {
				$args[$key] = $value;
			}
		}
		$rawB = NativeText::field(($args['bibliography'] ?? null));
		$rawW = NativeText::field(($args['webliography'] ?? null));
		$rawV = NativeText::field(($args['videography'] ?? null));
		if ((!is_string(($args['justifications'] ?? null)))) {
			unset($args['justifications']);
		}
		if ((!is_string(($args['objections'] ?? null)))) {
			unset($args['objections']);
		}
		$this->renderData = new RenderData;
		$this->renderData->expectReuse([($args['keywords'] ?? null), ($args['sections'] ?? null)]);
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		$pv['lang'] = $lang;
		$cache = $this->makeSMWCache();
		[$pieces, $cats] = [[], []];
		$PF = [];
		$PF['file_search'] = $this->L($lang, 'files', 'search');
		$PF['parent_is_justification_of'] = $this->L($lang, 'text', 'parent_is_justification_of');
		$PF['parent_is_objection_to'] = $this->L($lang, 'text', 'parent_is_objection_to');
		$PF['keywords_label'] = $this->L($lang, 'text', 'keywords_label_text');
		$PF['none'] = $this->L($lang, 'text', 'none_label');
		$PF['keywords_field'] = $this->L($lang, 'params', 'keywords_field');
		$PF['form_search_by_keywords'] = $this->L($lang, 'forms', 'search_by_keywords_title');
		$PF['search_by_keyword_tt'] = $this->L($lang, 'text', 'search_by_keyword_tt');
		$PF['search_type_field'] = $this->L($lang, 'params', 'search_type_field');
		$PF['search_type_value'] = $this->L($lang, 'params', 'search_type_value');
		$PF['form_edit_keywords'] = $this->L($lang, 'forms', 'keywords_edit_title');
		$PF['edit_keywords_tooltip'] = $this->L($lang, 'text', 'keywords_edit_tt');
		$PF['form_edit_summary'] = $this->L($lang, 'forms', 'summary_edit_title');
		$PF['edit_summary_tooltip'] = $this->L($lang, 'text', 'summary_edit_tt');
		$PF['form_edit_quotes'] = $this->L($lang, 'forms', 'quotes_edit_title');
		$PF['edit_quotes_tooltip'] = $this->L($lang, 'text', 'quotes_edit_tt');
		$PF['form_edit_references'] = $this->L($lang, 'forms', 'references_edit_title');
		$PF['edit_references_tooltip'] = $this->L($lang, 'text', 'references_edit_tt');
		$PF['form_edit_justifications'] = $this->L($lang, 'forms', 'justifications_form');
		$PF['edit_justifications_tooltip'] = $this->L($lang, 'text', 'justifications_edit_tt');
		$PF['form_edit_objections'] = $this->L($lang, 'forms', 'objections_form');
		$PF['edit_objections_tooltip'] = $this->L($lang, 'text', 'objections_edit_tt');
		$PF['form_new_arg_title'] = $this->L($lang, 'forms', 'new_arg_title');
		$PF['arg_new_title_field_base'] = $this->L($lang, 'text', 'arg_new_title_field_base');
		$PF['justification_add_label'] = $this->L($lang, 'text', 'justification_add_label');
		$PF['justification_add_tt'] = $this->L($lang, 'text', 'justification_add_tt');
		$PF['objection_add_label'] = $this->L($lang, 'text', 'objection_add_label');
		$PF['objection_add_tt'] = $this->L($lang, 'text', 'objection_add_tt');
		$PF['summary'] = $this->L($lang, 'text', 'summary_title');
		$PF['no_summary'] = $this->L($lang, 'text', 'none_summary_msg');
		$PF['quotes'] = $this->L($lang, 'text', 'quotes_title');
		$PF['no_quotes'] = $this->L($lang, 'text', 'none_quotes_msg');
		$PF['references'] = $this->L($lang, 'text', 'references_title');
		$PF['no_references'] = $this->L($lang, 'text', 'none_references_msg');
		$PF['justifications'] = $this->L($lang, 'text', 'justifications_title');
		$PF['no_justifications'] = $this->L($lang, 'text', 'none_justifications_msg');
		$PF['no_justifications_short'] = $this->L($lang, 'text', 'none_justifications_short_msg');
		$PF['objections'] = $this->L($lang, 'text', 'objections_title');
		$PF['no_objections'] = $this->L($lang, 'text', 'none_objections_msg');
		$PF['no_objections_short'] = $this->L($lang, 'text', 'none_objections_short_msg');
		$PF['form_edit_detailed_debate'] = $this->L($lang, 'forms', 'detailed_debate_edit_title');
		$PF['edit_detailed_debate_tooltip'] = $this->L($lang, 'text', 'detailed_debate_edit_tt');
		$PF['detailed_debate'] = $this->L($lang, 'text', 'detailed_debate_title');
		$PF['detailed_debate_label'] = $this->L($lang, 'text', 'detailed_debate_label_text');
		$PF['arg_is_debate'] = $this->L($lang, 'text', 'arg_is_debate_msg');
		$PF['form_edit_sections'] = $this->L($lang, 'forms', 'sections_edit_title');
		$PF['edit_sections_tooltip'] = $this->L($lang, 'text', 'sections_edit_tt');
		$PF['form_edit_interlanguage'] = $this->L($lang, 'forms', 'interlanguage_edit_title');
		$PF['edit_interlanguage_tooltip'] = $this->L($lang, 'text', 'interlanguage_edit_tt');
		$PF['edit'] = $this->L($lang, 'text', 'edit_label');
		$PF['rename_link_text'] = $this->L($lang, 'text', 'rename_link_label');
		$PF['type_justification'] = $this->L($lang, 'params', 'justification_type_label');
		$PF['type_objection'] = $this->L($lang, 'params', 'objection_type_label');
		$PF['unknown_author'] = $this->L($lang, 'text', 'unknown_author');
		$PF['volume_label'] = $this->L($lang, 'text', 'volume_label');
		$PF['issue_label'] = $this->L($lang, 'text', 'issue_label');
		$PF['more_quotes_button'] = $this->L($lang, 'text', 'more_quotes_button');
		$PF['more_quotes_note'] = $this->L($lang, 'text', 'more_quotes_note');
		$PF['parent_debates_title_1'] = $this->L($lang, 'text', 'parent_debates_title_1');
		$PF['parent_debates_title_n'] = $this->L($lang, 'text', 'parent_debates_title_n');
		$PF['cat_arguments'] = $this->L($lang, 'categories', 'arguments');
		$PF['cat_no_keywords'] = $this->L($lang, 'categories', 'keywords_missing');
		$PF['cat_no_summary'] = $this->L($lang, 'categories', 'summary_missing');
		$PF['cat_no_quotes'] = $this->L($lang, 'categories', 'quotes_missing');
		$PF['cat_no_references'] = $this->L($lang, 'categories', 'references_missing');
		$PF['cat_no_justifications'] = $this->L($lang, 'categories', 'justifications_missing');
		$PF['cat_no_objections'] = $this->L($lang, 'categories', 'objections_missing');
		$PF['cat_no_sections'] = $this->L($lang, 'categories', 'sections_missing');
		$PF['cat_no_content'] = $this->L($lang, 'categories', 'content_missing');
		$PF['cat_has_detailed_debate'] = $this->L($lang, 'categories', 'has_detailed_debate');
		$PF['cat_content_to_transfer'] = $this->L($lang, 'categories', 'content_to_transfer');
		$PF['cat_no_authors'] = $this->L($lang, 'categories', 'authors_missing');
		$PF['prop_arg_name'] = $this->L($lang, 'props', 'arg_page_name');
		$PF['prop_arg_number'] = $this->L($lang, 'props', 'arg_number');
		$PF['prop_argument_map'] = $this->L($lang, 'props', 'argument_map');
		$PF['prop_justif_list'] = $this->L($lang, 'props', 'justif_list');
		$PF['prop_obj_list'] = $this->L($lang, 'props', 'obj_list');
		$PF['prop_keyword'] = $this->L($lang, 'props', 'keyword');
		$PF['prop_detailed_debate'] = $this->L($lang, 'props', 'detailed_debate');
		$PF['prop_author'] = $this->L($lang, 'props', 'author');
		$PF['prop_article_name'] = $this->L($lang, 'props', 'article_name');
		$PF['prop_work_name'] = $this->L($lang, 'props', 'work_name');
		$PF['prop_publishing_house'] = $this->L($lang, 'props', 'publishing_house');
		$PF['prop_place_of_publication'] = $this->L($lang, 'props', 'place_of_publication');
		$PF['prop_arg_pro'] = $this->L($lang, 'props', 'arg_pro');
		$PF['prop_arg_con'] = $this->L($lang, 'props', 'arg_con');
		$PF['prop_justif'] = $this->L($lang, 'props', 'justif');
		$PF['prop_obj'] = $this->L($lang, 'props', 'obj');
		$PF['prop_breadcrumb'] = $this->L($lang, 'props', 'breadcrumb');
		$PF['prop_debate_name'] = $this->L($lang, 'props', 'debate_name');
		$PF['prop_parent_debate'] = $this->L($lang, 'props', 'parent_debate');
		$PF['prop_parent_argument'] = $this->L($lang, 'props', 'parent_argument');
		$PF['prop_section'] = $this->L($lang, 'props', 'section');
		$PF['cat_initialized'] = $this->L($lang, 'categories', 'initialized');
		$PF['cat_uninitialized'] = $this->L($lang, 'categories', 'uninitialized');
		$PF['init_warning_title'] = $this->L($lang, 'text', 'init_warning_title');
		$PF['init_warning_intro'] = $this->L($lang, 'text', 'init_warning_intro');
		$PF['init_yes_finish_label'] = $this->L($lang, 'text', 'init_yes_finish_label');
		$PF['init_yes_tt'] = $this->L($lang, 'text', 'init_yes_tt');
		$PF['init_ok_text'] = $this->L($lang, 'text', 'init_ok_text');
		$PF['init_no_rename_label'] = $this->L($lang, 'text', 'init_no_rename_label');
		$PF['init_summary_pro'] = $this->L($lang, 'text', 'init_summary_pro');
		$PF['init_summary_con'] = $this->L($lang, 'text', 'init_summary_con');
		$PF['init_summary_justif'] = $this->L($lang, 'text', 'init_summary_justif');
		$PF['init_summary_obj'] = $this->L($lang, 'text', 'init_summary_obj');
		{
			$q = ($args['quotes'] ?? null);
			if (((is_string($q)) && ($q !== ''))) {
				try {
					$r = $this->context->expandQuotes($q);
				} catch (\Throwable $error) {
					DatabaseFailure::rethrow($error);
					$r = null;
				}
				if (is_string($r)) {
					$args['_quotes_wikitext'] = $r;
					$args['quotes'] = $this->wkRenderQuotesFromSerialized($r, $pv, $lang, $cats, $PF);
				}
				else {
					$args['_quotes_wikitext'] = '';
					$args['quotes'] = '';
				}
			}
		}
		$hasResume = (($args['summary'] ?? null) !== null);
		$hasQuotes = (($args['quotes'] ?? null) !== null);
		$hasRefs = (((($args['bibliography'] ?? null) !== null) || (($args['webliography'] ?? null) !== null)) || (($args['videography'] ?? null) !== null));
		if (isset($args['established-name']) && ($args['established-name'] ?? null) !== false) {
			$pieces[] = $this->context->shortDescription(($args['established-name'] ?? null));
			$cats[] = ('[[Category:' . $this->L($lang, 'categories', 'has_alt_name') . ']]');
		}
		$cats[] = ('[[Category:' . $PF['cat_arguments'] . ']]');
		$pieces[] = $this->renderTitleWarnings($pv, $cats, $lang);
		{
			$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = [];
			if (isset($args['title-warnings']) && ($args['title-warnings'] ?? null) !== false) {
				$out[] = $this->renderBannersFromArgList(($args['title-warnings'] ?? null), $titleLookup, $pv, $lang, $cats);
			}
			if ((count($out) > 0)) {
				$pieces[] = implode('', $out);
			}
		}
		$maps = $this->computeArgumentMapsFromData($args);
		$this->ensureBreadcrumbSelection($pv, $cache, $cats, $lang, $PF);
		$pieces[] = $this->renderDebateParentsBoxes($pv, $cats, $lang, $cache, $PF);
		$pieces[] = $this->renderArgumentParentsBoxes($pv, $cats, $lang, $cache, $PF);
		$pieces[] = $this->renderInitializationBlock($args, $pv, $cats, $lang, $cache, $PF, $maps);
		$pieces[] = $this->renderKeywords($args, $pv, $cats, $lang, $PF);
		if (isset($args['argument-warnings']) && ($args['argument-warnings'] ?? null) !== false) {
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = [];
			$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], '', $this->L($lang, 'text', 'argument_banners_edit_tt'), true);
			$out[] = ('<h2 id="Summary" class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>');
			$out[] = $this->renderBannersFromArgList(($args['argument-warnings'] ?? null), $argLookup, $pv, $lang, $cats);
			$pieces[] = implode('', $out);
		}
		$summaryId = ' id="Summary"';
		if (NativeText::field(($args['argument-warnings'] ?? null)) !== '') {
			$summaryId = '';
		}
		$pieces[] = $this->renderSummary($args, $pv, $cats, $lang, $PF, $summaryId);
		$pieces[] = $this->renderQuotes($args, $pv, $cats, $lang, $PF);
		$detailed = $this->renderDebateDetailed($args, $pv, $cats, $lang, $PF);
		if (($detailed !== '')) {
			$pieces[] = $detailed;
		}
		else {
			$out = [];
			$editRefs = $this->addDataEditLink($PF['form_edit_references'], $pv['rawTitle'], '&nbsp;', $PF['edit_references_tooltip']);
			$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-bibliography">' . $PF['references'] . $editRefs . '</h2>');
			{
				$refsHtml = $this->wkRenderReferencesFromRaw($rawB, $rawW, $rawV, $pv, $lang, $cats, $PF);
				if (($refsHtml !== '')) {
					$out[] = ('<ul class="references-argument">' . $refsHtml . '</ul>');
				}
				else {
					$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_references'] . '</div>');
					$cats[] = ('[[Category:' . $PF['cat_no_references'] . ']]');
				}
			}
			$justifItems = $maps['justif']['items'];
			$objItems = $maps['obj']['items'];
			$editJust = $this->addDataEditLink($PF['form_edit_justifications'], $pv['rawTitle'], '&nbsp;', $PF['edit_justifications_tooltip']);
			$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-argument is-pro">' . $PF['justifications'] . $editJust . '</h2>');
			{
				$raw = ($args['justification-warnings'] ?? null);
				$raw = is_string($raw) ? trim($raw, NativeText::ASCII_WHITESPACE) : '';
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
					foreach ($this->csvValues($raw, ',') as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							$out[] = $this->renderSubsectionBanner($pv, $lang, $key, ['reorg' => true, 'form' => $PF['form_edit_justifications'], 'tooltip' => $PF['edit_justifications_tooltip']], $cats);
						}
					}
				}
			}
			if ($justifItems !== []) {
				$ulJ = (HtmlBuilder::create('ul'))->addClass('argument-list is-pro');
				$lis = [];
				foreach ($justifItems as $i => $it) {
					$lis[] = $this->wkRenderArgumentLi($pv, $lang, $it, 'justif', $i + 1, $cats);
				}
				($ulJ)->wikitext(implode("\n", $lis));
				$out[] = (string)$ulJ;
			}
			else {
				$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_justifications'] . '</div>');
				$cats[] = ('[[Category:' . $PF['cat_no_justifications'] . ']]');
			}
			{
				$baseField = NativeText::field($PF['arg_new_title_field_base']);
				$label = NativeText::field($PF['justification_add_label']);
				$tooltip = sprintf($PF['justification_add_tt'], $pv['rawTitle']);
				$rqForm = NativeText::field($PF['form_new_arg_title']);
				$rqQuery = [];
				$rqQuery[($baseField . '[type]')] = NativeText::field($PF['type_justification']);
				$rqQuery[($baseField . '[ID]')] = NativeText::field($pv['pageId']);
				$rqQuery['_run'] = '1';
				$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>');
			}
			$editObj = $this->addDataEditLink($PF['form_edit_objections'], $pv['rawTitle'], '&nbsp;', $PF['edit_objections_tooltip']);
			$out[] = ($this->listBreaker() . '<h2 class="section-modifiable wk-icon-before wk-icon-argument is-con">' . $PF['objections'] . $editObj . '</h2>');
			{
				$raw = ($args['objection-warnings'] ?? null);
				$raw = is_string($raw) ? trim($raw, NativeText::ASCII_WHITESPACE) : '';
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->OBJECTION_WARNING_BANNER_KEYS);
					foreach ($this->csvValues($raw, ',') as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->OBJECTION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							$out[] = $this->renderSubsectionBanner($pv, $lang, $key, ['reorg' => true, 'form' => $PF['form_edit_objections'], 'tooltip' => $PF['edit_objections_tooltip']], $cats);
						}
					}
				}
			}
			if ($objItems !== []) {
				$ulO = (HtmlBuilder::create('ul'))->addClass('argument-list is-con');
				$lis = [];
				foreach ($objItems as $i => $it) {
					$lis[] = $this->wkRenderArgumentLi($pv, $lang, $it, 'obj', $i + 1, $cats);
				}
				($ulO)->wikitext(implode("\n", $lis));
				$out[] = (string)$ulO;
			}
			else {
				$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_objections'] . '</div>');
				$cats[] = ('[[Category:' . $PF['cat_no_objections'] . ']]');
			}
			{
				$baseField = NativeText::field($PF['arg_new_title_field_base']);
				$label = NativeText::field($PF['objection_add_label']);
				$tooltip = sprintf($PF['objection_add_tt'], $pv['rawTitle']);
				$rqForm = NativeText::field($PF['form_new_arg_title']);
				$rqQuery = [];
				$rqQuery[($baseField . '[type]')] = NativeText::field($PF['type_objection']);
				$rqQuery[($baseField . '[ID]')] = NativeText::field($pv['pageId']);
				$rqQuery['_run'] = '1';
				$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>');
			}
			$pieces[] = implode('', $out);
		}
		$pieces[] = $this->renderDebatesParentsBottom($pv, $lang, $cache, $PF);
		if (isset($args['interlanguage']) && ($args['interlanguage'] ?? null) !== false) {
			$pieces[] = ($args['interlanguage'] ?? null);
		}
		$pieces[] = $this->renderRubriques($args, $cats, $lang, $PF);
		if (!$hasResume && !$hasQuotes && !$hasRefs) {
			$cats[] = ('[[Category:' . $PF['cat_no_content'] . ']]');
		}
		$this->setSemanticData($args, $pv, $lang, $cache, $PF, $maps, $rawB, $rawW, $rawV);
		$this->renderFullSEO($args, $pv, $lang);
		$pieces[] = implode('', $cats);
		$pieces[] = ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[Special:MovePage/' . $pv['encoded'] . '| ' . $PF['rename_link_text'] . ']]</span>');
		$pieces[] = ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataEditLink($PF['form_edit_sections'], $pv['rawTitle'], $PF['edit'], $PF['edit_sections_tooltip']) . '</span>');
		if (trim(NativeText::field(($args['interlanguage'] ?? null)), NativeText::ASCII_WHITESPACE) !== '') {
			$pieces[] = ('<span id="bouton-modifier-interlangue" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($PF['edit_interlanguage_tooltip']) . '">' . $this->addDataEditLink($PF['form_edit_interlanguage'], $pv['rawTitle'], $PF['edit'], $PF['edit_interlanguage_tooltip']) . '</span>');
		}
		$pieces[] = $this->renderArgumentSchemaJSONLD($args, $pv, $lang, $cache, $PF, $maps);
		if ($this->context instanceof PreparationProfilingContext) {
			$this->context->profilePreparation($this->renderData->statistics());
		}
		return implode('', $pieces);
	}

	protected function hasSMW() { return $this->context->smwAvailable(); }
	protected function wkSeoHas() { return true; }
	protected function vset($name, $value) { $this->context->variableSet($name, $value === false ? 'false' : NativeText::field($value)); }
	protected function vget($name) { return $this->context->variableGet($name); }
	protected function titleNewCached($text) { return $this->context->titleInfo($text); }
	protected function absUrlFromTitle($title, $query) { return $this->context->fullUrl($title, $query) ?? $this->context->localUrl($title, $query) ?? ''; }
	protected function readSemantic(array $query, string $origin): SemanticReadResult {
		if (!$this->hasSMW()) { return new SemanticReadResult(null); }
		$cache = $this->nativeSemanticCache ??= new FifoCache(250);
		$key = $this->smwKeyFromArray($query);
		$hit = $cache->get($key);
		if ($hit !== null) { $this->smwCacheHit($origin, 'query'); return $hit; }
		$result = $this->context->readSemantic($query, $origin);
		$cache->put($key, $result);
		return $result;
	}
	protected function smwCacheHit(string $origin, string $layer): void {
		if ($this->context instanceof SemanticProfilingContext) { $this->context->smwCacheHit($origin, $layer); }
	}
	protected function getArgs($frame = null): array { return $this->context->arguments(); }
	protected function detectLang(array $args): string { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Argument.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars(): array { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet(array $data) { $this->context->seoSet($data); }
	protected function smwSetSafe(array $data): void { $this->context->smwSet($data); }
}
