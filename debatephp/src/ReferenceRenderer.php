<?php
namespace MediaWiki\Extension\DebatePHP;

/** Reference port of the supplied Module:Debate. See docs/equivalence.md.
 * No Lua interpreter is used. Table preserves reference and 1-based semantics.
 * Generated mechanically, then checked against the reference source.
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

	protected function wkBuildSingleNodeFromNativeItems(array $items, callable $builder): Table {
		foreach ($items as $item) {
			$node = $builder($item);
			if (Lua::truth($node)) { return new Table([1 => $node]); }
		}
		return new Table;
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
		$raw = Lua::str((Lua::truth($v3 = $raw) ? $v3 : ''));
		$raw = Lua::trim($raw);
		if (($raw === '')) {
			return new Table([]);
		}
		if (!(Lua::truth(Lua::find($raw, $this->ITEM_SEP, 1, true)))) {
			return new Table([]);
		}
		$pages = new Table([]);
		foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
			$part = Lua::trim($part);
			if (($part !== '')) {
				$pages[(Lua::len($pages) + 1)] = $part;
			}
		}
		return $pages;
	}

	protected function seoCacheKey($args = null, $pv = null, $lang = null) {
		return Lua::concat(new Table([1 => Lua::str((Lua::truth($v4 = $lang) ? $v4 : '')), 2 => (Lua::truth($v5 = $pv['pageId']) ? $v5 : ''), 3 => Lua::str((Lua::truth($v6 = $pv['rawTitle']) ? $v6 : '')), 4 => Lua::str((Lua::truth($v7 = $args['expanded-topic']) ? $v7 : '')), 5 => Lua::str((Lua::truth($v8 = $args['keywords']) ? $v8 : '')), 6 => Lua::str((Lua::truth($v9 = $args['sections']) ? $v9 : ''))]), '
');
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v10 = $this->context->message('Debate.seo', $lang, $key, ...$rest)) ? $v10 : '');
	}

	protected function seoJoinList($items = null) {
		$out = [];
		$seen = [];
		foreach (((Lua::truth($v11 = $items) ? $v11 : new Table([])))->sequence() as $_ => $v) {
			$v = Lua::trim(Lua::str((Lua::truth($v12 = $v) ? $v12 : '')));
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

	protected function getPageCreation($pv = null, $args = null) {
		$v = (Lua::truth($v13 = $args) ? $args['creation-date'] : $v13);
		$v = (Lua::truth($v14 = (Lua::truth($v15 = (Lua::type($v) === 'string')) ? Lua::trim($v) : $v15)) ? $v14 : '');
		if (($v !== '')) {
			return ($v . 'T00:00:00Z');
		}
		return null;
	}

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$ck = $this->seoCacheKey($args, $pv, $lang);
		if (Lua::truth($this->SEO_CACHE[$ck])) {
			return null;
		}
		$this->SEO_CACHE[$ck] = true;
		$pageTitle = (Lua::truth($v16 = $pv['rawTitle']) ? $v16 : '');
		$topic = Lua::trim(Lua::str((Lua::truth($v17 = $args['expanded-topic']) ? $v17 : '')));
		if (($topic === '')) {
			$topic = $pageTitle;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain')), 'title_separator' => Lua::format($this->seoL($lang, 'title_separator'), $pageTitle), 'description' => Lua::format($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'author' => $this->seoL($lang, 'author'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v18 = $this->context->message('Debate.subsections', $lang, $key, ...$rest)) ? $v18 : '');
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = Lua::str((Lua::truth($v19 = $key) ? $v19 : ''));
		if (($key === '')) {
			return '';
		}
		$opts = (Lua::truth($v20 = $opts) ? $v20 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
		$linkHtml = '';
		$title = '';
		$text = '';
		if (Lua::truth($isReorg)) {
			$formName = Lua::str((Lua::truth($v21 = $opts['form']) ? $v21 : ''));
			$tooltip = Lua::str((Lua::truth($v22 = $opts['tooltip']) ? $v22 : ''));
			$linkLabel = Lua::str((Lua::truth($v23 = $opts['link_label']) ? $v23 : ''));
			if (($linkLabel === '')) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (($formName !== '')) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if ((Lua::truth($isReorg) && ($linkHtml !== ''))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = Lua::trim(Lua::str((Lua::truth($v24 = $title) ? $v24 : '')));
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
		if ((Lua::type($val) === 'string')) {
			$val = Lua::trim($val);
			if (($val === '')) {
				return null;
			}
		}
		$cur = $pending[$key];
		if (Lua::truth($multi)) {
			if (($cur === null)) {
				$pending[$key] = (Lua::truth($v25 = (Lua::truth($v26 = (Lua::type($val) === 'table')) ? $val : $v26)) ? $v25 : new Table([1 => $val]));
				return null;
			}
			if ((Lua::type($cur) !== 'table')) {
				$cur = new Table([1 => $cur]);
				$pending[$key] = $cur;
			}
			if ((Lua::type($val) === 'table')) {
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
		$raw = (Lua::truth($v27 = (Lua::truth($v28 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v28)) ? $v27 : '');
		if (($raw === '')) {
			return null;
		}
		$sep = (Lua::truth($v29 = $sep) ? $v29 : $this->ITEM_SEP);
		$vals = new Table([]);
		if (Lua::truth(Lua::find($raw, $sep, 1, true))) {
			foreach (Lua::split($raw, $sep, true) as $part) {
				$part = Lua::trim($part);
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
			if ((Lua::type($v) === 'table')) {
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
		$s = Lua::str((Lua::truth($v33 = $s) ? $v33 : ''));
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
		$formName = Lua::str((Lua::truth($v34 = $formName) ? $v34 : ''));
		$pageTitle = Lua::str((Lua::truth($v35 = $pageTitle) ? $v35 : ''));
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = '&nbsp;';
		$tt = Lua::str((Lua::truth($v36 = $tooltip) ? $v36 : ''));
		if (($tt !== '')) {
			return Lua::format('<span class="wk-adddata-link bouton-modifier navigation-not-searchable noprint" data-wk-tooltip="%s">[[%s|%s]]</span>', $this->escapeAttr($tt), $target, $lt);
		}
		return Lua::format('[[%s|%s]]', $target, $lt);
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function normalizeTooltipText($s = null) {
		return NativeText::tooltip($s);
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = Lua::str((Lua::truth($v38 = $formName) ? $v38 : ''));
		$label = Lua::str((Lua::truth($v39 = $label) ? $v39 : ''));
		$query = (Lua::truth($v40 = (Lua::truth($v41 = (Lua::type($query) === 'table')) ? $query : $v41)) ? $v40 : new Table([]));
		$tooltip = Lua::str((Lua::truth($v42 = $tooltip) ? $v42 : ''));
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
		$formName = Lua::str((Lua::truth($v45 = $formName) ? $v45 : ''));
		$pageTitle = Lua::str((Lua::truth($v46 = $pageTitle) ? $v46 : ''));
		$label = Lua::str((Lua::truth($v47 = $label) ? $v47 : ''));
		$tooltip = Lua::str((Lua::truth($v48 = $tooltip) ? $v48 : ''));
		$query = (Lua::truth($v49 = (Lua::truth($v50 = (Lua::type($query) === 'table')) ? $query : $v50)) ? $v49 : new Table([]));
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
		$formName = Lua::str((Lua::truth($v53 = $formName) ? $v53 : ''));
		$fieldLabel = Lua::str((Lua::truth($v54 = $fieldLabel) ? $v54 : ''));
		$fieldName = Lua::str((Lua::truth($v55 = $fieldName) ? $v55 : ''));
		$value = Lua::str((Lua::truth($v56 = $value) ? $v56 : ''));
		if ((((($formName === '') || ($fieldLabel === '')) || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$q = new Table([]);
		$q[($fieldLabel . '[' . $fieldName . ']')] = $value;
		if ((Lua::type($extraParams) === 'table')) {
			foreach (Lua::pairs($extraParams) as $k => $v) {
				if ((((Lua::truth($k) && ($k !== '')) && ($v !== null)) && (Lua::str($v) !== ''))) {
					$q[$k] = Lua::str($v);
				}
			}
		}
		$q['_run'] = '';
		$url = Lua::str($this->context->fullUrl($title, $q));
		$label = (Lua::truth($v57 = $linktext) ? $v57 : $value);
		$tt = Lua::str((Lua::truth($v58 = $tooltip) ? $v58 : ''));
		$link = ('[' . $url . ' ' . $label . ']');
		if (($tt !== '')) {
			return Lua::format('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return Lua::format('<span class="wk-adddata-link">%s</span>', $link);
	}

	protected function joinArticles($items = null, $lang = null) {
		$n = Lua::len($items);
		if (($n === 0)) {
			return '';
		}
		if (($n === 1)) {
			return $items[1];
		}
		$conj = $this->context->message('Common.text', $lang, 'list_conjunction_text');
		if (($n === 2)) {
			return ($items[1] . $conj . $items[2]);
		}
		$last = $items[$n];
		$first = new Table([]);
		$stop13 = ($n - 1);
		for ($i = 1; $i <= $stop13; $i++) {
			$first[$i] = $items[$i];
		}
		return (Lua::concat($first, ', ') . $conj . $last);
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function push($t = null, $s = null) {
		$t[(Lua::len($t) + 1)] = $s;
	}

	protected function buildSummaryLabelLookup($lang = null, $keys = null) {
		$lookup = [];
		foreach (((Lua::truth($v59 = $keys) ? $v59 : new Table([])))->sequence() as $_ => $key) {
			$lab = Lua::trim($this->subMsg($lang, ($key . '_label')));
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return new Table($lookup);
	}

	protected function getArgumentWarningLookup($lang = null) {
		$lang = Lua::str((Lua::truth($v60 = $lang) ? $v60 : ''));
		if (!(Lua::truth($this->ARGUMENT_WARNING_LOOKUPS[$lang]))) {
			$this->ARGUMENT_WARNING_LOOKUPS[$lang] = $this->buildSummaryLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
		}
		return $this->ARGUMENT_WARNING_LOOKUPS[$lang];
	}

	protected function resolveKeyFromLookup($raw = null, $lookup = null, $allowedKeys = null, $aliases = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v61 = $raw) ? $v61 : '')));
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

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsArg = Lua::trim(Lua::str((Lua::truth($v64 = $itemWarnings) ? $v64 : '')));
		if (($warnsArg === '')) {
			return null;
		}
		$lookup = $this->getArgumentWarningLookup($lang);
		$hasBanner = false;
		foreach ($this->csvValues($warnsArg, ',') as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->ARGUMENT_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, null));
				$hasBanner = true;
			}
		}
		if ((Lua::truth($hasBanner) && Lua::truth($cats))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]'));
		}
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
		$idPrefix = (Lua::truth($v69 = (Lua::truth($v70 = ($kind === 'pro')) ? 'Pro_argument_' : $v70)) ? $v69 : 'Con_argument_');
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
		$idPrefix = (Lua::truth($v73 = (Lua::truth($v74 = $isPro) ? 'Pro_argument_' : $v74)) ? $v73 : 'Con_argument_');
		$page = Lua::str((Lua::truth($v75 = $it['page']) ? $v75 : ''));
		$titleShown = Lua::str((Lua::truth($v76 = $it['title']) ? $v76 : ''));
		if (($titleShown === '')) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = Lua::str((Lua::truth($v77 = $it['warnings']) ? $v77 : ''));
		$banners = new Table([]);
		$this->renderArgumentItemBanners($warnings, $pv, $lang, $cats, $banners);
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . (Lua::truth($v78 = (Lua::truth($v79 = (Lua::len($banners) > 0)) ? Lua::concat($banners) : $v79)) ? $v78 : '') . '</div>' . '</li>');
	}

	protected function renderMetaWarningHtml($params = null) {
		$color = Lua::str((Lua::truth($v80 = $params['color']) ? $v80 : ''));
		$icon = Lua::str((Lua::truth($v81 = $params['icon']) ? $v81 : ''));
		$title = Lua::str((Lua::truth($v82 = $params['title']) ? $v82 : ''));
		$text = Lua::str((Lua::truth($v83 = $params['text']) ? $v83 : ''));
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre">' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderMainBanner($frame = null, $pv = null, $lang = null) {
		$titre = Lua::format($this->L($lang, 'text', 'main_banner_title'), $this->context->nowiki($pv['rawTitle']));
		return $this->renderMetaWarningHtml(new Table(['color' => $this->L($lang, 'text', 'main_banner_color'), 'title' => $titre, 'icon' => 'wk-icon-wikidebates', 'text' => ('<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">' . $this->L($lang, 'text', 'learn_more_label') . $this->L($lang, 'text', 'founding_principles_link') . '</div>')]));
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v84 = $this->context->message('Debate.banners', $lang, $key, ...$rest)) ? $v84 : '');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, $cats = null) {
		$key = Lua::str((Lua::truth($v85 = $key) ? $v85 : ''));
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
			$lab = Lua::trim($this->bannerMsg($lang, ($key . '_label')));
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveBannerKeyFromLookup($raw = null, $lookup = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v87 = $raw) ? $v87 : '')));
		if (($raw === '')) {
			return '';
		}
		return (Lua::truth($v88 = (Lua::truth($v89 = $lookup) ? $lookup[$raw] : $v89)) ? $v88 : '');
	}

	protected function renderWarningBanners($args = null, $pv = null, $cats = null, $lang = null) {
		$av = $args['progress'];
		$out = new Table([]);
		$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
		$debateLookup = $this->buildBannerLabelLookup($lang, $this->DEBATE_WARNING_BANNER_KEYS);
		$progLookup = $this->buildBannerLabelLookup($lang, $this->PROGRESS_BANNER_KEYS);
		$renderBannersFromList = function($list = null, $lookup = null) use (&$pv, &$cats, &$lang) {
		$html = new Table([]);
		foreach ($this->csvValues($list, ',') as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (($key !== '')) {
				Lua::insert($html, $this->renderBannerByKey($pv, $lang, $key, $cats));
			}
		}
		return Lua::concat($html);
		};
		if (Lua::truth($args['title-warnings'])) {
			Lua::insert($out, $renderBannersFromList($args['title-warnings'], $titleLookup));
		}
		if ((Lua::truth($av) && (Lua::trim($av) !== ''))) {
			$key = $this->resolveBannerKeyFromLookup($av, $progLookup);
			if (($key !== '')) {
				Lua::insert($out, $this->renderBannerByKey($pv, $lang, $key, $cats));
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
			Lua::insert($out, $renderBannersFromList($args['debate-warnings'], $debateLookup));
		}
		return Lua::concat($out);
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
			$lab = Lua::trim($this->subMsg($lang, ('intro_subbanner_' . $k . '_label')));
			if (($lab !== '')) {
				$lookup[$lab] = ('intro_subbanner_' . $k);
			}
		}
		return $lookup;
	}

	protected function wkRenderIntroSubsection($pv = null, $lang = null, $it = null, $cats = null, $lookup = null) {
		$titre = Lua::trim(Lua::str((Lua::truth($v101 = $it['title']) ? $v101 : '')));
		$contenu = Lua::trim(Lua::str((Lua::truth($v102 = $it['content']) ? $v102 : '')));
		$avert = Lua::trim(Lua::str((Lua::truth($v103 = $it['warnings']) ? $v103 : '')));
		if (($titre === '')) {
			$titre = $this->L($lang, 'text', 'default_subsection_title');
		}
		$out = [];
		$out[] = '<div class="fr-collapsible fr-collapsed">';
		$out[] = ('<h3 class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-question">' . $titre . '</h3>');
		$out[] = '<div class="fr-collapsible-content"><div class="encadre-section-deroulante">';
		if (($avert !== '')) {
			$banners = [];
			foreach (Lua::split($avert, ',', true) as $lab) {
				$lab = Lua::trim($lab);
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
		$raw = (Lua::truth($v105 = (Lua::truth($v106 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v106)) ? $v105 : '');
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
		$raw = (Lua::truth($v108 = (Lua::truth($v109 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v109)) ? $v108 : '');
		$wrap = (HtmlBuilder::create('div'))->addClass('bandeau-section wk-icon-before wk-icon-wikipedia hover-top navigation-not-searchable');
		$hasPages = false;
		if (($raw !== '')) {
			$expanded = $this->context->expandField($raw, 'debate.wikipedia');
			$pages = $this->wkParseWikipediaMarkers($expanded);
			if ((Lua::len($pages) > 0)) {
				$hasPages = true;
				$items = new Table([]);
				foreach (($pages)->sequence() as $_ => $page) {
					$url = $this->wkWikipediaUrl($page, $lang);
					$items[(Lua::len($items) + 1)] = ('<span class="hover-wikipedia">[' . $url . ' ' . $page . ']</span>');
				}
				($wrap)->wikitext(Lua::format($this->L($lang, 'text', 'wikipedia_seealso_msg'), $this->joinArticles($items, $lang)));
			}
			else {
				(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
			}
		}
		else {
			(($wrap)->addClass('aucun-contenu'))->wikitext($this->L($lang, 'text', 'none_wikipedia_msg'));
		}
		($wrap)->wikitext($this->addDataLink($this->L($lang, 'forms', 'wiki_seealso_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'wikipedia_edit_tt')));
		if ((($raw === '') || !(Lua::truth($hasPages)))) {
			return (Lua::str($wrap) . '[[Category:' . $this->L($lang, 'categories', 'wikipedia_missing') . ']]');
		}
		return Lua::str($wrap);
	}

	protected function renderQuestionsFAQ($sujetComplet = null, $sens = null, $lang = null) {
		$topic = (Lua::truth($v110 = (Lua::truth($v111 = (Lua::truth($v112 = $sujetComplet) ? ($sujetComplet !== '') : $v112)) ? $sujetComplet : $v111)) ? $v110 : '');
		$isCon = ($sens === 'con');
		$q = null;
		if (Lua::truth($isCon)) {
			$q = Lua::format($this->L($lang, 'text', 'faq_q_con'), $topic);
		}
		else {
			$q = Lua::format($this->L($lang, 'text', 'faq_q_pro'), $topic);
		}
		return ('<div class="question-FAQ">' . $q . '</div>');
	}

	protected function renderArgsList($args = null, $pv = null, $lang = null, $sens = null, $maps = null, $cats = null) {
		$isCon = ($sens === 'con');
		$hIconCon = 'is-con';
		$hIconPro = 'is-pro';
		$title = (Lua::truth($v113 = (Lua::truth($v114 = $isCon) ? $this->L($lang, 'text', 'args_con_title') : $v114)) ? $v113 : $this->L($lang, 'text', 'args_pro_title'));
		$btnForm = $this->addDataLink((Lua::truth($v115 = (Lua::truth($v116 = $isCon) ? $this->L($lang, 'forms', 'args_con_edit_title') : $v116)) ? $v115 : $this->L($lang, 'forms', 'args_pro_edit_title')), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), (Lua::truth($v117 = (Lua::truth($v118 = $isCon) ? $this->L($lang, 'text', 'args_con_edit_tt') : $v118)) ? $v117 : $this->L($lang, 'text', 'args_pro_edit_tt')));
		$out = new Table([]);
		$this->push($out, $this->listBreaker());
		$this->push($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-argument ' . (Lua::truth($v119 = (Lua::truth($v120 = $isCon) ? $hIconCon : $v120)) ? $v119 : $hIconPro) . '">' . $title . $btnForm . '</h2>'));
		$this->push($out, $this->renderQuestionsFAQ($args['expanded-topic'], $sens, $lang));
		$mode = (Lua::truth($v121 = (Lua::truth($v122 = (Lua::truth($v123 = $maps) ? $maps['_mode'] : $v123)) ? (Lua::truth($v124 = (Lua::truth($v125 = $isCon) ? $maps['_mode']['con'] : $v125)) ? $v124 : $maps['_mode']['pro']) : $v122)) ? $v121 : 'none');
		$data = (Lua::truth($v126 = (Lua::truth($v127 = $maps) ? (Lua::truth($v128 = (Lua::truth($v129 = $isCon) ? $maps['con'] : $v129)) ? $v128 : $maps['pro']) : $v127)) ? $v126 : null);
		$items = (Lua::truth($v130 = (Lua::truth($v131 = $data) ? $data['items'] : $v131)) ? $v130 : new Table([]));
		if ((($mode === 'data') && (Lua::len($items) > 0))) {
			$ul = ((HtmlBuilder::create('ul'))->addClass('argument-list'))->addClass((Lua::truth($v132 = (Lua::truth($v133 = $isCon) ? 'is-con' : $v133)) ? $v132 : 'is-pro'));
			$lis = [];
			$anyTitleWarnings = false;
			foreach (($items)->sequence() as $i => $it) {
				if ((Lua::truth($it['warnings']) && (Lua::trim($it['warnings']) !== ''))) {
					$anyTitleWarnings = true;
				}
				$lis[] = $this->wkRenderArgumentLi($pv, $lang, $it, (Lua::truth($v134 = (Lua::truth($v135 = $isCon) ? 'con' : $v135)) ? $v134 : 'pro'), $i, $cats);
			}
			($ul)->wikitext(implode('
', $lis));
			if (Lua::truth($anyTitleWarnings)) {
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]'));
			}
			$this->push($out, Lua::str($ul));
		}
		else {
			$cat = (Lua::truth($v136 = (Lua::truth($v137 = $isCon) ? ('[[Category:' . $this->L($lang, 'categories', 'con_missing') . ']]') : $v137)) ? $v136 : ('[[Category:' . $this->L($lang, 'categories', 'pro_missing') . ']]'));
			$msg = (Lua::truth($v138 = (Lua::truth($v139 = $isCon) ? $this->L($lang, 'text', 'none_con_args_msg') : $v139)) ? $v138 : $this->L($lang, 'text', 'none_pro_args_msg'));
			Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $msg . '</div>' . $cat));
		}
		$sideLabel = (Lua::truth($v140 = (Lua::truth($v141 = $isCon) ? $this->L($lang, 'text', 'side_con_word') : $v141)) ? $v140 : $this->L($lang, 'text', 'side_pro_word'));
		$label = Lua::format($this->L($lang, 'text', 'args_add_label'), $sideLabel);
		$tooltip = Lua::format($this->L($lang, 'text', 'arg_add_tt'), $sideLabel, $pv['rawTitle']);
		$baseField = $this->L($lang, 'forms', 'new_arg_title');
		$rqTypeValue = (Lua::truth($v142 = (Lua::truth($v143 = $isCon) ? $this->L($lang, 'forms', 'new_arg_con_type_label') : $v143)) ? $v142 : $this->L($lang, 'forms', 'new_arg_pro_type_label'));
		$rqQuery = new Table([]);
		$rqQuery[($baseField . '[type]')] = $rqTypeValue;
		$rqQuery[($baseField . '[ID]')] = $pv['pageId'];
		$rqQuery['_run'] = '1';
		$this->push($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($baseField, $label, $tooltip, $rqQuery) . '</div>'));
		return Lua::concat($out);
	}

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, &$out = null) {
		$warnsRef = Lua::trim(Lua::str((Lua::truth($v144 = $itemWarnings) ? $v144 : '')));
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
			$out[] = ((Lua::truth($v165 = (Lua::truth($v166 = (count($out) > 0)) ? ', ' : $v166)) ? $v165 : '') . $renderedLabel);
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
			foreach (Lua::split($authors, ',', true) as $a) {
				$a = Lua::trim($a);
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
		$out[] = ((Lua::truth($v172 = (Lua::truth($v173 = (count($out) > 0)) ? ', ' : $v173)) ? $v172 : '') . $renderedTitle);
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . implode('', $out) . '</li>');
	}

	protected function wkRenderVideoListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null) {
		return $this->wkRenderNativeReferenceList($raw, 'videography', function($item = null) use (&$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneVideoLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function renderRefBlock($pageVars = null, $lang = null, $iconClass = null, $titleText = null, $formKey = null, $value = null) {
		$out = new Table([]);
		Lua::insert($out, ('<h4 class="titre-references wk-icon-before ' . $iconClass . ' navigation-not-searchable">' . $titleText . $this->addDataLink($this->L($lang, 'forms', $formKey), $pageVars['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'section_edit_tt')) . '</h4>'));
		if ((Lua::truth($value) && (Lua::trim($value) !== ''))) {
			Lua::insert($out, ('<ul>' . $value . '</ul>'));
		}
		else {
			Lua::insert($out, ('<div class="aucune-reference navigation-not-searchable">' . $this->L($lang, 'text', 'none_references_msg') . '</div>'));
		}
		return Lua::concat($out);
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
		$text = Lua::trim(Lua::str((Lua::truth($v187 = $text) ? $v187 : '')));
		if (($text === '')) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = Lua::trim(Lua::str((Lua::truth($v188 = $titleText) ? $v188 : '')));
		if (($titleText === '')) {
			return '';
		}
		return Lua::str($this->context->fullUrl($titleText));
	}

	protected function wkTakeFirst($items = null, $limit = null) {
		$out = new Table([]);
		$limit = (Lua::truth($v189 = Lua::number($limit)) ? $v189 : 0);
		if (($limit <= 0)) {
			return $out;
		}
		$stop5 = Lua::len($items);
		for ($i = 1; $i <= $stop5; $i++) {
			if (($i > $limit)) {
				break;
			}
			$out[(Lua::len($out) + 1)] = $items[$i];
		}
		return $out;
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

	protected function wkSchemaPageLabel($it = null) {
		$label = Lua::trim(Lua::str((Lua::truth($v190 = (Lua::truth($v191 = $it) ? $it['page'] : $v191)) ? $v190 : '')));
		if (($label === '')) {
			$label = Lua::trim(Lua::str((Lua::truth($v192 = (Lua::truth($v193 = $it) ? $it['title'] : $v193)) ? $v192 : '')));
		}
		return $label;
	}

	protected function wkBuildAnswerNode($it = null, $kind = null, $lang = null) {
		$pageLabel = Lua::trim(Lua::str((Lua::truth($v194 = (Lua::truth($v195 = $it) ? $it['page'] : $v195)) ? $v194 : '')));
		if (($pageLabel === '')) {
			return null;
		}
		$answerName = Lua::trim(Lua::str((Lua::truth($v196 = (Lua::truth($v197 = $it) ? $it['title'] : $v197)) ? $v196 : '')));
		if (($answerName === '')) {
			$answerName = $pageLabel;
		}
		$url = $this->wkFullUrl($pageLabel);
		if (($url === '')) {
			return null;
		}
		$additionalType = null;
		if (($kind === 'pro')) {
			$additionalType = $this->wkBuildWikiUrl($lang, 'property_prefix', 'pro_label');
		}
		else {
			$additionalType = $this->wkBuildWikiUrl($lang, 'property_prefix', 'con_label');
		}
		return $this->jsonNode(['@type' => 'Answer', '@id' => ($url . '#answer'), 'url' => $url, 'name' => $answerName, 'text' => $pageLabel, 'author' => $this->jsonNode(['@id' => ($this->L($lang, 'urls', 'home') . '#organization')]), 'additionalType' => $additionalType, 'about' => $this->jsonNode(['@type' => 'Claim', '@id' => ($url . '#claim')])]);
	}

	protected function wkBuildSuggestedAnswers($maps = null, $lang = null) {
		$out = new Table([]);
		$position = 1;
		$proItems = (Lua::truth($v198 = (Lua::truth($v199 = (Lua::truth($v200 = $maps) ? $maps['pro'] : $v200)) ? $maps['pro']['items'] : $v199)) ? $v198 : new Table([]));
		$conItems = (Lua::truth($v201 = (Lua::truth($v202 = (Lua::truth($v203 = $maps) ? $maps['con'] : $v203)) ? $maps['con']['items'] : $v202)) ? $v201 : new Table([]));
		foreach (($this->wkTakeFirst($proItems, 3))->sequence() as $_ => $it) {
			$node = $this->wkBuildAnswerNode($it, 'pro', $lang);
			if (Lua::truth($node)) {
				$out[(Lua::len($out) + 1)] = $node;
				$position = ($position + 1);
			}
		}
		foreach (($this->wkTakeFirst($conItems, 3))->sequence() as $_ => $it) {
			$node = $this->wkBuildAnswerNode($it, 'con', $lang);
			if (Lua::truth($node)) {
				$out[(Lua::len($out) + 1)] = $node;
				$position = ($position + 1);
			}
		}
		return $out;
	}

	protected function wkBuildIsPartOf($lang = null) {
		$out = new Table([]);
		$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'debates_label')]);
		$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => ($this->L($lang, 'urls', 'home') . '#website')]);
		return $out;
	}

	protected function wkBuildIsRelatedTo($args = null) {
		$out = new Table([]);
		$raw = Lua::trim(Lua::str((Lua::truth($v204 = (Lua::truth($v205 = $args) ? $args['related-debates'] : $v205)) ? $v204 : '')));
		if (($raw === '')) {
			return $out;
		}
		$count = 0;
		foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
			$part = Lua::trim(Lua::str((Lua::truth($v206 = $part) ? $v206 : '')));
			if (($part !== '')) {
				$count = ($count + 1);
				if (($count > 5)) {
					break;
				}
				$url = $this->wkFullUrl($part);
				if (($url !== '')) {
					$out[(Lua::len($out) + 1)] = $this->jsonNode(['@id' => ($url . '#qa')]);
				}
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
			$section = Lua::trim(Lua::str((Lua::truth($v209 = $sections[$i]) ? $v209 : '')));
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
			$kw = Lua::trim(Lua::str((Lua::truth($v212 = $keywords[$i]) ? $v212 : '')));
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
		$authors = Lua::trim(Lua::str((Lua::truth($v214 = $authors) ? $v214 : '')));
		if (($authors === '')) {
			return null;
		}
		$out = [];
		foreach (Lua::split($authors, ',', true) as $a) {
			$a = Lua::trim(Lua::str((Lua::truth($v215 = $a) ? $v215 : '')));
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

	protected function wkBuildSingleNodeFromFirstItem($items = null, $builder = null) {
		$out = new Table([]);
		$stop1 = Lua::len((Lua::truth($v216 = $items) ? $v216 : new Table([])));
		for ($i = 1; $i <= $stop1; $i++) {
			$node = $builder($items[$i]);
			if (Lua::truth($node)) {
				$out[1] = $node;
				break;
			}
		}
		return $out;
	}

	protected function wkBuildClaimCitationsFromBibliography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v217 = $raw) ? $v217 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->nativeReferenceValues('bibliography', $raw);
		return $this->wkBuildSingleNodeFromNativeItems($items, function($item = null) {
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
		if (!(Lua::truth($node))) {
			return null;
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
		$raw = Lua::trim(Lua::str((Lua::truth($v226 = $raw) ? $v226 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->nativeReferenceValues('webliography', $raw);
		return $this->wkBuildSingleNodeFromNativeItems($items, function($item = null) {
		$page = $item['page'];
		$site = $item['site'];
		$link = $item['link'];
		$date = $item['date'];
		if ((($link === '') || ($page === ''))) {
			return null;
		}
		$node = $this->jsonNode(['@type' => 'WebPage', 'name' => $page, 'url' => $link]);
		if (($site !== '')) {
			$node['isPartOf'] = $this->jsonNode(['@type' => 'WebSite', 'name' => $site]);
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v231 = $raw) ? $v231 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->nativeReferenceValues('videography', $raw);
		return $this->wkBuildSingleNodeFromNativeItems($items, function($item = null) {
		$title = $item['title'];
		$link = $item['link'];
		$authors = $item['authors'];
		if ((($title === '') || ($link === ''))) {
			return null;
		}
		$node = $this->jsonNode(['@type' => 'VideoObject', 'name' => $title, 'url' => $link]);
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
			$suggestedAnswer = $this->wkBuildSuggestedAnswers($maps, $lang);
			$isPartOf = $this->wkBuildIsPartOf($lang);
			$isRelatedTo = $this->wkBuildIsRelatedTo($args);
			$pageCreation = $this->getPageCreation($pv, $args);
			$questionText = null;
			if ((Lua::truth($args['expanded-topic']) && (Lua::trim($args['expanded-topic']) !== ''))) {
				$questionText = Lua::format($this->L($lang, 'text', 'faq_q'), Lua::trim($args['expanded-topic']));
			}
			else {
				$questionText = $pv['rawTitle'];
			}
			$refRaw = (Lua::truth($v235 = $refRaw) ? $v235 : new Table([]));
			$refRaw['bibliography'] = (Lua::truth($v236 = $refRaw['bibliography']) ? $v236 : new Table([]));
			$refRaw['webliography'] = (Lua::truth($v237 = $refRaw['webliography']) ? $v237 : new Table([]));
			$refRaw['videography'] = (Lua::truth($v238 = $refRaw['videography']) ? $v238 : new Table([]));
			$bibliographyRaw = '';
			if (($refRaw['bibliography']['pro'] !== '')) {
				$bibliographyRaw = $refRaw['bibliography']['pro'];
			}
			if (($refRaw['bibliography']['con'] !== '')) {
				if (($bibliographyRaw !== '')) {
					$bibliographyRaw = ($bibliographyRaw . $this->ITEM_SEP);
				}
				$bibliographyRaw = ($bibliographyRaw . $refRaw['bibliography']['con']);
			}
			if (($refRaw['bibliography']['neutral'] !== '')) {
				if (($bibliographyRaw !== '')) {
					$bibliographyRaw = ($bibliographyRaw . $this->ITEM_SEP);
				}
				$bibliographyRaw = ($bibliographyRaw . $refRaw['bibliography']['neutral']);
			}
			$webliographyRaw = '';
			if (($refRaw['webliography']['pro'] !== '')) {
				$webliographyRaw = $refRaw['webliography']['pro'];
			}
			if (($refRaw['webliography']['con'] !== '')) {
				if (($webliographyRaw !== '')) {
					$webliographyRaw = ($webliographyRaw . $this->ITEM_SEP);
				}
				$webliographyRaw = ($webliographyRaw . $refRaw['webliography']['con']);
			}
			if (($refRaw['webliography']['neutral'] !== '')) {
				if (($webliographyRaw !== '')) {
					$webliographyRaw = ($webliographyRaw . $this->ITEM_SEP);
				}
				$webliographyRaw = ($webliographyRaw . $refRaw['webliography']['neutral']);
			}
			$videographyRaw = '';
			if (($refRaw['videography']['pro'] !== '')) {
				$videographyRaw = $refRaw['videography']['pro'];
			}
			if (($refRaw['videography']['con'] !== '')) {
				if (($videographyRaw !== '')) {
					$videographyRaw = ($videographyRaw . $this->ITEM_SEP);
				}
				$videographyRaw = ($videographyRaw . $refRaw['videography']['con']);
			}
			if (($refRaw['videography']['neutral'] !== '')) {
				if (($videographyRaw !== '')) {
					$videographyRaw = ($videographyRaw . $this->ITEM_SEP);
				}
				$videographyRaw = ($videographyRaw . $refRaw['videography']['neutral']);
			}
			$citations = new Table([]);
			$b = $this->wkBuildClaimCitationsFromBibliography($bibliographyRaw);
			if (Lua::truth($b[1])) {
				$citations[(Lua::len($citations) + 1)] = $b[1];
			}
			$w = $this->wkBuildClaimCitationsFromWebliography($webliographyRaw);
			if (Lua::truth($w[1])) {
				$citations[(Lua::len($citations) + 1)] = $w[1];
			}
			$subjectOf = $this->wkBuildClaimCitationsFromVideography($videographyRaw);
			$qaNode = (['@type' => 'QAPage', '@id' => ($pv['pageUrl'] . '#qa'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'educationalUse' => ([1 => 'debate', 2 => 'critical thinking']), 'learningResourceType' => 'debate', 'audience' => (['@type' => 'EducationalAudience', 'educationalLevel' => ([1 => 'general public', 2 => 'secondary education'])]), 'isPartOf' => $isPartOf, 'breadcrumb' => (['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => (['@id' => ($this->L($lang, 'urls', 'home') . '#organization')]), 'mainEntity' => (['@type' => 'Question', '@id' => ($pv['pageUrl'] . '#question'), 'name' => $pv['rawTitle'], 'text' => $questionText, 'answerCount' => Lua::len($suggestedAnswer), 'suggestedAnswer' => $suggestedAnswer])]);
			if ((Lua::truth($pageCreation) && ($pageCreation !== ''))) {
				$qaNode['mainEntity']['datePublished'] = $pageCreation;
			}
			if ((Lua::len($isRelatedTo) > 0)) {
				$qaNode['isRelatedTo'] = $isRelatedTo;
			}
			if ((Lua::len($keywords) > 0)) {
				$qaNode['keywords'] = $keywords;
			}
			if ((Lua::len($about) > 0)) {
				$qaNode['about'] = $about;
			}
			if ((Lua::len($mentions) > 0)) {
				$qaNode['mentions'] = $mentions;
			}
			if ((Lua::len($citations) > 0)) {
				$qaNode['citation'] = $citations;
			}
			if (Lua::truth($subjectOf[1])) {
				$qaNode['subjectOf'] = $subjectOf[1];
			}
			$data = (['@context' => 'https://schema.org', '@graph' => ([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $qaNode])]);
			if ($this->context instanceof NativeJsonContext) { return $this->context->jsonLdData($data); }
			$json = $this->context->jsonEncode(Table::wrap($data));
			return $this->context->jsonLd($json);
		} finally { $this->nativeJson = $previousNativeJson; }
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null) {
		$out = [];
		$out[count($out) + 1] = ('<div style="font-size: 95%; margin-top: 1em;">' . $this->L($lang, 'text', 'keywords_label_text'));
		if ((Lua::truth($args['keywords']) && (Lua::trim($args['keywords']) !== ''))) {
			$rendered = [];
			$formName = $this->L($lang, 'forms', 'search_by_keywords_title');
			$fieldLabel = $formName;
			$fieldName = $this->L($lang, 'params', 'keywords_field');
			$tt = $this->L($lang, 'text', 'search_by_keyword_tt');
			$typeField = $this->L($lang, 'params', 'search_type_field');
			$typeValue = $this->L($lang, 'params', 'search_type_value');
			$extra = new Table([($fieldLabel . '[' . $typeField . ']') => $typeValue]);
			foreach ($this->csvValues($args['keywords'], ',') as $_ => $k) {
				$k = Lua::trim(Lua::str((Lua::truth($v239 = $k) ? $v239 : '')));
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
		if ((Lua::truth($raw) && (Lua::trim($raw) !== ''))) {
			$items = [];
			$n = 0;
			foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
				$part = Lua::trim(Lua::str((Lua::truth($v240 = $part) ? $v240 : '')));
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
		if ((Lua::truth($args['sections']) && (Lua::trim($args['sections']) !== ''))) {
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
		$pourSurvol = Lua::trim((Lua::truth($v246 = $pro['mapHover']) ? $v246 : ''));
		$contreSurvol = Lua::trim((Lua::truth($v247 = $con['mapHover']) ? $v247 : ''));
		if (($pourSurvol === '')) {
			$pourSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_pro_args_msg_short') . '</div>');
		}
		if (($contreSurvol === '')) {
			$contreSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_con_args_msg_short') . '</div>');
		}
		$listePour = Lua::trim((Lua::truth($v248 = $pro['argsVar']) ? $v248 : ''));
		$listeContre = Lua::trim((Lua::truth($v249 = $con['argsVar']) ? $v249 : ''));
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
		if ((Lua::truth($args['topic']) && (Lua::trim($args['topic']) !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'debate_topic'), $args['topic'], false);
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_missing') . ']]'));
		}
		if ((Lua::truth($args['expanded-topic']) && (Lua::trim($args['expanded-topic']) !== ''))) {
			$pieces[] = $this->context->shortDescription(Lua::format($this->L($lang, 'text', 'shortdesc_topic'), $args['expanded-topic']));
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_complete_missing') . ']]'));
			$pieces[] = $this->context->shortDescription($this->L($lang, 'text', 'shortdesc_plain'));
		}
		if ((!(Lua::truth($args['creation-date'])) || (Lua::trim($args['creation-date']) === ''))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'creation_date_missing') . ']]'));
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
			$box = new Table([]);
			Lua::insert($box, ('<h2 class="navigation-not-searchable noprint wk-icon-before wk-icon-selfeval">' . $this->L($lang, 'text', 'self_eval_title') . '</h2>'));
			$startBtn = $this->wkAddDataGetButton($this->L($lang, 'forms', 'self_eval_home_title'), $target, $this->L($lang, 'text', 'self_eval_start_label'), $this->L($lang, 'text', 'self_eval_start_tt'), new Table([($ns . '[' . $f_debat . ']') => $pv['rawTitle']]));
			Lua::insert($box, ('<div class="boite-info navigation-not-searchable noprint">' . $this->L($lang, 'text', 'self_eval_blurb') . $startBtn . '</div>'));
			$pieces[] = Lua::concat($box);
		}
		$pieces[] = $this->renderReferences($args, $pv, $lang, $cats, $smwPending, $refRaw);
		$pieces[] = $this->renderConnexes($args, $pv, $cats, $lang);
		$pieces[] = ('<h2 class="latest-changes-button navigation-not-searchable noprint wk-icon-before wk-icon-refresh">' . $this->L($lang, 'text', 'latest_changes_title') . '</h2>' . '<div class="latest-changes navigation-not-searchable noprint">' . '<div class="latest-changes-button wk-btn mw-ui-button"' . ' title="' . $this->L($lang, 'text', 'latest_changes_show_tt') . '"' . ' data-page="' . $this->context->nowiki($pv['rawTitle']) . '">' . $this->L($lang, 'text', 'latest_changes_show_label') . '</div>' . '<div class="latest-changes-wrapper"></div>' . '</div>');
		$pieces[] = $this->renderRubriques($args, $cats, $lang);
		$this->setSemanticData($args, $pv, $lang, $maps, $smwPending);
		$this->renderFullSEO($args, $pv, $lang);
		$pieces[] = Lua::concat($cats);
		$renameLink = Lua::format($this->L($lang, 'text', 'rename_special_page_text'), $pv['encoded']);
		$pieces[] = ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[' . $renameLink . '|' . $this->L($lang, 'text', 'rename_link_label') . ']]</span>');
		$pieces[] = ('<span id="bouton-modifier-sujet" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'topic_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'topic_edit_tt')) . '</span>');
		$pieces[] = ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'sections_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'sections_edit_tt')) . '</span>');
		if ((Lua::truth($args['interlanguage']) && (Lua::trim(Lua::str((Lua::truth($v251 = $args['interlanguage']) ? $v251 : ''))) !== ''))) {
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
