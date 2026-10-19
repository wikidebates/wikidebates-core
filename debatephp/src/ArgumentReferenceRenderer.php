<?php
namespace MediaWiki\Extension\DebatePHP;

/** Native PHP port of the supplied Module:Argument. See docs/argument.md.
 * No Lua interpreter is used. Table preserves reference and 1-based semantics.
 * Generated mechanically, then checked against the reference source.
 */
class ArgumentReferenceRenderer {
	private ?DecodedReferences $decodedReferences = null;
	protected ArgumentContext $context;
	protected $ITEM_SEP;
	protected $FIELD_SEP;
	protected $QUOTE_ITEM_SEP;
	protected $QUOTE_FIELD_SEP;
	protected $JUSTIFICATION_WARNING_BANNER_KEYS;
	protected $OBJECTION_WARNING_BANNER_KEYS;
	protected $QUOTE_WARNING_BANNER_KEYS;
	protected $REFERENCE_WARNING_BANNER_KEYS;
	protected $SUMMARY_WARNING_BANNER_KEYS;
	protected $SUMMARY_WARNING_ALIASES;
	protected $DISPLAYED_TITLE_WARNING_BANNER_KEYS;
	protected $SMW_ASK_CACHE;
	protected $SMW_TITLES_CACHE;
	protected $URLENC_CACHE;
	protected $CARD_CACHE;
	protected $TITLE_WARNING_BANNER_KEYS;
	protected $ARGUMENT_WARNING_BANNER_KEYS;
	protected $SEO_CACHE;
	public function __construct(ArgumentContext $context) {
		$this->context = $context;
		$this->ITEM_SEP = '⟭';
		$this->FIELD_SEP = '⟬';
		$this->QUOTE_ITEM_SEP = '⟭⟭⟭';
		$this->QUOTE_FIELD_SEP = '⟬⟬⟬';
		$this->JUSTIFICATION_WARNING_BANNER_KEYS = new Table([1 => 'justifications_to_reorganize']);
		$this->OBJECTION_WARNING_BANNER_KEYS = new Table([1 => 'objections_to_reorganize']);
		$this->QUOTE_WARNING_BANNER_KEYS = new Table([1 => 'quote_too_short', 2 => 'quote_too_long', 3 => 'quote_ref_incomplete', 4 => 'quote_translated']);
		$this->REFERENCE_WARNING_BANNER_KEYS = new Table([1 => 'reference_quality_insufficient', 2 => 'reference_incomplete', 3 => 'dead_link']);
		$this->SUMMARY_WARNING_BANNER_KEYS = new Table([1 => 'summary_disadvantageous', 2 => 'summary_to_be_written', 3 => 'summary_unclear', 4 => 'summary_too_long', 5 => 'summary_to_separate', 6 => 'summary_style_to_review']);
		$this->SUMMARY_WARNING_ALIASES = null;
		$this->DISPLAYED_TITLE_WARNING_BANNER_KEYS = new Table([1 => 'displayed_title_too_long', 2 => 'displayed_title_unclear', 3 => 'displayed_title_disadvantageous']);
		$this->URLENC_CACHE = $this->cacheMake(2000);
		$this->TITLE_WARNING_BANNER_KEYS = new Table([1 => 'title_incomplete', 2 => 'title_disadvantageous', 3 => 'title_unclear', 4 => 'title_too_long']);
		$this->ARGUMENT_WARNING_BANNER_KEYS = new Table([1 => 'argument_sensitive', 2 => 'argument_fanciful', 3 => 'argument_potentially_illegal', 4 => 'argument_generated_by_ai']);
	}

	protected function anchorId($s = null) {
		return NativeText::anchorId($s);
	}

	protected function cacheMake($max = null) {
		return new Table(['data' => new Table, 'order' => new Table, 'max' => $max === null || $max === false ? 200 : $max]);
	}

	protected function cacheGet($C = null, $k = null) {
		return $C['data'][$k];
	}

	protected function cachePut($C = null, $k = null, $v = null) {
		if ($C['data'][$k] !== null) { $C['data'][$k] = $v; return; }
		$C['data'][$k] = $v;
		Lua::insert($C['order'], $k);
		if (count($C['order']) > $C['max']) {
			$old = Lua::remove($C['order'], 1);
			if ($old !== null) { $C['data'][$old] = null; }
		}
	}

	protected function normalizeTooltipText($s = null) {
		return NativeText::tooltip($s);
	}

	protected function fastParamsKey($params = null) {
		if ((Lua::type($params) !== 'table')) {
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

	protected function splitCSV($s = null, $sep = null) {
		return SerializedData::csv($s, $sep);
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
		$formName = Lua::str((Lua::truth($v13 = $formName) ? $v13 : ''));
		$pageTitle = Lua::str((Lua::truth($v14 = $pageTitle) ? $v14 : ''));
		if ((($formName === '') || ($pageTitle === ''))) {
			return '';
		}
		$opts = (Lua::truth($v15 = (Lua::truth($v16 = (Lua::type($opts) === 'table')) ? $opts : $v16)) ? $v15 : new Table([]));
		$target = $this->wkAddDataPath($formName, $pageTitle);
		$lt = $linktext;
		if ((($lt === null) || ($lt === ''))) {
			$lt = '&nbsp;';
		}
		$tt = Lua::str((Lua::truth($v17 = $tooltip) ? $v17 : ''));
		$classes = 'wk-adddata-link navigation-not-searchable noprint';
		if (($opts['editButton'] === true)) {
			$classes = ($classes . ' bouton-modifier');
		}
		if (($tt !== '')) {
			return Lua::format('<span class="%s" data-wk-tooltip="%s">[[%s|%s]]</span>', $classes, $this->escapeAttr($tt), $target, $lt);
		}
		return Lua::format('<span class="%s">[[%s|%s]]</span>', $classes, $target, $lt);
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
		$formName = Lua::str((Lua::truth($v26 = $opts['form']) ? $v26 : ''));
		$fieldName = Lua::str((Lua::truth($v27 = $opts['field']) ? $v27 : ''));
		$value = Lua::str((Lua::truth($v28 = $opts['value']) ? $v28 : ''));
		if (((($formName === '') || ($fieldName === '')) || ($value === ''))) {
			return '';
		}
		$title = Lua::str((Lua::truth($v29 = $opts['_title']) ? $v29 : ''));
		if (($title === '')) {
			$title = $this->wkRunQueryPath($formName);
		}
		$q = new Table([]);
		$qkey = Lua::str((Lua::truth($v30 = $opts['_qkey']) ? $v30 : ''));
		if (($qkey === '')) {
			$qkey = ($formName . '[' . $fieldName . ']');
		}
		$q[$qkey] = $value;
		$extra = $opts['extra'];
		if ((Lua::type($extra) === 'table')) {
			foreach (Lua::pairs($extra) as $k => $v) {
				$kk = Lua::str((Lua::truth($v31 = $k) ? $v31 : ''));
				if ((($kk !== '') && ($v !== null))) {
					$vv = Lua::str($v);
					if (($vv !== '')) {
						if (Lua::truth(Lua::find($kk, '%[', 1, true))) {
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
		$tt = $this->normalizeTooltipText(Lua::str((Lua::truth($v33 = $opts['tooltip']) ? $v33 : '')));
		$link = ('[' . $url . ' ' . $label . ']');
		if (($tt !== '')) {
			return Lua::format('<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>', $this->escapeAttr($tt), $link);
		}
		return Lua::format('<span class="wk-adddata-link">%s</span>', $link);
	}

	protected function listBreaker() {
		return '

<!--__WD_LIST_BREAK__-->';
	}

	protected function pfProp($PF = null, $k = null, $fallback = null) {
		$v = (Lua::truth($v34 = (Lua::truth($v35 = $PF) ? $PF[$k] : $v35)) ? $v34 : '');
		$v = Lua::trim(Lua::str((Lua::truth($v36 = $v) ? $v36 : '')));
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
		if ((Lua::type($val) === 'string')) {
			$val = Lua::trim($val);
			if (($val === '')) {
				return null;
			}
		}
		$cur = $pending[$key];
		if (Lua::truth($multi)) {
			if (($cur === null)) {
				$pending[$key] = (Lua::truth($v38 = (Lua::truth($v39 = (Lua::type($val) === 'table')) ? $val : $v39)) ? $v38 : new Table([1 => $val]));
				return null;
			}
			if ((Lua::type($cur) !== 'table')) {
				$cur = new Table([1 => $cur]);
				$pending[$key] = $cur;
			}
			if ((Lua::type($val) === 'table')) {
				foreach (Lua::ipairs($val) as $_ => $x) {
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
		return (Lua::truth($v40 = $this->L($lang, 'subsection_banners', $key, ...$rest)) ? $v40 : '');
	}

	protected function buildSummaryAliases($lang = null) {
		$a = new Table([]);
		foreach (Lua::ipairs($this->SUMMARY_WARNING_BANNER_KEYS) as $_ => $key) {
			$alias = $this->subMsg($lang, ($key . '_alias'));
			$alias = Lua::trim(Lua::str((Lua::truth($v41 = $alias) ? $v41 : '')));
			if (($alias !== '')) {
				foreach (Lua::ipairs($this->splitCSV($alias, ',')) as $_ => $one) {
					$one = Lua::trim(Lua::str((Lua::truth($v42 = $one) ? $v42 : '')));
					if (($one !== '')) {
						$a[$one] = $key;
					}
				}
			}
		}
		return $a;
	}

	protected function buildSummaryLabelLookup($lang = null, $keys = null) {
		$lookup = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v43 = $keys) ? $v43 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->subMsg($lang, ($key . '_label')));
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveKeyFromLookup($raw = null, $lookup = null, $allowedKeys = null, $aliases = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v44 = $raw) ? $v44 : '')));
		if (($raw === '')) {
			return '';
		}
		$k = (Lua::truth($v45 = (Lua::truth($v46 = $lookup) ? $lookup[$raw] : $v46)) ? $v45 : '');
		if (($k !== '')) {
			return $k;
		}
		if ((Lua::truth($aliases) && Lua::truth($aliases[$raw]))) {
			return $aliases[$raw];
		}
		if (Lua::truth($allowedKeys)) {
			foreach (Lua::ipairs($allowedKeys) as $_ => $kk) {
				if (($raw === $kk)) {
					return $kk;
				}
			}
		}
		return '';
	}

	protected function renderSubsectionBanner($pv = null, $lang = null, $key = null, $opts = null, $cats = null) {
		$key = Lua::str((Lua::truth($v47 = $key) ? $v47 : ''));
		if (($key === '')) {
			return '';
		}
		$opts = (Lua::truth($v48 = $opts) ? $v48 : new Table([]));
		$isReorg = ($opts['reorg'] === true);
		$icon = Lua::str((Lua::truth($v49 = $opts['icon']) ? $v49 : ''));
		$linkHtml = '';
		$title = '';
		$text = '';
		if (Lua::truth($isReorg)) {
			$formName = Lua::str((Lua::truth($v50 = $opts['form']) ? $v50 : ''));
			$tooltip = Lua::str((Lua::truth($v51 = $opts['tooltip']) ? $v51 : ''));
			$linkLabel = Lua::str((Lua::truth($v52 = $opts['link_label']) ? $v52 : ''));
			if (($linkLabel === '')) {
				$linkLabel = $this->L($lang, 'text', 'reorganize_link_label');
			}
			if (($formName !== '')) {
				$linkHtml = $this->addDataLink($formName, $pv['rawTitle'], $linkLabel, $tooltip);
			}
		}
		if ((Lua::truth($isReorg) && ($linkHtml !== ''))) {
			$title = $this->subMsg($lang, ($key . '_title'));
			$title = Lua::trim(Lua::str((Lua::truth($v53 = $title) ? $v53 : '')));
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

	protected function renderReferenceItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsRef = Lua::trim(Lua::str((Lua::truth($v60 = $itemWarnings) ? $v60 : '')));
		if (($warnsRef === '')) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->REFERENCE_WARNING_BANNER_KEYS);
		foreach (Lua::ipairs($this->splitCSV($warnsRef, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->REFERENCE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, $cats));
			}
		}
	}

	protected function wkRenderOneBiblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$authors = Lua::trim((Lua::truth($v61 = $item['authors']) ? $v61 : ''));
		if (($authors === '')) {
			$authors = $PF['unknown_author'];
		}
		$out[(Lua::len($out) + 1)] = $authors;
		{
			$k = (Lua::truth($v62 = (Lua::truth($v63 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v63)) ? $v62 : '');
			if (($k !== '')) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		$article = Lua::trim((Lua::truth($v64 = $item['article']) ? $v64 : ''));
		$work = Lua::trim((Lua::truth($v65 = $item['work']) ? $v65 : ''));
		$link = Lua::trim((Lua::truth($v66 = $item['link']) ? $v66 : ''));
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
				$out[(Lua::len($out) + 1)] = (', « [' . $link . ' ' . $article . '] »');
			}
			else {
				$out[(Lua::len($out) + 1)] = (', « ' . $article . ' »');
			}
		}
		if (($work !== '')) {
			if (($article !== '')) {
				$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
			}
			else {
				if (($link !== '')) {
					$out[(Lua::len($out) + 1)] = (', \'\'[' . $link . ' ' . $work . ']\'\'');
				}
				else {
					$out[(Lua::len($out) + 1)] = (', \'\'' . $work . '\'\'');
				}
			}
		}
		$volume = Lua::trim((Lua::truth($v67 = $item['volume']) ? $v67 : ''));
		if (($volume !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $PF['volume_label'] . '&#160;' . $volume);
		}
		$number = Lua::trim((Lua::truth($v68 = $item['number']) ? $v68 : ''));
		if (($number !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $PF['issue_label'] . '&#160;' . $number);
		}
		$location = Lua::trim((Lua::truth($v69 = $item['location']) ? $v69 : ''));
		if (($location !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $location);
		}
		$page = Lua::trim((Lua::truth($v70 = $item['page']) ? $v70 : ''));
		if (($page !== '')) {
			$out[(Lua::len($out) + 1)] = (', p.' . $page);
		}
		$publisher = Lua::trim((Lua::truth($v71 = $item['publisher']) ? $v71 : ''));
		if (($publisher !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $publisher);
			{
				$kp = (Lua::truth($v72 = (Lua::truth($v73 = $smwPending) ? $this->smwPropRef($PF, 'publishing_house') : $v73)) ? $v72 : '');
				if (($kp !== '')) {
					$this->smwQueueAdd($smwPending, $kp, $publisher, true);
				}
			}
		}
		$place = Lua::trim((Lua::truth($v74 = $item['place']) ? $v74 : ''));
		if (($place !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $place);
			{
				$kp = (Lua::truth($v75 = (Lua::truth($v76 = $smwPending) ? $this->smwPropRef($PF, 'place_of_publication') : $v76)) ? $v75 : '');
				if (($kp !== '')) {
					$this->smwQueueAdd($smwPending, $kp, $place, true);
				}
			}
		}
		$date = Lua::trim((Lua::truth($v77 = $item['date']) ? $v77 : ''));
		if (($date !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderOneWeblioLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$page = Lua::trim((Lua::truth($v78 = $item['page']) ? $v78 : ''));
		$site = Lua::trim((Lua::truth($v79 = $item['site']) ? $v79 : ''));
		$link = Lua::trim((Lua::truth($v80 = $item['link']) ? $v80 : ''));
		$date = Lua::trim((Lua::truth($v81 = $item['date']) ? $v81 : ''));
		$authors = Lua::trim((Lua::truth($v82 = $item['authors']) ? $v82 : ''));
		{
			$k = (Lua::truth($v83 = (Lua::truth($v84 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v84)) ? $v83 : '');
			if ((($k !== '') && ($authors !== ''))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		if (($authors !== '')) {
			$out[(Lua::len($out) + 1)] = $authors;
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
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v85 = (Lua::truth($v86 = ($authors !== '')) ? ', ' : $v86)) ? $v85 : '') . '[' . $link . ' ' . $label . ']');
			}
			else {
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v87 = (Lua::truth($v88 = ($authors !== '')) ? ', ' : $v88)) ? $v87 : '') . (Lua::truth($v89 = (Lua::truth($v90 = ($page !== '')) ? $page : $v90)) ? $v89 : $site));
			}
		}
		if ((($site !== '') && ($page !== ''))) {
			$out[(Lua::len($out) + 1)] = (', \'\'' . $site . '\'\'');
		}
		if (($date !== '')) {
			$out[(Lua::len($out) + 1)] = (', ' . $date);
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderOneVideoLi($item = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		$out = new Table([]);
		$title = Lua::trim((Lua::truth($v91 = $item['title']) ? $v91 : ''));
		$link = Lua::trim((Lua::truth($v92 = $item['link']) ? $v92 : ''));
		$authors = Lua::trim((Lua::truth($v93 = $item['authors']) ? $v93 : ''));
		{
			$k = (Lua::truth($v94 = (Lua::truth($v95 = $smwPending) ? $this->smwPropRef($PF, 'authors') : $v95)) ? $v94 : '');
			if ((($k !== '') && ($authors !== ''))) {
				$this->smwQueueAdd($smwPending, $k, $authors, true);
			}
		}
		if (($authors !== '')) {
			$out[(Lua::len($out) + 1)] = $authors;
		}
		if ((($link !== '') && ($title !== ''))) {
			$out[(Lua::len($out) + 1)] = ((Lua::truth($v96 = (Lua::truth($v97 = ($authors !== '')) ? ', ' : $v97)) ? $v96 : '') . '[' . $link . ' ' . $title . ']');
		}
		else {
			if (($title !== '')) {
				$out[(Lua::len($out) + 1)] = ((Lua::truth($v98 = (Lua::truth($v99 = ($authors !== '')) ? ', ' : $v99)) ? $v98 : '') . $title);
			}
		}
		$this->renderReferenceItemBanners($item['warnings'], $pv, $lang, $cats, $out);
		return ('<li>' . Lua::concat($out) . '</li>');
	}

	protected function wkRenderReferencesFromRaw($rawB = null, $rawW = null, $rawV = null, $pv = null, $lang = null, $cats = null, $PF = null, $smwPending = null, $args = null) {
		[$b, $w, $v] = [[], [], []];
		foreach (Lua::ipairs($this->wkParseBiblioMarkers($rawB)) as $_ => $it) {
			$b[] = $this->wkRenderOneBiblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach (Lua::ipairs($this->wkParseWeblioMarkers($rawW)) as $_ => $it) {
			$w[] = $this->wkRenderOneWeblioLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		foreach (Lua::ipairs($this->wkParseVideoMarkers($rawV)) as $_ => $it) {
			$v[] = $this->wkRenderOneVideoLi($it, $pv, $lang, $cats, $PF, $smwPending, $args);
		}
		if ((((count($b) === 0) && (count($w) === 0)) && (count($v) === 0))) {
			return '';
		}
		$out = implode("\n", [implode("\n", $b), implode("\n", $w), implode("\n", $v)]);
		$out = Lua::trim($out);
		return $out;
	}


	protected function smwKeyFromArray($q = null) {
		if ((Lua::type($q) !== 'table')) {
			$s = Lua::str((Lua::truth($v101 = $q) ? $v101 : ''));
			return (Lua::str(Lua::len($s)) . ':' . $s);
		}
		$idx = new Table([]);
		$n = 0;
		foreach (Lua::pairs($q) as $k => $_value) {
			if ((Lua::type($k) === 'number')) {
				$n = ($n + 1);
				$idx[$n] = $k;
			}
		}
		if (($n === 0)) {
			return '';
		}
		Lua::sort($idx);
		$parts = new Table([]);
		$stop13 = $n;
		for ($i = 1; $i <= $stop13; $i++) {
			$v = Lua::str((Lua::truth($v102 = $q[$idx[$i]]) ? $v102 : ''));
			$parts[$i] = (Lua::str(Lua::len($v)) . ':' . $v);
		}
		return Lua::concat($parts, '
');
	}

	protected function getPageCreation($pv = null, $args = null) {
		$v = (Lua::truth($v103 = $args) ? $args['creation-date'] : $v103);
		$v = (Lua::truth($v104 = (Lua::truth($v105 = (Lua::type($v) === 'string')) ? Lua::trim($v) : $v105)) ? $v104 : '');
		if (($v !== '')) {
			return ($v . 'T00:00:00Z');
		}
		return null;
	}

	protected function makeSMWCache() {
		return new Table(['debatsPour' => null, 'debatsContre' => null, 'justifs' => null, 'objs' => null, 'debatsTousFinal' => null, 'debatCardsTous' => null, 'debatCardsPour' => null, 'debatCardsContre' => null, 'argCardsJustifs' => null, 'argCardsObjs' => null, 'breadcrumbChainsFinal' => null, 'parentDebatesFinal' => null]);
	}

	protected function renderTitleWarnings($pv = null, $cats = null, $lang = null) {
		$title = (Lua::truth($v106 = $pv['rawTitle']) ? $v106 : '');
		if (($title === '')) {
			return '';
		}
		$out = new Table([]);
		$n = 0;
		foreach (Lua::split($title, '%s+') as $w) {
			$w = Lua::trim($w);
			if (($w !== '')) {
				$n = ($n + 1);
				if (($n >= 3)) {
					break;
				}
			}
		}
		if ((($n > 0) && ($n < 3))) {
			Lua::insert($out, ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_too_short_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . ' ' . $this->L($lang, 'text', 'if_needed_msg') . '\'\'\'</div>'));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_very_short') . ']]'));
		}
		if (((Lua::sub($title, (-1)) === '.') && (Lua::sub($title, (-4)) !== 'etc.'))) {
			Lua::insert($out, ('<div class="warningbox cdx-message cdx-message--block cdx-message--warning navigation-not-searchable">\'\'\'' . $this->L($lang, 'text', 'title_ends_with_dot_msg') . ' [[Special:MovePage/' . $pv['encoded'] . '|' . $this->L($lang, 'text', 'rename_page_label') . ']]' . '\'\'\'</div>'));
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'title_ends_with_dot') . ']]'));
		}
		if (($lang === 'fr')) {
			$prefix3 = Lua::sub($title, 1, 3);
			$prefix4 = Lua::sub($title, 1, 4);
			$prefix5 = Lua::sub($title, 1, 5);
			$prefix6 = Lua::sub($title, 1, 6);
			$prefix10 = Lua::sub($title, 1, 10);
			$prefix13 = Lua::sub($title, 1, 13);
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
		return Lua::concat($out);
	}

	protected function uniqList($list = null) {
		[$out, $seen] = [[], []];
		foreach (Lua::ipairs((Lua::truth($v107 = $list) ? $v107 : new Table([]))) as $_ => $x) {
			$x = Lua::trim(Lua::str((Lua::truth($v108 = $x) ? $v108 : '')));
			if ((($x !== '') && !isset($seen[$x]))) {
				$seen[$x] = true;
				$out[count($out) + 1] = $x;
			}
		}
		return new Table($out);
	}

	protected function smwAskTitles($conditions = null, $limit = null, string $origin = 'other') {
		$limit = (Lua::truth($v109 = Lua::number($limit)) ? $v109 : 200);
		$q = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v110 = $conditions) ? $v110 : new Table([]))) as $_ => $c) {
			Lua::insert($q, $c);
		}
		Lua::insert($q, ('limit=' . Lua::str($limit)));
		Lua::insert($q, 'link=none');
		$ck = ('TITLES
' . $this->smwKeyFromArray($q));
		$hit = $this->cacheGet($this->SMW_TITLES_CACHE, $ck);
		if (($hit !== null)) {
			$this->smwCacheHit($origin, 'titles');
			return $hit;
		}
		$res = $this->smwAskSafe($q, $origin);
		if ((Lua::type($res) !== 'table')) {
			$this->cachePut($this->SMW_TITLES_CACHE, $ck, new Table([]));
			return new Table([]);
		}
		[$out, $seen] = [new Table([]), new Table([])];
		foreach (Lua::ipairs($res) as $_ => $row) {
			$ft = (Lua::truth($v111 = (Lua::truth($v112 = $row['fulltext']) ? $v112 : $row['page'])) ? $v111 : $row[1]);
			if ((((Lua::type($ft) === 'string') && ($ft !== '')) && !(Lua::truth($seen[$ft])))) {
				$seen[$ft] = true;
				Lua::insert($out, $ft);
			}
		}
		$this->cachePut($this->SMW_TITLES_CACHE, $ck, $out);
		return $out;
	}

	protected function normTitle($s = null) {
		$s = Lua::str((Lua::truth($v113 = $s) ? $v113 : ''));
		$s = Lua::gsub($s, '_', ' ');
		return Lua::trim($s);
	}

	protected function smwSafeTitle($s = null) {
		$s = $this->canonicalTitle($s);
		if (($s === '')) {
			return '';
		}
		$s = Lua::gsub($s, '#.*$', '');
		$s = Lua::trim($s);
		if (($s === '')) {
			return '';
		}
		if (Lua::truth(Lua::find($s, '[%[%]{}|<>]'))) {
			return '';
		}
		return $s;
	}

	protected function containsTitle($val = null, $target = null) {
		$target = $this->normTitle($target);
		if ((($target === '') || ($val === null))) {
			return false;
		}
		$checkOne = function($x = null) use (&$val, &$target, &$checkOne, &$ft, &$_, &$y) {
		if (($x === null)) {
			return false;
		}
		if ((Lua::type($x) === 'string')) {
			return ($this->normTitle($x) === $target);
		}
		if ((Lua::type($x) === 'table')) {
			$ft = (Lua::truth($v114 = (Lua::truth($v115 = (Lua::truth($v116 = $x['fulltext']) ? $v116 : $x['page'])) ? $v115 : $x['name'])) ? $v114 : $x['title']);
			if (((Lua::type($ft) === 'string') && ($this->normTitle($ft) === $target))) {
				return true;
			}
			foreach (Lua::ipairs($x) as $_ => $y) {
				if (Lua::truth($checkOne($y))) {
					return true;
				}
			}
		}
		return false;
		};
		return $checkOne($val);
	}

	protected function ensureDebateParents($pv = null, $cache = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['debatsPour'] !== null))) {
			return null;
		}
		$argTitle = $this->smwSafeTitle($pv['rawTitle']);
		$propPro = Lua::str((Lua::truth($v117 = $PF['prop_arg_pro']) ? $v117 : ''));
		$propCon = Lua::str((Lua::truth($v118 = $PF['prop_arg_con']) ? $v118 : ''));
		if (((($propPro === '') || ($propCon === '')) || ($argTitle === ''))) {
			$cache['debatsPour'] = new Table([]);
			$cache['debatsContre'] = new Table([]);
			return null;
		}
		$q = new Table([1 => ('([[' . $propPro . '::' . $argTitle . ']] OR [[' . $propCon . '::' . $argTitle . ']] )'), 2 => ('?' . $propPro . '=pro'), 3 => ('?' . $propCon . '=con'), 4 => 'limit=200', 5 => 'link=none']);
		$res = $this->smwAskSafe($q, 'debate-parents');
		[$debatsPour, $debatsContre] = [new Table([]), new Table([])];
		[$seenP, $seenC] = [new Table([]), new Table([])];
		if ((Lua::type($res) === 'table')) {
			foreach (Lua::ipairs($res) as $_ => $row) {
				$ft = (Lua::truth($v119 = (Lua::truth($v120 = $row['fulltext']) ? $v120 : $row['page'])) ? $v119 : $row[1]);
				if (((Lua::type($ft) === 'string') && ($ft !== ''))) {
					if ((Lua::truth($this->containsTitle($row['pro'], $argTitle)) && !(Lua::truth($seenP[$ft])))) {
						$seenP[$ft] = true;
						Lua::insert($debatsPour, $ft);
					}
					if ((Lua::truth($this->containsTitle($row['con'], $argTitle)) && !(Lua::truth($seenC[$ft])))) {
						$seenC[$ft] = true;
						Lua::insert($debatsContre, $ft);
					}
				}
			}
		}
		if (((Lua::len($debatsPour) === 0) && (Lua::len($debatsContre) === 0))) {
			$debatsPour = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propPro . '::' . $argTitle . ']]')]), 200, 'debate-parents'));
			$debatsContre = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propCon . '::' . $argTitle . ']]')]), 200, 'debate-parents'));
		}
		$cache['debatsPour'] = $debatsPour;
		$cache['debatsContre'] = $debatsContre;
	}

	protected function ensureArgumentParents($pv = null, $cache = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['justifs'] !== null))) {
			return null;
		}
		$argTitle = $this->smwSafeTitle($pv['rawTitle']);
		$propJustif = Lua::str((Lua::truth($v121 = $PF['prop_justif']) ? $v121 : ''));
		$propObj = Lua::str((Lua::truth($v122 = $PF['prop_obj']) ? $v122 : ''));
		if (((($propJustif === '') || ($propObj === '')) || ($argTitle === ''))) {
			$cache['justifs'] = new Table([]);
			$cache['objs'] = new Table([]);
			return null;
		}
		$q = new Table([1 => ('([[' . $propJustif . '::' . $argTitle . ']] OR [[' . $propObj . '::' . $argTitle . ']] )'), 2 => ('?' . $propJustif . '=justif'), 3 => ('?' . $propObj . '=obj'), 4 => 'limit=200', 5 => 'link=none']);
		$res = $this->smwAskSafe($q, 'argument-parents');
		[$justifs, $objs] = [new Table([]), new Table([])];
		[$seenJ, $seenO] = [new Table([]), new Table([])];
		if ((Lua::type($res) === 'table')) {
			foreach (Lua::ipairs($res) as $_ => $row) {
				$ft = (Lua::truth($v123 = (Lua::truth($v124 = $row['fulltext']) ? $v124 : $row['page'])) ? $v123 : $row[1]);
				if (((Lua::type($ft) === 'string') && ($ft !== ''))) {
					if ((Lua::truth($this->containsTitle($row['justif'], $argTitle)) && !(Lua::truth($seenJ[$ft])))) {
						$seenJ[$ft] = true;
						Lua::insert($justifs, $ft);
					}
					if ((Lua::truth($this->containsTitle($row['obj'], $argTitle)) && !(Lua::truth($seenO[$ft])))) {
						$seenO[$ft] = true;
						Lua::insert($objs, $ft);
					}
				}
			}
		}
		if (((Lua::len($justifs) === 0) && (Lua::len($objs) === 0))) {
			$justifs = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propJustif . '::' . $argTitle . ']]')]), 200, 'argument-parents'));
			$objs = $this->uniqList($this->smwAskTitles(new Table([1 => ('[[' . $propObj . '::' . $argTitle . ']]')]), 200, 'argument-parents'));
		}
		$cache['justifs'] = $justifs;
		$cache['objs'] = $objs;
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

	protected function ensureDebateParentsFinal($pv = null, $cache = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['debatsTousFinal'] !== null))) {
			return null;
		}
		$final = new Table([]);
		$this->ensureDebateParents($pv, $cache, $PF);
		foreach (Lua::ipairs((Lua::truth($v127 = $cache['debatsPour']) ? $v127 : new Table([]))) as $_ => $d) {
			Lua::insert($final, $this->canonicalTitle($d));
		}
		foreach (Lua::ipairs((Lua::truth($v128 = $cache['debatsContre']) ? $v128 : new Table([]))) as $_ => $d) {
			Lua::insert($final, $this->canonicalTitle($d));
		}
		if (Lua::truth($this->hasSMW())) {
			$this->ensureArgumentParents($pv, $cache, $PF);
			$propBreadcrumb = Lua::str((Lua::truth($v129 = $PF['prop_breadcrumb']) ? $v129 : ''));
			$propParentDebate = Lua::str((Lua::truth($v130 = $PF['prop_parent_debate']) ? $v130 : ''));
			if ((($propBreadcrumb !== '') && ($propParentDebate !== ''))) {
				$parents = new Table([]);
				foreach (Lua::ipairs((Lua::truth($v131 = $cache['justifs']) ? $v131 : new Table([]))) as $_ => $a) {
					Lua::insert($parents, $this->canonicalTitle($a));
				}
				foreach (Lua::ipairs((Lua::truth($v132 = $cache['objs']) ? $v132 : new Table([]))) as $_ => $a) {
					Lua::insert($parents, $this->canonicalTitle($a));
				}
				$parents = $this->uniqList($parents);
				if ((Lua::len($parents) > 0)) {
					$conds = new Table([]);
					foreach (Lua::ipairs($parents) as $_ => $t) {
						$safe = $this->smwSafeTitle($t);
						if (($safe !== '')) {
							Lua::insert($conds, ('[[' . $safe . ']]'));
						}
					}
					if ((Lua::len($conds) > 0)) {
						$q = new Table([1 => ('(' . Lua::concat($conds, ' OR ') . ')'), 2 => ('?' . $propBreadcrumb . '=chain'), 3 => ('?' . $propParentDebate . '=pdeb'), 4 => 'limit=200', 5 => 'link=none']);
						$res = $this->smwAskSafe($q, 'breadcrumb');
						if ((Lua::type($res) === 'table')) {
							foreach (Lua::ipairs($res) as $_ => $row) {
								$chain = $row['chain'];
								$okLen = false;
								if ((Lua::type($chain) === 'string')) {
									$n = $this->breadcrumbLenByteSafe($chain);
									$okLen = (($n > 0) && ($n < 6));
								}
								else {
									if ((Lua::type($chain) === 'table')) {
										foreach (Lua::ipairs($chain) as $_ => $ch) {
											if ((Lua::type($ch) === 'string')) {
												$n = $this->breadcrumbLenByteSafe($ch);
												if ((($n > 0) && ($n < 6))) {
													$okLen = true;
													break;
												}
											}
										}
									}
								}
								if (Lua::truth($okLen)) {
									$pdeb = $row['pdeb'];
									if (((Lua::type($pdeb) === 'string') && (Lua::trim($pdeb) !== ''))) {
										Lua::insert($final, $this->canonicalTitle($pdeb));
									}
									else {
										if ((Lua::type($pdeb) === 'table')) {
											foreach (Lua::ipairs($pdeb) as $_ => $x) {
												if (((Lua::type($x) === 'string') && (Lua::trim($x) !== ''))) {
													Lua::insert($final, $this->canonicalTitle($x));
												}
												else {
													if ((Lua::type($x) === 'table')) {
														$ft = (Lua::truth($v133 = (Lua::truth($v134 = $x['fulltext']) ? $v134 : $x['page'])) ? $v133 : $x[1]);
														if (((Lua::type($ft) === 'string') && (Lua::trim($ft) !== ''))) {
															Lua::insert($final, $this->canonicalTitle($ft));
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}
		$cache['debatsTousFinal'] = $this->uniqList($final);
	}

	protected function normalizeTitleForCard($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v135 = $s) ? $v135 : '')));
		if (($s === '')) {
			return '';
		}
		if (((Lua::byte($s, 1) !== 58) && !(Lua::truth(Lua::find($s, '[%[<&]'))))) {
			return $s;
		}
		$s = Lua::gsub($s, '^:%s*', '');
		{
			$target = (Lua::truth($v136 = Lua::match($s, '^%[%[([^%]|]+)%|.-%]%]$')) ? $v136 : Lua::match($s, '^%[%[([^%]]+)%]%]$'));
			if ((Lua::truth($target) && ($target !== ''))) {
				$s = $target;
			}
		}
		$s = Lua::gsub(Lua::trim($s), '^:%s*', '');
		if (Lua::truth(Lua::find($s, '&', 1, true))) {
			$s = Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub(Lua::gsub($s, '&nbsp;', ' '), '&#160;', ' '), '&amp;', '&'), '&quot;', '"'), '&#39;', '\''), '&lt;', '<'), '&gt;', '>');
		}
		return Lua::trim($s);
	}

	protected function mwUrlEncodePF($s = null) {
		$s = Lua::str((Lua::truth($v137 = $s) ? $v137 : ''));
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
' . Lua::str((Lua::truth($v138 = $targetTitle) ? $v138 : '')) . '
' . Lua::str((Lua::truth($v139 = $currentArgTitle) ? $v139 : '')) . '
' . Lua::str((Lua::truth($v140 = $typeValue) ? $v140 : '')));
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

	protected function ensureDebateCards($pv = null, $cache = null, $lang = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['debatCardsTous'] !== null))) {
			return null;
		}
		$argTitle = $pv['rawTitle'];
		$debateKey = function($d = null) use (&$pv, &$cache, &$lang, &$PF, &$argTitle, &$debateKey, &$linked, &$_, &$k, &$cardsTous, &$typeDebate, &$cardsPour, &$cardsContre) {
		return $this->canonicalTitle($d);
		};
		$linked = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v141 = $cache['debatsPour']) ? $v141 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			if (($k !== '')) {
				$linked[$k] = true;
			}
		}
		foreach (Lua::ipairs((Lua::truth($v142 = $cache['debatsContre']) ? $v142 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			if (($k !== '')) {
				$linked[$k] = true;
			}
		}
		$cardsTous = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v143 = $cache['parentDebatesFinal']) ? $v143 : new Table([]))) as $_ => $d) {
			$k = $debateKey($d);
			$typeDebate = (Lua::truth($v144 = (Lua::truth($v145 = (Lua::truth($v146 = ($k !== '')) ? $linked[$k] : $v146)) ? 'debate' : $v145)) ? $v144 : '');
			Lua::insert($cardsTous, $this->cardDebateRendered($d, $argTitle, $typeDebate));
		}
		$cache['debatCardsTous'] = $cardsTous;
		$cardsPour = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v147 = $cache['debatsPour']) ? $v147 : new Table([]))) as $_ => $d) {
			Lua::insert($cardsPour, $this->cardDebateRendered($d, $argTitle, 'debate'));
		}
		$cache['debatCardsPour'] = $cardsPour;
		$cardsContre = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v148 = $cache['debatsContre']) ? $v148 : new Table([]))) as $_ => $d) {
			Lua::insert($cardsContre, $this->cardDebateRendered($d, $argTitle, 'debate'));
		}
		$cache['debatCardsContre'] = $cardsContre;
	}

	protected function ensureArgumentCards($pv = null, $cache = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['argCardsJustifs'] !== null))) {
			return null;
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$argTitle = $pv['rawTitle'];
		$typeArgument = 'argument';
		$cj = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v149 = $cache['justifs']) ? $v149 : new Table([]))) as $_ => $a) {
			Lua::insert($cj, $this->cardArgumentRendered($a, $argTitle, $typeArgument));
		}
		$cache['argCardsJustifs'] = $cj;
		$co = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v150 = $cache['objs']) ? $v150 : new Table([]))) as $_ => $a) {
			Lua::insert($co, $this->cardArgumentRendered($a, $argTitle, $typeArgument));
		}
		$cache['argCardsObjs'] = $co;
	}

	protected function ensureDot($s = null) {
		$s = Lua::str((Lua::truth($v151 = $s) ? $v151 : ''));
		$s = Lua::trim($s);
		if (($s === '')) {
			return $s;
		}
		if ((Lua::sub($s, (-1)) === '.')) {
			return $s;
		}
		return ($s . '.');
	}

	protected function renderParentBanner($iconClass = null, $texte = null) {
		return ('<div class="bandeau-en-tete searchaux wk-icon-before ' . $iconClass . '">' . $this->ensureDot($texte) . '</div>');
	}

	protected function bannerMsg($lang = null, $key = null, ...$rest) {
		return (Lua::truth($v152 = $this->context->message('Argument.banners', $lang, $key, ...$rest)) ? $v152 : '');
	}

	protected function renderMetaWarningHtml($params = null) {
		$color = Lua::str((Lua::truth($v153 = $params['color']) ? $v153 : ''));
		$icon = Lua::str((Lua::truth($v154 = $params['icon']) ? $v154 : ''));
		$title = Lua::str((Lua::truth($v155 = $params['title']) ? $v155 : ''));
		$text = Lua::str((Lua::truth($v156 = $params['text']) ? $v156 : ''));
		return ('<div class="bandeau bandeau-' . $color . ' wk-icon-before ' . $icon . ' navigation-not-searchable">' . '<div class="bandeau-titre>' . $title . '</div>' . '<div class="bandeau-texte">' . $text . '</div>' . '</div>');
	}

	protected function renderBannerByKey($pv = null, $lang = null, $key = null, $cats = null) {
		$key = Lua::str((Lua::truth($v157 = $key) ? $v157 : ''));
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
		foreach (Lua::ipairs((Lua::truth($v158 = $keys) ? $v158 : new Table([]))) as $_ => $key) {
			$lab = Lua::trim($this->bannerMsg($lang, ($key . '_label')));
			if (($lab !== '')) {
				$lookup[$lab] = $key;
			}
		}
		return $lookup;
	}

	protected function resolveBannerKeyFromLookup($raw = null, $lookup = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v159 = $raw) ? $v159 : '')));
		if (($raw === '')) {
			return '';
		}
		return (Lua::truth($v160 = (Lua::truth($v161 = $lookup) ? $lookup[$raw] : $v161)) ? $v160 : '');
	}

	protected function renderBannersFromArgList($rawList = null, $lookup = null, $pv = null, $lang = null, $cats = null) {
		$html = new Table([]);
		foreach (Lua::ipairs($this->splitCSV($rawList, ',')) as $_ => $x) {
			$key = $this->resolveBannerKeyFromLookup($x, $lookup);
			if (($key !== '')) {
				Lua::insert($html, $this->renderBannerByKey($pv, $lang, $key, $cats));
			}
		}
		return Lua::concat($html);
	}

	protected function joinCards($cards = null, $lang = null) {
		$n = (Lua::truth($v162 = (Lua::truth($v163 = $cards) ? Lua::len($cards) : $v163)) ? $v162 : 0);
		if (($n === 0)) {
			return '';
		}
		if (($n === 1)) {
			return (Lua::truth($v164 = $cards[1]) ? $v164 : '');
		}
		$conj = $this->L($lang, 'text', 'list_conjunction_text');
		$conj = Lua::str((Lua::truth($v165 = $conj) ? $v165 : ' et '));
		$conj = Lua::gsub($conj, '%s+', '&#32;');
		if (($n === 2)) {
			return ((Lua::truth($v166 = $cards[1]) ? $v166 : '') . $conj . (Lua::truth($v167 = $cards[2]) ? $v167 : ''));
		}
		$head = Lua::concat($cards, ', ', 1, ($n - 1));
		return ($head . $conj . (Lua::truth($v168 = $cards[$n]) ? $v168 : ''));
	}

	protected function renderDebateParentsBoxes($pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		if (!(Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureDebateParents($pv, $cache, $PF);
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$out = new Table([]);
		$nTous = Lua::len((Lua::truth($v169 = $cache['parentDebatesFinal']) ? $v169 : new Table([])));
		if (($nTous > 0)) {
			$msgKey = (Lua::truth($v170 = (Lua::truth($v171 = ($nTous === 1)) ? 'parent_used_in_debate_1' : $v171)) ? $v170 : 'parent_used_in_debate_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsTous'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-parent', $texte));
		}
		if ((Lua::truth($cache['debatsPour']) && (Lua::len($cache['debatsPour']) > 0))) {
			$msgKey = (Lua::truth($v172 = (Lua::truth($v173 = (Lua::len($cache['debatsPour']) === 1)) ? 'parent_is_pro_in_1' : $v173)) ? $v172 : 'parent_is_pro_in_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsPour'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-pro', $texte));
		}
		if ((Lua::truth($cache['debatsContre']) && (Lua::len($cache['debatsContre']) > 0))) {
			$msgKey = (Lua::truth($v174 = (Lua::truth($v175 = (Lua::len($cache['debatsContre']) === 1)) ? 'parent_is_con_in_1' : $v175)) ? $v174 : 'parent_is_con_in_n');
			$texte = Lua::format($this->L($lang, 'text', $msgKey), (': ' . $this->joinCards($cache['debatCardsContre'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-against', $texte));
		}
		return Lua::concat($out);
	}

	protected function renderArgumentParentsBoxes($pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		if (!(Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureArgumentParents($pv, $cache, $PF);
		$this->ensureArgumentCards($pv, $cache, $PF);
		$out = new Table([]);
		if ((Lua::truth($cache['justifs']) && (Lua::len($cache['justifs']) > 0))) {
			$texte = Lua::format($PF['parent_is_justification_of'], (': ' . $this->joinCards($cache['argCardsJustifs'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-pro', $texte));
		}
		if ((Lua::truth($cache['objs']) && (Lua::len($cache['objs']) > 0))) {
			$texte = Lua::format($PF['parent_is_objection_to'], (': ' . $this->joinCards($cache['argCardsObjs'], $lang)));
			Lua::insert($out, $this->renderParentBanner('wk-icon-argument is-against', $texte));
		}
		return Lua::concat($out);
	}

	protected function renderDebatesParentsBottom($pv = null, $lang = null, $cache = null, $PF = null) {
		if (!(Lua::truth($this->hasSMW()))) {
			return '';
		}
		$this->ensureDebateCards($pv, $cache, $lang, $PF);
		$n = Lua::len((Lua::truth($v176 = $cache['parentDebatesFinal']) ? $v176 : new Table([])));
		if (($n === 0)) {
			return '<h2 id="Parent_debates" style="display:none;"></h2>';
		}
		$title = (Lua::truth($v177 = (Lua::truth($v178 = ($n === 1)) ? $PF['parent_debates_title_1'] : $v178)) ? $v177 : $PF['parent_debates_title_n']);
		$lis = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v179 = $cache['debatCardsTous']) ? $v179 : new Table([]))) as $_ => $card) {
			Lua::insert($lis, ('<li style="position: relative;">' . $card . '</li>'));
		}
		return ('<h2 id="Parent_debates" class="wk-icon-before wk-icon-parent">' . $title . '</h2>' . '<ul class="hover-top navigation-not-searchable">' . Lua::concat($lis) . '</ul>');
	}

	protected function renderInitializationBlock($args = null, $pv = null, $cats = null, $lang = null, $cache = null, $PF = null) {
		$hasParents = (((Lua::len((Lua::truth($v180 = $cache['parentDebatesFinal']) ? $v180 : new Table([]))) > 0) || (Lua::len((Lua::truth($v181 = $cache['justifs']) ? $v181 : new Table([]))) > 0)) || (Lua::len((Lua::truth($v182 = $cache['objs']) ? $v182 : new Table([]))) > 0));
		if (Lua::truth($hasParents)) {
			return '';
		}
		$initRaw = $args['initialization'];
		$initRaw = (Lua::truth($v183 = (Lua::truth($v184 = (Lua::type($initRaw) === 'string')) ? Lua::trim($initRaw) : $v184)) ? $v183 : '');
		$hasInit = ($initRaw !== '');
		$orphanBanner = '';
		$orphanBanner = (Lua::truth($v185 = $this->renderBannerByKey($pv, $lang, 'argument_orphan', $cats)) ? $v185 : '');
		if (Lua::truth($hasInit)) {
			$today = $this->context->currentDate();
			$creationDate = Lua::trim(Lua::str((Lua::truth($v186 = $args['creation-date']) ? $v186 : '')));
			if (($creationDate === $today)) {
				return $orphanBanner;
			}
			Lua::insert($cats, ('[[Category:' . $PF['cat_uninitialized'] . ']]'));
			$parts = $this->splitByLiteral($initRaw, '@', 2);
			$initType = Lua::trim((Lua::truth($v187 = $parts[1]) ? $v187 : ''));
			$parentId = Lua::trim((Lua::truth($v188 = $parts[2]) ? $v188 : ''));
			$parentPage = '';
			{
				$parentId = Lua::str((Lua::truth($v189 = $parentId) ? $v189 : ''));
				$parentId = Lua::trim($parentId);
				$parentId = (Lua::truth($v190 = Lua::match($parentId, '(%d+)')) ? $v190 : '');
				if ((((($parentId !== '') && Lua::truth(true)) && Lua::truth($this->context->smwAvailable())) && (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function'))) {
					$PF['prop_debate_number'] = $this->L($lang, 'props', 'debate_number');
					[$ok, $res] = Lua::pcall(function($query) { return $this->smwAskUncached($query, 'initialization'); }, new Table([1 => ('[[' . $PF['prop_debate_number'] . '::' . $parentId . ']] OR [[' . $PF['prop_arg_number'] . '::' . $parentId . ']]'), 2 => 'limit=1', 3 => 'link=none']));
					if (((Lua::truth($ok) && (Lua::type($res) === 'table')) && Lua::truth($res[1]))) {
						$row = $res[1];
						$ft = (Lua::truth($v191 = (Lua::truth($v192 = $row['fulltext']) ? $v192 : $row['page'])) ? $v191 : $row[1]);
						if ((Lua::type($ft) === 'string')) {
							$ft = Lua::trim($ft);
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
			$displayedTitleParam = Lua::trim(Lua::str((Lua::truth($v193 = $this->L($lang, 'params', 'displayed_title')) ? $v193 : '')));
			if (($displayedTitleParam !== '')) {
				Lua::insert($qsParts, ($prefix . '[' . $displayedTitleParam . ']=' . $title));
			}
			$qs = Lua::concat($qsParts, '&');
			$warnTitle = Lua::format((Lua::truth($v194 = $PF['init_warning_title']) ? $v194 : '%s'), $formName);
			$warnIntro = $PF['init_warning_intro'];
			$yesText = (Lua::truth($v195 = (Lua::truth($v196 = $PF['init_yes_finish_label']) ? $v196 : $PF['edit'])) ? $v195 : '');
			$yesTooltip = Lua::format((Lua::truth($v197 = $PF['init_yes_tt']) ? $v197 : '%s'), $parentPage);
			$okText = Lua::format((Lua::truth($v198 = $PF['init_ok_text']) ? $v198 : '%s'), $parentPage);
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

	protected function ensureBreadcrumbSelection($pv = null, $cache = null, $cats = null, $lang = null, $PF = null) {
		if ((!(Lua::truth($cache)) || ($cache['breadcrumbChainsFinal'] !== null))) {
			return null;
		}
		$cache['breadcrumbChainsFinal'] = new Table([]);
		$cache['parentDebatesFinal'] = new Table([]);
		if ((!(Lua::truth($this->hasSMW())) || (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) !== 'function'))) {
			return null;
		}
		$lang = (Lua::truth($v204 = $lang) ? $v204 : 'fr');
		$title = $this->smwSafeTitle($pv['rawTitle']);
		if (($title === '')) {
			return null;
		}
		$propBreadcrumb = Lua::str((Lua::truth($v205 = $PF['prop_breadcrumb']) ? $v205 : ''));
		$propDebateName = Lua::str((Lua::truth($v206 = $PF['prop_debate_name']) ? $v206 : ''));
		$propPro = Lua::str((Lua::truth($v207 = $PF['prop_arg_pro']) ? $v207 : ''));
		$propCon = Lua::str((Lua::truth($v208 = $PF['prop_arg_con']) ? $v208 : ''));
		$propJustif = Lua::str((Lua::truth($v209 = $PF['prop_justif']) ? $v209 : ''));
		$propObj = Lua::str((Lua::truth($v210 = $PF['prop_obj']) ? $v210 : ''));
		if (((((($propBreadcrumb === '') || ($propPro === '')) || ($propCon === '')) || ($propJustif === '')) || ($propObj === ''))) {
			return null;
		}
		$q = new Table([1 => ('([[' . $propJustif . '::' . $title . ']] OR [[' . $propObj . '::' . $title . ']] OR [[' . $propPro . '::' . $title . ']] OR [[' . $propCon . '::' . $title . ']] )'), 2 => ('?' . $propBreadcrumb . '=chain'), 3 => (Lua::truth($v211 = (Lua::truth($v212 = ($propDebateName !== '')) ? ('?' . $propDebateName . '=debateName') : $v212)) ? $v211 : null), 4 => 'limit=200', 5 => 'link=none']);
		$qq = new Table([]);
		foreach (Lua::ipairs($q) as $_ => $x) {
			if (Lua::truth($x)) {
				Lua::insert($qq, $x);
			}
		}
		$res = $this->smwAskSafe($qq, 'breadcrumb');
		if (((Lua::type($res) !== 'table') || (Lua::len($res) === 0))) {
			return null;
		}
		$bestByDebate = new Table([]);
		$consider = function($ch = null) use (&$pv, &$cache, &$cats, &$lang, &$PF, &$title, &$propBreadcrumb, &$propDebateName, &$propPro, &$propCon, &$propJustif, &$propObj, &$q, &$qq, &$_, &$x, &$res, &$bestByDebate, &$consider, &$parts, &$clean, &$part, &$n, &$root, &$cur, &$row, &$chain, &$selected, &$item, &$filtered, &$chains, &$debates, &$tooLong, &$newChain) {
		if (((Lua::type($ch) !== 'string') || ($ch === ''))) {
			return null;
		}
		$parts = $this->splitByLiteral($ch, '⟭');
		$clean = new Table([]);
		foreach (Lua::ipairs($parts) as $_ => $part) {
			$part = Lua::trim($part);
			if (($part !== '')) {
				$clean[(Lua::len($clean) + 1)] = $part;
			}
		}
		$n = Lua::len($clean);
		if (($n === 0)) {
			return null;
		}
		$root = $this->canonicalTitle((Lua::truth($v213 = $clean[1]) ? $v213 : ''));
		if (($root === '')) {
			return null;
		}
		$cur = $bestByDebate[$root];
		if ((!(Lua::truth($cur)) || ($n < $cur['len']))) {
			$bestByDebate[$root] = new Table(['root' => $root, 'chain' => Lua::concat($clean, '⟭'), 'len' => $n]);
		}
		};
		foreach (Lua::ipairs($res) as $_ => $row) {
			$chain = $row['chain'];
			if ((Lua::type($chain) === 'string')) {
				$consider($chain);
			}
			else {
				if ((Lua::type($chain) === 'table')) {
					foreach (Lua::ipairs($chain) as $_ => $ch) {
						$consider($ch);
					}
				}
			}
		}
		$selected = new Table([]);
		foreach (Lua::pairs($bestByDebate) as $_ => $item) {
			$selected[(Lua::len($selected) + 1)] = $item;
		}
		if ((Lua::len($selected) === 0)) {
			return null;
		}
		if ((Lua::len($selected) > 1)) {
			$filtered = new Table([]);
			foreach (Lua::ipairs($selected) as $_ => $item) {
				if (($item['len'] < 4)) {
					$filtered[(Lua::len($filtered) + 1)] = $item;
				}
			}
			$selected = $filtered;
		}
		if ((Lua::len($selected) === 0)) {
			return null;
		}
		$chains = new Table([]);
		$debates = new Table([]);
		$tooLong = false;
		foreach (Lua::ipairs($selected) as $_ => $item) {
			$newChain = ($item['chain'] . '⟭' . $title);
			if (($this->breadcrumbLenByteSafe($newChain) > 4)) {
				$tooLong = true;
			}
			$chains[(Lua::len($chains) + 1)] = $newChain;
			$debates[(Lua::len($debates) + 1)] = $item['root'];
		}
		if (Lua::truth($tooLong)) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'breadcrumb_too_long') . ']]'));
		}
		$cache['breadcrumbChainsFinal'] = $this->uniqList($chains);
		$cache['parentDebatesFinal'] = $this->uniqList($debates);
	}

	protected function wkBuildArgumentData($items = null) {
		$items = (Lua::truth($v214 = $items) ? $v214 : new Table([]));
		$listLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($listLines, ('* ' . Lua::str((Lua::truth($v215 = $it['title']) ? $v215 : ''))));
		}
		$listText = Lua::concat($listLines, '
');
		$argsVarParts = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			$page = Lua::trim(Lua::str((Lua::truth($v216 = $it['page']) ? $v216 : '')));
			if (($page !== '')) {
				$title = Lua::trim(Lua::str((Lua::truth($v217 = $it['title']) ? $v217 : '')));
				if ((($title === '') || ($title === $page))) {
					Lua::insert($argsVarParts, $page);
				}
				else {
					Lua::insert($argsVarParts, ($page . $this->FIELD_SEP . $title));
				}
			}
		}
		$argsVar = Lua::concat($argsVarParts, $this->ITEM_SEP);
		$hoverLines = new Table([]);
		foreach (Lua::ipairs($items) as $_ => $it) {
			Lua::insert($hoverLines, ('<div class="argument-title--map wk-icon-before wk-icon-argument">' . '[[' . Lua::str((Lua::truth($v218 = $it['page']) ? $v218 : '')) . '|' . Lua::str((Lua::truth($v219 = $it['title']) ? $v219 : '')) . ']]' . '</div>'));
		}
		$mapHoverHtml = Lua::concat($hoverLines, '
');
		return new Table(['items' => $items, 'list' => $listText, 'argsVar' => $argsVar, 'mapHover' => $mapHoverHtml]);
	}

	protected function renderArgumentItemBanners($itemWarnings = null, $pv = null, $lang = null, $cats = null, $out = null) {
		$warnsArg = Lua::trim(Lua::str((Lua::truth($v220 = $itemWarnings) ? $v220 : '')));
		if (($warnsArg === '')) {
			return null;
		}
		$lookup = $this->buildSummaryLabelLookup($lang, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
		$hasBanner = false;
		foreach (Lua::ipairs($this->splitCSV($warnsArg, ',')) as $_ => $x) {
			$key = $this->resolveKeyFromLookup($x, $lookup, $this->DISPLAYED_TITLE_WARNING_BANNER_KEYS);
			if (($key !== '')) {
				Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, null));
				$hasBanner = true;
			}
		}
		if ((Lua::truth($hasBanner) && Lua::truth($cats))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'displayed_titles_to_review') . ']]'));
		}
	}

	protected function wkRenderArgumentLi($pv = null, $lang = null, $it = null, $kind = null, $idx = null, $cats = null) {
		$isJustif = ($kind === 'justif');
		$idPrefix = (Lua::truth($v221 = (Lua::truth($v222 = $isJustif) ? 'Justification_' : $v222)) ? $v221 : 'Objection_');
		$page = Lua::str((Lua::truth($v223 = $it['page']) ? $v223 : ''));
		$titleShown = Lua::str((Lua::truth($v224 = $it['title']) ? $v224 : ''));
		if (($titleShown === '')) {
			$titleShown = $page;
		}
		$aId = $this->anchorId($titleShown);
		$warnings = Lua::str((Lua::truth($v225 = $it['warnings']) ? $v225 : ''));
		$banners = new Table([]);
		$this->renderArgumentItemBanners($warnings, $pv, $lang, $cats, $banners);
		return ('<li id="' . $idPrefix . Lua::str($idx) . '" class="argument level-1">' . '<div id="' . $this->escapeAttr($aId) . '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">' . '[[' . $page . '|' . $titleShown . ']]' . (Lua::truth($v226 = (Lua::truth($v227 = (Lua::len($banners) > 0)) ? Lua::concat($banners) : $v227)) ? $v226 : '') . '</div>' . '</li>');
	}

	protected function renderKeywords($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$out = new Table([]);
		Lua::insert($out, ('<div style="font-size: 95%; margin-top: 1em;">' . $PF['keywords_label']));
		$raw = $args['keywords'];
		if ((Lua::truth($raw) && ($raw !== ''))) {
			$extra = null;
			if ((($PF['search_type_field'] !== '') && ($PF['search_type_value'] !== ''))) {
				$extra = new Table([$PF['search_type_field'] => $PF['search_type_value']]);
			}
			$rq_title = $this->wkRunQueryPath($PF['form_search_by_keywords']);
			$rq_qkey = ($PF['form_search_by_keywords'] . '[' . $PF['keywords_field'] . ']');
			$rendered = new Table([]);
			foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $k) {
				$k = Lua::trim(Lua::str((Lua::truth($v228 = $k) ? $v228 : '')));
				if (($k !== '')) {
					$chip = $this->runQueryLink(new Table(['form' => $PF['form_search_by_keywords'], 'field' => $PF['keywords_field'], 'value' => $k, 'label' => $k, 'tooltip' => $PF['search_by_keyword_tt'], 'extra' => $extra, '_title' => $rq_title, '_qkey' => $rq_qkey]));
					if ((Lua::truth($chip) && ($chip !== ''))) {
						Lua::insert($rendered, $chip);
					}
					else {
						Lua::insert($rendered, $k);
					}
				}
			}
			if ((Lua::len($rendered) > 0)) {
				Lua::insert($out, Lua::concat($rendered, ', '));
			}
			else {
				Lua::insert($out, ('<strong>' . $PF['none'] . '</strong>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
			}
		}
		else {
			Lua::insert($out, ('<strong>' . $PF['none'] . '</strong>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_keywords'] . ']]'));
		}
		Lua::insert($out, ($this->addDataEditLink($PF['form_edit_keywords'], $pv['rawTitle'], '&nbsp;', $PF['edit_keywords_tooltip']) . '<span style="display: none;">.</span></div>'));
		return Lua::concat($out);
	}

	protected function renderSummary($args = null, $pv = null, $cats = null, $lang = null, $PF = null, $summaryId = null) {
		$edit = $this->addDataEditLink($PF['form_edit_summary'], $pv['rawTitle'], '&nbsp;', $PF['edit_summary_tooltip']);
		$out = new Table([]);
		Lua::insert($out, ('<h2' . $summaryId . ' class="section-modifiable wk-icon-before wk-icon-summary">' . $PF['summary'] . $edit . '</h2>'));
		{
			$raw = $args['summary-warnings'];
			$raw = (Lua::truth($v229 = (Lua::truth($v230 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v230)) ? $v229 : '');
			if (($raw !== '')) {
				$lookup = $this->buildSummaryLabelLookup($lang, $this->SUMMARY_WARNING_BANNER_KEYS);
				$aliases = $this->SUMMARY_WARNING_ALIASES;
				if ((!(Lua::truth($aliases)) || ($aliases['_lang'] !== $lang))) {
					$aliases = $this->buildSummaryAliases($lang);
					$aliases['_lang'] = $lang;
					$this->SUMMARY_WARNING_ALIASES = $aliases;
				}
				foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->SUMMARY_WARNING_BANNER_KEYS, $aliases);
					if (($key !== '')) {
						Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, null, $cats));
					}
				}
			}
		}
		if ((Lua::truth($args['summary']) && ($args['summary'] !== ''))) {
			Lua::insert($out, ('<div class=\'argument-summary\'>
' . $args['summary'] . '</div>'));
		}
		else {
			Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_summary'] . '</div>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_summary'] . ']]'));
		}
		return Lua::concat($out);
	}

	protected function renderQuotes($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		$edit = $this->addDataEditLink($PF['form_edit_quotes'], $pv['rawTitle'], '&nbsp;', $PF['edit_quotes_tooltip']);
		$out = new Table([]);
		$idAttr = '';
		Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-quote"' . $idAttr . '>' . $PF['quotes'] . $edit . '</h2>'));
		$q = $args['quotes'];
		if ((Lua::truth($q) && ($q !== ''))) {
			Lua::insert($out, $q);
		}
		else {
			Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_quotes'] . '</div>'));
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_quotes'] . ']]'));
		}
		return Lua::concat($out);
	}

	protected function renderDebateDetailed($args = null, $pv = null, $cats = null, $lang = null, $PF = null) {
		if ((!(Lua::truth($args['dedicated-debate'])) || ($args['dedicated-debate'] === ''))) {
			return '';
		}
		Lua::insert($cats, ('[[Category:' . $PF['cat_has_detailed_debate'] . ']]'));
		$contentToTransfer = (Lua::str((Lua::truth($v231 = $args['justifications']) ? $v231 : '')) . Lua::str((Lua::truth($v232 = $args['objections']) ? $v232 : '')) . Lua::str((Lua::truth($v233 = $args['bibliography']) ? $v233 : '')) . Lua::str((Lua::truth($v234 = $args['webliography']) ? $v234 : '')) . Lua::str((Lua::truth($v235 = $args['videography']) ? $v235 : '')));
		if ((Lua::trim($contentToTransfer) !== '')) {
			Lua::insert($cats, ('[[Category:' . $PF['cat_content_to_transfer'] . ']]'));
		}
		$edit = $this->addDataEditLink($PF['form_edit_detailed_debate'], $pv['rawTitle'], '&nbsp;', Lua::format($PF['edit_detailed_debate_tooltip'], $pv['rawTitle']));
		$h2 = ('<h2 class="section-modifiable wk-icon-before wk-icon-details">' . $PF['detailed_debate'] . $edit . '</h2>');
		$debat = $this->canonicalTitle($args['dedicated-debate']);
		$mapHtml = '';
		if ((((Lua::truth($this->hasSMW()) && (Lua::type(($this->context->smwAvailable() ? function($query) { return $this->context->smwAsk($query); } : null)) === 'function')) && Lua::truth($PF['prop_argument_map'])) && ($PF['prop_argument_map'] !== ''))) {
			$debatSafe = $this->smwSafeTitle($debat);
			if (($debatSafe !== '')) {
				$res = $this->smwAskSafe(new Table([1 => ('[[' . $debatSafe . ']]'), 2 => ('?' . $PF['prop_argument_map'] . '=map'), 3 => 'limit=1', 4 => 'link=none']), 'cards');
				if ((((Lua::type($res) === 'table') && Lua::truth($res[1])) && Lua::truth($res[1]['map']))) {
					$v = $res[1]['map'];
					if ((Lua::type($v) === 'string')) {
						$mapHtml = $v;
					}
					else {
						if ((Lua::type($v) === 'table')) {
							foreach (Lua::ipairs($v) as $_ => $x) {
								if (((Lua::type($x) === 'string') && (Lua::trim($x) !== ''))) {
									$mapHtml = $x;
									break;
								}
								else {
									if ((Lua::type($x) === 'table')) {
										$ft = (Lua::truth($v236 = (Lua::truth($v237 = $x['fulltext']) ? $v237 : $x['page'])) ? $v236 : $x[1]);
										if (((Lua::type($ft) === 'string') && (Lua::trim($ft) !== ''))) {
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
		$box = ('<div class="bandeau-debat-detaille">' . '<div style="font-weight: bold;">' . Lua::format($PF['arg_is_debate'], $this->context->nowiki($pv['rawTitle'])) . '</div>' . '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert navigation-not-searchable onglet-externe">' . $PF['detailed_debate_label'] . '<span class="onglet-externe">[[ ' . $debat . ' ]]</span>' . '</div></div>');
		$mapBox = '';
		if (($mapHtml !== '')) {
			$mapBox = ('<div class="encart-debat-detaille onglet-externe">' . '<div class="titre-debat-detaille">' . $debat . '</div>' . $mapHtml . '</div>');
		}
		return ($h2 . $box . $mapBox);
	}

	protected function safeRubriqueValue($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v238 = $s) ? $v238 : '')));
		if (($s === '')) {
			return '';
		}
		if (Lua::truth(Lua::find($s, '[%[%]{}|#<>]'))) {
			return '';
		}
		$s = Lua::gsub($s, '%s+', ' ');
		$s = Lua::trim($s);
		return $s;
	}

	protected function renderRubriques($args = null, $cats = null, $lang = null, $PF = null) {
		if ((Lua::truth($args['sections']) && ($args['sections'] !== ''))) {
			$out = new Table([]);
			$nOk = 0;
			foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $r) {
				$r = $this->safeRubriqueValue($r);
				if (($r !== '')) {
					$nOk = ($nOk + 1);
					Lua::insert($out, ('[[Category:' . $r . ']]'));
				}
			}
			if (($nOk > 0)) {
				return Lua::concat($out);
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
		$quote = Lua::str((Lua::truth($v241 = $item['quote']) ? $v241 : ''));
		$authors = Lua::trim((Lua::truth($v242 = $item['authors']) ? $v242 : ''));
		$article = Lua::trim((Lua::truth($v243 = $item['article']) ? $v243 : ''));
		$work = Lua::trim((Lua::truth($v244 = $item['work']) ? $v244 : ''));
		$volume = Lua::trim((Lua::truth($v245 = $item['volume']) ? $v245 : ''));
		$number = Lua::trim((Lua::truth($v246 = $item['number']) ? $v246 : ''));
		$page = Lua::trim((Lua::truth($v247 = $item['page']) ? $v247 : ''));
		$location = Lua::trim((Lua::truth($v248 = $item['location']) ? $v248 : ''));
		$publisher = Lua::trim((Lua::truth($v249 = $item['publisher']) ? $v249 : ''));
		$place = Lua::trim((Lua::truth($v250 = $item['place']) ? $v250 : ''));
		$date = Lua::trim((Lua::truth($v251 = $item['date']) ? $v251 : ''));
		$link = Lua::trim((Lua::truth($v252 = $item['link']) ? $v252 : ''));
		$warnings = Lua::trim((Lua::truth($v253 = $item['warnings']) ? $v253 : ''));
		$this->vset('WD_QUOTES_MAX', Lua::str($idx));
		if (($authors !== '')) {
			$cur = Lua::trim(Lua::str((Lua::truth($v254 = $this->vget('WD_QUOTES_AUTHORS')) ? $v254 : '')));
			$this->vset('WD_QUOTES_AUTHORS', (Lua::truth($v255 = (Lua::truth($v256 = ($cur === '')) ? $authors : $v256)) ? $v255 : ($cur . ',' . $authors)));
		}
		if (($article !== '')) {
			$cur = Lua::trim(Lua::str((Lua::truth($v257 = $this->vget('WD_QUOTES_ARTICLES')) ? $v257 : '')));
			$this->vset('WD_QUOTES_ARTICLES', (Lua::truth($v258 = (Lua::truth($v259 = ($cur === '')) ? $article : $v259)) ? $v258 : ($cur . ',' . $article)));
		}
		if (($work !== '')) {
			$cur = Lua::trim(Lua::str((Lua::truth($v260 = $this->vget('WD_QUOTES_WORKS')) ? $v260 : '')));
			$this->vset('WD_QUOTES_WORKS', (Lua::truth($v261 = (Lua::truth($v262 = ($cur === '')) ? $work : $v262)) ? $v261 : ($cur . ',' . $work)));
		}
		if (($publisher !== '')) {
			$cur = Lua::trim(Lua::str((Lua::truth($v263 = $this->vget('WD_QUOTES_PUBLISHERS')) ? $v263 : '')));
			$this->vset('WD_QUOTES_PUBLISHERS', (Lua::truth($v264 = (Lua::truth($v265 = ($cur === '')) ? $publisher : $v265)) ? $v264 : ($cur . ',' . $publisher)));
		}
		if (($place !== '')) {
			$cur = Lua::trim(Lua::str((Lua::truth($v266 = $this->vget('WD_QUOTES_PLACES')) ? $v266 : '')));
			$this->vset('WD_QUOTES_PLACES', (Lua::truth($v267 = (Lua::truth($v268 = ($cur === '')) ? $place : $v268)) ? $v267 : ($cur . ',' . $place)));
		}
		$warnHtml = '';
		if (($warnings !== '')) {
			$lookup = $this->buildSummaryLabelLookup($lang, $this->QUOTE_WARNING_BANNER_KEYS);
			$b = new Table([]);
			foreach (Lua::split($warnings, ',', true) as $x) {
				$x = Lua::trim($x);
				if (($x !== '')) {
					$key = $this->resolveKeyFromLookup($x, $lookup, $this->QUOTE_WARNING_BANNER_KEYS);
					if (($key !== '')) {
						$h = $this->renderSubsectionBanner($pv, $lang, $key, null, $cats);
						if (($h !== '')) {
							$b[(Lua::len($b) + 1)] = $h;
						}
					}
				}
			}
			if ((Lua::len($b) > 0)) {
				$warnHtml = ('<div>' . Lua::concat($b, '') . '</div>');
			}
		}
		$bq = ('<blockquote data-wk-cite="' . Lua::str($idx) . '">' . $warnHtml . '« ' . $quote . ' »' . '</blockquote>');
		$ref = new Table([]);
		Lua::insert($ref, ('<div class="reference-citation" data-wk-cite="' . Lua::str($idx) . '">'));
		if (($authors !== '')) {
			Lua::insert($ref, $authors);
		}
		else {
			Lua::insert($ref, $PF['unknown_author']);
			Lua::insert($cats, ('[[Category:' . $PF['cat_no_authors'] . ']]'));
		}
		if (($article !== '')) {
			if (($link !== '')) {
				Lua::insert($ref, (', « [' . $link . ' ' . $article . '] »'));
			}
			else {
				Lua::insert($ref, (', « ' . $article . ' »'));
			}
		}
		if (($work !== '')) {
			if (($article !== '')) {
				Lua::insert($ref, (', \'\'' . $work . '\'\''));
			}
			else {
				if (($link !== '')) {
					Lua::insert($ref, (', \'\'[' . $link . ' ' . $work . ']\'\''));
				}
				else {
					Lua::insert($ref, (', \'\'' . $work . '\'\''));
				}
			}
		}
		if (($volume !== '')) {
			Lua::insert($ref, (', ' . $PF['volume_label'] . '&#160;' . $volume));
		}
		if (($number !== '')) {
			Lua::insert($ref, (', ' . $PF['issue_label'] . '&#160;' . $number));
		}
		if (($page !== '')) {
			Lua::insert($ref, (', p.' . $page));
		}
		if (($location !== '')) {
			Lua::insert($ref, (', ' . $location));
		}
		if (($publisher !== '')) {
			Lua::insert($ref, (', ' . $publisher));
		}
		if (($place !== '')) {
			Lua::insert($ref, (', ' . $place));
		}
		if (($date !== '')) {
			Lua::insert($ref, (', ' . $date));
		}
		Lua::insert($ref, '</div>');
		$html = ($bq . '
' . Lua::concat($ref) . '
');
		if ((($idx >= 1) && ($idx <= 4))) {
			$this->vset(('WD_QUOTE_' . Lua::str($idx)), $html);
		}
		return $html;
	}

	protected function wkRenderQuotesFromSerialized($raw = null, $pv = null, $lang = null, $cats = null, $PF = null) {
		$items = $this->wkParseQuoteMarkers($raw);
		if ((Lua::len($items) === 0)) {
			return '';
		}
		$out = [];
		foreach ($items->values as $i => $it) {
			$out[] = $this->wkRenderOneQuoteHtml($it, $i, $pv, $lang, $cats, $PF);
		}
		return implode('', $out);
	}

	protected function setSemanticData($args = null, $pv = null, $lang = null, $cache = null, $PF = null, $maps = null, $rawB = null, $rawW = null, $rawV = null) {
		$props = new Table([]);
		$props[$PF['prop_arg_name']] = $pv['rawTitle'];
		$props[$PF['prop_arg_number']] = $pv['pageId'];
		if (Lua::truth($this->hasSMW())) {
			$this->ensureDebateParentsFinal($pv, $cache, $PF);
			$this->ensureArgumentParents($pv, $cache, $PF);
			{
				$chains = $this->uniqList((Lua::truth($v269 = $cache['breadcrumbChainsFinal']) ? $v269 : new Table([])));
				if ((((Lua::len($chains) > 0) && Lua::truth($PF['prop_breadcrumb'])) && ($PF['prop_breadcrumb'] !== ''))) {
					$props[$PF['prop_breadcrumb']] = $chains;
				}
				if ((Lua::truth($PF['prop_parent_debate']) && ($PF['prop_parent_debate'] !== ''))) {
					$dp = $this->uniqList((Lua::truth($v270 = $cache['parentDebatesFinal']) ? $v270 : new Table([])));
					if ((Lua::len($dp) > 0)) {
						$props[$PF['prop_parent_debate']] = $dp;
					}
				}
			}
			{
				$parents = new Table([]);
				foreach (Lua::ipairs((Lua::truth($v271 = $cache['justifs']) ? $v271 : new Table([]))) as $_ => $x) {
					Lua::insert($parents, $this->canonicalTitle($x));
				}
				foreach (Lua::ipairs((Lua::truth($v272 = $cache['objs']) ? $v272 : new Table([]))) as $_ => $x) {
					Lua::insert($parents, $this->canonicalTitle($x));
				}
				$parents = $this->uniqList($parents);
				if ((((Lua::len($parents) > 0) && Lua::truth($PF['prop_parent_argument'])) && ($PF['prop_parent_argument'] !== ''))) {
					$props[$PF['prop_parent_argument']] = $parents;
				}
			}
		}
		$maps = (Lua::truth($v273 = $maps) ? $v273 : new Table([]));
		$justif = (Lua::truth($v274 = $maps['justif']) ? $v274 : new Table([]));
		$obj = (Lua::truth($v275 = $maps['obj']) ? $v275 : new Table([]));
		{
			$lst = new Table([]);
			foreach (Lua::ipairs((Lua::truth($v276 = $justif['items']) ? $v276 : new Table([]))) as $_ => $it) {
				$t = $this->canonicalTitle((Lua::truth($v277 = $it['page']) ? $v277 : ''));
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
			foreach (Lua::ipairs((Lua::truth($v278 = $obj['items']) ? $v278 : new Table([]))) as $_ => $it) {
				$t = $this->canonicalTitle((Lua::truth($v279 = $it['page']) ? $v279 : ''));
				if (($t !== '')) {
					Lua::insert($lst, $t);
				}
			}
			$lst = $this->uniqList($lst);
			if ((((Lua::len($lst) > 0) && Lua::truth($PF['prop_obj'])) && ($PF['prop_obj'] !== ''))) {
				$props[$PF['prop_obj']] = $lst;
			}
		}
		$justifSurvol = Lua::trim((Lua::truth($v280 = $justif['mapHover']) ? $v280 : ''));
		$objSurvol = Lua::trim((Lua::truth($v281 = $obj['mapHover']) ? $v281 : ''));
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
			$lst = Lua::trim((Lua::truth($v282 = $justif['argsVar']) ? $v282 : ''));
			if (((($lst !== '') && Lua::truth($PF['prop_justif_list'])) && ($PF['prop_justif_list'] !== ''))) {
				$props[$PF['prop_justif_list']] = $lst;
			}
		}
		{
			$lst = Lua::trim((Lua::truth($v283 = $obj['argsVar']) ? $v283 : ''));
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
			$uniqCSVVar = function($varName = null) use (&$args, &$pv, &$lang, &$cache, &$PF, &$maps, &$rawB, &$rawW, &$rawV, &$props, &$chains, &$dp, &$parents, &$_, &$x, &$justif, &$obj, &$lst, &$it, &$t, &$justifSurvol, &$objSurvol, &$mc, &$list, &$uniqCSVVar, &$raw, &$authors, &$k, &$articles, &$works, &$pubs, &$places, &$r) {
		$raw = Lua::str((Lua::truth($v284 = $this->vget($varName)) ? $v284 : ''));
		$raw = Lua::trim($raw);
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
				foreach (Lua::ipairs($this->splitCSV($args['sections'], ',')) as $_ => $r) {
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
		return (Lua::truth($v287 = $this->context->message('Argument.seo', $lang, $key, ...$rest)) ? $v287 : '');
	}

	protected function seoJoinList($items = null) {
		$out = new Table([]);
		$seen = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v288 = $items) ? $v288 : new Table([]))) as $_ => $v) {
			$v = Lua::trim(Lua::str((Lua::truth($v289 = $v) ? $v289 : '')));
			if ((($v !== '') && !(Lua::truth($seen[$v])))) {
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

	protected function renderFullSEO($args = null, $pv = null, $lang = null) {
		$titleText = (Lua::truth($v290 = $pv['rawTitle']) ? $v290 : '');
		$ck = ('SEO
' . Lua::str((Lua::truth($v291 = $lang) ? $v291 : '')) . '
' . Lua::str((Lua::truth($v292 = $pv['pageId']) ? $v292 : '')) . '
' . $titleText . '
' . $this->seoKeyPart((Lua::truth($v293 = $args['keywords']) ? $v293 : '')) . '
' . $this->seoKeyPart((Lua::truth($v294 = $args['sections']) ? $v294 : '')));
		$hit = $this->cacheGet($this->SEO_CACHE, $ck);
		if (($hit !== null)) {
			return '';
		}
		$this->cachePut($this->SEO_CACHE, $ck, true);
		$topic = Lua::trim(Lua::str((Lua::truth($v295 = $args['keywords']) ? $v295 : '')));
		if (($topic === '')) {
			$topic = $titleText;
		}
		$kw = $this->buildSeoKeywords($args, $lang);
		$this->wkSeoSet(new Table(['title' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain') . ' – ' . $this->L($lang, 'seo', 'site_name')), 'title_mode' => ($titleText . $this->L($lang, 'seo', 'space') . ': ' . $this->L($lang, 'seo', 'shortdesc_plain')), 'title_separator' => Lua::format($this->seoL($lang, 'title_separator'), $titleText), 'description' => Lua::format($this->seoL($lang, 'description'), $topic), 'keywords' => $kw, 'author' => $this->seoL($lang, 'author'), 'image' => $this->seoL($lang, 'image'), 'image_alt' => $this->seoL($lang, 'image_alt'), 'type' => 'article', 'section' => $this->seoL($lang, 'section'), 'site_name' => $this->seoL($lang, 'site_name'), 'twitter_site' => $this->seoL($lang, 'twitter_site'), 'locale' => $this->seoL($lang, 'locale'), 'robots' => 'index,follow', 'googlebot' => 'index,follow']));
		return '';
	}

	protected function wkBuildWikiUrl($lang = null, $prefixKey = null, $labelKey = null) {
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $this->L($lang, 'urls', $labelKey)), 'WIKI'));
	}

	protected function wkBuildWikiUrlFromText($lang = null, $prefixKey = null, $text = null) {
		$text = Lua::trim(Lua::str((Lua::truth($v296 = $text) ? $v296 : '')));
		if (($text === '')) {
			return '';
		}
		return ($this->L($lang, 'urls', 'wiki_base') . $this->context->uriEncode(($this->L($lang, 'urls', $prefixKey) . $text), 'WIKI'));
	}

	protected function wkFullUrl($titleText = null) {
		$titleText = Lua::trim(Lua::str((Lua::truth($v297 = $titleText) ? $v297 : '')));
		if (($titleText === '')) {
			return '';
		}
		[$ok, $u] = Lua::pcall([$this->context, 'fullUrl'], $titleText);
		if ((Lua::truth($ok) && Lua::truth($u))) {
			return Lua::str($u);
		}
		return '';
	}

	protected function wkTakeFirst($items = null, $limit = null) {
		$out = new Table([]);
		$limit = (Lua::truth($v298 = Lua::number($limit)) ? $v298 : 0);
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
		$argsLabel = $this->L($lang, 'urls', 'arguments_label');
		$argsUrl = $this->wkBuildWikiUrl($lang, 'portal_prefix', 'arguments_label');
		return new Table(['@type' => 'BreadcrumbList', '@id' => ($pv['pageUrl'] . '#breadcrumb'), 'itemListElement' => new Table([1 => new Table(['@type' => 'ListItem', 'position' => 1, 'item' => new Table(['@id' => $homeUrl, 'name' => $homeLabel])]), 2 => new Table(['@type' => 'ListItem', 'position' => 2, 'item' => new Table(['@id' => $argsUrl, 'name' => $argsLabel])]), 3 => new Table(['@type' => 'ListItem', 'position' => 3, 'item' => new Table(['@id' => $pv['pageUrl'], 'name' => $pv['rawTitle']])])])]);
	}

	protected function wkBuildAboutAndMentions($args = null) {
		$about = new Table([]);
		$mentions = new Table([]);
		$rawKeywords = $this->splitCSV((Lua::truth($v299 = (Lua::truth($v300 = $args) ? $args['keywords'] : $v300)) ? $v299 : ''), ',');
		$stop3 = Lua::len($rawKeywords);
		for ($i = 1; $i <= $stop3; $i++) {
			$kw = Lua::trim(Lua::str((Lua::truth($v301 = $rawKeywords[$i]) ? $v301 : '')));
			if (($kw !== '')) {
				$node = new Table(['@type' => 'Thing', 'name' => $kw]);
				if (($i <= 3)) {
					$about[(Lua::len($about) + 1)] = $node;
				}
				else {
					$mentions[(Lua::len($mentions) + 1)] = $node;
				}
			}
		}
		return [$about, $mentions];
	}

	protected function wkBuildKeywords($args = null, $lang = null) {
		$out = new Table([]);
		$sections = $this->splitCSV((Lua::truth($v302 = (Lua::truth($v303 = $args) ? $args['sections'] : $v303)) ? $v302 : ''), ',');
		$termSetId = $this->wkBuildWikiUrl($lang, 'category_prefix', 'sections_label');
		$stop3 = Lua::len($sections);
		for ($i = 1; $i <= $stop3; $i++) {
			if (($i > 4)) {
				break;
			}
			$section = Lua::trim(Lua::str((Lua::truth($v304 = $sections[$i]) ? $v304 : '')));
			if (($section !== '')) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'DefinedTerm', '@id' => $this->wkBuildWikiUrlFromText($lang, 'category_prefix', $section), 'name' => $section, 'inDefinedTermSet' => new Table(['@id' => $termSetId])]);
			}
		}
		return $out;
	}

	protected function wkBuildDiscussionUrl($pv = null, $lang = null) {
		return $this->wkBuildWikiUrlFromText($lang, 'discussion_prefix', $pv['rawTitle']);
	}

	protected function wkAppendAllValidIds($out = null, $titles = null, $suffix = null, $lang = null) {
		$added = false;
		foreach (Lua::ipairs((Lua::truth($v305 = $titles) ? $v305 : new Table([]))) as $_ => $title) {
			$title = $this->canonicalTitle(Lua::trim(Lua::str((Lua::truth($v306 = $title) ? $v306 : ''))));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . $suffix)]);
					$added = true;
				}
			}
		}
		return $added;
	}

	protected function wkAppendFirstValidId($out = null, $titles = null, $suffix = null, $lang = null) {
		foreach (Lua::ipairs((Lua::truth($v307 = $titles) ? $v307 : new Table([]))) as $_ => $title) {
			$title = $this->canonicalTitle(Lua::trim(Lua::str((Lua::truth($v308 = $title) ? $v308 : ''))));
			if (($title !== '')) {
				$url = $this->wkFullUrl($title, $lang);
				if (($url !== '')) {
					$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . $suffix)]);
					return true;
				}
			}
		}
		return false;
	}

	protected function wkBuildClaimIsPartOf($lang = null, $cache = null) {
		$out = new Table([]);
		$out[(Lua::len($out) + 1)] = new Table(['@id' => $this->wkBuildWikiUrl($lang, 'category_prefix', 'arguments_label')]);
		$debats = new Table([]);
		foreach (Lua::ipairs((Lua::truth($v309 = (Lua::truth($v310 = $cache) ? $cache['debatsPour'] : $v310)) ? $v309 : new Table([]))) as $_ => $d) {
			Lua::insert($debats, $this->canonicalTitle($d));
		}
		foreach (Lua::ipairs((Lua::truth($v311 = (Lua::truth($v312 = $cache) ? $cache['debatsContre'] : $v312)) ? $v311 : new Table([]))) as $_ => $d) {
			Lua::insert($debats, $this->canonicalTitle($d));
		}
		$debats = $this->uniqList($debats);
		if (Lua::truth($this->wkAppendAllValidIds($out, $debats, '#qa', $lang))) {
			return $out;
		}
		if (Lua::truth($this->wkAppendFirstValidId($out, (Lua::truth($v313 = (Lua::truth($v314 = $cache) ? $cache['justifs'] : $v314)) ? $v313 : new Table([])), '#claim', $lang))) {
			return $out;
		}
		if (Lua::truth($this->wkAppendFirstValidId($out, (Lua::truth($v315 = (Lua::truth($v316 = $cache) ? $cache['objs'] : $v316)) ? $v315 : new Table([])), '#claim', $lang))) {
			return $out;
		}
		return $out;
	}

	protected function wkBuildHasPart($args = null, $maps = null, $lang = null) {
		$out = new Table([]);
		$detailedDebate = Lua::trim(Lua::str((Lua::truth($v317 = (Lua::truth($v318 = $args) ? $args['dedicated-debate'] : $v318)) ? $v317 : '')));
		$detailedDebate = $this->canonicalTitle($detailedDebate);
		if (($detailedDebate !== '')) {
			$url = $this->wkFullUrl($detailedDebate, $lang);
			if (($url !== '')) {
				$out[(Lua::len($out) + 1)] = new Table(['@id' => ($url . '#qa')]);
				return $out;
			}
		}
		$justifItems = (Lua::truth($v319 = (Lua::truth($v320 = (Lua::truth($v321 = $maps) ? $maps['justif'] : $v321)) ? $maps['justif']['items'] : $v320)) ? $v319 : new Table([]));
		$objItems = (Lua::truth($v322 = (Lua::truth($v323 = (Lua::truth($v324 = $maps) ? $maps['obj'] : $v324)) ? $maps['obj']['items'] : $v323)) ? $v322 : new Table([]));
		$stop8 = min(Lua::len($justifItems), 3);
		for ($i = 1; $i <= $stop8; $i++) {
			$item = $justifItems[$i];
			$argTitle = Lua::trim(Lua::str((Lua::truth($v325 = (Lua::truth($v326 = $item) ? $item['page'] : $v326)) ? $v325 : '')));
			$argTitle = $this->canonicalTitle($argTitle);
			$url = $this->wkFullUrl($argTitle, $lang);
			if (($url !== '')) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Claim', '@id' => ($url . '#claim'), 'additionalType' => $this->wkBuildWikiUrl($lang, 'property_prefix', 'justification_label')]);
			}
		}
		$stop12 = min(Lua::len($objItems), 1);
		for ($i = 1; $i <= $stop12; $i++) {
			$item = $objItems[$i];
			$argTitle = Lua::trim(Lua::str((Lua::truth($v327 = (Lua::truth($v328 = $item) ? $item['page'] : $v328)) ? $v327 : '')));
			$argTitle = $this->canonicalTitle($argTitle);
			$url = $this->wkFullUrl($argTitle, $lang);
			if (($url !== '')) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Claim', '@id' => ($url . '#claim'), 'additionalType' => $this->wkBuildWikiUrl($lang, 'property_prefix', 'objection_label')]);
			}
		}
		return $out;
	}

	protected function wkBuildQuoteAuthorValue($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v329 = $raw) ? $v329 : '')));
		if (($raw === '')) {
			return null;
		}
		$authors = $this->splitCSV($raw, ',');
		if ((Lua::len($authors) === 0)) {
			return null;
		}
		if ((Lua::len($authors) === 1)) {
			return new Table(['@type' => 'Person', 'name' => $authors[1]]);
		}
		$out = new Table([]);
		foreach (Lua::ipairs($authors) as $_ => $one) {
			$one = Lua::trim(Lua::str((Lua::truth($v330 = $one) ? $v330 : '')));
			if (($one !== '')) {
				$out[(Lua::len($out) + 1)] = new Table(['@type' => 'Person', 'name' => $one]);
			}
		}
		if ((Lua::len($out) === 0)) {
			return null;
		}
		return $out;
	}

	protected function wkBuildSingleNodeFromFirstItem($items = null, $buildNode = null) {
		if (((Lua::type($items) !== 'table') || !(Lua::truth($items[1])))) {
			return new Table([]);
		}
		$node = $buildNode($items[1]);
		if (Lua::truth($node)) {
			return new Table([1 => $node]);
		}
		return new Table([]);
	}

	protected function wkBuildClaimCitationsFromQuotes($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v331 = $raw) ? $v331 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->wkParseQuoteMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$authors, &$article, &$work, &$volume, &$number, &$publisher, &$date, &$link, &$node, &$authorValue) {
		$authors = Lua::trim(Lua::str((Lua::truth($v332 = $item['authors']) ? $v332 : '')));
		$article = Lua::trim(Lua::str((Lua::truth($v333 = $item['article']) ? $v333 : '')));
		$work = Lua::trim(Lua::str((Lua::truth($v334 = $item['work']) ? $v334 : '')));
		$volume = Lua::trim(Lua::str((Lua::truth($v335 = $item['volume']) ? $v335 : '')));
		$number = Lua::trim(Lua::str((Lua::truth($v336 = $item['number']) ? $v336 : '')));
		$publisher = Lua::trim(Lua::str((Lua::truth($v337 = $item['publisher']) ? $v337 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v338 = $item['date']) ? $v338 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v339 = $item['link']) ? $v339 : '')));
		$node = null;
		if (((($number !== '') && ($article !== '')) && ($work !== ''))) {
			$node = new Table(['@type' => 'Article', 'name' => $article, 'isPartOf' => new Table(['@type' => 'Periodical', 'name' => $work])]);
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = new Table(['@type' => 'Book', 'name' => $work]);
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
					$node = new Table(['@type' => 'WebPage', 'name' => $article]);
					if (($work !== '')) {
						$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $work]);
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
			$node['publisher'] = new Table(['@type' => 'Organization', 'name' => $publisher]);
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
		$raw = Lua::trim(Lua::str((Lua::truth($v340 = $raw) ? $v340 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->wkParseBiblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$authors, &$article, &$work, &$volume, &$number, &$publisher, &$date, &$link, &$node, &$authorValue) {
		$authors = Lua::trim(Lua::str((Lua::truth($v341 = $item['authors']) ? $v341 : '')));
		$article = Lua::trim(Lua::str((Lua::truth($v342 = $item['article']) ? $v342 : '')));
		$work = Lua::trim(Lua::str((Lua::truth($v343 = $item['work']) ? $v343 : '')));
		$volume = Lua::trim(Lua::str((Lua::truth($v344 = $item['volume']) ? $v344 : '')));
		$number = Lua::trim(Lua::str((Lua::truth($v345 = $item['number']) ? $v345 : '')));
		$publisher = Lua::trim(Lua::str((Lua::truth($v346 = $item['publisher']) ? $v346 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v347 = $item['date']) ? $v347 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v348 = $item['link']) ? $v348 : '')));
		$node = null;
		if (((($number !== '') && ($article !== '')) && ($work !== ''))) {
			$node = new Table(['@type' => 'Article', 'name' => $article, 'isPartOf' => new Table(['@type' => 'Periodical', 'name' => $work])]);
			if (($volume !== '')) {
				$node['volumeNumber'] = $volume;
			}
			$node['issueNumber'] = $number;
		}
		else {
			if ((($publisher !== '') && ($work !== ''))) {
				$node = new Table(['@type' => 'Book', 'name' => $work]);
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
					$node = new Table(['@type' => 'WebPage', 'name' => $article]);
					if (($work !== '')) {
						$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $work]);
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
			$node['publisher'] = new Table(['@type' => 'Organization', 'name' => $publisher]);
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
		$raw = Lua::trim(Lua::str((Lua::truth($v349 = $raw) ? $v349 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->wkParseWeblioMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$page, &$site, &$link, &$date, &$node) {
		$page = Lua::trim(Lua::str((Lua::truth($v350 = $item['page']) ? $v350 : '')));
		$site = Lua::trim(Lua::str((Lua::truth($v351 = $item['site']) ? $v351 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v352 = $item['link']) ? $v352 : '')));
		$date = Lua::trim(Lua::str((Lua::truth($v353 = $item['date']) ? $v353 : '')));
		if ((($link === '') || ($page === ''))) {
			return null;
		}
		$node = new Table(['@type' => 'WebPage', 'name' => $page, 'url' => $link]);
		if (($site !== '')) {
			$node['isPartOf'] = new Table(['@type' => 'WebSite', 'name' => $site]);
		}
		if (($date !== '')) {
			$node['datePublished'] = $date;
		}
		return $node;
		});
	}

	protected function wkBuildClaimCitationsFromVideography($raw = null) {
		$raw = Lua::trim(Lua::str((Lua::truth($v354 = $raw) ? $v354 : '')));
		if (($raw === '')) {
			return new Table([]);
		}
		$items = $this->wkParseVideoMarkers($raw);
		return $this->wkBuildSingleNodeFromFirstItem($items, function($item = null) use (&$raw, &$items, &$title, &$link, &$authors, &$node, &$authorValue) {
		$title = Lua::trim(Lua::str((Lua::truth($v355 = $item['title']) ? $v355 : '')));
		$link = Lua::trim(Lua::str((Lua::truth($v356 = $item['link']) ? $v356 : '')));
		$authors = Lua::trim(Lua::str((Lua::truth($v357 = $item['authors']) ? $v357 : '')));
		if ((($title === '') || ($link === ''))) {
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


	protected function renderArgumentSchemaJSONLD($args = null, $pv = null, $lang = null, $cache = null, $PF = null, $maps = null) {
		$this->ensureDebateParentsFinal($pv, $cache, $PF);
		$this->ensureArgumentParents($pv, $cache, $PF);
		$this->ensureBreadcrumbSelection($pv, $cache, new Table([]), $lang, $PF);
		$websiteNode = $this->wkBuildWebsiteNode($lang);
		$breadcrumbNode = $this->wkBuildBreadcrumbNode($pv, $lang);
		$organizationNode = $this->wkBuildOrganizationNode($lang);
		[$about, $mentions] = $this->wkBuildAboutAndMentions($args);
		$keywords = $this->wkBuildKeywords($args, $lang);
		$isPartOf = $this->wkBuildClaimIsPartOf($lang, $cache);
		$hasPart = $this->wkBuildHasPart($args, $maps, $lang);
		$creationDate = $this->getPageCreation($pv, $args);
		$citations = new Table([]);
		$q = $this->wkBuildClaimCitationsFromQuotes((Lua::truth($v358 = $args['_quotes_wikitext']) ? $v358 : ''));
		if (Lua::truth($q[1])) {
			$citations[(Lua::len($citations) + 1)] = $q[1];
		}
		$b = $this->wkBuildClaimCitationsFromBibliography((Lua::truth($v359 = $args['bibliography']) ? $v359 : ''));
		if (Lua::truth($b[1])) {
			$citations[(Lua::len($citations) + 1)] = $b[1];
		}
		$w = $this->wkBuildClaimCitationsFromWebliography((Lua::truth($v360 = $args['webliography']) ? $v360 : ''));
		if (Lua::truth($w[1])) {
			$citations[(Lua::len($citations) + 1)] = $w[1];
		}
		$subjectOf = $this->wkBuildClaimCitationsFromVideography((Lua::truth($v361 = $args['videography']) ? $v361 : ''));
		$pageNode = new Table(['@type' => 'WebPage', '@id' => $pv['pageUrl'], 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'isPartOf' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#website')]), 'breadcrumb' => new Table(['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'mainEntity' => new Table(['@id' => ($pv['pageUrl'] . '#claim')])]);
		$claimNode = new Table(['@type' => 'Claim', '@id' => ($pv['pageUrl'] . '#claim'), 'url' => $pv['pageUrl'], 'name' => $pv['rawTitle'], 'inLanguage' => $lang, 'learningResourceType' => 'argument', 'educationalUse' => 'analysis', 'educationalLevel' => 'higher education', 'audience' => new Table(['@type' => 'EducationalAudience', 'educationalRole' => 'student']), 'isPartOf' => $isPartOf, 'breadcrumb' => new Table(['@id' => ($pv['pageUrl'] . '#breadcrumb')]), 'discussionUrl' => $this->wkBuildDiscussionUrl($pv, $lang), 'publisher' => new Table(['@id' => ($this->L($lang, 'urls', 'home') . '#organization')])]);
		if ((Lua::truth($args['established-name']) && (Lua::trim($args['established-name']) !== ''))) {
			$claimNode['alternateName'] = $args['established-name'];
		}
		{
			$summary = Lua::trim(Lua::str((Lua::truth($v362 = (Lua::truth($v363 = $args) ? $args['summary'] : $v363)) ? $v362 : '')));
			if (($summary !== '')) {
				$claimNode['abstract'] = $summary;
			}
		}
		if (Lua::truth($creationDate)) {
			$claimNode['datePublished'] = $creationDate;
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
		if ((Lua::len($hasPart) > 0)) {
			$claimNode['hasPart'] = $hasPart;
		}
		if ((Lua::len($citations) > 0)) {
			$claimNode['citation'] = $citations;
		}
		if ((Lua::len($subjectOf) > 0)) {
			$claimNode['subjectOf'] = $subjectOf;
		}
		$data = new Table(['@context' => 'https://schema.org', '@graph' => new Table([1 => $websiteNode, 2 => $organizationNode, 3 => $breadcrumbNode, 4 => $pageNode, 5 => $claimNode])]);
		$json = $this->safeJsonEncode($data);
		return $this->context->jsonLd($json);
	}

	protected function canonicalTitle($s = null) {
		$s = Lua::trim(Lua::str((Lua::truth($v364 = $s) ? $v364 : '')));
		if (($s === '')) {
			return '';
		}
		$s = Lua::gsub($s, '^:%s*', '');
		$s = Lua::gsub(Lua::gsub($s, '^%[%[%s*', ''), '%s*%]%]$', '');
		$s = (Lua::truth($v365 = Lua::match($s, '^([^%]|]+)%|')) ? $v365 : $s);
		$s = Lua::trim($s);
		$t = $this->titleNewCached($s);
		return (Lua::truth($v366 = (Lua::truth($v367 = $t) ? $t['prefixedText'] : $v367)) ? $v366 : $s);
	}

	protected function computeArgumentMapsFromData($args = null) {
		$rawJ = (Lua::truth($v368 = $args['justifications']) ? $v368 : '');
		$rawO = (Lua::truth($v369 = $args['objections']) ? $v369 : '');
		$justifItems = $this->wkExtractItemsFromSerializedData($rawJ);
		$objItems = $this->wkExtractItemsFromSerializedData($rawO);
		return new Table(['justif' => $this->wkBuildArgumentData($justifItems), 'obj' => $this->wkBuildArgumentData($objItems)]);
	}

	public function render($frame = null) {
		$this->decodedReferences = null;
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
			$value = Lua::trim($args[$key]);
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
		if ((Lua::type($args['justifications']) !== 'string')) {
			$args['justifications'] = null;
		}
		if ((Lua::type($args['objections']) !== 'string')) {
			$args['objections'] = null;
		}
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
		$PF['search_type_field'] = Lua::str((Lua::truth($v373 = $this->L($lang, 'params', 'search_type_field')) ? $v373 : ''));
		$PF['search_type_value'] = Lua::str((Lua::truth($v374 = $this->L($lang, 'params', 'search_type_value')) ? $v374 : ''));
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
			if (((Lua::type($q) === 'string') && ($q !== ''))) {
				[$ok, $r] = Lua::pcall(function($text) { return $this->context->expandQuotes($text); }, $q);
				if ((Lua::truth($ok) && (Lua::type($r) === 'string'))) {
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
			$out = new Table([]);
			if (Lua::truth($args['title-warnings'])) {
				Lua::insert($out, $this->renderBannersFromArgList($args['title-warnings'], $titleLookup, $pv, $lang, $cats));
			}
			if ((Lua::len($out) > 0)) {
				$pieces[] = Lua::concat($out);
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
			$out = new Table([]);
			$btnForm = $this->addDataLink($this->L($lang, 'forms', 'banners_edit_title'), $pv['rawTitle'], '', $this->L($lang, 'text', 'argument_banners_edit_tt'), new Table(['editButton' => true]));
			Lua::insert($out, ('<h2 id="Summary" class="section-modifiable wk-icon-before wk-icon-flag">' . $this->L($lang, 'text', 'warnings_title') . $btnForm . '</h2>'));
			Lua::insert($out, $this->renderBannersFromArgList($args['argument-warnings'], $argLookup, $pv, $lang, $cats));
			$pieces[] = Lua::concat($out);
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
			$out = new Table([]);
			$editRefs = $this->addDataEditLink($PF['form_edit_references'], $pv['rawTitle'], '&nbsp;', $PF['edit_references_tooltip']);
			Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-bibliography">' . $PF['references'] . $editRefs . '</h2>'));
			{
				$refsHtml = $this->wkRenderReferencesFromRaw($rawB, $rawW, $rawV, $pv, $lang, $cats, $PF, null, $args);
				if (($refsHtml !== '')) {
					Lua::insert($out, ('<ul class="references-argument">' . $refsHtml . '</ul>'));
				}
				else {
					Lua::insert($out, ('<div class="aucun-contenu navigation-not-searchable">' . $PF['no_references'] . '</div>'));
					Lua::insert($cats, ('[[Category:' . $PF['cat_no_references'] . ']]'));
				}
			}
			$justifItems = (Lua::truth($v375 = (Lua::truth($v376 = $maps['justif']) ? $maps['justif']['items'] : $v376)) ? $v375 : new Table([]));
			$objItems = (Lua::truth($v377 = (Lua::truth($v378 = $maps['obj']) ? $maps['obj']['items'] : $v378)) ? $v377 : new Table([]));
			$editJust = $this->addDataEditLink($PF['form_edit_justifications'], $pv['rawTitle'], '&nbsp;', $PF['edit_justifications_tooltip']);
			Lua::insert($out, ('<h2 class="section-modifiable wk-icon-before wk-icon-argument is-pro">' . $PF['justifications'] . $editJust . '</h2>'));
			{
				$raw = $args['justification-warnings'];
				$raw = (Lua::truth($v379 = (Lua::truth($v380 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v380)) ? $v379 : '');
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
					foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->JUSTIFICATION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_justifications'], 'tooltip' => $PF['edit_justifications_tooltip']]), $cats));
						}
					}
				}
			}
			if ((Lua::len($justifItems) > 0)) {
				$ulJ = (HtmlBuilder::create('ul'))->addClass('argument-list is-pro');
				$lis = new Table([]);
				foreach (Lua::ipairs($justifItems) as $i => $it) {
					Lua::insert($lis, $this->wkRenderArgumentLi($pv, $lang, $it, 'justif', $i, $cats));
				}
				($ulJ)->wikitext(Lua::concat($lis, '
'));
				Lua::insert($out, Lua::str($ulJ));
			}
			else {
				Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_justifications'] . '</div>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_justifications'] . ']]'));
			}
			{
				$baseField = Lua::str((Lua::truth($v381 = $PF['arg_new_title_field_base']) ? $v381 : ''));
				$sideLabel = Lua::str((Lua::truth($v382 = $PF['side_pro_word']) ? $v382 : 'pour'));
				$label = Lua::str((Lua::truth($v383 = $PF['justification_add_label']) ? $v383 : ''));
				$tooltip = Lua::format($PF['justification_add_tt'], $pv['rawTitle']);
				$rqForm = Lua::str((Lua::truth($v384 = $PF['form_new_arg_title']) ? $v384 : ''));
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = Lua::str((Lua::truth($v385 = $PF['type_justification']) ? $v385 : ''));
				$rqQuery[($baseField . '[ID]')] = Lua::str((Lua::truth($v386 = $pv['pageId']) ? $v386 : ''));
				$rqQuery['_run'] = '1';
				Lua::insert($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>'));
			}
			$editObj = $this->addDataEditLink($PF['form_edit_objections'], $pv['rawTitle'], '&nbsp;', $PF['edit_objections_tooltip']);
			Lua::insert($out, ($this->listBreaker() . '<h2 class="section-modifiable wk-icon-before wk-icon-argument is-con">' . $PF['objections'] . $editObj . '</h2>'));
			{
				$raw = $args['objection-warnings'];
				$raw = (Lua::truth($v387 = (Lua::truth($v388 = (Lua::type($raw) === 'string')) ? Lua::trim($raw) : $v388)) ? $v387 : '');
				if (($raw !== '')) {
					$lookup = $this->buildSummaryLabelLookup($lang, $this->OBJECTION_WARNING_BANNER_KEYS);
					foreach (Lua::ipairs($this->splitCSV($raw, ',')) as $_ => $x) {
						$key = $this->resolveKeyFromLookup($x, $lookup, $this->OBJECTION_WARNING_BANNER_KEYS);
						if (($key !== '')) {
							Lua::insert($out, $this->renderSubsectionBanner($pv, $lang, $key, new Table(['reorg' => true, 'form' => $PF['form_edit_objections'], 'tooltip' => $PF['edit_objections_tooltip']]), $cats));
						}
					}
				}
			}
			if ((Lua::len($objItems) > 0)) {
				$ulO = (HtmlBuilder::create('ul'))->addClass('argument-list is-con');
				$lis = new Table([]);
				foreach (Lua::ipairs($objItems) as $i => $it) {
					Lua::insert($lis, $this->wkRenderArgumentLi($pv, $lang, $it, 'obj', $i, $cats));
				}
				($ulO)->wikitext(Lua::concat($lis, '
'));
				Lua::insert($out, Lua::str($ulO));
			}
			else {
				Lua::insert($out, ('<div class="aucun-argument navigation-not-searchable">' . $PF['no_objections'] . '</div>'));
				Lua::insert($cats, ('[[Category:' . $PF['cat_no_objections'] . ']]'));
			}
			{
				$baseField = Lua::str((Lua::truth($v389 = $PF['arg_new_title_field_base']) ? $v389 : ''));
				$label = Lua::str((Lua::truth($v390 = $PF['objection_add_label']) ? $v390 : ''));
				$tooltip = Lua::format($PF['objection_add_tt'], $pv['rawTitle']);
				$rqForm = Lua::str((Lua::truth($v391 = $PF['form_new_arg_title']) ? $v391 : ''));
				$rqQuery = new Table([]);
				$rqQuery[($baseField . '[type]')] = Lua::str((Lua::truth($v392 = $PF['type_objection']) ? $v392 : ''));
				$rqQuery[($baseField . '[ID]')] = Lua::str((Lua::truth($v393 = $pv['pageId']) ? $v393 : ''));
				$rqQuery['_run'] = '1';
				Lua::insert($out, ('<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">' . $this->wkRunQueryHtmlTagButton($rqForm, $label, $tooltip, $rqQuery) . '</div>'));
			}
			$pieces[] = Lua::concat($out);
		}
		$pieces[] = $this->renderDebatesParentsBottom($pv, $lang, $cache, $PF);
		if (Lua::truth($args['interlanguage'])) {
			$pieces[] = $args['interlanguage'];
		}
		if ((!(Lua::truth($args['creation-date'])) || (Lua::trim($args['creation-date']) === ''))) {
			Lua::insert($cats, ('[[Category:' . $this->L($lang, 'categories', 'creation_date_missing') . ']]'));
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
		if ((Lua::truth($args['interlanguage']) && (Lua::trim(Lua::str((Lua::truth($v394 = $args['interlanguage']) ? $v394 : ''))) !== ''))) {
			$pieces[] = ('<span id="bouton-modifier-interlangue" style="display: none;"' . ' data-wk-tooltip="' . $this->escapeAttr($PF['edit_interlanguage_tooltip']) . '">' . $this->addDataEditLink($PF['form_edit_interlanguage'], $pv['rawTitle'], $PF['edit'], $PF['edit_interlanguage_tooltip']) . '</span>');
		}
		$pieces[] = $this->renderArgumentSchemaJSONLD($args, $pv, $lang, $cache, $PF, $maps);
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
