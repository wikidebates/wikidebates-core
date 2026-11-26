<?php
namespace MediaWiki\Extension\DebatePHP;

/** Argument renderer. This PHP source is authoritative and maintained directly.
 * Legacy Table boundaries remain until their native migration is complete.
 * See docs/native-php-migration-baseline.md for the behavioural contract.
 */
class ArgumentReferenceRenderer {
	use WarningLabels;

	private ?DecodedReferences $decodedReferences = null;
	private ?RenderData $renderData = null;
	private ?FifoCache $cardTitleCache = null;
	private ?FifoCache $nativeSemanticCache = null;
	private bool $nativeJson = false;
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
	protected $SMW_ASK_CACHE;
	protected $SMW_TITLES_CACHE;
	protected $URLENC_CACHE;
	protected $CARD_CACHE;
	protected array $TITLE_WARNING_BANNER_KEYS;
	protected array $ARGUMENT_WARNING_BANNER_KEYS;
	protected $SEO_CACHE;
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

	protected function fastParamsKey($params = null) {
		if ((!($params instanceof Table))) {
			return '';
		}
		$n = 0;
		foreach (Lua::pairs($params) as $_ => $_value) {
			$n = ($n + 1);
			if (($n > 2)) {
				return null;
			}
		}
		if ((($n === 1) && ($params[1] !== null))) {
			return ('1=' . Lua::str($params[1]));
		}
		if (($n === 2)) {
			[$k1, $k2] = [null, null];
			foreach (Lua::pairs($params) as $k => $_value) {
				if (!(Lua::truth($k1))) {
					$k1 = $k;
				}
				else {
					$k2 = $k;
				}
			}
			[$a, $b] = [Lua::str($k1), Lua::str($k2)];
			if (($a > $b)) {
				[$k1, $k2] = [$k2, $k1];
			}
			return (Lua::str($k1) . '=' . Lua::str($params[$k1]) . '&' . Lua::str($k2) . '=' . Lua::str($params[$k2]));
		}
		return null;
	}

	protected function escapeAttr($s = null) {
		return NativeText::attribute($s);
	}

	protected function nativeReferenceValues(string $format, ?string $raw): array {
		return ($this->decodedReferences ??= new DecodedReferences)->values($format, $raw);
	}

	protected function wkBuildNodesFromNativeItems(array $items, callable $builder): Table {
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
		return new Table($out);
	}

	protected function csvValues(?string $text, ?string $separator = null): array {
		return ($this->renderData ??= new RenderData)->csvValues($text, $separator);
	}

	protected function jsonNode(array $data): Table|array {
		return $this->nativeJson ? $data : new Table($data);
	}

	protected function splitCSV($s = null, $sep = null) {
		return ($this->renderData ??= new RenderData)->csv($s, $sep);
	}

	protected function splitByLiteral($s = null, $sep = null, $maxParts = null) {
		return SerializedData::split($s, $sep, $maxParts);
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

	protected function addDataLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null, $opts = null) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$opts = (Lua::truth($v15 = (Lua::truth($v16 = (($opts instanceof Table))) ? $opts : $v16)) ? $v15 : new Table([]));
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = $linktext;
		if ((($lt === null) || ($lt === ''))) {
			$lt = '&nbsp;';
		}
		$tt = NativeText::field($tooltip);
		$classes = 'wk-adddata-link navigation-not-searchable noprint';
		if (($opts['editButton'] === true)) {
			$classes = ($classes . ' bouton-modifier');
		}
		if (($tt !== '')) {
			return sprintf('<span class="%s" data-wk-tooltip="%s">[[%s|%s]]</span>', $classes, $this->escapeAttr($tt), $target, $lt);
		}
		return sprintf('<span class="%s">[[%s|%s]]</span>', $classes, $target, $lt);
	}

	protected function addDataEditLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null) {
		return $this->addDataLink($formName, $pageTitle, $linktext, $tooltip, new Table(['editButton' => true]));
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodePathSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = Lua::str($formName ?? '');
		if ($formName === '') { return ''; }
		$tooltip = $this->normalizeTooltipText(Lua::str($tooltip ?? ''));
		$query = $query instanceof Table ? $query : new Table;
		$href = Lua::str($this->context->localUrl($this->wkRunQueryPath($formName), $query));
		$attributes = new Table(['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav']);
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', '＋ ' . Lua::str($label ?? ''), $attributes, true);
	}

	protected function runQueryLink($opts = null) {
		$opts = (Lua::truth($v25 = $opts) ? $v25 : new Table([]));
		$formName = NativeText::field($opts['form']);
		$fieldName = NativeText::field($opts['field']);
		$value = NativeText::field($opts['value']);
		if (((($formName === '') || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = NativeText::field($opts['_title']);
		if (($title === '')) {
			$title = $this->wkRunQueryPath($formName);
		}
		$q = new Table([]);
		$qkey = NativeText::field($opts['_qkey']);
		if (($qkey === '')) {
			$qkey = ($formName . '[' . $fieldName . ']');
		}
		$q[$qkey] = $value;
		$extra = $opts['extra'];
		if ((($extra instanceof Table))) {
			foreach (Lua::pairs($extra) as $k => $v) {
				$kk = NativeText::field($k);
				if ((($kk !== '') && ($v !== null))) {
					$vv = Lua::str($v);
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
		$label = Lua::str((Lua::truth($v32 = $opts['label']) ? $v32 : $value));
		$tt = $this->normalizeTooltipText(NativeText::field($opts['tooltip']));
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

	protected function pfProp($PF = null, $k = null, $fallback = null) {
		$v = (Lua::truth($v34 = (Lua::truth($v35 = $PF) ? $PF[$k] : $v35)) ? $v34 : '');
		$v = trim(NativeText::field($v), NativeText::ASCII_WHITESPACE);
		if (($v !== '')) {
			return $v;
		}
		return (Lua::truth($v37 = $fallback) ? $v37 : '');
	}

	protected function smwPropRef($PF = null, $key = null) {
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

	protected function smwQueueAdd($pending = null, $key = null, $val = null, $multi = null) {
		if ((((!(Lua::truth($pending)) || !(Lua::truth($key))) || ($key === '')) || ($val === null))) {
			return null;
		}
		if ((is_string($val))) {
			$val = trim($val, NativeText::ASCII_WHITESPACE);
			if (($val === '')) {
				return null;
			}
		}
		$cur = $pending[$key];
		if (Lua::truth($multi)) {
			if (($cur === null)) {
				$pending[$key] = (Lua::truth($v38 = (Lua::truth($v39 = (($val instanceof Table))) ? $val : $v39)) ? $v38 : new Table([1 => $val]));
				return null;
			}
			if ((!($cur instanceof Table))) {
				$cur = new Table([1 => $cur]);
				$pending[$key] = $cur;
			}
			if ((($val instanceof Table))) {
				foreach (($val)->sequence() as $_ => $x) {
					if (($x !== null)) {
						$cur[(Lua::len($cur) + 1)] = $x;
					}
				}
			}
			else {
				$cur[(Lua::len($cur) + 1)] = $val;
			}
			return null;
		}
		$pending[$key] = $val;
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

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$opts = (Lua::truth($v48 = $opts) ? $v48 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
		$icon = NativeText::field($opts['icon']);
		$linkHtml = '';
		$title = '';
		$text = '';
		if ($isReorg) {
			$formName = NativeText::field($opts['form']);
			$tooltip = NativeText::field($opts['tooltip']);
			$linkLabel = NativeText::field($opts['link_label']);
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
		if (((Lua::truth($cats) && Lua::truth($cat)) && ($cat !== ''))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function wkParseBiblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('bibliography', $raw);
	}

	protected function wkParseWeblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('webliography', $raw);
	}

	protected function wkParseVideoMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('videography', $raw);
	}

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, &$out = null) {
		$warnsRef = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsRef === '')) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach ($this->csvValues($warnsRef, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
				if ($out instanceof Table) { Lua::insert($out, $fragment); }
				elseif ($fragment !== null) { $out[] = $fragment; }
			}
		}
	}

	protected function wkRenderOneBiblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$authors = $item['authors'];
		if (($authors === '')) {
			$authors = $PF['unknown_author'];
		}
		$out[] = $authors;
		{
			$k = (Lua::truth($v62 = (Lua::truth($v63 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v63)) ? $v62 : '');
			if (($k !== '')) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		$article = $item['article'];
		$work = $item['work'];
		$link = $item['link'];
		{
			if (Lua::truth($smwPending)) {
				$ka = $this->smwPropRef($PF, 'article_name');
				if ((($ka !== '') && ($article !== ''))) {
					$this->smwQueueAdd($smwPending, $ka, $article, true);
				}
				$kw = $this->smwPropRef($PF, 'work_name');
				if ((($kw !== '') && ($work !== ''))) {
					$this->smwQueueAdd($smwPending, $kw, $work, true);
				}
			}
		}
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
			{
				$kp = (Lua::truth($v72 = (Lua::truth($v73 = $smwPending) ? $this->smwPropRef($PF, 'publishing_house') : $v73)) ? $v72 : '');
				if (($kp !== '')) {
					$this->smwQueueAdd($smwPending, $kp, $publisher, true);
				}
			}
		}
		$place = $item['place'];
		if (($place !== '')) {
			$out[] = (', ' . $place);
			{
				$kp = (Lua::truth($v75 = (Lua::truth($v76 = $smwPending) ? $this->smwPropRef($PF, 'place_of_publication') : $v76)) ? $v75 : '');
				if (($kp !== '')) {
					$this->smwQueueAdd($smwPending, $kp, $place, true);
				}
			}
		}
		$date = $item['date'];
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderOneWeblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$date = $item['date'];
		$authors = $item['authors'];
		{
			$k = (Lua::truth($v83 = (Lua::truth($v84 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v84)) ? $v83 : '');
			if ((($k !== '') && ($authors !== ''))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
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
				$out[] = ((($authors !== '') ? ', ' : '') . (Lua::truth($v89 = (Lua::truth($v90 = ($page !== '')) ? $page : $v90)) ? $v89 : $site));
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

	protected function wkRenderOneVideoLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		{
			$k = (Lua::truth($v94 = (Lua::truth($v95 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v95)) ? $v94 : '');
			if ((($k !== '') && ($authors !== ''))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
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

	protected function wkRenderReferencesFromRaw($rawB = null, $rawW = null, $rawV = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		[$b, $w, $v] = [[], [], []];
		foreach ($this->nativeReferenceValues('bibliography', $rawB) as $_ => $it) {
			$b[] = $this->wkRenderOneBiblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach ($this->nativeReferenceValues('webliography', $rawW) as $_ => $it) {
			$w[] = $this->wkRenderOneWeblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach ($this->nativeReferenceValues('videography', $rawV) as $_ => $it) {
			$v[] = $this->wkRenderOneVideoLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		if ((((count($b) === 0) && (count($w) === 0)) && (count($v) === 0))) {
			return '';
		}
		$out = implode("\n", [implode("\n", $b), implode("\n", $w), implode("\n", $v)]);
		$out = trim($out, NativeText::ASCII_WHITESPACE);
		return $out;
	}


	protected function smwKeyFromArray($q = null) {
		return CacheKey::semantic($q);
	}

	protected function makeSMWCache(): ParentRelations {
		return new ParentRelations;
	}

	protected function renderTitleWarnings($pv = null, $cats = null, $lang = null) {
		$title = (Lua::truth($v106 = $pv['rawTitle']) ? $v106 : '');
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
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_very_short') . ']]'));
		}
		if (((substr($title, -1) === '.') && (substr($title, -4) !== 'etc.'))) {
			$out[count($out) + 1] = ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_ends_with_dot_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . '\'\'\'</div>');
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_ends_with_dot') . ']]'));
		}
		if (($lang === 'fr')) {
			$prefix3 = substr($title, 0, 3);
			$prefix4 = substr($title, 0, 4);
			$prefix5 = substr($title, 0, 5);
			$prefix6 = substr($title, 0, 6);
			$prefix10 = substr($title, 0, 10);
			$prefix13 = substr($title, 0, 13);
			if (($prefix3 === 'Ça')) {
				Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Cela"]]');
			}
			else {
				if (($prefix3 === 'Sa')) {
					Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Sa"]]');
				}
			}
			$map4 = new Table(['Ces' => 'Titres d\'arguments commençant par "Ces"', 'Cet' => 'Titres d\'arguments commençant par "Cet"', 'Ils' => 'Titres d\'arguments commençant par "Ils"', 'Ses' => 'Titres d\'arguments commençant par "Ses"', 'Son' => 'Titres d\'arguments commençant par "Son"']);
			if (Lua::truth($map4[$prefix4])) {
				Lua::insert($cats, ('[[Category:' . $map4[$prefix4] . ']]'));
			}
			$map5 = new Table(['Cela' => 'Titres d\'arguments commençant par "Cela"', 'Elle' => 'Titres d\'arguments commençant par "Elle"', 'Leur' => 'Titres d\'arguments commençant par "Leur"', 'Leurs' => 'Titres d\'arguments commençant par "Leurs"', 'Non,' => 'Titres d\'arguments commençant par "Non"', 'Nous' => 'Titres d\'arguments commençant par "Nous"', 'Oui,' => 'Titres d\'arguments commençant par "Oui"']);
			if (Lua::truth($map5[$prefix5])) {
				Lua::insert($cats, ('[[Category:' . $map5[$prefix5] . ']]'));
			}
			$map6 = new Table(['Cette' => 'Titres d\'arguments commençant par "Cette"', 'Elles' => 'Titres d\'arguments commençant par "Elles"']);
			if (Lua::truth($map6[$prefix6])) {
				Lua::insert($cats, ('[[Category:' . $map6[$prefix6] . ']]'));
			}
			$map10 = new Table(['C\'est faux' => 'Titres d\'arguments commençant par "C\'est faux"', 'Hors-sujet' => 'Titres d\'arguments commençant par "Hors-sujet"']);
			if (Lua::truth($map10[$prefix10])) {
				Lua::insert($cats, ('[[Category:' . $map10[$prefix10] . ']]'));
			}
			if (($prefix13 === 'Au contraire,')) {
				Lua::insert($cats, '[[Category:Titres d\'arguments commençant par "Au contraire"]]');
			}
		}
		return implode('', $out);
	}

	/** Retain the one-based envelope only at the remaining SMW/legacy boundary. */
	protected function uniqList(Table|array|null $list = null): Table {
		$values = $this->uniqueStrings($list instanceof Table ? $list->sequence() : ($list ?? []));
		return new Table($values === [] ? [] : array_combine(range(1, count($values)), $values));
	}

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
	private function semanticRowTitle(array $row): mixed {
		foreach (['fulltext', 'page', 1] as $key) {
			$value = $row[$key] ?? null;
			if ($value !== null && $value !== false) {
				return $value;
			}
		}
		return null;
	}

	protected function smwAskTitles(string $condition, string $origin): array {
		$query = new Table([1 => $condition, 2 => 'limit=200', 3 => 'link=none']);
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
		if (($s === '')) {
			return '';
		}
		$s = Lua::gsub($s, '#.*$', '');
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		if (($s === '')) {
			return '';
		}
		if (Lua::truth(Lua::find($s, '[%[%]{}|<>]'))) {
			return '';
		}
		return $s;
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
			if ($value instanceof Table) {
				$value = Table::unwrap($value);
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

	protected function ensureDebateParents($pv = null, ?ParentRelations $cache = null, $PF = null): void {
		if ($cache === null || $cache->proDebates !== null) {
			return;
		}
		[$cache->proDebates, $cache->conDebates] = $this->findParents(
			$this->smwSafeTitle($pv['rawTitle']),
			NativeText::field($PF['prop_arg_pro']), NativeText::field($PF['prop_arg_con']),
			'pro', 'con', 'debate-parents'
		);
	}

	protected function ensureArgumentParents($pv = null, ?ParentRelations $cache = null, $PF = null): void {
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
		$query = new Table([1 => '(' . $firstCondition . ' OR ' . $secondCondition . ' )',
			2 => '?' . $firstProperty . '=' . $firstAlias,
			3 => '?' . $secondProperty . '=' . $secondAlias, 4 => 'limit=200', 5 => 'link=none']);
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
		} elseif ($result->completeEmpty && $this->context instanceof NativeSemanticContext) {
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

	protected function ensureDebateParentsFinal($pv = null, ?ParentRelations $cache = null, $PF = null): void {
		if ($cache === null || $cache->allDebates !== null) {
			return;
		}
		$this->ensureDebateParents($pv, $cache, $PF);
		$final = [];
		foreach ([$cache->proDebates ?? [], $cache->conDebates ?? []] as $titles) {
			foreach ($titles as $title) {
				$final[] = $this->canonicalTitle($title);
			}
		}
		if ($this->hasSMW()) {
			$this->ensureArgumentParents($pv, $cache, $PF);
			$propBreadcrumb = NativeText::field($PF['prop_breadcrumb']);
			$propParentDebate = NativeText::field($PF['prop_parent_debate']);
			if ($propBreadcrumb !== '' && $propParentDebate !== '') {
				$parents = [];
				foreach ([$cache->justificationParents ?? [], $cache->objectionParents ?? []] as $titles) {
					foreach ($titles as $title) {
						$parents[] = $this->canonicalTitle($title);
					}
				}
				$conditions = [];
				foreach ($this->uniqueStrings($parents) as $title) {
					$safe = $this->smwSafeTitle($title);
					if ($safe !== '') {
						$conditions[] = '[[' . $safe . ']]';
					}
				}
				if ($conditions !== []) {
					$query = new Table([1 => '(' . implode(' OR ', $conditions) . ')',
						2 => '?' . $propBreadcrumb . '=chain', 3 => '?' . $propParentDebate . '=pdeb',
						4 => 'limit=200', 5 => 'link=none']);
					$result = $this->readSemantic($query, 'breadcrumb');
					foreach (SemanticReadResult::sequence($result->rows ?? []) as $row) {
						$chain = $row['chain'] ?? null;
						$valid = false;
						foreach (is_array($chain) ? SemanticReadResult::sequence($chain) : [$chain] as $part) {
							if (is_string($part)) {
								$length = $this->breadcrumbLenByteSafe($part);
								if ($length > 0 && $length < 6) {
									$valid = true;
									break;
								}
							}
						}
						if (!$valid) {
							continue;
						}
						$parent = $row['pdeb'] ?? null;
						if (is_string($parent)) {
							if (trim($parent, NativeText::ASCII_WHITESPACE) !== '') {
								$final[] = $this->canonicalTitle($parent);
							}
						} elseif (is_array($parent)) {
							foreach (SemanticReadResult::sequence($parent) as $value) {
								$title = is_array($value) ? $this->semanticRowTitle($value) : $value;
								if (is_string($title) && trim($title, NativeText::ASCII_WHITESPACE) !== '') {
									$final[] = $this->canonicalTitle($title);
								}
							}
						}
					}
				}
			}
		}
		$cache->allDebates = $this->uniqueParentTitles($final);
	}

	protected function normalizeTitleForCard($s = null) {
		$s = trim(Lua::str($s === null || $s === false ? '' : $s), " \t\r\n\v\f");
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
		$out = Lua::gsub($out, '%%20', '+');
		$this->cachePut($this->URLENC_CACHE, $s, $out);
		return $out;
	}

	protected function argumentMapCardLua($targetTitle = null, $currentArgTitle = null, $typeValue = null) {
		$t = $this->normalizeTitleForCard($targetTitle);
		$a = $this->normalizeTitleForCard($currentArgTitle);
		$p2 = $this->mwUrlEncodePF($a);
		$dataAttr = '';
		if ((Lua::truth($typeValue) && ($typeValue !== ''))) {
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

	protected function ensureDebateCards($pv = null, ?ParentRelations $cache = null, $lang = null, $PF = null) {
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

	protected function ensureArgumentCards($pv = null, ?ParentRelations $cache = null, $PF = null) {
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

	protected function renderMetaWarningHtml($params = null) {
		$color = NativeText::field($params['color']);
		$icon = NativeText::field($params['icon']);
		$title = NativeText::field($params['title']);
		$text = NativeText::field($params['text']);
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre>' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, $cats = null) {
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
		$html = $this->renderMetaWarningHtml(new Table(['color' => $color, 'icon' => $icon, 'title' => $title, 'text' => $text]));
		$cat = $this->bannerMsg($lang, ($key . '_category'));
		if (((Lua::truth($cats) && Lua::truth($cat)) && ($cat !== ''))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function renderBannersFromArgList($rawList = null, $lookup = null, $pv = null, $lang = null, $cats = null) {
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

	protected function renderDebateParentsBoxes($pv = null, $cats = null, $lang = null, ?ParentRelations $cache = null, $PF = null) {
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

	protected function renderArgumentParentsBoxes($pv = null, $cats = null, $lang = null, ?ParentRelations $cache = null, $PF = null) {
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

	protected function renderDebatesParentsBottom($pv = null, $lang = null, ?ParentRelations $cache = null, $PF = null) {
		if (!($this->hasSMW())) {
			return '';
		}
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$n = count($cache->breadcrumbDebates ?? []);
		if (($n === 0)) {
			return '<h2 id="Parent_debates" style="display:none;"></h2>';
		}
		$title = (Lua::truth($v177 = (Lua::truth($v178 = ($n === 1)) ? $PF['parent_debates_title_1'] : $v178)) ? $v177 : $PF['parent_debates_title_n']);
		$lis = [];
		foreach ($cache->allDebateCards ?? [] as $card) {
			$lis[] = ('<li style="position: relative;">' . $card . '</li>');
		}
		return ('<h2 id="Parent_debates" class="wk-icon-before wk-icon-parent">' . $title . '</h2>' . '<ul class="hover-top navigation-not-searchable">' . implode('', $lis) . '</ul>');
	}

	protected function renderInitializationBlock($args = null, $pv = null, $cats = null, $lang = null, ?ParentRelations $cache = null, $PF = null) {
		$hasParents = (((count($cache->breadcrumbDebates ?? []) > 0) || (count($cache->justificationParents ?? []) > 0)) || (count($cache->objectionParents ?? []) > 0));
		if ($hasParents) {
			return '';
		}
		$initRaw = $args['initialization'];
		$initRaw = (Lua::truth($v183 = (Lua::truth($v184 = (is_string($initRaw))) ? trim($initRaw, NativeText::ASCII_WHITESPACE) : $v184)) ? $v183 : '');
		$hasInit = ($initRaw !== '');
		$orphanBanner = '';
		$orphanBanner = (Lua::truth($v185 = $this->renderBannerByKey($pv, $lang, 'argument_orphan', $cats)) ? $v185 : '');
		if ($hasInit) {
			// Keep the same-day initialization grace period, using the stored revision.
			$publicationDate = $this->context->publicationDate();
			if ($publicationDate === null || substr($publicationDate, 0, 10) === $this->context->currentDate()) {
				return $orphanBanner;
			}
			Lua::insert($cats, ('[[Category:' . $PF['cat_uninitialized'] . ']]'));
			$parts = $this->splitByLiteral($initRaw, '@', 2);
			$initType = trim((Lua::truth($v187 = $parts[1]) ? $v187 : ''), NativeText::ASCII_WHITESPACE);
			$parentId = trim((Lua::truth($v188 = $parts[2]) ? $v188 : ''), NativeText::ASCII_WHITESPACE);
			$parentPage = '';
			{
				$parentId = NativeText::field($parentId);
				$parentId = trim($parentId, NativeText::ASCII_WHITESPACE);
				$parentId = (Lua::truth($v190 = Lua::match($parentId, '(%d+)')) ? $v190 : '');
				if ((((($parentId !== '') && Lua::truth(true)) && Lua::truth($this->context->smwAvailable())) && (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function'))) {
					$PF['prop_debate_number'] = $this->L($lang, 'props', 'debate_number');
					[$ok, $res] = Lua::pcall(function($query) { return $this->smwAskUncached($query, 'initialization'); }, new Table([1 => ('[[' . $PF['prop_debate_number'] . '::' . $parentId . ']] OR [[' . $PF['prop_arg_number'] . '::' . $parentId . ']]'), 2 => 'limit=1', 3 => 'link=none']));
					if (((Lua::truth($ok) && (($res instanceof Table))) && Lua::truth($res[1]))) {
						$row = $res[1];
						$ft = (Lua::truth($v191 = (Lua::truth($v192 = $row['fulltext']) ? $v192 : $row['page'])) ? $v191 : $row[1]);
						if ((is_string($ft))) {
							$ft = trim($ft, NativeText::ASCII_WHITESPACE);
							if (($ft !== '')) {
								$parentPage = $ft;
							}
						}
					}
				}
			}
			$parentPage = $this->canonicalTitle($parentPage);
			$title = $pv['rawTitle'];
			$t_arg_pro = $this->L($lang, 'forms', 'arg_pro');
			$t_arg_con = $this->L($lang, 'forms', 'arg_con');
			$t_justification = $this->L($lang, 'params', 'justification_type_label');
			$t_objection = $this->L($lang, 'params', 'objection_type_label');
			$formName = null;
			if (($initType === $t_arg_pro)) {
				$formName = $this->L($lang, 'forms', 'edit_args_pro');
			}
			else {
				if (($initType === $t_arg_con)) {
					$formName = $this->L($lang, 'forms', 'edit_args_con');
				}
				else {
					if (($initType === $t_justification)) {
						$formName = $this->L($lang, 'forms', 'justifications');
					}
					else {
						if (($initType === $t_objection)) {
							$formName = $this->L($lang, 'forms', 'objections');
						}
					}
				}
			}
			if ((((!(Lua::truth($formName)) || ($formName === '')) || !(Lua::truth($initType))) || ($initType === ''))) {
				return $orphanBanner;
			}
			$idx = 99;
			$prefix = ($initType . '[' . Lua::str($idx) . ']');
			$qsParts = new Table([1 => ($prefix . '[' . $this->L($lang, 'params', 'page') . ']=' . $title)]);
			$displayedTitleParam = trim($this->L($lang, 'params', 'displayed_title'), NativeText::ASCII_WHITESPACE);
			if (($displayedTitleParam !== '')) {
				Lua::insert($qsParts, ($prefix . '[' . $displayedTitleParam . ']=' . $title));
			}
			$qs = Lua::concat($qsParts, '&');
			$warnTitle = sprintf((Lua::truth($v194 = $PF['init_warning_title']) ? $v194 : '%s'), $formName);
			$warnIntro = $PF['init_warning_intro'];
			$yesText = (Lua::truth($v195 = (Lua::truth($v196 = $PF['init_yes_finish_label']) ? $v196 : $PF['edit'])) ? $v195 : '');
			$yesTooltip = sprintf((Lua::truth($v197 = $PF['init_yes_tt']) ? $v197 : '%s'), $parentPage);
			$okText = sprintf((Lua::truth($v198 = $PF['init_ok_text']) ? $v198 : '%s'), $parentPage);
			$summary = null;
			if (($initType === $t_arg_pro)) {
				$summary = $PF['init_summary_pro'];
			}
			else {
				if (($initType === $t_arg_con)) {
					$summary = $PF['init_summary_con'];
				}
				else {
					if (($initType === $t_justification)) {
						$summary = $PF['init_summary_justif'];
					}
					else {
						if (($initType === $t_objection)) {
							$summary = $PF['init_summary_obj'];
						}
						else {
							$summary = '';
						}
					}
				}
			}
			if (($summary !== '')) {
				$summary = ($summary . ' : « ' . $title . ' »');
			}
			$params = new Table(['form' => $formName, 'target' => $parentPage, 'query string' => $qs,
				'link text' => $yesText, 'link type' => 'button', 'tooltip' => $yesTooltip, 'ok text' => $okText]);
			if ($summary !== '') { $params['summary'] = $summary; }
			$params['reload'] = true;
			[$ok, $r] = Lua::pcall(function() use ($params) { return $this->context->autoEdit($params); });
			$auto = $ok && Lua::truth($r) ? $r : '';

			return ($orphanBanner . '<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $warnTitle . ' [[' . $parentPage . ']].' . '\'\'\'

' . $warnIntro . '
' . $auto . '</div><!-- Fin de warningbox -->');
		}
		return $orphanBanner;
	}

	protected function ensureBreadcrumbSelection($pv = null, ?ParentRelations $cache = null, $cats = null, $lang = null, $PF = null): void {
		if ($cache === null || $cache->breadcrumbChains !== null) {
			return;
		}
		$cache->breadcrumbChains = [];
		$cache->breadcrumbDebates = [];
		if (!$this->hasSMW()) {
			return;
		}
		$lang ??= 'fr';
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
		$result = $this->readSemantic(new Table($query), 'breadcrumb');
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
			Lua::insert($cats, '[[Category:' . $this->L($lang, 'categories', 'breadcrumb_too_long') . ']]');
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
			$argsVarParts[] = $page === $title ? $page : $page . $this->FIELD_SEP . $title;
			$hoverLines[] = '<div class="argument-title--map wk-icon-before wk-icon-argument">[[' . $page . '|' . $title . ']]</div>';
		}
		return [
			'items' => $items,
			'list' => implode("\n", $listLines),
			'argsVar' => implode($this->ITEM_SEP, $argsVarParts),
			'mapHover' => implode("\n", $hoverLines)
		];
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null): array {
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
				$fragment = $this->renderSubsectionBanner($pv, $lang, $key, null, null);
				if ($fragment !== null) { $out[] = $fragment; }
				$hasBanner = true;
			}
		}
		if (($hasBanner && Lua::truth($cats))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]'));
		}
		return $out;
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, $cats = null) {
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
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . implode('', $banners) . '</div>' . '</li>');
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$out = [];
		$out[count($out) + 1] = ('<div style="font-size: 95%; margin-top: 1em;">' . $PF['keywords_label']);
		$raw = $args['keywords'];
		if ((Lua::truth($raw) && ($raw !== ''))) {
			$extra = null;
			if ((($PF['search_type_field'] !== '') && ($PF['search_type_value'] !== ''))) {
				$extra = new Table([$PF['search_type_field'] => $PF['search_type_value']]);
			}
			$rq_title = $this->wkRunQueryPath($PF['form_search_by_keywords']);
			$rq_qkey = ($PF['form_search_by_keywords'] . '[' . $PF['keywords_field'] . ']');
			$rendered = [];
			foreach ($this->csvValues($raw, ',') as $_ => $k) {
				$k = trim(NativeText::field($k), NativeText::ASCII_WHITESPACE);
				if (($k !== '')) {
					$chip = $this->runQueryLink(new Table(['form' => $PF['form_search_by_keywords'], 'field' => $PF['keywords_field'], 'value' => $k, 'label' => $k, 'tooltip' => $PF['search_by_keyword_tt'], 'extra' => $extra, '_title' => $rq_title, '_qkey' => $rq_qkey]));
					if ((Lua::truth($chip) && ($chip !== ''))) {
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
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
			}
		}
		else {
			$out[count($out) + 1] = ('<strong>' . $PF['none'] . '</strong>');
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
		}
		$out[count($out) + 1] = ($this->addDataEditLink($PF['form_edit_keywords'], $pv['rawTitle'], '&nbsp;', $PF['edit_keywords_tooltip']) . '<span style="display: none;">.</span></div>');
		return implode('', $out);
	}

	protected function renderSummary($args = null, $pv = null, $cats = null, $lang = null, $PF = null, $summaryId = null) {
		$edit = $this->addDataEditLink($PF['form_edit_summary'], $pv['rawTitle'], '&nbsp;', $PF['edit_summary_tooltip']);
		$out = [];
		$out[] = ('<h2' . $summaryId . ' class="section-modifiable wk-icon-before wk-icon-summary">' . $PF['summary'] . $edit . '</h2>');
		{
			$raw = $args['summary-warnings'];
			$raw = (Lua::truth($v229 = (Lua::truth($v230 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v230)) ? $v229 : '');
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
		if ((Lua::truth($args['summary']) && ($args['summary'] !== ''))) {
			$out[] = ('<div class=\'argument-summary\'>
' . $args['summary'] . '</div>');
		}
		else {
			$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_summary'] . '</div>');
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_summary'] . ']]'));
		}
		return implode('', $out);
	}

	protected function renderQuotes($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$edit = $this->addDataEditLink($PF['form_edit_quotes'], $pv['rawTitle'], '&nbsp;', $PF['edit_quotes_tooltip']);
		$out = [];
		$idAttr = '';
		$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-quote"' . $idAttr . '>' . $PF['quotes'] . $edit . '</h2>');
		$q = $args['quotes'];
		if ((Lua::truth($q) && ($q !== ''))) {
			$out[] = $q;
		}
		else {
			$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_quotes'] . '</div>');
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_quotes'] . ']]'));
		}
		return implode('', $out);
	}

	protected function renderDebateDetailed($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		if ((!(Lua::truth($args['dedicated-debate'])) || ($args['dedicated-debate'] === ''))) {
			return '';
		}
		Lua::insert($cats, ('[[Category:' . $PF['cat_has_detailed_debate'] . ']]'));
		$contentToTransfer = (NativeText::field($args['justifications']) . NativeText::field($args['objections']) . NativeText::field($args['bibliography']) . NativeText::field($args['webliography']) . NativeText::field($args['videography']));
		if ((trim($contentToTransfer, NativeText::ASCII_WHITESPACE) !== '')) {
			Lua::insert($cats, ('[[Category:' . $PF['cat_content_to_transfer'] . ']]'));
		}
		$edit = $this->addDataEditLink($PF['form_edit_detailed_debate'], $pv['rawTitle'], '&nbsp;', sprintf($PF['edit_detailed_debate_tooltip'], $pv['rawTitle']));
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-details">' . $PF['detailed_debate'] . $edit . '</h2>');
		$debat = $this->canonicalTitle($args['dedicated-debate']);
		$mapHtml = '';
		if (((($this->hasSMW() && (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function')) && Lua::truth($PF['prop_argument_map'])) && ($PF['prop_argument_map'] !== ''))) {
			$debatSafe = $this->smwSafeTitle($debat);
			if (($debatSafe !== '')) {
				$semantic = $this->readSemantic(new Table([1 => ('[[' . $debatSafe . ']]'), 2 => ('?' . $PF['prop_argument_map'] . '=map'), 3 => 'limit=1', 4 => 'link=none']), 'cards');
				$res = $semantic->rows;
				if ((((is_array($res)) && Lua::truth(($res[1] ?? null))) && Lua::truth((($res[1] ?? null)['map'] ?? null)))) {
					$v = (($res[1] ?? null)['map'] ?? null);
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
										$ft = (Lua::truth($v236 = (Lua::truth($v237 = ($x['fulltext'] ?? null)) ? $v237 : ($x['page'] ?? null))) ? $v236 : ($x[1] ?? null));
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
		if (Lua::truth(Lua::find($s, '[%[%]{}|#<>]'))) {
			return '';
		}
		$s = Lua::gsub($s, '%s+', ' ');
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		return $s;
	}

	protected function renderRubriques($args = null, $cats = null, $lang = null, $PF = null) {
		if ((Lua::truth($args['sections']) && ($args['sections'] !== ''))) {
			$out = [];
			$nOk = 0;
			foreach ($this->csvValues($args['sections'], ',') as $_ => $r) {
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

	protected function wkParseQuoteMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('quotes', $raw);
	}

	protected function wkRenderOneQuoteHtml($item = null, $idx = null, $pv = null, $lang = null, $cats = null, $PF = null) {
		$item = $item instanceof Table ? $item->values : $item;
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
		$this->vset('WD_QUOTES_MAX', Lua::str($idx));
		if (($authors !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_AUTHORS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_AUTHORS', (Lua::truth($v255 = (Lua::truth($v256 = ($cur === '')) ? $authors : $v256)) ? $v255 : ($cur . ',' . $authors)));
		}
		if (($article !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_ARTICLES') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_ARTICLES', (Lua::truth($v258 = (Lua::truth($v259 = ($cur === '')) ? $article : $v259)) ? $v258 : ($cur . ',' . $article)));
		}
		if (($work !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_WORKS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_WORKS', (Lua::truth($v261 = (Lua::truth($v262 = ($cur === '')) ? $work : $v262)) ? $v261 : ($cur . ',' . $work)));
		}
		if (($publisher !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_PUBLISHERS') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_PUBLISHERS', (Lua::truth($v264 = (Lua::truth($v265 = ($cur === '')) ? $publisher : $v265)) ? $v264 : ($cur . ',' . $publisher)));
		}
		if (($place !== '')) {
			$cur = trim(($this->vget('WD_QUOTES_PLACES') ?? ''), NativeText::ASCII_WHITESPACE);
			$this->vset('WD_QUOTES_PLACES', (Lua::truth($v267 = (Lua::truth($v268 = ($cur === '')) ? $place : $v268)) ? $v267 : ($cur . ',' . $place)));
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
		$bq = ('<blockquote data-wk-cite="' . Lua::str($idx) . '">' . $warnHtml . '« ' . $quote . ' »' . '</blockquote>');
		$ref = [];
		$ref[] = ('<div class="reference-citation" data-wk-cite="' . Lua::str($idx) . '">');
		if (($authors !== '')) {
			$ref[] = $authors;
		}
		else {
			$ref[] = $PF['unknown_author'];
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_authors'] . ']]'));
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
			$this->vset(('WD_QUOTE_' . Lua::str($idx)), $html);
		}
		return $html;
	}

	protected function wkRenderQuotesFromSerialized($raw = null, $pv = null, $lang = null, $cats = null, $PF = null) {
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

	protected function setSemanticData($args = null, $pv = null, $lang = null, ?ParentRelations $cache = null, $PF = null, $maps = null, $rawB = null, $rawW = null, $rawV = null) {
		$props = new Table([]);
		$props[$PF['prop_arg_name']] = $pv['rawTitle'];
		$props[$PF['prop_arg_number']] = $pv['pageId'];
		if ($this->hasSMW()) {
			$this->ensureDebateParentsFinal($pv, $cache, $PF);
			$this->ensureArgumentParents($pv, $cache, $PF);
			{
				$chains = $this->uniqList(($cache->breadcrumbChains ?? []));
				if ((((Lua::len($chains) > 0) && Lua::truth($PF['prop_breadcrumb'])) && ($PF['prop_breadcrumb'] !== ''))) {
					$props[$PF['prop_breadcrumb']] = $chains;
				}
				if ((Lua::truth($PF['prop_parent_debate']) && ($PF['prop_parent_debate'] !== ''))) {
					$dp = $this->uniqList(($cache->breadcrumbDebates ?? []));
					if ((Lua::len($dp) > 0)) {
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
				$parents = $this->uniqList($parents);
				if ((((Lua::len($parents) > 0) && Lua::truth($PF['prop_parent_argument'])) && ($PF['prop_parent_argument'] !== ''))) {
					$props[$PF['prop_parent_argument']] = $parents;
				}
			}
		}
		$justif = $maps['justif'] ?? [];
		$obj = $maps['obj'] ?? [];
		{
			$lst = new Table([]);
			foreach ($justif['items'] ?? [] as $it) {
				$t = $this->canonicalTitle($it['page']);
				if (($t !== '')) {
					Lua::insert($lst, $t);
				}
			}
			$lst = $this->uniqList($lst);
			if ((((Lua::len($lst) > 0) && Lua::truth($PF['prop_justif'])) && ($PF['prop_justif'] !== ''))) {
				$props[$PF['prop_justif']] = $lst;
			}
		}
		{
			$lst = new Table([]);
			foreach ($obj['items'] ?? [] as $it) {
				$t = $this->canonicalTitle($it['page']);
				if (($t !== '')) {
					Lua::insert($lst, $t);
				}
			}
			$lst = $this->uniqList($lst);
			if ((((Lua::len($lst) > 0) && Lua::truth($PF['prop_obj'])) && ($PF['prop_obj'] !== ''))) {
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
		if ((Lua::truth($PF['prop_argument_map']) && ($PF['prop_argument_map'] !== ''))) {
			$props[$PF['prop_argument_map']] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $PF['justifications'] . '</div>' . '<div class="wk-arg-map__td">' . $justifSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $PF['objections'] . '</div>' . '<div class="wk-arg-map__td">' . $objSurvol . '</div>' . '</div>' . '</div>');
		}
		{
			$lst = trim($justif['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
			if (((($lst !== '') && Lua::truth($PF['prop_justif_list'])) && ($PF['prop_justif_list'] !== ''))) {
				$props[$PF['prop_justif_list']] = $lst;
			}
		}
		{
			$lst = trim($obj['argsVar'] ?? '', NativeText::ASCII_WHITESPACE);
			if (((($lst !== '') && Lua::truth($PF['prop_obj_list'])) && ($PF['prop_obj_list'] !== ''))) {
				$props[$PF['prop_obj_list']] = $lst;
			}
		}
		$mc = $args['keywords'];
		if ((Lua::truth($mc) && ($mc !== ''))) {
			$list = $this->splitCSV($mc, ',');
			if ((Lua::len($list) > 0)) {
				$props[$PF['prop_keyword']] = $list;
			}
		}
		if ((Lua::truth($args['dedicated-debate']) && ($args['dedicated-debate'] !== ''))) {
			$props[$PF['prop_detailed_debate']] = $this->canonicalTitle($args['dedicated-debate']);
		}
		{
			$uniqCSVVar = function($varName = null) {
		$raw = ($this->vget($varName) ?? '');
		$raw = trim($raw, NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return null;
		}
		$lst = $this->splitCSV($raw, ',');
		$lst = $this->uniqList($lst);
		return (Lua::truth($v285 = (Lua::truth($v286 = (Lua::len($lst) > 0)) ? $lst : $v286)) ? $v285 : null);
		};
			$authors = $uniqCSVVar('WD_QUOTES_AUTHORS');
			if (Lua::truth($authors)) {
				$k = $this->smwPropRef($PF, 'authors');
				if (($k !== '')) {
					$this->smwQueueAdd($props, $k, $authors, true);
				}
			}
			$articles = $uniqCSVVar('WD_QUOTES_ARTICLES');
			if (Lua::truth($articles)) {
				$k = $this->smwPropRef($PF, 'article_name');
				if (($k !== '')) {
					$this->smwQueueAdd($props, $k, $articles, true);
				}
			}
			$works = $uniqCSVVar('WD_QUOTES_WORKS');
			if (Lua::truth($works)) {
				$k = $this->smwPropRef($PF, 'work_name');
				if (($k !== '')) {
					$this->smwQueueAdd($props, $k, $works, true);
				}
			}
			$pubs = $uniqCSVVar('WD_QUOTES_PUBLISHERS');
			if (Lua::truth($pubs)) {
				$k = $this->smwPropRef($PF, 'publishing_house');
				if (($k !== '')) {
					$this->smwQueueAdd($props, $k, $pubs, true);
				}
			}
			$places = $uniqCSVVar('WD_QUOTES_PLACES');
			if (Lua::truth($places)) {
				$k = $this->smwPropRef($PF, 'place_of_publication');
				if (($k !== '')) {
					$this->smwQueueAdd($props, $k, $places, true);
				}
			}
		}
		{
			if ((Lua::truth($args['sections']) && ($args['sections'] !== ''))) {
				$lst = new Table([]);
				foreach ($this->csvValues($args['sections'], ',') as $_ => $r) {
					$r = $this->safeRubriqueValue($r);
					if (($r !== '')) {
						Lua::insert($lst, $r);
					}
				}
				$lst = $this->uniqList($lst);
				if ((((Lua::len($lst) > 0) && Lua::truth($PF['prop_section'])) && ($PF['prop_section'] !== ''))) {
					$props[$PF['prop_section']] = $lst;
				}
			}
		}
		$this->smwSetSafe($props);
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return $this->context->message('Argument.seo', $lang, $key, ...$rest);
	}

	protected function seoJoinList($items = null) {
		$out = [];
		$seen = [];
		foreach (((Lua::truth($v288 = $items) ? $v288 : new Table([])))->sequence() as $_ => $v) {
			$v = trim(NativeText::field($v), NativeText::ASCII_WHITESPACE);
			if ((($v !== '') && !isset($seen[$v]))) {
				$seen[$v] = true;
				$out[count($out) + 1] = $v;
			}
		}
		return implode(', ', $out);
	}

	protected function buildSeoKeywords($args = null, $lang = null) {
		$items = [];
		foreach ($this->csvValues($args['keywords'], ',') as $_ => $k) {
			$items[count($items) + 1] = $k;
		}
		foreach ($this->csvValues($args['sections'], ',') as $_ => $s) {
			$items[count($items) + 1] = $s;
		}
		return $this->seoJoinList(new Table($items));
	}

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$titleText = (Lua::truth($v290 = $pv['rawTitle']) ? $v290 : '');
		$ck = ('SEO
' . NativeText::field($lang) . '
' . NativeText::field($pv['pageId']) . '
' . $titleText . '
' . $this->seoKeyPart((Lua::truth($v293 = $args['keywords']) ? $v293 : '')) . '
' . $this->seoKeyPart((Lua::truth($v294 = $args['sections']) ? $v294 : '')));
		$hit = $this->cacheGet($this->SEO_CACHE, $ck);
		if (($hit !== null)) {
			return '';
		}
		$this->cachePut($this->SEO_CACHE, $ck, true);
		$topic = trim(NativeText::field($args['keywords']), NativeText::ASCII_WHITESPACE);
		if (($topic === '')) {
			$topic = $titleText;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain')), 'title_separator' => sprintf($this->seoL($lang, 'title_separator'), $titleText), 'description' => sprintf($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'author' => $this->seoL($lang, 'author'), 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'type' => 'article', 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
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
		if (($titleText === '')) {
			return '';
		}
		[$ok, $u] = Lua::pcall([$this->context, 'fullUrl'], $titleText);
		if ((Lua::truth($ok) && Lua::truth($u))) {
			return Lua::str($u);
		}
		return '';
	}

	protected function wkBuildWebsiteNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return $this->jsonNode(['@type' => 'WebSite', '@id' => ($homeUrl . '#website'), 'url' => $homeUrl, 'name' => $siteName]);
	}

	protected function wkBuildOrganizationNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return $this->jsonNode(['@type' => 'Organization', '@id' => ($homeUrl . '#organization'), 'name' => $siteName, 'url' => $homeUrl]);
	}

	protected function wkBuildBreadcrumbNode($pv = null, $lang = null) {
		$homeLabel = $this->L($lang, 'urls', 'home_label');
		$homeUrl = $this->L($lang, 'urls', 'home');
		$argsLabel = $this->L($lang, 'urls', 'arguments_label');
		$argsUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'arguments_label');
		return $this->jsonNode(['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => new Table([1 => $this->jsonNode(['@type' => 'ListItem', 'position' => 1, 'item' => $this->jsonNode(['@id' => $homeUrl, 'name' => $homeLabel])]), 2 => $this->jsonNode(['@type' => 'ListItem', 'position' => 2, 'item' => $this->jsonNode(['@id' => $argsUrl, 'name' => $argsLabel])]), 3 => $this->jsonNode(['@type' => 'ListItem', 'position' => 3, 'item' => $this->jsonNode(['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']])])])]);
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = [];
		$mentions = [];
		$rawKeywords = $this->csvValues((Lua::truth($v299 = (Lua::truth($v300 = $args) ? $args['keywords'] : $v300)) ? $v299 : ''), ',');
		$stop3 = count($rawKeywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = trim(NativeText::field($rawKeywords[$i]), NativeText::ASCII_WHITESPACE);
			if (($kw !== '')) {
				$node = $this->jsonNode(['@type' => 'Thing', 'name' => $kw]);
				if (($i <= 3)) {
					$about[count($about) + 1] = $node;
				}
				else {
					$mentions[count($mentions) + 1] = $node;
				}
			}
		}
		return [new Table($about), new Table($mentions)];
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = [];
		$sections = $this->csvValues((Lua::truth($v302 = (Lua::truth($v303 = $args) ? $args['sections'] : $v303)) ? $v302 : ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = count($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (($i > 4)) {
				break;
			}
			$section = trim(NativeText::field($sections[$i]), NativeText::ASCII_WHITESPACE);
			if (($section !== '')) {
				$out[count($out) + 1] = $this->jsonNode(['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => $this->jsonNode(['@id' => $termSetId])]);
			}
		}
		return new Table($out);
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}

	protected function wkAppendAllValidIds($out = null, $titles = null, $suffix = null, $lang = null) {
		$added = false;
		foreach ($titles ?? [] as $title) {
			$title = $this->canonicalTitle(trim(NativeText::field($title), NativeText::ASCII_WHITESPACE));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => ($url . $suffix)]);
					$added = true;
				}
			}
		}
		return $added;
	}

	protected function wkAppendFirstValidId($out = null, $titles = null, $suffix = null, $lang = null) {
		foreach ($titles ?? [] as $title) {
			$title = $this->canonicalTitle(trim(NativeText::field($title), NativeText::ASCII_WHITESPACE));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => ($url . $suffix)]);
					return true;
				}
			}
		}
		return false;
	}

	protected function wkBuildClaimIsPartOf($lang = null, ?ParentRelations $cache = null) {
		$out = new Table([1 => $this->jsonNode(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'arguments_label')])]);
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
		$authors = $this->splitCSV($raw, ',');
		if ((Lua::len($authors) === 0)) {
			return null;
		}
		if ((Lua::len($authors) === 1)) {
			return $this->jsonNode(['@type' => 'Person', 'name' => $authors[1]]);
		}
		$out = [];
		foreach (($authors)->sequence() as $_ => $one) {
			$one = trim(NativeText::field($one), NativeText::ASCII_WHITESPACE);
			if (($one !== '')) {
				$out[count($out) + 1] = $this->jsonNode(['@type' => 'Person', 'name' => $one]);
			}
		}
		if ((count($out) === 0)) {
			return null;
		}
		return new Table($out);
	}

	protected function wkBuildClaimCitationsFromQuotes($raw = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return new Table([]);
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
			$node = $this->jsonNode(['@type' => 'Article', 'name' => $article, 'isPartOf' => $this->jsonNode(['@type' => 'Periodical', 'name' => $work])]);
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = $this->jsonNode(['@type' => 'Book', 'name' => $work]);
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
					$node = $this->jsonNode(['@type' => 'WebPage', 'name' => $article]);
					if (($work !== '')) {
						$node['isPartOf'] = $this->jsonNode(['@type' => 'WebSite', 'name' => $work]);
					}
				}
			}
		}
		if ($node === null) {
			if ($article === '' && $work === '') {
				return null;
			}
			$node = $this->jsonNode(['@type' => 'CreativeWork', 'name' => $article !== '' ? $article : $work]);
			if ($article !== '' && $work !== '') {
				$node['isPartOf'] = $this->jsonNode(['@type' => 'CreativeWork', 'name' => $work]);
			}
			// Preserve ambiguous volume/number as literal identifiers.
			$identifiers = [];
			foreach (['volume' => $volume, 'number' => $number] as $key => $value) {
				if ($value !== '') {
					$identifiers[count($identifiers) + 1] = $this->jsonNode([
						'@type' => 'PropertyValue', 'propertyID' => $key, 'value' => $value
					]);
				}
			}
			if ($identifiers !== []) {
				$node['identifier'] = new Table($identifiers);
			}
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		if (($publisher !== '')) {
			$node['publisher'] = $this->jsonNode(['@type' => 'Organization', 'name' => $publisher]);
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
			return new Table([]);
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
			$node = $this->jsonNode(['@type' => 'Article', 'name' => $article, 'isPartOf' => $this->jsonNode(['@type' => 'Periodical', 'name' => $work])]);
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = $this->jsonNode(['@type' => 'Book', 'name' => $work]);
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
					$node = $this->jsonNode(['@type' => 'WebPage', 'name' => $article]);
					if (($work !== '')) {
						$node['isPartOf'] = $this->jsonNode(['@type' => 'WebSite', 'name' => $work]);
					}
				}
			}
		}
		if ($node === null) {
			if ($article === '' && $work === '') {
				return null;
			}
			$node = $this->jsonNode(['@type' => 'CreativeWork', 'name' => $article !== '' ? $article : $work]);
			if ($article !== '' && $work !== '') {
				$node['isPartOf'] = $this->jsonNode(['@type' => 'CreativeWork', 'name' => $work]);
			}
			// Preserve ambiguous volume/number as literal identifiers.
			$identifiers = [];
			foreach (['volume' => $volume, 'number' => $number] as $key => $value) {
				if ($value !== '') {
					$identifiers[count($identifiers) + 1] = $this->jsonNode([
						'@type' => 'PropertyValue', 'propertyID' => $key, 'value' => $value
					]);
				}
			}
			if ($identifiers !== []) {
				$node['identifier'] = new Table($identifiers);
			}
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		if (($publisher !== '')) {
			$node['publisher'] = $this->jsonNode(['@type' => 'Organization', 'name' => $publisher]);
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
			return new Table([]);
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
		$node = $this->jsonNode(['@type' => 'WebPage', 'name' => $page !== '' ? $page : $site]);
		if ($link !== '') {
			$node['url'] = $link;
		}
		if ($page !== '' && $site !== '') {
			$node['isPartOf'] = $this->jsonNode(['@type' => 'WebSite', 'name' => $site]);
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
			return new Table([]);
		}
		$items = $this->nativeReferenceValues('videography', $raw);
		return $this->wkBuildNodesFromNativeItems($items, function($item = null) {
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		if ($title === '') {
			return null;
		}
		$node = $this->jsonNode(['@type' => 'VideoObject', 'name' => $title]);
		if ($link !== '') {
			$node['url'] = $link;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		return $node;
		});
	}


	protected function renderArgumentSchemaJSONLD($args = null, $pv = null, $lang = null, ?ParentRelations $cache = null, $PF = null, $maps = null) {
		$previousNativeJson = $this->nativeJson;
		$this->nativeJson = $this->context instanceof NativeJsonContext;
		try {
			$this->ensureDebateParentsFinal($pv, $cache, $PF);
			$this->ensureArgumentParents($pv, $cache, $PF);
			$this->ensureBreadcrumbSelection($pv, $cache, new Table([]), $lang, $PF);
			$websiteNode = $this->wkBuildWebsiteNode($lang);
			$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
			$organizationNode = $this->wkBuildOrganizationNode($lang);
			[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
			$keywords = $this->wkBuildKeywords($args, $lang);
			$isPartOf = $this->wkBuildClaimIsPartOf($lang, $cache);
			$parts = SchemaData::argumentParts($args, $maps, $pv['pageUrl'], $lang,
				fn(string $title): string => $this->wkFullUrl($this->canonicalTitle($title)), $this->context);
			$citations = new Table;
			foreach ([
				'wkBuildClaimCitationsFromQuotes' => '_quotes_wikitext',
				'wkBuildClaimCitationsFromBibliography' => 'bibliography',
				'wkBuildClaimCitationsFromWebliography' => 'webliography',
				'wkBuildClaimCitationsFromVideography' => 'videography'
			] as $builder => $field) {
				foreach ($this->$builder((string)($args[$field] ?? ''))->sequence() as $node) {
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
			$pageNode['author'] = $this->jsonNode(['@type' => 'Organization', 'name' => $credit]);
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
			if ((Lua::truth($args['established-name']) && (trim($args['established-name'], NativeText::ASCII_WHITESPACE) !== ''))) {
				$claimNode['alternateName'] = $args['established-name'];
			}
			{
				$summary = NativeText::seoPlainText((string)($args['summary'] ?? ''), $this->context);
				if (($summary !== '')) {
					$claimNode['abstract'] = $summary;
				}
			}
			if ((Lua::len($about) > 0)) {
				$claimNode['about'] = $about;
			}
			if ((Lua::len($mentions) > 0)) {
				$claimNode['mentions'] = $mentions;
			}
			if ((Lua::len($keywords) > 0)) {
				$claimNode['keywords'] = $keywords;
			}
			if ((Lua::len($citations) > 0)) {
				$claimNode['citation'] = $citations;
			}
			$data = (['@context' => 'https://schema.org', '@graph' => ([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode, 5 => $claimNode])]);
			if ($this->context instanceof NativeJsonContext) { return $this->context->jsonLdData($data); }
			$json = $this->safeJsonEncode(Table::wrap($data));
			return $this->context->jsonLd($json);
		} finally { $this->nativeJson = $previousNativeJson; }
	}

	protected function canonicalTitle($s = null) {
		$s = trim(NativeText::field($s), NativeText::ASCII_WHITESPACE);
		if (($s === '')) {
			return '';
		}
		$s = Lua::gsub($s, '^:%s*', '');
		$s = Lua::gsub(Lua::gsub($s, '^%[%[%s*', ''), '%s*%]%]$', '');
		$s = (Lua::truth($v365 = Lua::match($s, '^([^%]|]+)%|')) ? $v365 : $s);
		$s = trim($s, NativeText::ASCII_WHITESPACE);
		$t = $this->titleNewCached($s);
		return (Lua::truth($v366 = (Lua::truth($v367 = $t) ? $t['prefixedText'] : $v367)) ? $v366 : $s);
	}

	protected function computeArgumentMapsFromData($args = null): array {
		$justifications = $this->wkExtractItemsFromSerializedData(NativeText::field($args['justifications']));
		$objections = $this->wkExtractItemsFromSerializedData(NativeText::field($args['objections']));
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
		$this->SMW_ASK_CACHE = $this->cacheMake(250);
		$this->SMW_TITLES_CACHE = $this->cacheMake(250);
		$this->SEO_CACHE = $this->cacheMake(60);
		$args = $this->getArgs($frame);
		$norm = static function($key) use ($args): void {
			if (!is_string($args[$key])) { return; }
			$value = trim($args[$key], NativeText::ASCII_WHITESPACE);
			$args[$key] = $value === '' ? null : $value;
		};
		$norm('summary');
		$norm('quotes');
		$norm('bibliography');
		$norm('webliography');
		$norm('videography');
		$norm('keywords');
		$norm('sections');
		$norm('interlanguage');
		$norm('dedicated-debate');
		$norm('title-warnings');
		$norm('argument-warnings');
		$norm('summary-warnings');
		$norm('justification-warnings');
		$norm('objection-warnings');
		$rawB = (Lua::truth($v370 = $args['bibliography']) ? $v370 : '');
		$rawW = (Lua::truth($v371 = $args['webliography']) ? $v371 : '');
		$rawV = (Lua::truth($v372 = $args['videography']) ? $v372 : '');
		if ((!is_string($args['justifications']))) {
			$args['justifications'] = null;
		}
		if ((!is_string($args['objections']))) {
			$args['objections'] = null;
		}
		$this->renderData = new RenderData;
		$this->renderData->expectReuse([$args['keywords'], $args['sections']]);
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		$pv['lang'] = $lang;
		$cache = $this->makeSMWCache();
		[$pieces, $cats] = [[], new Table([])];
		$PF = new Table([]);
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
			$q = $args['quotes'];
			if (((is_string($q)) && ($q !== ''))) {
				[$ok, $r] = Lua::pcall(function($text) { return $this->context->expandQuotes($text); }, $q);
				if ((Lua::truth($ok) && (is_string($r)))) {
					$args['_quotes_wikitext'] = $r;
					$args['quotes'] = $this->wkRenderQuotesFromSerialized($r, $pv, $lang, $cats, $PF);
				}
				else {
					$args['_quotes_wikitext'] = '';
					$args['quotes'] = '';
				}
			}
		}
		$hasResume = ($args['summary'] !== null);
		$hasQuotes = ($args['quotes'] !== null);
		$hasRefs = ((($args['bibliography'] !== null) || ($args['webliography'] !== null)) || ($args['videography'] !== null));
		if (Lua::truth($args['established-name'])) {
			$pieces[] = $this->context->shortDescription($args['established-name']);
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'has_alt_name') . ']]'));
		}
		Lua::insert($cats, ('[[Category:' . $PF['cat_arguments'] . ']]'));
		$pieces[] = $this->renderTitleWarnings($pv, $cats, $lang);
		{
			$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = [];
			if (Lua::truth($args['title-warnings'])) {
				$out[] = $this->renderBannersFromArgList($args['title-warnings'], $titleLookup, $pv, $lang, $cats);
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
		if (Lua::truth($args['argument-warnings'])) {
			$argLookup = $this->buildBannerLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
			$out = [];
			$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], '', $this->L($lang, 'text', 'argument_banners_edit_tt'), new Table(['editButton' => true]));
			$out[] = ('<h2 id="Summary" class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>');
			$out[] = $this->renderBannersFromArgList($args['argument-warnings'], $argLookup, $pv, $lang, $cats);
			$pieces[] = implode('', $out);
		}
		$summaryId = ' id="Summary"';
		if ((Lua::truth($args['argument-warnings']) && (Lua::str($args['argument-warnings']) !== ''))) {
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
				$refsHtml = $this->wkRenderReferencesFromRaw($rawB, $rawW, $rawV, $pv, $lang, $cats, $PF, null, $args);
				if (($refsHtml !== '')) {
					$out[] = ('<ul class="references-argument">' . $refsHtml . '</ul>');
				}
				else {
					$out[] = ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_references'] . '</div>');
					Lua::insert($cats, ('[[Category:' . $PF['cat_no_references'] . ']]'));
				}
			}
			$justifItems = $maps['justif']['items'];
			$objItems = $maps['obj']['items'];
			$editJust = $this->addDataEditLink($PF['form_edit_justifications'], $pv['rawTitle'], '&nbsp;', $PF['edit_justifications_tooltip']);
			$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-argument is-pro">' . $PF['justifications'] . $editJust . '</h2>');
			{
				$raw = $args['justification-warnings'];
				$raw = (Lua::truth($v379 = (Lua::truth($v380 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v380)) ? $v379 : '');
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
					foreach ($this->csvValues($raw, ',') as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							$out[] = $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_justifications'], 'tooltip' => $PF['edit_justifications_tooltip']]), $cats);
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
				$out[] = Lua::str($ulJ);
			}
			else {
				$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_justifications'] . '</div>');
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_justifications'] . ']]'));
			}
			{
				$baseField = NativeText::field($PF['arg_new_title_field_base']);
				$sideLabel = Lua::str((Lua::truth($v382 = $PF['side_pro_word']) ? $v382 : 'pour'));
				$label = NativeText::field($PF['justification_add_label']);
				$tooltip = sprintf($PF['justification_add_tt'], $pv['rawTitle']);
				$rqForm = NativeText::field($PF['form_new_arg_title']);
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = NativeText::field($PF['type_justification']);
				$rqQuery[($baseField . '[ID]')] = NativeText::field($pv['pageId']);
				$rqQuery['_run'] = '1';
				$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>');
			}
			$editObj = $this->addDataEditLink($PF['form_edit_objections'], $pv['rawTitle'], '&nbsp;', $PF['edit_objections_tooltip']);
			$out[] = ($this->listBreaker() . '<h2 class="section-modifiable wk-icon-before wk-icon-argument is-con">' . $PF['objections'] . $editObj . '</h2>');
			{
				$raw = $args['objection-warnings'];
				$raw = (Lua::truth($v387 = (Lua::truth($v388 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v388)) ? $v387 : '');
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->OBJECTION_WARNING_BANNER_KEYS);
					foreach ($this->csvValues($raw, ',') as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->OBJECTION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							$out[] = $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_objections'], 'tooltip' => $PF['edit_objections_tooltip']]), $cats);
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
				$out[] = Lua::str($ulO);
			}
			else {
				$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_objections'] . '</div>');
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_objections'] . ']]'));
			}
			{
				$baseField = NativeText::field($PF['arg_new_title_field_base']);
				$label = NativeText::field($PF['objection_add_label']);
				$tooltip = sprintf($PF['objection_add_tt'], $pv['rawTitle']);
				$rqForm = NativeText::field($PF['form_new_arg_title']);
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = NativeText::field($PF['type_objection']);
				$rqQuery[($baseField . '[ID]')] = NativeText::field($pv['pageId']);
				$rqQuery['_run'] = '1';
				$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>');
			}
			$pieces[] = implode('', $out);
		}
		$pieces[] = $this->renderDebatesParentsBottom($pv, $lang, $cache, $PF);
		if (Lua::truth($args['interlanguage'])) {
			$pieces[] = $args['interlanguage'];
		}
		$pieces[] = $this->renderRubriques($args, $cats, $lang, $PF);
		if (((!(Lua::truth($hasResume)) && !(Lua::truth($hasQuotes))) && !(Lua::truth($hasRefs)))) {
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_content'] . ']]'));
		}
		$this->setSemanticData($args, $pv, $lang, $cache, $PF, $maps, $rawB, $rawW, $rawV);
		$this->renderFullSEO($args, $pv, $lang);
		$pieces[] = Lua::concat($cats, '');
		$pieces[] = ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[Special:MovePage/' . $pv['encoded'] . '| ' . $PF['rename_link_text'] . ']]</span>');
		$pieces[] = ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataEditLink($PF['form_edit_sections'], $pv['rawTitle'], $PF['edit'], $PF['edit_sections_tooltip']) . '</span>');
		if ((Lua::truth($args['interlanguage']) && (trim(NativeText::field($args['interlanguage']), NativeText::ASCII_WHITESPACE) !== ''))) {
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
	protected function vset($name, $value) { $this->context->variableSet($name, Lua::str($value ?? '')); }
	protected function vget($name) { return $this->context->variableGet($name); }
	protected function titleNewCached($text) { return $this->context->titleInfo($text); }
	protected function safeJsonEncode($data) { return $this->context->jsonEncode($data); }
	protected function seoKeyPart($text) { return md5(Lua::str($text ?? '')); }
	protected function absUrlFromTitle($title, $query) { return $this->context->fullUrl($title, $query) ?? $this->context->localUrl($title, $query) ?? ''; }
	protected function smwAskSafe($query, string $origin = 'other') {
		if (!$this->hasSMW()) { return null; }
		$key = $this->smwKeyFromArray($query);
		$hit = $this->cacheGet($this->SMW_ASK_CACHE, $key);
		if ($hit !== null) { $this->smwCacheHit($origin, 'query'); return $hit === false ? null : $hit; }
		$result = $this->smwAskUncached($query, $origin);
		$this->cachePut($this->SMW_ASK_CACHE, $key, $result ?? false);
		return $result;
	}
	protected function readSemantic(Table $query, string $origin): SemanticReadResult {
		if (!$this->context instanceof NativeSemanticContext) {
			return new SemanticReadResult(Table::unwrap($this->smwAskSafe($query, $origin)));
		}
		if (!$this->hasSMW()) { return new SemanticReadResult(null); }
		$cache = $this->nativeSemanticCache ??= new FifoCache(250);
		$key = $this->smwKeyFromArray($query);
		$hit = $cache->get($key);
		if ($hit !== null) { $this->smwCacheHit($origin, 'query'); return $hit; }
		$result = $this->context->readSemantic($query, $origin);
		$cache->put($key, $result);
		return $result;
	}
	protected function smwAskUncached(Table $query, string $origin): ?Table {
		return $this->context instanceof SemanticProfilingContext
			? $this->context->profiledSmwAsk($query, $origin) : $this->context->smwAsk($query);
	}
	protected function smwCacheHit(string $origin, string $layer): void {
		if ($this->context instanceof SemanticProfilingContext) { $this->context->smwCacheHit($origin, $layer); }
	}
	protected function getArgs($frame = null) { return $this->context->arguments(); }
	protected function detectLang($args = null) { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Argument.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars() { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet($data = null) { $this->context->seoSet($data); }
	protected function smwSetSafe($data = null) { $this->context->smwSet($data); }
}
