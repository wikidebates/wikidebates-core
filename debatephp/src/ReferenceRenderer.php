<?php
namespace MediaWiki\Extension\DebatePHP;

/** Debate renderer. This PHP source is authoritative and maintained directly.
 * Legacy Table boundaries remain until their native migration is complete.
 * See docs/native-php-migration-baseline.md for the behavioural contract.
 */
class ReferenceRenderer {
	private ?DecodedReferences $decodedReferences = null;
	private ?RenderData $renderData = null;
	private bool $nativeJson = false;
	protected Context $context;
	protected $ITEM_SEP;
	protected $FIELD_SEP;
	protected $SEO_CACHE;
	protected $ARGUMENT_WARNING_BANNER_KEYS;
	protected $ARGUMENT_WARNING_LOOKUPS;
	protected $PROGRESS_BANNER_KEYS;
	protected $TITLE_WARNING_BANNER_KEYS;
	protected $DEBATE_WARNING_BANNER_KEYS;
	protected $INTRO_SUB_SEP;
	protected $INTRO_FIELD_SEP;
	protected $INTRO_SUB_WARNING_KEYS;
	protected $REFERENCE_WARNING_BANNER_KEYS;
	protected $REF_SEP;
	protected $REF_FIELD_SEP;
	public function __construct(Context $context) {
		$this->context = $context;
		$this->ITEM_SEP = '⟭';
		$this->FIELD_SEP = '⟬';
		$this->SEO_CACHE = new Table([]);
		$this->ARGUMENT_WARNING_BANNER_KEYS = new Table([1 => 'displayed_title_too_long', 2 => 'displayed_title_unclear', 3 => 'displayed_title_disadvantageous']);
		$this->ARGUMENT_WARNING_LOOKUPS = new Table([]);
		$this->PROGRESS_BANNER_KEYS = new Table([1 => 'progress_under_construction', 2 => 'progress_draft']);
		$this->TITLE_WARNING_BANNER_KEYS = new Table([1 => 'title_non_standard', 2 => 'title_to_explain', 3 => 'title_to_simplify']);
		$this->DEBATE_WARNING_BANNER_KEYS = new Table([1 => 'debate_unbalanced', 2 => 'debate_redundant', 3 => 'debate_fanciful', 4 => 'debate_sensitive', 5 => 'outline_to_improve', 6 => 'debate_generated_by_ai']);
		$this->INTRO_SUB_SEP = '⟭⟭⟭';
		$this->INTRO_FIELD_SEP = '⟬⟬⟬';
		$this->INTRO_SUB_WARNING_KEYS = new Table([1 => 'too_long', 2 => 'to_develop', 3 => 'unclear', 4 => 'biased', 5 => 'refs_missing', 6 => 'style_to_review']);
		$this->REFERENCE_WARNING_BANNER_KEYS = new Table([1 => 'reference_quality_insufficient', 2 => 'reference_incomplete', 3 => 'dead_link']);
		$this->REF_SEP = '⟭';
		$this->REF_FIELD_SEP = '⟬';
	}

	protected function smwProp($lang = null, $key = null) {
		return $this->context->message('Debate.props', $lang, $key);
	}

	protected function isProgress($lang = null, $val = null, $key = null) {
		if ((!(Lua::truth($val)) || ($val === ''))) {
			return false;
		}
		$label = $this->context->message('Debate.progress', $lang, $key);
		return ($val === $label);
	}

	protected function anchorId($s = null) {
		return NativeText::anchorId($s);
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

	protected function wkRenderNativeReferenceList(?string $raw, string $format, callable $builder): string {
		$out = [];
		foreach ($this->nativeReferenceValues($format, $raw) as $item) {
			$li = $builder($item);
			if (Lua::truth($li) && $li !== '') { $out[] = $li; }
		}
		return implode("\n", $out);
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

	protected function wkParseWikipediaMarkers($raw = null) {
		$raw = NativeText::field($raw);
		$raw = trim($raw, NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return new Table([]);
		}
		if (!(str_contains($raw, $this->ITEM_SEP))) {
			return new Table([]);
		}
		$pages = new Table([]);
		foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
			$part = trim($part, NativeText::ASCII_WHITESPACE);
			if (($part !== '')) {
				$pages[(Lua::len($pages) + 1)] = $part;
			}
		}
		return $pages;
	}

	protected function seoCacheKey($args = null, $pv = null, $lang = null) {
		return Lua::concat(new Table([1 => NativeText::field($lang), 2 => (Lua::truth($v5 = $pv['pageId']) ? $v5 : ''), 3 => NativeText::field($pv['rawTitle']), 4 => NativeText::field($args['expanded-topic']), 5 => NativeText::field($args['keywords']), 6 => NativeText::field($args['sections'])]), '
');
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v10 = $this->context->message('Debate.seo', $lang, $key, ...$rest)) ? $v10 : '');
	}

	protected function seoJoinList($items = null) {
		$out = [];
		$seen = [];
		foreach (((Lua::truth($v11 = $items) ? $v11 : new Table([])))->sequence() as $_ => $v) {
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
		$ck = $this->seoCacheKey($args, $pv, $lang);
		if (Lua::truth($this->SEO_CACHE[$ck])) {
			return null;
		}
		$this->SEO_CACHE[$ck] = true;
		$pageTitle = (Lua::truth($v16 = $pv['rawTitle']) ? $v16 : '');
		$topic = trim(NativeText::field($args['expanded-topic']), NativeText::ASCII_WHITESPACE);
		if (($topic === '')) {
			$topic = $pageTitle;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain')), 'title_separator' => sprintf($this->seoL($lang, 'title_separator'), $pageTitle), 'description' => sprintf($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'author' => $this->seoL($lang, 'author'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v18 = $this->context->message('Debate.subsections', $lang, $key, ...$rest)) ? $v18 : '');
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = NativeText::field($key);
		if (($key === '')) {
			return '';
		}
		$opts = (Lua::truth($v20 = $opts) ? $v20 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
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

	protected function smwQueueAdd($pending = null, $key = null, $val = null, $multi = null) {
		if (((!(Lua::truth($key)) || ($key === '')) || ($val === null))) {
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
				$pending[$key] = (Lua::truth($v25 = (Lua::truth($v26 = (($val instanceof Table))) ? $val : $v26)) ? $v25 : new Table([1 => $val]));
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

	protected function smwQueueAddFromSepString($pending = null, $key = null, $raw = null, $sep = null) {
		$raw = (Lua::truth($v27 = (Lua::truth($v28 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v28)) ? $v27 : '');
		if (($raw === '')) {
			return null;
		}
		$sep = (Lua::truth($v29 = $sep) ? $v29 : $this->ITEM_SEP);
		$vals = new Table([]);
		if (str_contains($raw, $sep)) {
			foreach (Lua::split($raw, $sep, true) as $part) {
				$part = trim($part, NativeText::ASCII_WHITESPACE);
				if (($part !== '')) {
					$vals[(Lua::len($vals) + 1)] = $part;
				}
			}
		}
		else {
			$vals[1] = $raw;
		}
		if ((Lua::len($vals) > 0)) {
			$this->smwQueueAdd($pending, $key, $vals, true);
		}
	}

	protected function smwQueueAddFromCSV($pending = null, $key = null, $raw = null) {
		$this->smwQueueAddFromSepString($pending, $key, $raw, ',');
	}

	protected function smwQueueMerge($dst = null, $src = null) {
		foreach (Lua::pairs((Lua::truth($v30 = $src) ? $v30 : new Table([]))) as $k => $v) {
			if ((($v instanceof Table))) {
				$this->smwQueueAdd($dst, $k, $v, true);
			}
			else {
				$this->smwQueueAdd($dst, $k, $v, false);
			}
		}
	}


	protected function pf_escape($v = null) {
		if (!(Lua::truth($v))) {
			return '';
		}
		$v = Lua::str($v);
		$v = Lua::gsub(Lua::gsub($v, '|', '&#124;'), '=', '&#61;');
		return $v;
	}

	protected function wkEncodeAddDataSegment($s = null) {
		$s = NativeText::field($s);
		$s = Lua::gsub($s, '%%', '%%25');
		$s = Lua::gsub($s, '%?', '%%3F');
		$s = Lua::gsub($s, '#', '%%23');
		$s = Lua::gsub($s, '/', '%%2F');
		return $s;
	}

	protected function wkWikipediaUrl($page = null, $lang = null) {
		if ((!(Lua::truth($page)) || ($page === ''))) {
			return '';
		}
		$base = $this->L($lang, 'urls', 'wikipedia');
		if ((!(Lua::truth($base)) || ($base === ''))) {
			return '';
		}
		return ($base . $this->context->uriEncode($page, 'WIKI'));
	}

	protected function wkAddDataPath($formName = null, $pageTitle = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		$page = $this->wkEncodeAddDataSegment($this->wkDbKey($pageTitle));
		return ('Special:AddData/' . $form . '/' . $page);
	}

	protected function addDataLink($formName = null, $pageTitle = null, $linktext = null, $tooltip = null) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = '&nbsp;';
		$tt = NativeText::field($tooltip);
		if (($tt !== '')) {
			return sprintf('<span class="wk-adddata-link bouton-modifier navigation-not-searchable noprint" data-wk-tooltip="%s">[[%s|%s]]</span>', $this->escapeAttr($tt), $target, $lt);
		}
		return sprintf('[[%s|%s]]', $target, $lt);
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function normalizeTooltipText($s = null) {
		return NativeText::tooltip($s);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = NativeText::field($formName);
		$label = NativeText::field($label);
		$query = (Lua::truth($v40 = (Lua::truth($v41 = (($query instanceof Table))) ? $query : $v41)) ? $v40 : new Table([]));
		$tooltip = NativeText::field($tooltip);
		$tooltip = $this->normalizeTooltipText($tooltip);
		if (($formName === '')) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$href = Lua::str($this->context->localUrl($title, $query));
		$content = ('＋ ' . $label);
		$attributes = new Table(['href' => 'javascript:void(0)', 'data-href' => $this->escapeAttr($href), 'class' => 'wk-btn__a wk-js-nav']);
		if ($tooltip !== '') { $attributes['title'] = $this->escapeAttr($tooltip); }
		return $this->context->htmlTag('a', $content, $attributes, true);
	}

	protected function wkAddDataGetButton($formName = null, $pageTitle = null, $label = null, $tooltip = null, $query = null) {
		$formName = NativeText::field($formName);
		$pageTitle = NativeText::field($pageTitle);
		$label = NativeText::field($label);
		$tooltip = NativeText::field($tooltip);
		$query = (Lua::truth($v49 = (Lua::truth($v50 = (($query instanceof Table))) ? $query : $v50)) ? $v49 : new Table([]));
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$title = $this->wkAddDataPath($formName, $pageTitle);
		$url = Lua::str($this->context->fullUrl($title, $query));
		$linkInner = ('<span class="wk-btn__icon" aria-hidden="true">➜</span>' . '<span class="wk-btn__label">' . $label . '</span>');
		$link = ('[' . $url . ' ' . $linkInner . ']');
		return ('<div class="wk-btn wk-auto-id mw-ui-button navigation-not-searchable"' . (Lua::truth($v51 = (Lua::truth($v52 = ($tooltip !== '')) ? (' title="' . $this->escapeAttr($tooltip) . '"') : $v52)) ? $v51 : '') . '>' . $link . '</div>');
	}

	protected function runQueryLink($formName = null, $fieldLabel = null, $fieldName = null, $value = null, $linktext = null, $tooltip = null, $extraParams = null) {
		$formName = NativeText::field($formName);
		$fieldLabel = NativeText::field($fieldLabel);
		$fieldName = NativeText::field($fieldName);
		$value = NativeText::field($value);
		if ((((($formName === '') || ($fieldLabel === '')) || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$q = new Table([]);
		$q[($fieldLabel . '[' . $fieldName . ']')] = $value;
		if ((($extraParams instanceof Table))) {
			foreach (Lua::pairs($extraParams) as $k => $v) {
				if ((((Lua::truth($k) && ($k !== '')) && ($v !== null)) && (Lua::str($v) !== ''))) {
					$q[$k] = Lua::str($v);
				}
			}
		}
		$q['_run'] = '';
		$url = Lua::str($this->context->fullUrl($title, $q));
		$label = (Lua::truth($v57 = $linktext) ? $v57 : $value);
		$tt = NativeText::field($tooltip);
		$link = ('[' . $url . ' ' . $label . ']');
		if (($tt !== '')) {
			return sprintf('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return sprintf('<span class="wk-adddata-link">%s</span>', $link);
	}

	/** Join dense, zero-based rendered Wikipedia links. */
	protected function joinArticles(array $items, $lang = null): string {
		if (count($items) < 2) {
			return $items[0] ?? '';
		}
		$conjunction = $this->context->message('Common.text', $lang, 'list_conjunction_text');
		$last = array_pop($items);
		return implode(', ', $items) . $conjunction . $last;
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function buildSummaryLabelLookup($lang = null, $keys = null) {
		$lookup = [];
		foreach (((Lua::truth($v59 = $keys) ? $v59 : new Table([])))->sequence() as $_ => $key) {
			$lab = trim($this->subMsg($lang, ($key . '_label')), NativeText::ASCII_WHITESPACE);
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return new Table($lookup);
	}

	protected function getArgumentWarningLookup($lang = null) {
		$lang = NativeText::field($lang);
		if (!(Lua::truth($this->ARGUMENT_WARNING_LOOKUPS[$lang]))) {
			$this->ARGUMENT_WARNING_LOOKUPS[$lang] = $this->buildSummaryLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
		}
		return $this->ARGUMENT_WARNING_LOOKUPS[$lang];
	}

	protected function resolveKeyFromLookup($raw = null, $lookup = null, $allowedKeys = null, $aliases = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return '';
		}
		$k = (Lua::truth($v62 = (Lua::truth($v63 = $lookup) ? $lookup[$raw] : $v63)) ? $v62 : '');
		if (($k !== '')) {
			return $k;
		}
		if ((Lua::truth($aliases) && Lua::truth($aliases[$raw]))) {
			return $aliases[$raw];
		}
		if (Lua::truth($allowedKeys)) {
			foreach (($allowedKeys)->sequence() as $_ => $kk) {
				if (($raw === $kk)) {
					return $kk;
				}
			}
		}
		return '';
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null): array {
		$warnsArg = trim(NativeText::field($itemWarnings), NativeText::ASCII_WHITESPACE);
		if (($warnsArg === '')) {
			return [];
		}
		$lookup = $this->getArgumentWarningLookup($lang);
		$hasBanner = false;
		$out = [];
		foreach ($this->csvValues($warnsArg, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->ARGUMENT_WARNING_BANNER_KEYS);
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

	protected function wkSplit2($s = null, $sep = null) {
		return NativeText::splitOnce($s, $sep);
	}

	protected function wkExtractArgumentItemsFromData($raw = null) {
		return SerializedData::argumentItems($raw, false);
	}

	protected function wkComputeArgumentItems($raw = null) {
		$items = $this->wkExtractArgumentItemsFromData($raw);
		if ((Lua::len($items) > 0)) {
			return [$items, 'data'];
		}
		return [new Table([]), 'none'];
	}

	protected function wkBuildArgumentData($items = null, $lang = null, $kind = null) {
		$items = (Lua::truth($v68 = $items) ? $v68 : new Table([]));
		$idPrefix = (($kind === 'pro') ? 'Pro_argument_' : 'Con_argument_');
		$listLines = [];
		$argsVarParts = [];
		$mapLines = [];
		$hoverLines = [];
		foreach (($items)->sequence() as $i => $it) {
			$listLines[] = ('* ' . $it['title']);
			if (($it['page'] === $it['title'])) {
				if ($it['page'] !== null) { $argsVarParts[] = $it['page']; }
			}
			else {
				$argsVarParts[] = ($it['page'] . '⟬' . $it['title']);
			}
			$mapLines[] = ('<div  id="' . $idPrefix . $i . '_map" class="argument-title--map wk-icon-before wk-icon-argument">' . $it['title'] . '</div>');
			$hoverLines[] = ('<div class="argument-title--map wk-icon-before wk-icon-argument">' . '[[' . $it['page'] . '|' . $it['title'] . ']]</div>');
		}
		$listText = implode('
', $listLines);
		$argsVar = implode(' -;- ', $argsVarParts);
		$mapHtml = implode('
', $mapLines);
		$mapHoverHtml = implode('
', $hoverLines);
		return new Table(['items' => $items, 'list' => $listText, 'argsVar' => $argsVar, 'map' => $mapHtml, 'mapHover' => $mapHoverHtml]);
	}

	protected function computeArgumentMapsFromArgs($args = null, $lang = null) {
		$rawPro = (Lua::truth($v71 = $args['pro-arguments']) ? $v71 : '');
		$rawCon = (Lua::truth($v72 = $args['con-arguments']) ? $v72 : '');
		if (($rawPro !== '')) {
			$rawPro = $this->context->expandField($rawPro, 'debate.arguments.pro');
		}
		if (($rawCon !== '')) {
			$rawCon = $this->context->expandField($rawCon, 'debate.arguments.con');
		}
		[$pro, $proMode] = $this->wkComputeArgumentItems($rawPro);
		[$con, $conMode] = $this->wkComputeArgumentItems($rawCon);
		return new Table(['pro' => $this->wkBuildArgumentData($pro, $lang, 'pro'), 'con' => $this->wkBuildArgumentData($con, $lang, 'con'), '_mode' => new Table(['pro' => $proMode, 'con' => $conMode])]);
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, $cats = null) {
		$isPro = ($kind === 'pro');
		$idPrefix = ($isPro ? 'Pro_argument_' : 'Con_argument_');
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

	protected function renderMetaWarningHtml($params = null) {
		$color = NativeText::field($params['color']);
		$icon = NativeText::field($params['icon']);
		$title = NativeText::field($params['title']);
		$text = NativeText::field($params['text']);
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre">' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderMainBanner($frame = null, $pv = null, $lang = null) {
		$titre = sprintf($this->L($lang, 'text', 'main_banner_title'), $this->context->nowiki($pv['rawTitle']));
		return $this->renderMetaWarningHtml(new Table(['color' => $this->L($lang, 'text', 'main_banner_color'), 'title' => $titre, 'icon' => 'wk-icon-wikidebates', 'text' => ('<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">' . $this->L($lang, 'text', 'learn_more_label') . $this->L($lang, 'text', 'founding_principles_link') . '</div>')]));
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v84 = $this->context->message('Debate.banners', $lang, $key, ...$rest)) ? $v84 : '');
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

	protected function buildBannerLabelLookup($lang = null, $keys = null) {
		$lookup = new Table([]);
		foreach (((Lua::truth($v86 = $keys) ? $v86 : new Table([])))->sequence() as $_ => $key) {
			$lab = trim($this->bannerMsg($lang, ($key . '_label')), NativeText::ASCII_WHITESPACE);
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveBannerKeyFromLookup($raw = null, $lookup = null) {
		$raw = trim(NativeText::field($raw), NativeText::ASCII_WHITESPACE);
		if (($raw === '')) {
			return '';
		}
		return (Lua::truth($v88 = (Lua::truth($v89 = $lookup) ? $lookup[$raw] : $v89)) ? $v88 : '');
	}

	protected function renderWarningBanners($args = null, $pv = null, $cats = null, $lang = null) {
		$av = $args['progress'];
		$out = [];
		$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
		$debateLookup = $this->buildBannerLabelLookup($lang, $this->DEBATE_WARNING_BANNER_KEYS);
		$progLookup = $this->buildBannerLabelLookup($lang, $this->PROGRESS_BANNER_KEYS);
		$renderBannersFromList = function($list = null, $lookup = null) use (&$pv, &$cats, &$lang) {
		$html = [];
		foreach ($this->csvValues($list, ',') as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (($key !== '')) {
				$html[] = $this->renderBannerByKey($pv, $lang, $key, $cats);
			}
		}
		return implode('', $html);
		};
		if (Lua::truth($args['title-warnings'])) {
			$out[] = $renderBannersFromList($args['title-warnings'], $titleLookup);
		}
		if ((Lua::truth($av) && (trim($av, NativeText::ASCII_WHITESPACE) !== ''))) {
			$key = $this->resolveBannerKeyFromLookup($av, $progLookup);
			if (($key !== '')) {
				$out[] = $this->renderBannerByKey($pv, $lang, $key, $cats);
			}
		}
		if (Lua::truth($this->isProgress($lang, $av, 'constructed'))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'constructed') . ']]'));
		}
		else {
			if (((!(Lua::truth($this->isProgress($lang, $av, 'draft'))) && !(Lua::truth($this->isProgress($lang, $av, 'building')))) && !(Lua::truth($this->isProgress($lang, $av, 'constructed'))))) {
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'progress_missing') . ']]'));
			}
		}
		if (Lua::truth($args['debate-warnings'])) {
			$out[] = $renderBannersFromList($args['debate-warnings'], $debateLookup);
		}
		return implode('', $out);
	}

	protected function renderArgumentMapTop($lang = null, $maps = null) {
		$maps = (Lua::truth($v90 = $maps) ? $v90 : new Table([]));
		$pro = (Lua::truth($v91 = (Lua::truth($v92 = $maps['pro']) ? $maps['pro']['map'] : $v92)) ? $v91 : '');
		$con = (Lua::truth($v93 = (Lua::truth($v94 = $maps['con']) ? $maps['con']['map'] : $v94)) ? $v93 : '');
		$labelPour = $this->L($lang, 'text', 'arg_map_pro_label');
		$labelContre = $this->L($lang, 'text', 'arg_map_con_label');
		$msgPour = $this->L($lang, 'text', 'none_pro_args_msg_short');
		$msgContre = $this->L($lang, 'text', 'none_con_args_msg_short');
		$root = ((((HtmlBuilder::create('div'))->attr('id', 'Argument_map'))->addClass('wk-arg-map'))->addClass('wk-arg-map--top'))->addClass('navigation-not-searchable');
		$colP = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-pro');
		$colC = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-con');
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelPour));
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext((Lua::truth($v95 = (Lua::truth($v96 = ($pro !== '')) ? $pro : $v96)) ? $v95 : ('<div class="carte-vide navigation-not-searchable">' . $msgPour . '</div>'))));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelContre));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext((Lua::truth($v97 = (Lua::truth($v98 = ($con !== '')) ? $con : $v98)) ? $v97 : ('<div class="carte-vide navigation-not-searchable">' . $msgContre . '</div>'))));
		(($root)->node($colP))->node($colC);
		return Lua::str($root);
	}

	protected function wkParseIntroSubsections($raw = null) {
		return SerializedData::records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true);
	}

	protected function buildIntroSubWarningLookup($lang = null) {
		$lookup = new Table([]);
		foreach (($this->INTRO_SUB_WARNING_KEYS)->sequence() as $_ => $k) {
			$lab = trim($this->subMsg($lang, ('intro_subbanner_' . $k . '_label')), NativeText::ASCII_WHITESPACE);
			if (($lab !== '')) {
				$lookup[$lab] = ('intro_subbanner_' . $k);
			}
		}
		return $lookup;
	}

	protected function wkRenderIntroSubsection($pv = null, $lang = null, $it = null, $cats = null, $lookup = null) {
		$titre = trim(NativeText::field($it['title']), NativeText::ASCII_WHITESPACE);
		$contenu = trim(NativeText::field($it['content']), NativeText::ASCII_WHITESPACE);
		$avert = trim(NativeText::field($it['warnings']), NativeText::ASCII_WHITESPACE);
		if (($titre === '')) {
			$titre = $this->L($lang, 'text', 'default_subsection_title');
		}
		$out = [];
		$out[] = '<div class="fr-collapsible fr-collapsed">';
		$out[] = ('<h3 class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-question">' . $titre . '</h3>');
		$out[] = '<div class="fr-collapsible-content"><div class="encadre-section-deroulante">';
		if (($avert !== '')) {
			$banners = [];
			foreach (explode(',', $avert) as $lab) {
				$lab = trim($lab, NativeText::ASCII_WHITESPACE);
				if (($lab !== '')) {
					$k = (Lua::truth($v104 = $lookup[$lab]) ? $v104 : '');
					if (($k !== '')) {
						$h = $this->renderSubsectionBanner($pv, $lang, $k, new Table([]), $cats);
						if ((Lua::truth($h) && ($h !== ''))) {
							$banners[] = $h;
						}
					}
				}
			}
			if ((count($banners) > 0)) {
				$out[] = ('<div class="minibandeaux">' . implode('', $banners) . '</div>');
			}
		}
		if (($contenu !== '')) {
			$out[] = $contenu;
		}
		else {
			$editForm = $this->L($lang, 'forms', 'intro_edit_title');
			$editTT = $this->L($lang, 'text', 'intro_edit_tt');
			$linkLabel = $this->L($lang, 'text', 'intro_empty_link_label');
			$emptyIntro = $this->L($lang, 'text', 'intro_empty_msg');
			$emptyCat = $this->L($lang, 'text', 'intro_empty_category');
			$link = $this->addDataLink($editForm, $pv['rawTitle'], $linkLabel, $editTT);
			$out[] = ('<i>' . $emptyIntro . '</i>');
			if (($emptyCat !== '')) {
				Lua::insert($cats, ('[[Category:' . $emptyCat . ']]'));
			}
		}
		$out[] = '</div></div></div>';
		return implode('', $out);
	}

	protected function wkRenderIntroSubsectionsBlock($pv = null, $lang = null, $raw = null, $cats = null) {
		$items = $this->wkParseIntroSubsections($raw);
		if ((Lua::len($items) === 0)) {
			return '';
		}
		$lookup = $this->buildIntroSubWarningLookup($lang);
		$out = [];
		foreach (($items)->sequence() as $_ => $it) {
			$out[] = $this->wkRenderIntroSubsection($pv, $lang, $it, $cats, $lookup);
		}
		return implode('', $out);
	}

	protected function renderIntroduction($args = null, $pv = null, $lang = null, $cats = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-question">' . $this->L($lang, 'text', 'understand_debate_title') . $this->addDataLink($this->L($lang, 'forms', 'intro_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'intro_edit_tt')) . '</h2>');
		$raw = $args['introduction'];
		$raw = (Lua::truth($v105 = (Lua::truth($v106 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v106)) ? $v105 : '');
		if (($raw !== '')) {
			$expanded = $this->context->expandField($raw, 'debate.introduction');
			$subs = $this->wkRenderIntroSubsectionsBlock($pv, $lang, $expanded, (Lua::truth($v107 = $cats) ? $v107 : new Table([])));
			if (($subs !== '')) {
				$s = Lua::gsub(($h2 . $subs), '%s*$', '');
				return $s;
			}
		}
		$cat = ('[[Category:' . $this->L($lang, 'categories', 'intro_missing') . ']]');
		$body = ('<div class="aucun-contenu navigation-not-searchable" style="margin-bottom: 1em;">' . $this->L($lang, 'text', 'none_intro_msg') . '</div>' . $cat);
		$s = Lua::gsub(($h2 . $body), '%s*$', '');
		return $s;
	}

	protected function renderWikipediaLinks($args = null, $pv = null, $lang = null) {
		$raw = $args['wikipedia-articles'];
		$raw = (Lua::truth($v108 = (Lua::truth($v109 = (is_string($raw))) ? trim($raw, NativeText::ASCII_WHITESPACE) : $v109)) ? $v108 : '');
		$wrap = (HtmlBuilder::create('div'))->addClass('bandeau-section wk-icon-before wk-icon-wikipedia hover-top navigation-not-searchable');
		$hasPages = false;
		if (($raw !== '')) {
			$expanded = $this->context->expandField($raw, 'debate.wikipedia');
			$pages = $this->wkParseWikipediaMarkers($expanded);
			if ((Lua::len($pages) > 0)) {
				$hasPages = true;
				$items = [];
				foreach (($pages)->sequence() as $_ => $page) {
					$url = $this->wkWikipediaUrl($page, $lang);
					$items[] = ('<span class="hover-wikipedia">[' . $url . ' ' . $page . ']</span>');
				}
				($wrap)->wikitext(sprintf($this->L($lang, 'text', 'wikipedia_seealso_msg'), $this->joinArticles($items, $lang)));
			}
			else {
				(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
			}
		}
		else {
			(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
		}
		($wrap)->wikitext($this->addDataLink($this->L($lang, 'forms', 'wiki_seealso_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'wikipedia_edit_tt')));
		if ((($raw === '') || !($hasPages))) {
			return (Lua::str($wrap) . '[[Category:' . $this->L($lang, 'categories', 'wikipedia_missing') . ']]');
		}
		return Lua::str($wrap);
	}

	protected function renderQuestionsFAQ($sujetComplet = null, $sens = null, $lang = null) {
		$topic = (Lua::truth($v110 = (Lua::truth($v111 = (Lua::truth($v112 = $sujetComplet) ? ($sujetComplet !== '') : $v112)) ? $sujetComplet : $v111)) ? $v110 : '');
		$isCon = ($sens === 'con');
		$q = null;
		if ($isCon) {
			$q = sprintf($this->L($lang, 'text', 'faq_q_con'), $topic);
		}
		else {
			$q = sprintf($this->L($lang, 'text', 'faq_q_pro'), $topic);
		}
		return ('<div class="question-FAQ">' . $q . '</div>');
	}

	protected function renderArgsList($args = null, $pv = null, $lang = null, $sens = null, $maps = null, $cats = null) {
		$isCon = ($sens === 'con');
		$hIconCon = 'is-con';
		$hIconPro = 'is-pro';
		$title = (Lua::truth($v113 = (Lua::truth($v114 = $isCon) ? $this->L($lang, 'text', 'args_con_title') : $v114)) ? $v113 : $this->L($lang, 'text', 'args_pro_title'));
		$btnForm = $this->addDataLink((Lua::truth($v115 = (Lua::truth($v116 = $isCon) ? $this->L($lang, 'forms', 'args_con_edit_title') : $v116)) ? $v115 : $this->L($lang, 'forms', 'args_pro_edit_title')), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), (Lua::truth($v117 = (Lua::truth($v118 = $isCon) ? $this->L($lang, 'text', 'args_con_edit_tt') : $v118)) ? $v117 : $this->L($lang, 'text', 'args_pro_edit_tt')));
		$out = [];
		$out[] = $this->listBreaker();
		$out[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-argument ' . (Lua::truth($v119 = (Lua::truth($v120 = $isCon) ? $hIconCon : $v120)) ? $v119 : $hIconPro) . '">' . $title . $btnForm . '</h2>');
		$out[] = $this->renderQuestionsFAQ($args['expanded-topic'], $sens, $lang);
		$mode = (Lua::truth($v121 = (Lua::truth($v122 = (Lua::truth($v123 = $maps) ? $maps['_mode'] : $v123)) ? (Lua::truth($v124 = (Lua::truth($v125 = $isCon) ? $maps['_mode']['con'] : $v125)) ? $v124 : $maps['_mode']['pro']) : $v122)) ? $v121 : 'none');
		$data = (Lua::truth($v126 = (Lua::truth($v127 = $maps) ? (Lua::truth($v128 = (Lua::truth($v129 = $isCon) ? $maps['con'] : $v129)) ? $v128 : $maps['pro']) : $v127)) ? $v126 : null);
		$items = (Lua::truth($v130 = (Lua::truth($v131 = $data) ? $data['items'] : $v131)) ? $v130 : new Table([]));
		if ((($mode === 'data') && (Lua::len($items) > 0))) {
			$ul = ((HtmlBuilder::create('ul'))->addClass('argument-list'))->addClass(($isCon ? 'is-con' : 'is-pro'));
			$lis = [];
			$anyTitleWarnings = false;
			foreach (($items)->sequence() as $i => $it) {
				if ((Lua::truth($it['warnings']) && (trim($it['warnings'], NativeText::ASCII_WHITESPACE) !== ''))) {
					$anyTitleWarnings = true;
				}
				$lis[] = $this->wkRenderArgumentLi($pv, $lang, $it, ($isCon ? 'con' : 'pro'), $i, $cats);
			}
			($ul)->wikitext(implode('
', $lis));
			if ($anyTitleWarnings) {
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]'));
			}
			$out[] = Lua::str($ul);
		}
		else {
			$cat = (Lua::truth($v136 = (Lua::truth($v137 = $isCon) ? ('[[Category:' . $this->L($lang, 'categories', 'con_missing') . ']]') : $v137)) ? $v136 : ('[[Category:' . $this->L($lang, 'categories', 'pro_missing') . ']]'));
			$msg = (Lua::truth($v138 = (Lua::truth($v139 = $isCon) ? $this->L($lang, 'text', 'none_con_args_msg') : $v139)) ? $v138 : $this->L($lang, 'text', 'none_pro_args_msg'));
			$out[] = ('<div class="aucun-argument navigation-not-searchable">' . $msg . '</div>' . $cat);
		}
		$sideLabel = (Lua::truth($v140 = (Lua::truth($v141 = $isCon) ? $this->L($lang, 'text', 'side_con_word') : $v141)) ? $v140 : $this->L($lang, 'text', 'side_pro_word'));
		$label = sprintf($this->L($lang, 'text', 'args_add_label'), $sideLabel);
		$tooltip = sprintf($this->L($lang, 'text', 'arg_add_tt'), $sideLabel, $pv['rawTitle']);
		$baseField = $this->L($lang, 'forms', 'new_arg_title');
		$rqTypeValue = (Lua::truth($v142 = (Lua::truth($v143 = $isCon) ? $this->L($lang, 'forms', 'new_arg_con_type_label') : $v143)) ? $v142 : $this->L($lang, 'forms', 'new_arg_pro_type_label'));
		$rqQuery = new Table([]);
		$rqQuery[($baseField . '[type]')] = $rqTypeValue;
		$rqQuery[($baseField . '[ID]')] = $pv['pageId'];
		$rqQuery['_run'] = '1';
		$out[] = ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($baseField, $label, $tooltip, $rqQuery) . '</div>');
		return implode('', $out);
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

	protected function wkRenderLiListFromArg($raw = null, $parseFn = null, $renderOneFn = null) {
		$items = $parseFn($raw);
		if ((Lua::len($items) === 0)) {
			return '';
		}
		$lis = [];
		foreach (($items)->sequence() as $_ => $item) {
			$li = $renderOneFn($item);
			if ((Lua::truth($li) && ($li !== ''))) {
				$lis[] = $li;
			}
		}
		return implode("\n", $lis);
	}

	protected function wkParseBiblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('bibliography', $raw);
	}

	protected function wkRenderOneBiblioLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$authors = $item['authors'];
		if (($authors === '')) {
			$authors = $this->L($lang, 'text', 'refs_to_review');
			Lua::insert($categories, ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]'));
		}
		$out[] = $authors;
		$article = $item['article'];
		$work = $item['work'];
		$link = $item['link'];
		if (($article !== '')) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'article_name'), $article, true);
		}
		if (($work !== '')) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'work_name'), $work, true);
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
		if (($work !== '')) {
			$volume = $item['volume'];
			if (($volume !== '')) {
				$out[] = (', ' . $volume);
			}
			$number = $item['number'];
			if (($number !== '')) {
				$out[] = (', n°' . $number);
			}
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
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'publishing_house'), $publisher, true);
			$out[] = (', ' . $publisher);
		}
		$place = $item['place'];
		if (($place !== '')) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'place_of_publication'), $place, true);
			$out[] = (', ' . $place);
		}
		$date = $item['date'];
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderBiblioListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null) {
		return $this->wkRenderNativeReferenceList($raw, 'bibliography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneBiblioLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function wkParseWeblioMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('webliography', $raw);
	}

	protected function wkRenderOneWeblioLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null, $side = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$out = [];
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$authors = $item['authors'];
		$date = $item['date'];
		if (($page !== '')) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'article_name'), $page, true);
		}
		if (($site !== '')) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'website_name'), $site, true);
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
			if (($label === '')) {
				return '';
			}
			$renderedLabel = null;
			if (($link !== '')) {
				$renderedLabel = ('[' . $link . ' ' . $label . ']');
			}
			else {
				$renderedLabel = $label;
			}
			$out[] = (((count($out) > 0) ? ', ' : '') . $renderedLabel);
		}
		if ((($site !== '') && ($page !== ''))) {
			$out[] = (', \'\'' . $site . '\'\'');
		}
		if (($date !== '')) {
			$out[] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		if (($authors !== '')) {
			$propKey = 'author';
			if (($side === 'pro')) {
				$propKey = 'author_pro';
			}
			else {
				if (($side === 'con')) {
					$propKey = 'author_con';
				}
			}
			foreach (explode(',', $authors) as $a) {
				$a = trim($a, NativeText::ASCII_WHITESPACE);
				if (($a !== '')) {
					$this->smwQueueAdd($smwPending, $this->smwProp($lang, $propKey), $a, true);
				}
			}
		}
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderWeblioListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null, $side = null) {
		return $this->wkRenderNativeReferenceList($raw, 'webliography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending, &$side) {
		return $this->wkRenderOneWeblioLi($pv, $item, $lang, $categories, $smwPending, $side);
		});
	}

	protected function wkParseVideoMarkers($raw = null) {
		return ($this->decodedReferences ??= new DecodedReferences)->parse('videography', $raw);
	}

	protected function wkRenderOneVideoLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null) {
		$item = $item instanceof Table ? $item->values : $item;
		$title = $item['title'];
		if (($title === '')) {
			return '';
		}
		$link = $item['link'];
		$authors = $item['authors'];
		$out = [];
		if (($authors !== '')) {
			$out[] = $authors;
		}
		$renderedTitle = null;
		if (($link !== '')) {
			$renderedTitle = ('[' . $link . ' ' . $title . ']');
		}
		else {
			$renderedTitle = $title;
		}
		$out[] = (((count($out) > 0) ? ', ' : '') . $renderedTitle);
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderVideoListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null) {
		return $this->wkRenderNativeReferenceList($raw, 'videography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneVideoLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function renderRefBlock($pageVars = null, $lang = null, $iconClass = null, $titleText = null, $formKey = null, $value = null) {
		$out = [];
		$out[] = ('<h4 class="titre-references wk-icon-before ' . $iconClass . ' navigation-not-searchable">' . $titleText . $this->addDataLink($this->L($lang, 'forms', $formKey), $pageVars['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'section_edit_tt')) . '</h4>');
		if ((Lua::truth($value) && (trim($value, NativeText::ASCII_WHITESPACE) !== ''))) {
			$out[] = ('<ul>' . $value . '</ul>');
		}
		else {
			$out[] = ('<div class="aucune-reference navigation-not-searchable">' . $this->L($lang, 'text', 'none_references_msg') . '</div>');
		}
		return implode('', $out);
	}

	protected function renderReferences($args = null, $pageVars = null, $lang = null, $categories = null, $smwPending = null, $refRaw = null) {
		$wrap = [];
		$refRaw = (Lua::truth($v174 = $refRaw) ? $v174 : new Table([]));
		$refRaw['bibliography'] = (Lua::truth($v175 = $refRaw['bibliography']) ? $v175 : new Table([]));
		$refRaw['webliography'] = (Lua::truth($v176 = $refRaw['webliography']) ? $v176 : new Table([]));
		$refRaw['videography'] = (Lua::truth($v177 = $refRaw['videography']) ? $v177 : new Table([]));
		$wrap[] = ('<h2 class="wk-icon-before wk-icon-further">' . $this->L($lang, 'text', 'further_reading_title') . '</h2>');
		{
			$h3 = ('<h3 id="Bibliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-bibliography">' . $this->L($lang, 'text', 'bibliography_title') . '</h3>');
			$content = [];
			$proRaw = (Lua::truth($v178 = $args['pro-bibliography']) ? $v178 : '');
			if (($proRaw !== '')) {
				$refRaw['bibliography']['pro'] = $this->context->expandField($proRaw, 'debate.bibliography.pro');
				$proRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['pro'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v179 = $args['con-bibliography']) ? $v179 : '');
			if (($conRaw !== '')) {
				$refRaw['bibliography']['con'] = $this->context->expandField($conRaw, 'debate.bibliography.con');
				$conRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['con'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v180 = $args['bibliography']) ? $v180 : '');
			if (($neuRaw !== '')) {
				$refRaw['bibliography']['neutral'] = $this->context->expandField($neuRaw, 'debate.bibliography.neutral');
				$neuRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['neutral'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['neutral'] = '';
			}
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'biblio_pro_title', $proRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'biblio_con_title', $conRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'biblio_neutral_title', $neuRaw);
			$wrap[] = ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . implode('', $content) . '</div></div>');
		}
		{
			$h3 = ('<h3 id="Webliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-webliography">' . $this->L($lang, 'text', 'webliography_title') . '</h3>');
			$content = [];
			$proRaw = (Lua::truth($v181 = $args['pro-webliography']) ? $v181 : '');
			if (($proRaw !== '')) {
				$refRaw['webliography']['pro'] = $this->context->expandField($proRaw, 'debate.webliography.pro');
				$proRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['pro'], $pageVars, $lang, $categories, $smwPending, 'pro');
			}
			else {
				$refRaw['webliography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v182 = $args['con-webliography']) ? $v182 : '');
			if (($conRaw !== '')) {
				$refRaw['webliography']['con'] = $this->context->expandField($conRaw, 'debate.webliography.con');
				$conRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['con'], $pageVars, $lang, $categories, $smwPending, 'con');
			}
			else {
				$refRaw['webliography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v183 = $args['webliography']) ? $v183 : '');
			if (($neuRaw !== '')) {
				$refRaw['webliography']['neutral'] = $this->context->expandField($neuRaw, 'debate.webliography.neutral');
				$neuRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['neutral'], $pageVars, $lang, $categories, $smwPending, 'neutral');
			}
			else {
				$refRaw['webliography']['neutral'] = '';
			}
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'webliography_pro_title', $proRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'webliography_con_title', $conRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'webliography_neutral_title', $neuRaw);
			$wrap[] = ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . implode('', $content) . '</div></div>');
		}
		{
			$h3 = ('<h3 id="Videography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-videography">' . $this->L($lang, 'text', 'videography_title') . '</h3>');
			$content = [];
			$proRaw = (Lua::truth($v184 = $args['pro-videography']) ? $v184 : '');
			if (($proRaw !== '')) {
				$refRaw['videography']['pro'] = $this->context->expandField($proRaw, 'debate.videography.pro');
				$proRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['pro'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v185 = $args['con-videography']) ? $v185 : '');
			if (($conRaw !== '')) {
				$refRaw['videography']['con'] = $this->context->expandField($conRaw, 'debate.videography.con');
				$conRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['con'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v186 = $args['videography']) ? $v186 : '');
			if (($neuRaw !== '')) {
				$refRaw['videography']['neutral'] = $this->context->expandField($neuRaw, 'debate.videography.neutral');
				$neuRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['neutral'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['neutral'] = '';
			}
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'videography_pro_title', $proRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'videography_con_title', $conRaw);
			$content[] = $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'videography_neutral_title', $neuRaw);
			$wrap[] = ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . implode('', $content) . '</div></div>');
		}
		return implode('', $wrap);
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
		return Lua::str($this->context->fullUrl($titleText));
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
		$debatesLabel = $this->L($lang, 'urls', 'debates_label');
		$debatesUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'debates_label');
		return $this->jsonNode(['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => new Table([1 => $this->jsonNode(['@type' => 'ListItem', 'position' => 1, 'item' => $this->jsonNode(['@id' => $homeUrl, 'name' => $homeLabel])]), 2 => $this->jsonNode(['@type' => 'ListItem', 'position' => 2, 'item' => $this->jsonNode(['@id' => $debatesUrl, 'name' => $debatesLabel])]), 3 => $this->jsonNode(['@type' => 'ListItem', 'position' => 3, 'item' => $this->jsonNode(['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']])])])]);
	}

	protected function wkBuildIsPartOf($lang = null) {
		$out = new Table([]);
		$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'debates_label')]);
		$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => ($this->L($lang, 'urls', 'home') . '#website')]);
		return $out;
	}

	protected function wkBuildRelatedLinks($args = null) {
		$out = new Table([]);
		$raw = trim((string)($args['related-debates'] ?? ''));
		foreach (explode($this->ITEM_SEP, $raw) as $part) {
			// Related debates are rendered as wiki links, possibly with a label.
			$title = trim(NativeText::splitOnce($part, '|')[0]);
			if ($title === '') {
				continue;
			}
			$url = $this->wkFullUrl($title);
			if ($url !== '') {
				$out[count($out) + 1] = $url;
			}
		}
		return $out;
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = [];
		$sections = $this->csvValues((Lua::truth($v207 = (Lua::truth($v208 = $args) ? $args['sections'] : $v208)) ? $v207 : ''), ',');
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

	protected function wkBuildAboutAndMentions($args = null) {
		$about = [];
		$mentions = [];
		$keywords = $this->csvValues((Lua::truth($v210 = (Lua::truth($v211 = $args) ? $args['keywords'] : $v211)) ? $v210 : ''), ',');
		$stop3 = count($keywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = trim(NativeText::field($keywords[$i]), NativeText::ASCII_WHITESPACE);
			if (($kw !== '')) {
				$node = $this->jsonNode(['@type' => 'Thing', 'name' => $kw]);
				if (($i <= 2)) {
					$about[count($about) + 1] = $node;
				}
				else {
					$mentions[count($mentions) + 1] = $node;
				}
			}
		}
		return [new Table($about), new Table($mentions)];
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		$prefix = $this->L($lang, 'urls', 'discussion_prefix');
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}



	protected function wkBuildQuoteAuthorValue($authors = null) {
		$authors = trim(NativeText::field($authors), NativeText::ASCII_WHITESPACE);
		if (($authors === '')) {
			return null;
		}
		$out = [];
		foreach (explode(',', $authors) as $a) {
			$a = trim(NativeText::field($a), NativeText::ASCII_WHITESPACE);
			if (($a !== '')) {
				$out[count($out) + 1] = $this->jsonNode(['@type' => 'Person', 'name' => $a]);
			}
		}
		if ((count($out) === 0)) {
			return null;
		}
		if ((count($out) === 1)) {
			return $out[1];
		}
		return new Table($out);
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

	protected function renderDebateSchemaJSONLD($pv = null, $lang = null, $args = null, $maps = null, $refRaw = null) {
		$previousNativeJson = $this->nativeJson;
		$this->nativeJson = $this->context instanceof NativeJsonContext;
		try {
			$websiteNode = $this->wkBuildWebsiteNode($lang);
			$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
			$organizationNode = $this->wkBuildOrganizationNode($lang);
			[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
			$keywords = $this->wkBuildKeywords($args, $lang);
			$isPartOf = $this->wkBuildIsPartOf($lang);
			$relatedLinks = $this->wkBuildRelatedLinks($args);
			$questionText = null;
			if ((Lua::truth($args['expanded-topic']) && (trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
				$questionText = sprintf($this->L($lang, 'text', 'faq_q'), trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE));
			}
			else {
				$questionText = $pv['rawTitle'];
			}
			$citations = new Table;
			// Reuse the lists decoded for the three visible sections, rather
			// than concatenating their serialized records and decoding again.
			foreach ([
				'bibliography' => 'wkBuildClaimCitationsFromBibliography',
				'webliography' => 'wkBuildClaimCitationsFromWebliography',
				'videography' => 'wkBuildClaimCitationsFromVideography'
			] as $format => $builder) {
				foreach (['pro', 'con', 'neutral'] as $side) {
					foreach ($this->$builder((string)($refRaw[$format][$side] ?? ''))->sequence() as $node) {
						$citations[count($citations) + 1] = $node;
					}
				}
			}
			$pageNode = [
				'@type' => [1 => 'WebPage', 2 => 'LearningResource'],
				// Keep the existing graph identifier: related pages already reference it.
				'@id' => $pv['pageUrl'] . '#qa',
				'url' => $pv['pageUrl'],
				'name' => $pv['rawTitle'],
				'inLanguage' => $lang,
				'isPartOf' => $isPartOf,
				'breadcrumb' => ['@id' => $pv['pageUrl'] . '#breadcrumb'],
				'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang),
				'publisher' => ['@id' => $this->L($lang, 'urls', 'home') . '#organization'],
				'mainEntity' => [
					'@type' => 'Question',
					'@id' => $pv['pageUrl'] . '#question',
					'name' => $pv['rawTitle'],
					'text' => NativeText::seoPlainText($questionText, $this->context) ?: $pv['rawTitle']
				]
			] + SchemaData::education($lang, 'debate');
			$parts = SchemaData::sections($maps, ['pro' => 'pro', 'con' => 'con'],
				$pv['pageUrl'], $lang, fn(string $title): string => $this->wkFullUrl($title), $this->context);
			if ($parts !== []) {
				$pageNode['hasPart'] = $parts;
			}
			// Credit the collective as an author of the page, not of its arguments.
			$credit = $this->seoL($lang, 'author');
			$pageNode['creditText'] = $credit;
			$pageNode['author'] = $this->jsonNode(['@type' => 'Organization', 'name' => $credit]);
			if ((Lua::len($relatedLinks) > 0)) {
				$pageNode['relatedLink'] = $relatedLinks;
			}
			if ((Lua::len($keywords) > 0)) {
				$pageNode['keywords'] = $keywords;
			}
			if ((Lua::len($about) > 0)) {
				$pageNode['about'] = $about;
			}
			if ((Lua::len($mentions) > 0)) {
				$pageNode['mentions'] = $mentions;
			}
			if ((Lua::len($citations) > 0)) {
				$pageNode['citation'] = $citations;
			}
			$data = (['@context' => 'https://schema.org', '@graph' => ([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode])]);
			if ($this->context instanceof NativeJsonContext) { return $this->context->jsonLdData($data); }
			$json = $this->context->jsonEncode(Table::wrap($data));
			return $this->context->jsonLd($json);
		} finally { $this->nativeJson = $previousNativeJson; }
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null) {
		$out = [];
		$out[count($out) + 1] = ('<div style="font-size: 95%; margin-top: 1em;">' . $this->L($lang, 'text', 'keywords_label_text'));
		if ((Lua::truth($args['keywords']) && (trim($args['keywords'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$rendered = [];
			$formName = $this->L($lang, 'forms', 'search_by_keywords_title');
			$fieldLabel = $formName;
			$fieldName = $this->L($lang, 'params', 'keywords_field');
			$tt = $this->L($lang, 'text', 'search_by_keyword_tt');
			$typeField = $this->L($lang, 'params', 'search_type_field');
			$typeValue = $this->L($lang, 'params', 'search_type_value');
			$extra = new Table([($fieldLabel . '[' . $typeField . ']') => $typeValue]);
			foreach ($this->csvValues($args['keywords'], ',') as $_ => $k) {
				$k = trim(NativeText::field($k), NativeText::ASCII_WHITESPACE);
				if (($k !== '')) {
					$chip = $this->runQueryLink($formName, $fieldLabel, $fieldName, $k, $k, $tt, $extra);
					if ((!(Lua::truth($chip)) || ($chip === ''))) {
						$chip = $k;
					}
					if (($chip !== '')) {
						$rendered[count($rendered) + 1] = $chip;
					}
				}
			}
			if ((count($rendered) > 0)) {
				$out[count($out) + 1] = implode(', ', $rendered);
			}
			else {
				$out[count($out) + 1] = ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>');
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]'));
			}
		}
		else {
			$out[count($out) + 1] = ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>');
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]'));
		}
		$out[count($out) + 1] = ($this->addDataLink($this->L($lang, 'forms', 'keywords_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'keywords_edit_tt')) . '<span style="display: none;">.</span></div>');
		return implode('', $out);
	}

	protected function renderConnexes($args = null, $pv = null, $cats = null, $lang = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-parent">' . $this->L($lang, 'forms', 'related_edit_title') . $this->addDataLink($this->L($lang, 'forms', 'related_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'related_edit_tt')) . '</h2>');
		$raw = $args['related-debates'];
		if ((Lua::truth($raw) && (trim($raw, NativeText::ASCII_WHITESPACE) !== ''))) {
			$items = [];
			$n = 0;
			foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
				$part = trim(NativeText::field($part), NativeText::ASCII_WHITESPACE);
				if (($part !== '')) {
					$n = ($n + 1);
					$items[$n] = ('<li style="position: relative;"><span class="hover-map">[[' . $part . ']]</span></li>');
				}
			}
			if (($n > 0)) {
				return ($h2 . '<ul class="hover-top searchaux">' . implode('', $items) . '</ul>');
			}
		}
		Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'related_missing') . ']]'));
		return ($h2 . '<div class="aucun-contenu navigation-not-searchable">' . $this->L($lang, 'text', 'none_related_msg') . '</div>');
	}

	protected function renderRubriques($args = null, $cats = null, $lang = null) {
		if ((Lua::truth($args['sections']) && (trim($args['sections'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$out = [];
			foreach ($this->csvValues($args['sections'], ',') as $_ => $r) {
				$out[count($out) + 1] = ('[[Category:' . $r . ']]');
			}
			return implode('', $out);
		}
		return ('[[Category:' . $this->L($lang, 'categories', 'sections_missing') . ']]');
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, $maps = null, $smwPending = null) {
		$maps = (Lua::truth($v241 = $maps) ? $v241 : new Table([]));
		$pro = (Lua::truth($v242 = $maps['pro']) ? $v242 : new Table([]));
		$con = (Lua::truth($v243 = $maps['con']) ? $v243 : new Table([]));
		$pourPages = new Table([]);
		$contrePages = new Table([]);
		foreach (((Lua::truth($v244 = $pro['items']) ? $v244 : new Table([])))->sequence() as $_ => $it) {
			if ((Lua::truth($it['page']) && ($it['page'] !== ''))) {
				Lua::insert($pourPages, $it['page']);
			}
		}
		foreach (((Lua::truth($v245 = $con['items']) ? $v245 : new Table([])))->sequence() as $_ => $it) {
			if ((Lua::truth($it['page']) && ($it['page'] !== ''))) {
				Lua::insert($contrePages, $it['page']);
			}
		}
		$props = new Table([]);
		$pourSurvol = trim((Lua::truth($v246 = $pro['mapHover']) ? $v246 : ''), NativeText::ASCII_WHITESPACE);
		$contreSurvol = trim((Lua::truth($v247 = $con['mapHover']) ? $v247 : ''), NativeText::ASCII_WHITESPACE);
		if (($pourSurvol === '')) {
			$pourSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_pro_args_msg_short') . '</div>');
		}
		if (($contreSurvol === '')) {
			$contreSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_con_args_msg_short') . '</div>');
		}
		$listePour = trim((Lua::truth($v248 = $pro['argsVar']) ? $v248 : ''), NativeText::ASCII_WHITESPACE);
		$listeContre = trim((Lua::truth($v249 = $con['argsVar']) ? $v249 : ''), NativeText::ASCII_WHITESPACE);
		$props[$this->smwProp($lang, 'debate_name')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'debate_number')] = $pv['pageId'];
		$props[$this->smwProp($lang, 'breadcrumb')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'parent_debate')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'pro_list')] = $listePour;
		$props[$this->smwProp($lang, 'con_list')] = $listeContre;
		$props[$this->smwProp($lang, 'arg_map')] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_pro_label') . '</div>' . '<div class="wk-arg-map__td">' . $pourSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_con_label') . '</div>' . '<div class="wk-arg-map__td">' . $contreSurvol . '</div>' . '</div>' . '</div>');
		if ((Lua::len($pourPages) > 0)) {
			$props[$this->smwProp($lang, 'arg_pro')] = $pourPages;
		}
		if ((Lua::len($contrePages) > 0)) {
			$props[$this->smwProp($lang, 'arg_con')] = $contrePages;
		}
		if (Lua::truth($smwPending)) {
			$this->smwQueueMerge($props, $smwPending);
		}
		$this->smwSetSafe($props);
	}

	protected function escapeAttr($s = null) {
		return NativeText::attribute($s);
	}

	public function render($frame = null) {
		$this->decodedReferences = null;
		$this->renderData = null;
		foreach (Lua::pairs($this->SEO_CACHE) as $k => $_value) {
			$this->SEO_CACHE[$k] = null;
		}
		$args = $this->getArgs($frame);
		$this->renderData = new RenderData;
		$this->renderData->expectReuse([$args['keywords'], $args['sections']]);
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		[$pieces, $cats] = [[], new Table([])];
		$smwPending = new Table([]);
		$refRaw = new Table(['bibliography' => new Table([]), 'webliography' => new Table([]), 'videography' => new Table([])]);
		$this->smwQueueAddFromCSV($smwPending, $this->smwProp($lang, 'keyword'), $args['keywords']);
		$maps = $this->computeArgumentMapsFromArgs($args, $lang);
		Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'debates') . ']]'));
		if ((Lua::truth($args['topic']) && (trim($args['topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'debate_topic'), $args['topic'], false);
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_missing') . ']]'));
		}
		if ((Lua::truth($args['expanded-topic']) && (trim($args['expanded-topic'], NativeText::ASCII_WHITESPACE) !== ''))) {
			$pieces[] = $this->context->shortDescription(sprintf($this->L($lang, 'text', 'shortdesc_topic'), $args['expanded-topic']));
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_complete_missing') . ']]'));
			$pieces[] = $this->context->shortDescription($this->L($lang, 'text', 'shortdesc_plain'));
		}
		$pieces[] = $this->renderArgumentMapTop($lang, $maps);
		$pieces[] = $this->renderKeywords($args, $pv, $cats, $lang);
		$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'debate_banners_edit_tt'));
		$pieces[] = ('<h2 class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>');
		$pieces[] = $this->renderMainBanner($this->context, $pv, $lang);
		$pieces[] = $this->renderWarningBanners($args, $pv, $cats, $lang);
		$pieces[] = $this->renderIntroduction($args, $pv, $lang, $cats);
		$pieces[] = $this->renderWikipediaLinks($args, $pv, $lang);
		$pieces[] = $this->renderArgsList($args, $pv, $lang, 'pro', $maps, $cats);
		$pieces[] = $this->renderArgsList($args, $pv, $lang, 'con', $maps, $cats);
		if (!(Lua::truth($this->isProgress($lang, $args['progress'], 'draft')))) {
			$target = ($this->L($lang, 'text', 'self_eval_ns_label') . ':' . $pv['rawTitle'] . ' (ID)');
			$ns = $this->L($lang, 'text', 'self_eval_ns_label');
			$f_debat = $this->L($lang, 'text', 'self_eval_debate_field');
			$box = [];
			$box[] = ('<h2 class="navigation-not-searchable noprint wk-icon-before wk-icon-selfeval">' . $this->L($lang, 'text', 'self_eval_title') . '</h2>');
			$startBtn = $this->wkAddDataGetButton($this->L($lang, 'forms', 'self_eval_home_title'), $target, $this->L($lang, 'text', 'self_eval_start_label'), $this->L($lang, 'text', 'self_eval_start_tt'), new Table([($ns . '[' . $f_debat . ']') => $pv['rawTitle']]));
			$box[] = ('<div class="boite-info navigation-not-searchable noprint">' . $this->L($lang, 'text', 'self_eval_blurb') . $startBtn . '</div>');
			$pieces[] = implode('', $box);
		}
		$pieces[] = $this->renderReferences($args, $pv, $lang, $cats, $smwPending, $refRaw);
		$pieces[] = $this->renderConnexes($args, $pv, $cats, $lang);
		$pieces[] = ('<h2 class="latest-changes-button navigation-not-searchable noprint wk-icon-before wk-icon-refresh">' . $this->L($lang, 'text', 'latest_changes_title') . '</h2>' . '<div class="latest-changes navigation-not-searchable noprint">' . '<div class="latest-changes-button wk-btn mw-ui-button"' . ' title="' . $this->L($lang, 'text', 'latest_changes_show_tt') . '"' . ' data-page="' . $this->context->nowiki($pv['rawTitle']) . '">' . $this->L($lang, 'text', 'latest_changes_show_label') . '</div>' . '<div class="latest-changes-wrapper"></div>' . '</div>');
		$pieces[] = $this->renderRubriques($args, $cats, $lang);
		$this->setSemanticData($args, $pv, $lang, $maps, $smwPending);
		$this->renderFullSEO($args, $pv, $lang);
		$pieces[] = Lua::concat($cats);
		$renameLink = sprintf($this->L($lang, 'text', 'rename_special_page_text'), $pv['encoded']);
		$pieces[] = ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[' . $renameLink . '|' . $this->L($lang, 'text', 'rename_link_label') . ']]</span>');
		$pieces[] = ('<span id="bouton-modifier-sujet" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'topic_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'topic_edit_tt')) . '</span>');
		$pieces[] = ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'sections_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'sections_edit_tt')) . '</span>');
		if ((Lua::truth($args['interlanguage']) && (trim(NativeText::field($args['interlanguage']), NativeText::ASCII_WHITESPACE) !== ''))) {
			$pieces[] = $args['interlanguage'];
			$pieces[] = ('<span id="bouton-modifier-interlangue" class="bouton-modifier navigation-not-searchable noprint" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($this->L($lang, 'text', 'interlanguage_edit_tt')) . '">' . $this->addDataLink($this->L($lang, 'forms', 'interlanguage_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label')) . '</span>');
		}
		$pieces[] = $this->renderDebateSchemaJSONLD($pv, $lang, $args, $maps, $refRaw);
		if ($this->context instanceof PreparationProfilingContext) {
			$this->context->profilePreparation($this->renderData->statistics());
		}
		return implode('', $pieces);
	}

	protected function getArgs($frame = null) { return $this->context->arguments(); }
	protected function detectLang($args = null) { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Debate.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars() { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet($data = null) { $this->context->seoSet($data); }
	protected function smwSetSafe($data = null) { $this->context->smwSet($data); }
}
