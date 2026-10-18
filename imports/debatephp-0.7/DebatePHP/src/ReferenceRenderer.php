<?php
namespace MediaWiki\Extension\DebatePHP;

/** Reference port of the supplied Module:Debate. See docs/equivalence.md.
 * No Lua interpreter is used. Table preserves reference and 1-based semantics.
 * Generated mechanically, then checked against the reference source.
 */
class ReferenceRenderer {
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
		if (Lua::truth((Lua::truth($v1 = !Lua::truth($val)) ? $v1 : ($val === '')))) {
			return false;
		}
		$label = $this->context->message('Debate.progress', $lang, $key);
		return ($val === $label);
	}

	protected function anchorId($s = null) {
		$s = Lua::str((Lua::truth($v2 = $s) ? $v2 : ''));
		$s = Lua::gsub($s, '&#039;', '\'');
		$s = Lua::gsub($s, '&#39;', '\'');
		$s = Lua::gsub($s, '&apos;', '\'');
		$s = Lua::gsub($s, '%s+', ' ');
		$s = Lua::trim($s);
		$s = Lua::gsub($s, ' ', '_');
		$s = Lua::gsub($s, '[%[%]{}|#<>"]', '');
		return $s;
	}

	protected function splitCSV($s = null, $sep = null) {
		return SerializedData::csv($s, $sep);
	}

	protected function wkParseWikipediaMarkers($raw = null) {
		$raw = Lua::str((Lua::truth($v5 = $raw) ? $v5 : ''));
		$raw = Lua::trim($raw);
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		if (Lua::truth(!Lua::truth(Lua::find($raw, $this->ITEM_SEP, 1, true)))) {
			return new Table([]);
		}
		$pages = new Table([]);
		foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
			$part = Lua::trim($part);
			if (Lua::truth(($part !== ''))) {
				$pages[(Lua::len($pages) + 1)] = $part;
			}
		}
		return $pages;
	}

	protected function seoCacheKey($args = null, $pv = null, $lang = null) {
		return Lua::concat(new Table([1 => Lua::str((Lua::truth($v6 = $lang) ? $v6 : '')), 2 => (Lua::truth($v7 = $pv['pageId']) ? $v7 : ''), 3 => Lua::str((Lua::truth($v8 = $pv['rawTitle']) ? $v8 : '')), 4 => Lua::str((Lua::truth($v9 = $args['expanded-topic']) ? $v9 : '')), 5 => Lua::str((Lua::truth($v10 = $args['keywords']) ? $v10 : '')), 6 => Lua::str((Lua::truth($v11 = $args['sections']) ? $v11 : ''))]), '
');
	}

	protected function seoL($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v12 = $this->context->message('Debate.seo', $lang, $key, ...$rest)) ? $v12 : '');
	}

	protected function seoJoinList($items = null) {
		$out = new Table([]);
		$seen = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v13 = $items) ? $v13 : new Table([]))) as $_ => $v) {
			$v = Lua::trim(Lua::str((Lua::truth($v14 = $v) ? $v14 : '')));
			if (Lua::truth((Lua::truth($v15 = ($v !== '')) ? !Lua::truth($seen[$v]) : $v15))) {
				$seen[$v] = true;
				$out[(Lua::len($out) + 1)] = $v;
			}
		}
		return Lua::concat($out, ', ');
	}

	protected function buildSeoKeywords($args = null, $lang = null) {
		$items = new Table([]);
		foreach (Lua::ipairs($this->splitCSV($args['keywords'], ',')) as $_ => $k) {
			$items[(Lua::len($items) + 1)] = $k;
		}
		foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $s) {
			$items[(Lua::len($items) + 1)] = $s;
		}
		return $this->seoJoinList($items);
	}

	protected function getPageCreation($pv = null, $args = null) {
		$v = (Lua::truth($v16 = $args) ? $args['creation-date'] : $v16);
		$v = (Lua::truth($v17 = (Lua::truth($v18 = (Lua::type($v) === 'string')) ? Lua::trim($v) : $v18)) ? $v17 : '');
		if (Lua::truth(($v !== ''))) {
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
		$pageTitle = (Lua::truth($v19 = $pv['rawTitle']) ? $v19 : '');
		$topic = Lua::trim(Lua::str((Lua::truth($v20 = $args['expanded-topic']) ? $v20 : '')));
		if (Lua::truth(($topic === ''))) {
			$topic = $pageTitle;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($pageTitle . ' ' . $this->L($lang, 'text', 'shortdesc_plain')), 'title_separator' => Lua::format($this->seoL($lang, 'title_separator'), $pageTitle), 'description' => Lua::format($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'author' => $this->seoL($lang, 'author'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
	}

	protected function subMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v21 = $this->context->message('Debate.subsections', $lang, $key, ...$rest)) ? $v21 : '');
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = Lua::str((Lua::truth($v22 = $key) ? $v22 : ''));
		if (Lua::truth(($key === ''))) {
			return '';
		}
		$opts = (Lua::truth($v23 = $opts) ? $v23 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
		$linkHtml = '';
		$title = '';
		$text = '';
		if (Lua::truth($isReorg)) {
			$formName = Lua::str((Lua::truth($v24 = $opts['form']) ? $v24 : ''));
			$tooltip = Lua::str((Lua::truth($v25 = $opts['tooltip']) ? $v25 : ''));
			$linkLabel = Lua::str((Lua::truth($v26 = $opts['link_label']) ? $v26 : ''));
			if (Lua::truth(($linkLabel === ''))) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (Lua::truth(($formName !== ''))) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if (Lua::truth((Lua::truth($v27 = $isReorg) ? ($linkHtml !== '') : $v27))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = Lua::trim(Lua::str((Lua::truth($v28 = $title) ? $v28 : '')));
			if (Lua::truth(($title !== ''))) {
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
		if (Lua::truth((Lua::truth($v29 = ($title === '')) ? ($text === '') : $v29))) {
			return '';
		}
		$html = ('<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">' . $title . '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">' . '<div class="smwttcontent">' . $text . '</div>' . '</span>' . '</div>');
		$cat = $this->subMsg($lang, ($key . '_category'));
		if (Lua::truth((Lua::truth($v30 = (Lua::truth($v31 = $cats) ? $cat : $v31)) ? ($cat !== '') : $v30))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function smwQueueAdd($pending = null, $key = null, $val = null, $multi = null) {
		if (Lua::truth((Lua::truth($v32 = (Lua::truth($v33 = !Lua::truth($key)) ? $v33 : ($key === ''))) ? $v32 : ($val === null)))) {
			return null;
		}
		if (Lua::truth((Lua::type($val) === 'string'))) {
			$val = Lua::trim($val);
			if (Lua::truth(($val === ''))) {
				return null;
			}
		}
		$cur = $pending[$key];
		if (Lua::truth($multi)) {
			if (Lua::truth(($cur === null))) {
				$pending[$key] = (Lua::truth($v34 = (Lua::truth($v35 = (Lua::type($val) === 'table')) ? $val : $v35)) ? $v34 : new Table([1 => $val]));
				return null;
			}
			if (Lua::truth((Lua::type($cur) !== 'table'))) {
				$cur = new Table([1 => $cur]);
				$pending[$key] = $cur;
			}
			if (Lua::truth((Lua::type($val) === 'table'))) {
				foreach (Lua::ipairs($val) as $_ => $x) {
					if (Lua::truth(($x !== null))) {
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
		$raw = (Lua::truth($v36 = (Lua::truth($v37 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v37)) ? $v36 : '');
		if (Lua::truth(($raw === ''))) {
			return null;
		}
		$sep = (Lua::truth($v38 = $sep) ? $v38 : $this->ITEM_SEP);
		$vals = new Table([]);
		if (Lua::truth(Lua::find($raw, $sep, 1, true))) {
			foreach (Lua::split($raw, $sep, true) as $part) {
				$part = Lua::trim($part);
				if (Lua::truth(($part !== ''))) {
					$vals[(Lua::len($vals) + 1)] = $part;
				}
			}
		}
		else {
			$vals[1] = $raw;
		}
		if (Lua::truth((Lua::len($vals) > 0))) {
			$this->smwQueueAdd($pending, $key, $vals, true);
		}
	}

	protected function smwQueueAddFromCSV($pending = null, $key = null, $raw = null) {
		$this->smwQueueAddFromSepString($pending, $key, $raw, ',');
	}

	protected function smwQueueMerge($dst = null, $src = null) {
		foreach (Lua::pairs((Lua::truth($v39 = $src) ? $v39 : new Table([]))) as $k => $v) {
			if (Lua::truth((Lua::type($v) === 'table'))) {
				$this->smwQueueAdd($dst, $k, $v, true);
			}
			else {
				$this->smwQueueAdd($dst, $k, $v, false);
			}
		}
	}


	protected function pf_escape($v = null) {
		if (Lua::truth(!Lua::truth($v))) {
			return '';
		}
		$v = Lua::str($v);
		$v = Lua::gsub(Lua::gsub($v, '|', '&#124;'), '=', '&#61;');
		return $v;
	}

	protected function wkEncodeAddDataSegment($s = null) {
		$s = Lua::str((Lua::truth($v42 = $s) ? $v42 : ''));
		$s = Lua::gsub($s, '%%', '%%25');
		$s = Lua::gsub($s, '%?', '%%3F');
		$s = Lua::gsub($s, '#', '%%23');
		$s = Lua::gsub($s, '/', '%%2F');
		return $s;
	}

	protected function wkWikipediaUrl($page = null, $lang = null) {
		if (Lua::truth((Lua::truth($v43 = !Lua::truth($page)) ? $v43 : ($page === '')))) {
			return '';
		}
		$base = $this->L($lang, 'urls', 'wikipedia');
		if (Lua::truth((Lua::truth($v44 = !Lua::truth($base)) ? $v44 : ($base === '')))) {
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
		$formName = Lua::str((Lua::truth($v45 = $formName) ? $v45 : ''));
		$pageTitle = Lua::str((Lua::truth($v46 = $pageTitle) ? $v46 : ''));
		if (Lua::truth((Lua::truth($v47 = ($formName === '')) ? $v47 : ($pageTitle === '')))) {
			return '';
		}
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = '&nbsp;';
		$tt = Lua::str((Lua::truth($v48 = $tooltip) ? $v48 : ''));
		if (Lua::truth(($tt !== ''))) {
			return Lua::format('<span class="wk-adddata-link bouton-modifier navigation-not-searchable noprint" data-wk-tooltip="%s">[[%s|%s]]</span>', $this->escapeAttr($tt), $target, $lt);
		}
		return Lua::format('[[%s|%s]]', $target, $lt);
	}

	protected function wkRunQueryPath($formName = null) {
		$form = $this->wkEncodeAddDataSegment($this->wkDbKey($formName));
		return ('Special:RunQuery/' . $form);
	}

	protected function normalizeTooltipText($s = null) {
		$s = Lua::str((Lua::truth($v49 = $s) ? $v49 : ''));
		$s = Lua::gsub($s, '\'', '’');
		$s = Lua::gsub($s, '"', '“');
		return $s;
	}

	protected function wkRunQueryHtmlTagButton($formName = null, $label = null, $tooltip = null, $query = null) {
		$formName = Lua::str((Lua::truth($v50 = $formName) ? $v50 : ''));
		$label = Lua::str((Lua::truth($v51 = $label) ? $v51 : ''));
		$query = (Lua::truth($v52 = (Lua::truth($v53 = (Lua::type($query) === 'table')) ? $query : $v53)) ? $v52 : new Table([]));
		$tooltip = Lua::str((Lua::truth($v54 = $tooltip) ? $v54 : ''));
		$tooltip = $this->normalizeTooltipText($tooltip);
		if (Lua::truth(($formName === ''))) {
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
		$formName = Lua::str((Lua::truth($v57 = $formName) ? $v57 : ''));
		$pageTitle = Lua::str((Lua::truth($v58 = $pageTitle) ? $v58 : ''));
		$label = Lua::str((Lua::truth($v59 = $label) ? $v59 : ''));
		$tooltip = Lua::str((Lua::truth($v60 = $tooltip) ? $v60 : ''));
		$query = (Lua::truth($v61 = (Lua::truth($v62 = (Lua::type($query) === 'table')) ? $query : $v62)) ? $v61 : new Table([]));
		if (Lua::truth((Lua::truth($v63 = ($formName === '')) ? $v63 : ($pageTitle === '')))) {
			return '';
		}
		$title = $this->wkAddDataPath($formName, $pageTitle);
		$url = Lua::str($this->context->fullUrl($title, $query));
		$linkInner = ('<span class="wk-btn__icon" aria-hidden="true">➜</span>' . '<span class="wk-btn__label">' . $label . '</span>');
		$link = ('[' . $url . ' ' . $linkInner . ']');
		return ('<div class="wk-btn wk-auto-id mw-ui-button navigation-not-searchable"' . (Lua::truth($v64 = (Lua::truth($v65 = ($tooltip !== '')) ? (' title="' . $this->escapeAttr($tooltip) . '"') : $v65)) ? $v64 : '') . '>' . $link . '</div>');
	}

	protected function runQueryLink($formName = null, $fieldLabel = null, $fieldName = null, $value = null, $linktext = null, $tooltip = null, $extraParams = null) {
		$formName = Lua::str((Lua::truth($v66 = $formName) ? $v66 : ''));
		$fieldLabel = Lua::str((Lua::truth($v67 = $fieldLabel) ? $v67 : ''));
		$fieldName = Lua::str((Lua::truth($v68 = $fieldName) ? $v68 : ''));
		$value = Lua::str((Lua::truth($v69 = $value) ? $v69 : ''));
		if (Lua::truth((Lua::truth($v70 = (Lua::truth($v71 = (Lua::truth($v72 = ($formName === '')) ? $v72 : ($fieldLabel === ''))) ? $v71 : ($fieldName === ''))) ? $v70 : ($value === '')))) {
			return '';
		}
		$title = $this->wkRunQueryPath($formName);
		$q = new Table([]);
		$q[($fieldLabel . '[' . $fieldName . ']')] = $value;
		if (Lua::truth((Lua::type($extraParams) === 'table'))) {
			foreach (Lua::pairs($extraParams) as $k => $v) {
				if (Lua::truth((Lua::truth($v73 = (Lua::truth($v74 = (Lua::truth($v75 = $k) ? ($k !== '') : $v75)) ? ($v !== null) : $v74)) ? (Lua::str($v) !== '') : $v73))) {
					$q[$k] = Lua::str($v);
				}
			}
		}
		$q['_run'] = '';
		$url = Lua::str($this->context->fullUrl($title, $q));
		$label = (Lua::truth($v76 = $linktext) ? $v76 : $value);
		$tt = Lua::str((Lua::truth($v77 = $tooltip) ? $v77 : ''));
		$link = ('[' . $url . ' ' . $label . ']');
		if (Lua::truth(($tt !== ''))) {
			return Lua::format('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return Lua::format('<span class="wk-adddata-link">%s</span>', $link);
	}

	protected function joinArticles($items = null, $lang = null) {
		$n = Lua::len($items);
		if (Lua::truth(($n === 0))) {
			return '';
		}
		if (Lua::truth(($n === 1))) {
			return $items[1];
		}
		$conj = $this->context->message('Common.text', $lang, 'list_conjunction_text');
		if (Lua::truth(($n === 2))) {
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
		$lookup = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v78 = $keys) ? $v78 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->subMsg($lang, ($key . '_label')));
			if (Lua::truth(($lab !== ''))) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function getArgumentWarningLookup($lang = null) {
		$lang = Lua::str((Lua::truth($v79 = $lang) ? $v79 : ''));
		if (Lua::truth(!Lua::truth($this->ARGUMENT_WARNING_LOOKUPS[$lang]))) {
			$this->ARGUMENT_WARNING_LOOKUPS[$lang] = $this->buildSummaryLabelLookup($lang, $this->ARGUMENT_WARNING_BANNER_KEYS);
		}
		return $this->ARGUMENT_WARNING_LOOKUPS[$lang];
	}

	protected function resolveKeyFromLookup($raw = null, $lookup = null, $allowedKeys = null, $aliases = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v80 = $raw) ? $v80 : '')));
		if (Lua::truth(($raw === ''))) {
			return '';
		}
		$k = (Lua::truth($v81 = (Lua::truth($v82 = $lookup) ? $lookup[$raw] : $v82)) ? $v81 : '');
		if (Lua::truth(($k !== ''))) {
			return $k;
		}
		if (Lua::truth((Lua::truth($v83 = $aliases) ? $aliases[$raw] : $v83))) {
			return $aliases[$raw];
		}
		if (Lua::truth($allowedKeys)) {
			foreach (Lua::ipairs($allowedKeys) as $_ => $kk) {
				if (Lua::truth(($raw === $kk))) {
					return $kk;
				}
			}
		}
		return '';
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsArg = Lua::trim(Lua::str((Lua::truth($v84 = $itemWarnings) ? $v84 : '')));
		if (Lua::truth(($warnsArg === ''))) {
			return null;
		}
		$lookup = $this->getArgumentWarningLookup($lang);
		$hasBanner = false;
		foreach (Lua::ipairs($this->splitCSV($warnsArg, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->ARGUMENT_WARNING_BANNER_KEYS);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, null));
				$hasBanner = true;
			}
		}
		if (Lua::truth((Lua::truth($v85 = $hasBanner) ? $cats : $v85))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]'));
		}
	}

	protected function wkSplit2($s = null, $sep = null) {
		$s = Lua::str((Lua::truth($v86 = $s) ? $v86 : ''));
		$sep = Lua::str((Lua::truth($v87 = $sep) ? $v87 : ''));
		if (Lua::truth(($sep === ''))) {
			return [$s, ''];
		}
		$i = Lua::find($s, $sep, 1, true);
		if (Lua::truth(!Lua::truth($i))) {
			return [$s, ''];
		}
		return [Lua::sub($s, 1, ($i - 1)), Lua::sub($s, ($i + Lua::len($sep)))];
	}

	protected function wkExtractArgumentItemsFromData($raw = null) {
		return SerializedData::argumentItems($raw, false);
	}

	protected function wkComputeArgumentItems($raw = null) {
		$items = $this->wkExtractArgumentItemsFromData($raw);
		if (Lua::truth((Lua::len($items) > 0))) {
			return [$items, 'data'];
		}
		return [new Table([]), 'none'];
	}

	protected function wkBuildArgumentData($items = null, $lang = null, $kind = null) {
		$items = (Lua::truth($v90 = $items) ? $v90 : new Table([]));
		$idPrefix = (Lua::truth($v91 = (Lua::truth($v92 = ($kind === 'pro')) ? 'Pro_argument_' : $v92)) ? $v91 : 'Con_argument_');
		$listLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($listLines, ('* ' . $it['title']));
		}
		$listText = Lua::concat($listLines, '
');
		$argsVarParts = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			if (Lua::truth(($it['page'] === $it['title']))) {
				Lua::insert($argsVarParts, $it['page']);
			}
			else {
				Lua::insert($argsVarParts, ($it['page'] . '⟬' . $it['title']));
			}
		}
		$argsVar = Lua::concat($argsVarParts, ' -;- ');
		$mapLines = new Table([]);
		foreach (Lua::ipairs($items) as $i => $it) {
			Lua::insert($mapLines, ('<div  id="' . $idPrefix . $i . '_map" class="argument-title--map wk-icon-before wk-icon-argument">' . $it['title'] . '</div>'));
		}
		$mapHtml = Lua::concat($mapLines, '
');
		$hoverLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($hoverLines, ('<div class="argument-title--map wk-icon-before wk-icon-argument">' . '[[' . $it['page'] . '|' . $it['title'] . ']]</div>'));
		}
		$mapHoverHtml = Lua::concat($hoverLines, '
');
		return new Table(['items' => $items, 'list' => $listText, 'argsVar' => $argsVar, 'map' => $mapHtml, 'mapHover' => $mapHoverHtml]);
	}

	protected function computeArgumentMapsFromArgs($args = null, $lang = null) {
		$rawPro = (Lua::truth($v93 = $args['pro-arguments']) ? $v93 : '');
		$rawCon = (Lua::truth($v94 = $args['con-arguments']) ? $v94 : '');
		if (Lua::truth(($rawPro !== ''))) {
			$rawPro = $this->context->expandField($rawPro, 'debate.arguments.pro');
		}
		if (Lua::truth(($rawCon !== ''))) {
			$rawCon = $this->context->expandField($rawCon, 'debate.arguments.con');
		}
		[$pro, $proMode] = $this->wkComputeArgumentItems($rawPro);
		[$con, $conMode] = $this->wkComputeArgumentItems($rawCon);
		return new Table(['pro' => $this->wkBuildArgumentData($pro, $lang, 'pro'), 'con' => $this->wkBuildArgumentData($con, $lang, 'con'), '_mode' => new Table(['pro' => $proMode, 'con' => $conMode])]);
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, $cats = null) {
		$isPro = ($kind === 'pro');
		$idPrefix = (Lua::truth($v95 = (Lua::truth($v96 = $isPro) ? 'Pro_argument_' : $v96)) ? $v95 : 'Con_argument_');
		$page = Lua::str((Lua::truth($v97 = $it['page']) ? $v97 : ''));
		$titleShown = Lua::str((Lua::truth($v98 = $it['title']) ? $v98 : ''));
		if (Lua::truth(($titleShown === ''))) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = Lua::str((Lua::truth($v99 = $it['warnings']) ? $v99 : ''));
		$banners = new Table([]);
		$this->renderArgumentItemBanners($warnings, $pv, $lang, $cats, $banners);
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . (Lua::truth($v100 = (Lua::truth($v101 = (Lua::len($banners) > 0)) ? Lua::concat($banners) : $v101)) ? $v100 : '') . '</div>' . '</li>');
	}

	protected function renderMetaWarningHtml($params = null) {
		$color = Lua::str((Lua::truth($v102 = $params['color']) ? $v102 : ''));
		$icon = Lua::str((Lua::truth($v103 = $params['icon']) ? $v103 : ''));
		$title = Lua::str((Lua::truth($v104 = $params['title']) ? $v104 : ''));
		$text = Lua::str((Lua::truth($v105 = $params['text']) ? $v105 : ''));
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre">' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderMainBanner($frame = null, $pv = null, $lang = null) {
		$titre = Lua::format($this->L($lang, 'text', 'main_banner_title'), $this->context->nowiki($pv['rawTitle']));
		return $this->renderMetaWarningHtml(new Table(['color' => $this->L($lang, 'text', 'main_banner_color'), 'title' => $titre, 'icon' => 'wk-icon-wikidebates', 'text' => ('<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">' . $this->L($lang, 'text', 'learn_more_label') . $this->L($lang, 'text', 'founding_principles_link') . '</div>')]));
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v106 = $this->context->message('Debate.banners', $lang, $key, ...$rest)) ? $v106 : '');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, $cats = null) {
		$key = Lua::str((Lua::truth($v107 = $key) ? $v107 : ''));
		if (Lua::truth(($key === ''))) {
			return '';
		}
		$color = $this->bannerMsg($lang, ($key . '_color'));
		$icon = $this->bannerMsg($lang, ($key . '_icon'));
		$title = $this->bannerMsg($lang, ($key . '_title'), $pv['rawTitle']);
		$text = $this->bannerMsg($lang, ($key . '_text'), $pv['rawTitle']);
		if (Lua::truth((Lua::truth($v108 = (Lua::truth($v109 = (Lua::truth($v110 = ($color === '')) ? ($icon === '') : $v110)) ? ($title === '') : $v109)) ? ($text === '') : $v108))) {
			return '';
		}
		$html = $this->renderMetaWarningHtml(new Table(['color' => $color, 'icon' => $icon, 'title' => $title, 'text' => $text]));
		$cat = $this->bannerMsg($lang, ($key . '_category'));
		if (Lua::truth((Lua::truth($v111 = (Lua::truth($v112 = $cats) ? $cat : $v112)) ? ($cat !== '') : $v111))) {
			Lua::insert($cats, ('[[Category:' . $cat . ']]'));
		}
		return $html;
	}

	protected function buildBannerLabelLookup($lang = null, $keys = null) {
		$lookup = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v113 = $keys) ? $v113 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->bannerMsg($lang, ($key . '_label')));
			if (Lua::truth(($lab !== ''))) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveBannerKeyFromLookup($raw = null, $lookup = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v114 = $raw) ? $v114 : '')));
		if (Lua::truth(($raw === ''))) {
			return '';
		}
		return (Lua::truth($v115 = (Lua::truth($v116 = $lookup) ? $lookup[$raw] : $v116)) ? $v115 : '');
	}

	protected function renderWarningBanners($args = null, $pv = null, $cats = null, $lang = null) {
		$av = $args['progress'];
		$out = new Table([]);
		$titleLookup = $this->buildBannerLabelLookup($lang, $this->TITLE_WARNING_BANNER_KEYS);
		$debateLookup = $this->buildBannerLabelLookup($lang, $this->DEBATE_WARNING_BANNER_KEYS);
		$progLookup = $this->buildBannerLabelLookup($lang, $this->PROGRESS_BANNER_KEYS);
		$renderBannersFromList = function($list = null, $lookup = null) use (&$args, &$pv, &$cats, &$lang, &$av, &$out, &$titleLookup, &$debateLookup, &$progLookup, &$renderBannersFromList, &$html, &$_, &$x, &$key) {
		$html = new Table([]);
		foreach (Lua::ipairs($this->splitCSV($list, ',')) as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($html, $this->renderBannerByKey($pv, $lang, $key, $cats));
			}
		}
		return Lua::concat($html);
		};
		if (Lua::truth($args['title-warnings'])) {
			Lua::insert($out, $renderBannersFromList($args['title-warnings'], $titleLookup));
		}
		if (Lua::truth((Lua::truth($v117 = $av) ? (Lua::trim($av) !== '') : $v117))) {
			$key = $this->resolveBannerKeyFromLookup($av, $progLookup);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($out, $this->renderBannerByKey($pv, $lang, $key, $cats));
			}
		}
		if (Lua::truth($this->isProgress($lang, $av, 'constructed'))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'constructed') . ']]'));
		}
		else {
			if (Lua::truth((Lua::truth($v118 = (Lua::truth($v119 = !Lua::truth($this->isProgress($lang, $av, 'draft'))) ? !Lua::truth($this->isProgress($lang, $av, 'building')) : $v119)) ? !Lua::truth($this->isProgress($lang, $av, 'constructed')) : $v118))) {
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'progress_missing') . ']]'));
			}
		}
		if (Lua::truth($args['debate-warnings'])) {
			Lua::insert($out, $renderBannersFromList($args['debate-warnings'], $debateLookup));
		}
		return Lua::concat($out);
	}

	protected function renderArgumentMapTop($lang = null, $maps = null) {
		$maps = (Lua::truth($v120 = $maps) ? $v120 : new Table([]));
		$pro = (Lua::truth($v121 = (Lua::truth($v122 = $maps['pro']) ? $maps['pro']['map'] : $v122)) ? $v121 : '');
		$con = (Lua::truth($v123 = (Lua::truth($v124 = $maps['con']) ? $maps['con']['map'] : $v124)) ? $v123 : '');
		$labelPour = $this->L($lang, 'text', 'arg_map_pro_label');
		$labelContre = $this->L($lang, 'text', 'arg_map_con_label');
		$msgPour = $this->L($lang, 'text', 'none_pro_args_msg_short');
		$msgContre = $this->L($lang, 'text', 'none_con_args_msg_short');
		$root = ((((HtmlBuilder::create('div'))->attr('id', 'Argument_map'))->addClass('wk-arg-map'))->addClass('wk-arg-map--top'))->addClass('navigation-not-searchable');
		$colP = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-pro');
		$colC = ((HtmlBuilder::create('div'))->addClass('wk-arg-map__col'))->addClass('is-con');
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelPour));
		($colP)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext((Lua::truth($v125 = (Lua::truth($v126 = ($pro !== '')) ? $pro : $v126)) ? $v125 : ('<div class="carte-vide navigation-not-searchable">' . $msgPour . '</div>'))));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__th'))->wikitext($labelContre));
		($colC)->node(((HtmlBuilder::create('div'))->addClass('wk-arg-map__td'))->wikitext((Lua::truth($v127 = (Lua::truth($v128 = ($con !== '')) ? $con : $v128)) ? $v127 : ('<div class="carte-vide navigation-not-searchable">' . $msgContre . '</div>'))));
		(($root)->node($colP))->node($colC);
		return Lua::str($root);
	}

	protected function wkParseIntroSubsections($raw = null) {
		return SerializedData::records($raw, '⟭⟭⟭', '⟬⟬⟬', ['title', 'content', 'warnings'], true);
	}

	protected function buildIntroSubWarningLookup($lang = null) {
		$lookup = new Table([]);
		foreach (Lua::ipairs($this->INTRO_SUB_WARNING_KEYS) as $_ => $k) {
			$lab = Lua::trim($this->subMsg($lang, ('intro_subbanner_' . $k . '_label')));
			if (Lua::truth(($lab !== ''))) {
				$lookup[$lab] = ('intro_subbanner_' . $k);
			}
		}
		return $lookup;
	}

	protected function wkRenderIntroSubsection($pv = null, $lang = null, $it = null, $cats = null, $lookup = null) {
		$titre = Lua::trim(Lua::str((Lua::truth($v131 = $it['title']) ? $v131 : '')));
		$contenu = Lua::trim(Lua::str((Lua::truth($v132 = $it['content']) ? $v132 : '')));
		$avert = Lua::trim(Lua::str((Lua::truth($v133 = $it['warnings']) ? $v133 : '')));
		if (Lua::truth(($titre === ''))) {
			$titre = $this->L($lang, 'text', 'default_subsection_title');
		}
		$out = new Table([]);
		Lua::insert($out, '<div class="fr-collapsible fr-collapsed">');
		Lua::insert($out, ('<h3 class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-question">' . $titre . '</h3>'));
		Lua::insert($out, '<div class="fr-collapsible-content"><div class="encadre-section-deroulante">');
		if (Lua::truth(($avert !== ''))) {
			$banners = new Table([]);
			foreach (Lua::split($avert, ',', true) as $lab) {
				$lab = Lua::trim($lab);
				if (Lua::truth(($lab !== ''))) {
					$k = (Lua::truth($v134 = $lookup[$lab]) ? $v134 : '');
					if (Lua::truth(($k !== ''))) {
						$h = $this->renderSubsectionBanner($pv, $lang, $k, new Table([]), $cats);
						if (Lua::truth((Lua::truth($v135 = $h) ? ($h !== '') : $v135))) {
							$banners[(Lua::len($banners) + 1)] = $h;
						}
					}
				}
			}
			if (Lua::truth((Lua::len($banners) > 0))) {
				Lua::insert($out, ('<div class="minibandeaux">' . Lua::concat($banners) . '</div>'));
			}
		}
		if (Lua::truth(($contenu !== ''))) {
			Lua::insert($out, $contenu);
		}
		else {
			$editForm = $this->L($lang, 'forms', 'intro_edit_title');
			$editTT = $this->L($lang, 'text', 'intro_edit_tt');
			$linkLabel = $this->L($lang, 'text', 'intro_empty_link_label');
			$emptyIntro = $this->L($lang, 'text', 'intro_empty_msg');
			$emptyCat = $this->L($lang, 'text', 'intro_empty_category');
			$link = $this->addDataLink($editForm, $pv['rawTitle'], $linkLabel, $editTT);
			Lua::insert($out, ('<i>' . $emptyIntro . '</i>'));
			if (Lua::truth(($emptyCat !== ''))) {
				Lua::insert($cats, ('[[Category:' . $emptyCat . ']]'));
			}
		}
		Lua::insert($out, '</div></div></div>');
		return Lua::concat($out);
	}

	protected function wkRenderIntroSubsectionsBlock($pv = null, $lang = null, $raw = null, $cats = null) {
		$items = $this->wkParseIntroSubsections($raw);
		if (Lua::truth((Lua::len($items) === 0))) {
			return '';
		}
		$lookup = $this->buildIntroSubWarningLookup($lang);
		$out = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			$out[(Lua::len($out) + 1)] = $this->wkRenderIntroSubsection($pv, $lang, $it, $cats, $lookup);
		}
		return Lua::concat($out);
	}

	protected function renderIntroduction($args = null, $pv = null, $lang = null, $cats = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-question">' . $this->L($lang, 'text', 'understand_debate_title') . $this->addDataLink($this->L($lang, 'forms', 'intro_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'intro_edit_tt')) . '</h2>');
		$raw = $args['introduction'];
		$raw = (Lua::truth($v136 = (Lua::truth($v137 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v137)) ? $v136 : '');
		if (Lua::truth(($raw !== ''))) {
			$expanded = $this->context->expandField($raw, 'debate.introduction');
			$subs = $this->wkRenderIntroSubsectionsBlock($pv, $lang, $expanded, (Lua::truth($v138 = $cats) ? $v138 : new Table([])));
			if (Lua::truth(($subs !== ''))) {
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
		$raw = (Lua::truth($v139 = (Lua::truth($v140 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v140)) ? $v139 : '');
		$wrap = (HtmlBuilder::create('div'))->addClass('bandeau-section wk-icon-before wk-icon-wikipedia hover-top navigation-not-searchable');
		$hasPages = false;
		if (Lua::truth(($raw !== ''))) {
			$expanded = $this->context->expandField($raw, 'debate.wikipedia');
			$pages = $this->wkParseWikipediaMarkers($expanded);
			if (Lua::truth((Lua::len($pages) > 0))) {
				$hasPages = true;
				$items = new Table([]);
				foreach (Lua::ipairs($pages) as $_ => $page) {
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
		if (Lua::truth((Lua::truth($v141 = ($raw === '')) ? $v141 : !Lua::truth($hasPages)))) {
			return (Lua::str($wrap) . '[[Category:' . $this->L($lang, 'categories', 'wikipedia_missing') . ']]');
		}
		return Lua::str($wrap);
	}

	protected function renderQuestionsFAQ($sujetComplet = null, $sens = null, $lang = null) {
		$topic = (Lua::truth($v142 = (Lua::truth($v143 = (Lua::truth($v144 = $sujetComplet) ? ($sujetComplet !== '') : $v144)) ? $sujetComplet : $v143)) ? $v142 : '');
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
		$title = (Lua::truth($v145 = (Lua::truth($v146 = $isCon) ? $this->L($lang, 'text', 'args_con_title') : $v146)) ? $v145 : $this->L($lang, 'text', 'args_pro_title'));
		$btnForm = $this->addDataLink((Lua::truth($v147 = (Lua::truth($v148 = $isCon) ? $this->L($lang, 'forms', 'args_con_edit_title') : $v148)) ? $v147 : $this->L($lang, 'forms', 'args_pro_edit_title')), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), (Lua::truth($v149 = (Lua::truth($v150 = $isCon) ? $this->L($lang, 'text', 'args_con_edit_tt') : $v150)) ? $v149 : $this->L($lang, 'text', 'args_pro_edit_tt')));
		$out = new Table([]);
		$this->push($out, $this->listBreaker());
		$this->push($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-argument ' . (Lua::truth($v151 = (Lua::truth($v152 = $isCon) ? $hIconCon : $v152)) ? $v151 : $hIconPro) . '">' . $title . $btnForm . '</h2>'));
		$this->push($out, $this->renderQuestionsFAQ($args['expanded-topic'], $sens, $lang));
		$mode = (Lua::truth($v153 = (Lua::truth($v154 = (Lua::truth($v155 = $maps) ? $maps['_mode'] : $v155)) ? (Lua::truth($v156 = (Lua::truth($v157 = $isCon) ? $maps['_mode']['con'] : $v157)) ? $v156 : $maps['_mode']['pro']) : $v154)) ? $v153 : 'none');
		$data = (Lua::truth($v158 = (Lua::truth($v159 = $maps) ? (Lua::truth($v160 = (Lua::truth($v161 = $isCon) ? $maps['con'] : $v161)) ? $v160 : $maps['pro']) : $v159)) ? $v158 : null);
		$items = (Lua::truth($v162 = (Lua::truth($v163 = $data) ? $data['items'] : $v163)) ? $v162 : new Table([]));
		if (Lua::truth((Lua::truth($v164 = ($mode === 'data')) ? (Lua::len($items) > 0) : $v164))) {
			$ul = ((HtmlBuilder::create('ul'))->addClass('argument-list'))->addClass((Lua::truth($v165 = (Lua::truth($v166 = $isCon) ? 'is-con' : $v166)) ? $v165 : 'is-pro'));
			$lis = new Table([]);
			$anyTitleWarnings = false;
			foreach (Lua::ipairs($items) as $i => $it) {
				if (Lua::truth((Lua::truth($v167 = $it['warnings']) ? (Lua::trim($it['warnings']) !== '') : $v167))) {
					$anyTitleWarnings = true;
				}
				Lua::insert($lis, $this->wkRenderArgumentLi($pv, $lang, $it, (Lua::truth($v168 = (Lua::truth($v169 = $isCon) ? 'con' : $v169)) ? $v168 : 'pro'), $i, $cats));
			}
			($ul)->wikitext(Lua::concat($lis, '
'));
			if (Lua::truth($anyTitleWarnings)) {
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]'));
			}
			$this->push($out, Lua::str($ul));
		}
		else {
			$cat = (Lua::truth($v170 = (Lua::truth($v171 = $isCon) ? ('[[Category:' . $this->L($lang, 'categories', 'con_missing') . ']]') : $v171)) ? $v170 : ('[[Category:' . $this->L($lang, 'categories', 'pro_missing') . ']]'));
			$msg = (Lua::truth($v172 = (Lua::truth($v173 = $isCon) ? $this->L($lang, 'text', 'none_con_args_msg') : $v173)) ? $v172 : $this->L($lang, 'text', 'none_pro_args_msg'));
			Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $msg . '</div>' . $cat));
		}
		$sideLabel = (Lua::truth($v174 = (Lua::truth($v175 = $isCon) ? $this->L($lang, 'text', 'side_con_word') : $v175)) ? $v174 : $this->L($lang, 'text', 'side_pro_word'));
		$label = Lua::format($this->L($lang, 'text', 'args_add_label'), $sideLabel);
		$tooltip = Lua::format($this->L($lang, 'text', 'arg_add_tt'), $sideLabel, $pv['rawTitle']);
		$baseField = $this->L($lang, 'forms', 'new_arg_title');
		$rqTypeValue = (Lua::truth($v176 = (Lua::truth($v177 = $isCon) ? $this->L($lang, 'forms', 'new_arg_con_type_label') : $v177)) ? $v176 : $this->L($lang, 'forms', 'new_arg_pro_type_label'));
		$rqQuery = new Table([]);
		$rqQuery[($baseField . '[type]')] = $rqTypeValue;
		$rqQuery[($baseField . '[ID]')] = $pv['pageId'];
		$rqQuery['_run'] = '1';
		$this->push($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($baseField, $label, $tooltip, $rqQuery) . '</div>'));
		return Lua::concat($out);
	}

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsRef = Lua::trim(Lua::str((Lua::truth($v178 = $itemWarnings) ? $v178 : '')));
		if (Lua::truth(($warnsRef === ''))) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach (Lua::ipairs($this->splitCSV($warnsRef, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (Lua::truth(($key !== ''))) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, $cats));
			}
		}
	}

	protected function wkRenderLiListFromArg($raw = null, $parseFn = null, $renderOneFn = null) {
		$items = $parseFn($raw);
		if (Lua::truth((Lua::len($items) === 0))) {
			return '';
		}
		$lis = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $item) {
			$li = $renderOneFn($item);
			if (Lua::truth((Lua::truth($v179 = $li) ? ($li !== '') : $v179))) {
				$lis[(Lua::len($lis) + 1)] = $li;
			}
		}
		return Lua::concat($lis, '
');
	}

	protected function wkParseBiblioMarkers($raw = null) {
		return SerializedData::records($raw, '⟭', '⟬', ['authors', 'article', 'work', 'volume', 'number', 'location', 'page', 'publisher', 'place', 'date', 'link', 'warnings'], false);
	}

	protected function wkRenderOneBiblioLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null) {
		$out = new Table([]);
		$authors = Lua::trim((Lua::truth($v183 = $item['authors']) ? $v183 : ''));
		if (Lua::truth(($authors === ''))) {
			$authors = $this->L($lang, 'text', 'refs_to_review');
			Lua::insert($categories, ('[[Category:' . $this->L($lang, 'categories', 'refs_to_review') . ']]'));
		}
		$out[(Lua::len($out) + 1)] = $authors;
		$article = Lua::trim((Lua::truth($v184 = $item['article']) ? $v184 : ''));
		$work = Lua::trim((Lua::truth($v185 = $item['work']) ? $v185 : ''));
		$link = Lua::trim((Lua::truth($v186 = $item['link']) ? $v186 : ''));
		if (Lua::truth(($article !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'article_name'), $article, true);
		}
		if (Lua::truth(($work !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'work_name'), $work, true);
		}
		if (Lua::truth(($article !== ''))) {
			if (Lua::truth(($link !== ''))) {
				$out[(Lua::len($out) + 1)] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$out[(Lua::len($out) + 1)] = (', « ' . $article . ' »');
			}
		}
		if (Lua::truth(($work !== ''))) {
			if (Lua::truth(($article !== ''))) {
				$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (Lua::truth(($link !== ''))) {
					$out[(Lua::len($out) + 1)] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		if (Lua::truth(($work !== ''))) {
			$volume = Lua::trim((Lua::truth($v187 = $item['volume']) ? $v187 : ''));
			if (Lua::truth(($volume !== ''))) {
				$out[(Lua::len($out) + 1)] = (', ' . $volume);
			}
			$number = Lua::trim((Lua::truth($v188 = $item['number']) ? $v188 : ''));
			if (Lua::truth(($number !== ''))) {
				$out[(Lua::len($out) + 1)] = (', n°' . $number);
			}
		}
		$location = Lua::trim((Lua::truth($v189 = $item['location']) ? $v189 : ''));
		if (Lua::truth(($location !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $location);
		}
		$page = Lua::trim((Lua::truth($v190 = $item['page']) ? $v190 : ''));
		if (Lua::truth(($page !== ''))) {
			$out[(Lua::len($out) + 1)] = (', p.' . $page);
		}
		$publisher = Lua::trim((Lua::truth($v191 = $item['publisher']) ? $v191 : ''));
		if (Lua::truth(($publisher !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'publishing_house'), $publisher, true);
			$out[(Lua::len($out) + 1)] = (', ' . $publisher);
		}
		$place = Lua::trim((Lua::truth($v192 = $item['place']) ? $v192 : ''));
		if (Lua::truth(($place !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'place_of_publication'), $place, true);
			$out[(Lua::len($out) + 1)] = (', ' . $place);
		}
		$date = Lua::trim((Lua::truth($v193 = $item['date']) ? $v193 : ''));
		if (Lua::truth(($date !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderBiblioListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null) {
		return $this->wkRenderLiListFromArg($raw, [$this, 'wkParseBiblioMarkers'], function($item = null) use (&$raw, &$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneBiblioLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function wkParseWeblioMarkers($raw = null) {
		return SerializedData::records($raw, '⟭', '⟬', ['page', 'site', 'link', 'authors', 'date', 'warnings'], false);
	}

	protected function wkRenderOneWeblioLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null, $side = null) {
		$out = new Table([]);
		$page = Lua::trim((Lua::truth($v197 = $item['page']) ? $v197 : ''));
		$site = Lua::trim((Lua::truth($v198 = $item['site']) ? $v198 : ''));
		$link = Lua::trim((Lua::truth($v199 = $item['link']) ? $v199 : ''));
		$authors = Lua::trim((Lua::truth($v200 = $item['authors']) ? $v200 : ''));
		$date = Lua::trim((Lua::truth($v201 = $item['date']) ? $v201 : ''));
		if (Lua::truth(($page !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'article_name'), $page, true);
		}
		if (Lua::truth(($site !== ''))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'website_name'), $site, true);
		}
		if (Lua::truth(($authors !== ''))) {
			$out[(Lua::len($out) + 1)] = $authors;
		}
		{
			$label = '';
			if (Lua::truth(($page !== ''))) {
				$label = $page;
			}
			else {
				if (Lua::truth(($site !== ''))) {
					$label = $site;
				}
			}
			if (Lua::truth(($label === ''))) {
				return '';
			}
			$renderedLabel = null;
			if (Lua::truth(($link !== ''))) {
				$renderedLabel = ('[' . $link . ' ' . $label . ']');
			}
			else {
				$renderedLabel = $label;
			}
			$out[(Lua::len($out) + 1)] = ((Lua::truth($v202 = (Lua::truth($v203 = (Lua::len($out) > 0)) ? ', ' : $v203)) ? $v202 : '') . $renderedLabel);
		}
		if (Lua::truth((Lua::truth($v204 = ($site !== '')) ? ($page !== '') : $v204))) {
			$out[(Lua::len($out) + 1)] = (', \'\'' . $site . '\'\'');
		}
		if (Lua::truth(($date !== ''))) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		if (Lua::truth(($authors !== ''))) {
			$propKey = 'author';
			if (Lua::truth(($side === 'pro'))) {
				$propKey = 'author_pro';
			}
			else {
				if (Lua::truth(($side === 'con'))) {
					$propKey = 'author_con';
				}
			}
			foreach (Lua::split($authors, ',', true) as $a) {
				$a = Lua::trim($a);
				if (Lua::truth(($a !== ''))) {
					$this->smwQueueAdd($smwPending, $this->smwProp($lang, $propKey), $a, true);
				}
			}
		}
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderWeblioListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null, $side = null) {
		return $this->wkRenderLiListFromArg($raw, [$this, 'wkParseWeblioMarkers'], function($item = null) use (&$raw, &$pv, &$lang, &$categories, &$smwPending, &$side) {
		return $this->wkRenderOneWeblioLi($pv, $item, $lang, $categories, $smwPending, $side);
		});
	}

	protected function wkParseVideoMarkers($raw = null) {
		return SerializedData::records($raw, '⟭', '⟬', ['title', 'link', 'authors', 'warnings'], false);
	}

	protected function wkRenderOneVideoLi($pv = null, $item = null, $lang = null, $categories = null, $smwPending = null) {
		$title = Lua::trim((Lua::truth($v208 = $item['title']) ? $v208 : ''));
		if (Lua::truth(($title === ''))) {
			return '';
		}
		$link = Lua::trim((Lua::truth($v209 = $item['link']) ? $v209 : ''));
		$authors = Lua::trim((Lua::truth($v210 = $item['authors']) ? $v210 : ''));
		$out = new Table([]);
		if (Lua::truth(($authors !== ''))) {
			$out[(Lua::len($out) + 1)] = $authors;
		}
		$renderedTitle = null;
		if (Lua::truth(($link !== ''))) {
			$renderedTitle = ('[' . $link . ' ' . $title . ']');
		}
		else {
			$renderedTitle = $title;
		}
		$out[(Lua::len($out) + 1)] = ((Lua::truth($v211 = (Lua::truth($v212 = (Lua::len($out) > 0)) ? ', ' : $v212)) ? $v211 : '') . $renderedTitle);
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $categories, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderVideoListFromArg($raw = null, $pv = null, $lang = null, $categories = null, $smwPending = null) {
		return $this->wkRenderLiListFromArg($raw, [$this, 'wkParseVideoMarkers'], function($item = null) use (&$raw, &$pv, &$lang, &$categories, &$smwPending) {
		return $this->wkRenderOneVideoLi($pv, $item, $lang, $categories, $smwPending);
		});
	}

	protected function renderRefBlock($pageVars = null, $lang = null, $iconClass = null, $titleText = null, $formKey = null, $value = null) {
		$out = new Table([]);
		Lua::insert($out, ('<h4 class="titre-references wk-icon-before ' . $iconClass . ' navigation-not-searchable">' . $titleText . $this->addDataLink($this->L($lang, 'forms', $formKey), $pageVars['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'section_edit_tt')) . '</h4>'));
		if (Lua::truth((Lua::truth($v213 = $value) ? (Lua::trim($value) !== '') : $v213))) {
			Lua::insert($out, ('<ul>' . $value . '</ul>'));
		}
		else {
			Lua::insert($out, ('<div class="aucune-reference navigation-not-searchable">' . $this->L($lang, 'text', 'none_references_msg') . '</div>'));
		}
		return Lua::concat($out);
	}

	protected function renderReferences($args = null, $pageVars = null, $lang = null, $categories = null, $smwPending = null, $refRaw = null) {
		$wrap = new Table([]);
		$refRaw = (Lua::truth($v214 = $refRaw) ? $v214 : new Table([]));
		$refRaw['bibliography'] = (Lua::truth($v215 = $refRaw['bibliography']) ? $v215 : new Table([]));
		$refRaw['webliography'] = (Lua::truth($v216 = $refRaw['webliography']) ? $v216 : new Table([]));
		$refRaw['videography'] = (Lua::truth($v217 = $refRaw['videography']) ? $v217 : new Table([]));
		Lua::insert($wrap, ('<h2 class="wk-icon-before wk-icon-further">' . $this->L($lang, 'text', 'further_reading_title') . '</h2>'));
		{
			$h3 = ('<h3 id="Bibliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-bibliography">' . $this->L($lang, 'text', 'bibliography_title') . '</h3>');
			$content = new Table([]);
			$proRaw = (Lua::truth($v218 = $args['pro-bibliography']) ? $v218 : '');
			if (Lua::truth(($proRaw !== ''))) {
				$refRaw['bibliography']['pro'] = $this->context->expandField($proRaw, 'debate.bibliography.pro');
				$proRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['pro'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v219 = $args['con-bibliography']) ? $v219 : '');
			if (Lua::truth(($conRaw !== ''))) {
				$refRaw['bibliography']['con'] = $this->context->expandField($conRaw, 'debate.bibliography.con');
				$conRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['con'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v220 = $args['bibliography']) ? $v220 : '');
			if (Lua::truth(($neuRaw !== ''))) {
				$refRaw['bibliography']['neutral'] = $this->context->expandField($neuRaw, 'debate.bibliography.neutral');
				$neuRaw = $this->wkRenderBiblioListFromArg($refRaw['bibliography']['neutral'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['bibliography']['neutral'] = '';
			}
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'biblio_pro_title', $proRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'biblio_con_title', $conRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'biblio_neutral_title', $neuRaw));
			Lua::insert($wrap, ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . Lua::concat($content) . '</div></div>'));
		}
		{
			$h3 = ('<h3 id="Webliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-webliography">' . $this->L($lang, 'text', 'webliography_title') . '</h3>');
			$content = new Table([]);
			$proRaw = (Lua::truth($v221 = $args['pro-webliography']) ? $v221 : '');
			if (Lua::truth(($proRaw !== ''))) {
				$refRaw['webliography']['pro'] = $this->context->expandField($proRaw, 'debate.webliography.pro');
				$proRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['pro'], $pageVars, $lang, $categories, $smwPending, 'pro');
			}
			else {
				$refRaw['webliography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v222 = $args['con-webliography']) ? $v222 : '');
			if (Lua::truth(($conRaw !== ''))) {
				$refRaw['webliography']['con'] = $this->context->expandField($conRaw, 'debate.webliography.con');
				$conRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['con'], $pageVars, $lang, $categories, $smwPending, 'con');
			}
			else {
				$refRaw['webliography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v223 = $args['webliography']) ? $v223 : '');
			if (Lua::truth(($neuRaw !== ''))) {
				$refRaw['webliography']['neutral'] = $this->context->expandField($neuRaw, 'debate.webliography.neutral');
				$neuRaw = $this->wkRenderWeblioListFromArg($refRaw['webliography']['neutral'], $pageVars, $lang, $categories, $smwPending, 'neutral');
			}
			else {
				$refRaw['webliography']['neutral'] = '';
			}
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'webliography_pro_title', $proRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'webliography_con_title', $conRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'webliography_neutral_title', $neuRaw));
			Lua::insert($wrap, ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . Lua::concat($content) . '</div></div>'));
		}
		{
			$h3 = ('<h3 id="Videography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-videography">' . $this->L($lang, 'text', 'videography_title') . '</h3>');
			$content = new Table([]);
			$proRaw = (Lua::truth($v224 = $args['pro-videography']) ? $v224 : '');
			if (Lua::truth(($proRaw !== ''))) {
				$refRaw['videography']['pro'] = $this->context->expandField($proRaw, 'debate.videography.pro');
				$proRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['pro'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['pro'] = '';
			}
			$conRaw = (Lua::truth($v225 = $args['con-videography']) ? $v225 : '');
			if (Lua::truth(($conRaw !== ''))) {
				$refRaw['videography']['con'] = $this->context->expandField($conRaw, 'debate.videography.con');
				$conRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['con'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['con'] = '';
			}
			$neuRaw = (Lua::truth($v226 = $args['videography']) ? $v226 : '');
			if (Lua::truth(($neuRaw !== ''))) {
				$refRaw['videography']['neutral'] = $this->context->expandField($neuRaw, 'debate.videography.neutral');
				$neuRaw = $this->wkRenderVideoListFromArg($refRaw['videography']['neutral'], $pageVars, $lang, $categories, $smwPending);
			}
			else {
				$refRaw['videography']['neutral'] = '';
			}
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-pro', $this->L($lang, 'text', 'side_pro_title'), 'videography_pro_title', $proRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-argument is-con', $this->L($lang, 'text', 'side_con_title'), 'videography_con_title', $conRaw));
			Lua::insert($content, $this->renderRefBlock($pageVars, $lang, 'wk-icon-neutral mw-no-invert', $this->L($lang, 'text', 'side_neutral_title'), 'videography_neutral_title', $neuRaw));
			Lua::insert($wrap, ('<div class="fr-collapsible fr-collapsed">' . $h3 . '<div class="fr-collapsible-content contenu-references">' . Lua::concat($content) . '</div></div>'));
		}
		return Lua::concat($wrap);
	}

	protected function wkBuildWikiUrl($lang = null, $prefixKey = null, $labelKey = null) {
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $this->L($lang, 'urls', $labelKey)), 'WIKI'));
	}

	protected function wkBuildWikiUrlFromText($lang = null, $prefixKey = null, $text = null) {
		$text = Lua::trim(Lua::str((Lua::truth($v227 = $text) ? $v227 : '')));
		if (Lua::truth(($text === ''))) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = Lua::trim(Lua::str((Lua::truth($v228 = $titleText) ? $v228 : '')));
		if (Lua::truth(($titleText === ''))) {
			return '';
		}
		return Lua::str($this->context->fullUrl($titleText));
	}

	protected function wkTakeFirst($items = null, $limit = null) {
		$out = new Table([]);
		$limit = (Lua::truth($v229 = Lua::number($limit)) ? $v229 : 0);
		if (Lua::truth(($limit <= 0))) {
			return $out;
		}
		$stop5 = Lua::len($items);
		for ($i = 1; $i <= $stop5; $i++) {
			if (Lua::truth(($i > $limit))) {
				break;
			}
			$out[(Lua::len($out) + 1)] = $items[$i];
		}
		return $out;
	}

	protected function wkBuildWebsiteNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return new Table(['@type' => 'WebSite', '@id' => ($homeUrl . '#website'), 'url' => $homeUrl, 'name' => $siteName]);
	}

	protected function wkBuildOrganizationNode($lang = null) {
		$homeUrl = $this->L($lang, 'urls', 'home');
		$siteName = $this->L($lang, 'seo', 'site_name');
		return new Table(['@type' => 'Organization', '@id' => ($homeUrl . '#organization'), 'name' => $siteName, 'url' => $homeUrl]);
	}

	protected function wkBuildBreadcrumbNode($pv = null, $lang = null) {
		$homeLabel = $this->L($lang, 'urls', 'home_label');
		$homeUrl = $this->L($lang, 'urls', 'home');
		$debatesLabel = $this->L($lang, 'urls', 'debates_label');
		$debatesUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'debates_label');
		return new Table(['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => new Table([1 => new Table(['@type' => 'ListItem', 'position' => 1, 'item' => new Table(['@id' => $homeUrl, 'name' => $homeLabel])]), 2 => new Table(['@type' => 'ListItem', 'position' => 2, 'item' => new Table(['@id' => $debatesUrl, 'name' => $debatesLabel])]), 3 => new Table(['@type' => 'ListItem', 'position' => 3, 'item' => new Table(['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']])])])]);
	}

	protected function wkSchemaPageLabel($it = null) {
		$label = Lua::trim(Lua::str((Lua::truth($v230 = (Lua::truth($v231 = $it) ? $it['page'] : $v231)) ? $v230 : '')));
		if (Lua::truth(($label === ''))) {
			$label = Lua::trim(Lua::str((Lua::truth($v232 = (Lua::truth($v233 = $it) ? $it['title'] : $v233)) ? $v232 : '')));
		}
		return $label;
	}

	protected function wkBuildAnswerNode($it = null, $kind = null, $lang = null) {
		$pageLabel = Lua::trim(Lua::str((Lua::truth($v234 = (Lua::truth($v235 = $it) ? $it['page'] : $v235)) ? $v234 : '')));
		if (Lua::truth(($pageLabel === ''))) {
			return null;
		}
		$answerName = Lua::trim(Lua::str((Lua::truth($v236 = (Lua::truth($v237 = $it) ? $it['title'] : $v237)) ? $v236 : '')));
		if (Lua::truth(($answerName === ''))) {
			$answerName = $pageLabel;
		}
		$url = $this->wkFullUrl($pageLabel);
		if (Lua::truth(($url === ''))) {
			return null;
		}
		$additionalType = null;
		if (Lua::truth(($kind === 'pro'))) {
			$additionalType = $this->wkBuildWikiUrl($lang, 'property_prefix', 'pro_label');
		}
		else {
			$additionalType = $this->wkBuildWikiUrl($lang, 'property_prefix', 'con_label');
		}
		return new Table(['@type' => 'Answer', '@id' => ($url . '#answer'), 'url' => $url, 'name' => $answerName, 'text' => $pageLabel, 'author' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#organization')]), 'additionalType' => $additionalType, 'about' => new Table(['@type' => 'Claim', '@id' => ($url . '#claim')])]);
	}

	protected function wkBuildSuggestedAnswers($maps = null, $lang = null) {
		$out = new Table([]);
		$position = 1;
		$proItems = (Lua::truth($v238 = (Lua::truth($v239 = (Lua::truth($v240 = $maps) ? $maps['pro'] : $v240)) ? $maps['pro']['items'] : $v239)) ? $v238 : new Table([]));
		$conItems = (Lua::truth($v241 = (Lua::truth($v242 = (Lua::truth($v243 = $maps) ? $maps['con'] : $v243)) ? $maps['con']['items'] : $v242)) ? $v241 : new Table([]));
		foreach (Lua::ipairs($this->wkTakeFirst($proItems, 3)) as $_ => $it) {
			$node = $this->wkBuildAnswerNode($it, 'pro', $lang);
			if (Lua::truth($node)) {
				$out[(Lua::len($out) + 1)] = $node;
				$position = ($position + 1);
			}
		}
		foreach (Lua::ipairs($this->wkTakeFirst($conItems, 3)) as $_ => $it) {
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
		$out[(Lua::len($out) + 1)] = new Table(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'debates_label')]);
		$out[(Lua::len($out) + 1)] = new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#website')]);
		return $out;
	}

	protected function wkBuildIsRelatedTo($args = null) {
		$out = new Table([]);
		$raw = Lua::trim(Lua::str((Lua::truth($v244 = (Lua::truth($v245 = $args) ? $args['related-debates'] : $v245)) ? $v244 : '')));
		if (Lua::truth(($raw === ''))) {
			return $out;
		}
		$count = 0;
		foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
			$part = Lua::trim(Lua::str((Lua::truth($v246 = $part) ? $v246 : '')));
			if (Lua::truth(($part !== ''))) {
				$count = ($count + 1);
				if (Lua::truth(($count > 5))) {
					break;
				}
				$url = $this->wkFullUrl($part);
				if (Lua::truth(($url !== ''))) {
					$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . '#qa')]);
				}
			}
		}
		return $out;
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = new Table([]);
		$sections = $this->splitCSV((Lua::truth($v247 = (Lua::truth($v248 = $args) ? $args['sections'] : $v248)) ? $v247 : ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = Lua::len($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (Lua::truth(($i > 4))) {
				break;
			}
			$section = Lua::trim(Lua::str((Lua::truth($v249 = $sections[$i]) ? $v249 : '')));
			if (Lua::truth(($section !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => new Table(['@id' => $termSetId])]);
			}
		}
		return $out;
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = new Table([]);
		$mentions = new Table([]);
		$keywords = $this->splitCSV((Lua::truth($v250 = (Lua::truth($v251 = $args) ? $args['keywords'] : $v251)) ? $v250 : ''), ',');
		$stop3 = Lua::len($keywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = Lua::trim(Lua::str((Lua::truth($v252 = $keywords[$i]) ? $v252 : '')));
			if (Lua::truth(($kw !== ''))) {
				$node = new Table(['@type' => 'Thing', 'name' => $kw]);
				if (Lua::truth(($i <= 2))) {
					$about[(Lua::len($about) + 1)] = $node;
				}
				else {
					$mentions[(Lua::len($mentions) + 1)] = $node;
				}
			}
		}
		return [$about, $mentions];
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		$prefix = $this->L($lang, 'urls', 'discussion_prefix');
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}



	protected function wkBuildQuoteAuthorValue($authors = null) {
		$authors = Lua::trim(Lua::str((Lua::truth($v255 = $authors) ? $v255 : '')));
		if (Lua::truth(($authors === ''))) {
			return null;
		}
		$out = new Table([]);
		foreach (Lua::split($authors, ',', true) as $a) {
			$a = Lua::trim(Lua::str((Lua::truth($v256 = $a) ? $v256 : '')));
			if (Lua::truth(($a !== ''))) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Person', 'name' => $a]);
			}
		}
		if (Lua::truth((Lua::len($out) === 0))) {
			return null;
		}
		if (Lua::truth((Lua::len($out) === 1))) {
			return $out[1];
		}
		return $out;
	}

	protected function wkBuildSingleNodeFromFirstItem($items = null, $builder = null) {
		$out = new Table([]);
		$stop1 = Lua::len((Lua::truth($v257 = $items) ? $v257 : new Table([])));
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
		$raw = Lua::trim(Lua::str((Lua::truth($v258 = $raw) ? $v258 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseBiblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$authors, &$article, &$work, &$number, &$publisher, &$date, &$link, &$node, &$authorValue) {
		$authors = Lua::trim(Lua::str((Lua::truth($v259 = $item['authors']) ? $v259 : '')));
		$article = Lua::trim(Lua::str((Lua::truth($v260 = $item['article']) ? $v260 : '')));
		$work = Lua::trim(Lua::str((Lua::truth($v261 = $item['work']) ? $v261 : '')));
		$volume = Lua::trim(Lua::str((Lua::truth($v262 = $item['volume']) ? $v262 : '')));
		$number = Lua::trim(Lua::str((Lua::truth($v263 = $item['number']) ? $v263 : '')));
		$publisher = Lua::trim(Lua::str((Lua::truth($v264 = $item['publisher']) ? $v264 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v265 = $item['date']) ? $v265 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v266 = $item['link']) ? $v266 : '')));
		$node = null;
		if (Lua::truth((Lua::truth($v267 = (Lua::truth($v268 = ($number !== '')) ? ($article !== '') : $v268)) ? ($work !== '') : $v267))) {
			$node = new Table(['@type' => 'Article', 'name' => $article, 'isPartOf' => new Table(['@type' => 'Periodical', 'name' => $work])]);
			if (Lua::truth(($volume !== ''))) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if (Lua::truth((Lua::truth($v269 = ($publisher !== '')) ? ($work !== '') : $v269))) {
				$node = new Table(['@type' => 'Book', 'name' => $work]);
				if (Lua::truth(($article !== ''))) {
					$node['description'] = $article;
				}
				if (Lua::truth(($volume !== ''))) {
					$node['volumeNumber'] = $volume;
				}
				if (Lua::truth(($number !== ''))) {
					$node['issueNumber'] = $number;
				}
			}
			else {
				if (Lua::truth((Lua::truth($v270 = ($link !== '')) ? ($article !== '') : $v270))) {
					$node = new Table(['@type' => 'WebPage', 'name' => $article]);
					if (Lua::truth(($work !== ''))) {
						$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $work]);
					}
				}
			}
		}
		if (Lua::truth(!Lua::truth($node))) {
			return null;
		}
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		if (Lua::truth(($publisher !== ''))) {
			$node['publisher'] = new Table(['@type' => 'Organization', 'name' => $publisher]);
		}
		if (Lua::truth(($date !== ''))) {
			$node['datePublished'] = $date;
		}
		if (Lua::truth(($link !== ''))) {
			$node['url'] = $link;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromWebliography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v271 = $raw) ? $v271 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseWeblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$page, &$site, &$link, &$date, &$node) {
		$page = Lua::trim(Lua::str((Lua::truth($v272 = $item['page']) ? $v272 : '')));
		$site = Lua::trim(Lua::str((Lua::truth($v273 = $item['site']) ? $v273 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v274 = $item['link']) ? $v274 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v275 = $item['date']) ? $v275 : '')));
		if (Lua::truth((Lua::truth($v276 = ($link === '')) ? $v276 : ($page === '')))) {
			return null;
		}
		$node = new Table(['@type' => 'WebPage', 'name' => $page, 'url' => $link]);
		if (Lua::truth(($site !== ''))) {
			$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $site]);
		}
		if (Lua::truth(($date !== ''))) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v277 = $raw) ? $v277 : '')));
		if (Lua::truth(($raw === ''))) {
			return new Table([]);
		}
		$items = $this->wkParseVideoMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$title, &$link, &$authors, &$node, &$authorValue) {
		$title = Lua::trim(Lua::str((Lua::truth($v278 = $item['title']) ? $v278 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v279 = $item['link']) ? $v279 : '')));
		$authors = Lua::trim(Lua::str((Lua::truth($v280 = $item['authors']) ? $v280 : '')));
		if (Lua::truth((Lua::truth($v281 = ($title === '')) ? $v281 : ($link === '')))) {
			return null;
		}
		$node = new Table(['@type' => 'VideoObject', 'name' => $title, 'url' => $link]);
		$authorValue = $this->wkBuildQuoteAuthorValue($authors);
		if (Lua::truth($authorValue)) {
			$node['author'] = $authorValue;
		}
		return $node;
		});
	}

	protected function renderDebateSchemaJSONLD($pv = null, $lang = null, $args = null, $maps = null, $refRaw = null) {
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
		if (Lua::truth((Lua::truth($v282 = $args['expanded-topic']) ? (Lua::trim($args['expanded-topic']) !== '') : $v282))) {
			$questionText = Lua::format($this->L($lang, 'text', 'faq_q'), Lua::trim($args['expanded-topic']));
		}
		else {
			$questionText = $pv['rawTitle'];
		}
		$refRaw = (Lua::truth($v283 = $refRaw) ? $v283 : new Table([]));
		$refRaw['bibliography'] = (Lua::truth($v284 = $refRaw['bibliography']) ? $v284 : new Table([]));
		$refRaw['webliography'] = (Lua::truth($v285 = $refRaw['webliography']) ? $v285 : new Table([]));
		$refRaw['videography'] = (Lua::truth($v286 = $refRaw['videography']) ? $v286 : new Table([]));
		$bibliographyRaw = '';
		if (Lua::truth(($refRaw['bibliography']['pro'] !== ''))) {
			$bibliographyRaw = $refRaw['bibliography']['pro'];
		}
		if (Lua::truth(($refRaw['bibliography']['con'] !== ''))) {
			if (Lua::truth(($bibliographyRaw !== ''))) {
				$bibliographyRaw = ($bibliographyRaw . $this->ITEM_SEP);
			}
			$bibliographyRaw = ($bibliographyRaw . $refRaw['bibliography']['con']);
		}
		if (Lua::truth(($refRaw['bibliography']['neutral'] !== ''))) {
			if (Lua::truth(($bibliographyRaw !== ''))) {
				$bibliographyRaw = ($bibliographyRaw . $this->ITEM_SEP);
			}
			$bibliographyRaw = ($bibliographyRaw . $refRaw['bibliography']['neutral']);
		}
		$webliographyRaw = '';
		if (Lua::truth(($refRaw['webliography']['pro'] !== ''))) {
			$webliographyRaw = $refRaw['webliography']['pro'];
		}
		if (Lua::truth(($refRaw['webliography']['con'] !== ''))) {
			if (Lua::truth(($webliographyRaw !== ''))) {
				$webliographyRaw = ($webliographyRaw . $this->ITEM_SEP);
			}
			$webliographyRaw = ($webliographyRaw . $refRaw['webliography']['con']);
		}
		if (Lua::truth(($refRaw['webliography']['neutral'] !== ''))) {
			if (Lua::truth(($webliographyRaw !== ''))) {
				$webliographyRaw = ($webliographyRaw . $this->ITEM_SEP);
			}
			$webliographyRaw = ($webliographyRaw . $refRaw['webliography']['neutral']);
		}
		$videographyRaw = '';
		if (Lua::truth(($refRaw['videography']['pro'] !== ''))) {
			$videographyRaw = $refRaw['videography']['pro'];
		}
		if (Lua::truth(($refRaw['videography']['con'] !== ''))) {
			if (Lua::truth(($videographyRaw !== ''))) {
				$videographyRaw = ($videographyRaw . $this->ITEM_SEP);
			}
			$videographyRaw = ($videographyRaw . $refRaw['videography']['con']);
		}
		if (Lua::truth(($refRaw['videography']['neutral'] !== ''))) {
			if (Lua::truth(($videographyRaw !== ''))) {
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
		$qaNode = new Table(['@type' => 'QAPage', '@id' => ($pv['pageUrl'] . '#qa'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'educationalUse' => new Table([1 => 'debate', 2 => 'critical thinking']), 'learningResourceType' => 'debate', 'audience' => new Table(['@type' => 'EducationalAudience', 'educationalLevel' => new Table([1 => 'general public', 2 => 'secondary education'])]), 'isPartOf' => $isPartOf, 'breadcrumb' => new Table(['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#organization')]), 'mainEntity' => new Table(['@type' => 'Question', '@id' => ($pv['pageUrl'] . '#question'), 'name' => $pv['rawTitle'], 'text' => $questionText, 'answerCount' => Lua::len($suggestedAnswer), 'suggestedAnswer' => $suggestedAnswer])]);
		if (Lua::truth((Lua::truth($v287 = $pageCreation) ? ($pageCreation !== '') : $v287))) {
			$qaNode['mainEntity']['datePublished'] = $pageCreation;
		}
		if (Lua::truth((Lua::len($isRelatedTo) > 0))) {
			$qaNode['isRelatedTo'] = $isRelatedTo;
		}
		if (Lua::truth((Lua::len($keywords) > 0))) {
			$qaNode['keywords'] = $keywords;
		}
		if (Lua::truth((Lua::len($about) > 0))) {
			$qaNode['about'] = $about;
		}
		if (Lua::truth((Lua::len($mentions) > 0))) {
			$qaNode['mentions'] = $mentions;
		}
		if (Lua::truth((Lua::len($citations) > 0))) {
			$qaNode['citation'] = $citations;
		}
		if (Lua::truth($subjectOf[1])) {
			$qaNode['subjectOf'] = $subjectOf[1];
		}
		$data = new Table(['@context' => 'https://schema.org', '@graph' => new Table([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $qaNode])]);
		$json = $this->context->jsonEncode($data);
		return $this->context->jsonLd($json);
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null) {
		$out = new Table([]);
		Lua::insert($out, ('<div style="font-size: 95%; margin-top: 1em;">' . $this->L($lang, 'text', 'keywords_label_text')));
		if (Lua::truth((Lua::truth($v288 = $args['keywords']) ? (Lua::trim($args['keywords']) !== '') : $v288))) {
			$rendered = new Table([]);
			$formName = $this->L($lang, 'forms', 'search_by_keywords_title');
			$fieldLabel = $formName;
			$fieldName = $this->L($lang, 'params', 'keywords_field');
			$tt = $this->L($lang, 'text', 'search_by_keyword_tt');
			$typeField = $this->L($lang, 'params', 'search_type_field');
			$typeValue = $this->L($lang, 'params', 'search_type_value');
			$extra = new Table([($fieldLabel . '[' . $typeField . ']') => $typeValue]);
			foreach (Lua::ipairs($this->splitCSV($args['keywords'], ',')) as $_ => $k) {
				$k = Lua::trim(Lua::str((Lua::truth($v289 = $k) ? $v289 : '')));
				if (Lua::truth(($k !== ''))) {
					$chip = $this->runQueryLink($formName, $fieldLabel, $fieldName, $k, $k, $tt, $extra);
					if (Lua::truth((Lua::truth($v290 = !Lua::truth($chip)) ? $v290 : ($chip === '')))) {
						$chip = $k;
					}
					if (Lua::truth(($chip !== ''))) {
						Lua::insert($rendered, $chip);
					}
				}
			}
			if (Lua::truth((Lua::len($rendered) > 0))) {
				Lua::insert($out, Lua::concat($rendered, ', '));
			}
			else {
				Lua::insert($out, ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>'));
				Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]'));
			}
		}
		else {
			Lua::insert($out, ('<strong>' . $this->L($lang, 'text', 'none_label') . '</strong>'));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'keywords_missing') . ']]'));
		}
		Lua::insert($out, ($this->addDataLink($this->L($lang, 'forms', 'keywords_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'keywords_edit_tt')) . '<span style="display: none;">.</span></div>'));
		return Lua::concat($out);
	}

	protected function renderConnexes($args = null, $pv = null, $cats = null, $lang = null) {
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-parent">' . $this->L($lang, 'forms', 'related_edit_title') . $this->addDataLink($this->L($lang, 'forms', 'related_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'related_edit_tt')) . '</h2>');
		$raw = $args['related-debates'];
		if (Lua::truth((Lua::truth($v291 = $raw) ? (Lua::trim($raw) !== '') : $v291))) {
			$items = new Table([]);
			$n = 0;
			foreach (Lua::split($raw, $this->ITEM_SEP, true) as $part) {
				$part = Lua::trim(Lua::str((Lua::truth($v292 = $part) ? $v292 : '')));
				if (Lua::truth(($part !== ''))) {
					$n = ($n + 1);
					$items[$n] = ('<li style="position: relative;"><span class="hover-map">[[' . $part . ']]</span></li>');
				}
			}
			if (Lua::truth(($n > 0))) {
				return ($h2 . '<ul class="hover-top searchaux">' . Lua::concat($items) . '</ul>');
			}
		}
		Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'related_missing') . ']]'));
		return ($h2 . '<div class="aucun-contenu navigation-not-searchable">' . $this->L($lang, 'text', 'none_related_msg') . '</div>');
	}

	protected function renderRubriques($args = null, $cats = null, $lang = null) {
		if (Lua::truth((Lua::truth($v293 = $args['sections']) ? (Lua::trim($args['sections']) !== '') : $v293))) {
			$out = new Table([]);
			foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $r) {
				Lua::insert($out, ('[[Category:' . $r . ']]'));
			}
			return Lua::concat($out);
		}
		return ('[[Category:' . $this->L($lang, 'categories', 'sections_missing') . ']]');
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, $maps = null, $smwPending = null) {
		$maps = (Lua::truth($v294 = $maps) ? $v294 : new Table([]));
		$pro = (Lua::truth($v295 = $maps['pro']) ? $v295 : new Table([]));
		$con = (Lua::truth($v296 = $maps['con']) ? $v296 : new Table([]));
		$pourPages = new Table([]);
		$contrePages = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v297 = $pro['items']) ? $v297 : new Table([]))) as $_ => $it) {
			if (Lua::truth((Lua::truth($v298 = $it['page']) ? ($it['page'] !== '') : $v298))) {
				Lua::insert($pourPages, $it['page']);
			}
		}
		foreach (Lua::ipairs((Lua::truth($v299 = $con['items']) ? $v299 : new Table([]))) as $_ => $it) {
			if (Lua::truth((Lua::truth($v300 = $it['page']) ? ($it['page'] !== '') : $v300))) {
				Lua::insert($contrePages, $it['page']);
			}
		}
		$props = new Table([]);
		$pourSurvol = Lua::trim((Lua::truth($v301 = $pro['mapHover']) ? $v301 : ''));
		$contreSurvol = Lua::trim((Lua::truth($v302 = $con['mapHover']) ? $v302 : ''));
		if (Lua::truth(($pourSurvol === ''))) {
			$pourSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_pro_args_msg_short') . '</div>');
		}
		if (Lua::truth(($contreSurvol === ''))) {
			$contreSurvol = ('<div class="carte-vide">' . $this->L($lang, 'text', 'none_con_args_msg_short') . '</div>');
		}
		$listePour = Lua::trim((Lua::truth($v303 = $pro['argsVar']) ? $v303 : ''));
		$listeContre = Lua::trim((Lua::truth($v304 = $con['argsVar']) ? $v304 : ''));
		$props[$this->smwProp($lang, 'debate_name')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'debate_number')] = $pv['pageId'];
		$props[$this->smwProp($lang, 'breadcrumb')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'parent_debate')] = $pv['rawTitle'];
		$props[$this->smwProp($lang, 'pro_list')] = $listePour;
		$props[$this->smwProp($lang, 'con_list')] = $listeContre;
		$props[$this->smwProp($lang, 'arg_map')] = ('<div class="wk-arg-map navigation-not-searchable">' . '<div class="wk-arg-map__col is-pro">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_pro_label') . '</div>' . '<div class="wk-arg-map__td">' . $pourSurvol . '</div>' . '</div>' . '<div class="wk-arg-map__col is-con">' . '<div class="wk-arg-map__th">' . $this->L($lang, 'text', 'arg_map_con_label') . '</div>' . '<div class="wk-arg-map__td">' . $contreSurvol . '</div>' . '</div>' . '</div>');
		if (Lua::truth((Lua::len($pourPages) > 0))) {
			$props[$this->smwProp($lang, 'arg_pro')] = $pourPages;
		}
		if (Lua::truth((Lua::len($contrePages) > 0))) {
			$props[$this->smwProp($lang, 'arg_con')] = $contrePages;
		}
		if (Lua::truth($smwPending)) {
			$this->smwQueueMerge($props, $smwPending);
		}
		$this->smwSetSafe($props);
	}

	protected function escapeAttr($s = null) {
		$s = Lua::str((Lua::truth($v305 = $s) ? $v305 : ''));
		return Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub($s, '[
	]', ' '), '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), '"', '&quot;');
	}

	public function render($frame = null) {
		foreach (Lua::pairs($this->SEO_CACHE) as $k => $_value) {
			$this->SEO_CACHE[$k] = null;
		}
		$args = $this->getArgs($frame);
		$lang = $this->detectLang($args);
		$pv = $this->computePageVars();
		[$pieces, $cats] = [new Table([]), new Table([])];
		$smwPending = new Table([]);
		$refRaw = new Table(['bibliography' => new Table([]), 'webliography' => new Table([]), 'videography' => new Table([])]);
		$this->smwQueueAddFromCSV($smwPending, $this->smwProp($lang, 'keyword'), $args['keywords']);
		$maps = $this->computeArgumentMapsFromArgs($args, $lang);
		Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'debates') . ']]'));
		if (Lua::truth((Lua::truth($v306 = $args['topic']) ? (Lua::trim($args['topic']) !== '') : $v306))) {
			$this->smwQueueAdd($smwPending, $this->smwProp($lang, 'debate_topic'), $args['topic'], false);
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_missing') . ']]'));
		}
		if (Lua::truth((Lua::truth($v307 = $args['expanded-topic']) ? (Lua::trim($args['expanded-topic']) !== '') : $v307))) {
			Lua::insert($pieces, $this->context->shortDescription(Lua::format($this->L($lang, 'text', 'shortdesc_topic'), $args['expanded-topic'])));
		}
		else {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'topic_complete_missing') . ']]'));
			Lua::insert($pieces, $this->context->shortDescription($this->L($lang, 'text', 'shortdesc_plain')));
		}
		if (Lua::truth((Lua::truth($v308 = !Lua::truth($args['creation-date'])) ? $v308 : (Lua::trim($args['creation-date']) === '')))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'creation_date_missing') . ']]'));
		}
		Lua::insert($pieces, $this->renderArgumentMapTop($lang, $maps));
		Lua::insert($pieces, $this->renderKeywords($args, $pv, $cats, $lang));
		$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'debate_banners_edit_tt'));
		Lua::insert($pieces, ('<h2 class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>'));
		Lua::insert($pieces, $this->renderMainBanner($this->context, $pv, $lang));
		Lua::insert($pieces, $this->renderWarningBanners($args, $pv, $cats, $lang));
		Lua::insert($pieces, $this->renderIntroduction($args, $pv, $lang, $cats));
		Lua::insert($pieces, $this->renderWikipediaLinks($args, $pv, $lang));
		Lua::insert($pieces, $this->renderArgsList($args, $pv, $lang, 'pro', $maps, $cats));
		Lua::insert($pieces, $this->renderArgsList($args, $pv, $lang, 'con', $maps, $cats));
		if (Lua::truth(!Lua::truth($this->isProgress($lang, $args['progress'], 'draft')))) {
			$target = ($this->L($lang, 'text', 'self_eval_ns_label') . ':' . $pv['rawTitle'] . ' (ID)');
			$ns = $this->L($lang, 'text', 'self_eval_ns_label');
			$f_debat = $this->L($lang, 'text', 'self_eval_debate_field');
			$box = new Table([]);
			Lua::insert($box, ('<h2 class="navigation-not-searchable noprint wk-icon-before wk-icon-selfeval">' . $this->L($lang, 'text', 'self_eval_title') . '</h2>'));
			$startBtn = $this->wkAddDataGetButton($this->L($lang, 'forms', 'self_eval_home_title'), $target, $this->L($lang, 'text', 'self_eval_start_label'), $this->L($lang, 'text', 'self_eval_start_tt'), new Table([($ns . '[' . $f_debat . ']') => $pv['rawTitle']]));
			Lua::insert($box, ('<div class="boite-info navigation-not-searchable noprint">' . $this->L($lang, 'text', 'self_eval_blurb') . $startBtn . '</div>'));
			Lua::insert($pieces, Lua::concat($box));
		}
		Lua::insert($pieces, $this->renderReferences($args, $pv, $lang, $cats, $smwPending, $refRaw));
		Lua::insert($pieces, $this->renderConnexes($args, $pv, $cats, $lang));
		Lua::insert($pieces, ('<h2 class="latest-changes-button navigation-not-searchable noprint wk-icon-before wk-icon-refresh">' . $this->L($lang, 'text', 'latest_changes_title') . '</h2>' . '<div class="latest-changes navigation-not-searchable noprint">' . '<div class="latest-changes-button wk-btn mw-ui-button"' . ' title="' . $this->L($lang, 'text', 'latest_changes_show_tt') . '"' . ' data-page="' . $this->context->nowiki($pv['rawTitle']) . '">' . $this->L($lang, 'text', 'latest_changes_show_label') . '</div>' . '<div class="latest-changes-wrapper"></div>' . '</div>'));
		Lua::insert($pieces, $this->renderRubriques($args, $cats, $lang));
		$this->setSemanticData($args, $pv, $lang, $maps, $smwPending);
		$this->renderFullSEO($args, $pv, $lang);
		Lua::insert($pieces, Lua::concat($cats));
		$renameLink = Lua::format($this->L($lang, 'text', 'rename_special_page_text'), $pv['encoded']);
		Lua::insert($pieces, ('<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[[' . $renameLink . '|' . $this->L($lang, 'text', 'rename_link_label') . ']]</span>'));
		Lua::insert($pieces, ('<span id="bouton-modifier-sujet" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'topic_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'topic_edit_tt')) . '</span>'));
		Lua::insert($pieces, ('<span id="bouton-modifier-categories" style="display: none;">' . $this->addDataLink($this->L($lang, 'forms', 'sections_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label'), $this->L($lang, 'text', 'sections_edit_tt')) . '</span>'));
		if (Lua::truth((Lua::truth($v309 = $args['interlanguage']) ? (Lua::trim(Lua::str((Lua::truth($v310 = $args['interlanguage']) ? $v310 : ''))) !== '') : $v309))) {
			Lua::insert($pieces, $args['interlanguage']);
			Lua::insert($pieces, ('<span id="bouton-modifier-interlangue" class="bouton-modifier navigation-not-searchable noprint" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($this->L($lang, 'text', 'interlanguage_edit_tt')) . '">' . $this->addDataLink($this->L($lang, 'forms', 'interlanguage_edit_title'), $pv['rawTitle'], $this->L($lang, 'text', 'edit_label')) . '</span>'));
		}
		Lua::insert($pieces, $this->renderDebateSchemaJSONLD($pv, $lang, $args, $maps, $refRaw));
		return Lua::concat($pieces, '');
	}

	protected function getArgs($frame = null) { return $this->context->arguments(); }
	protected function detectLang($args = null) { return $this->context->language($args); }
	protected function L($lang = null, $section = null, $key = null, ...$rest) { return $this->context->message('Debate.' . $section, $lang, $key, ...$rest); }
	protected function computePageVars() { return $this->context->pageVars(); }
	protected function wkDbKey($page = null) { return $this->context->dbKey($page); }
	protected function wkSeoSet($data = null) { $this->context->seoSet($data); }
	protected function smwSetSafe($data = null) { $this->context->smwSet($data); }
}
