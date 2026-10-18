--	Module:Debate (i18n FR/EN) — Optimisé
--	Objectif : rendu du modèle « Débat » (MediaWiki 1.43) fidèle au rendu existant

local p = {}

----------------------------------------------------------------------
--	Accès global au frame + VariablesLua (accélération sans changer le rendu)
----------------------------------------------------------------------

local mw			= mw
local F				= mw.getCurrentFrame()
local V				= ( mw.ext and mw.ext.VariablesLua ) or nil
local WD_I18N		= require( 'Module:WD/I18N' )
local uri_encode	= mw.uri.encode
local string_format	= string.format
local title_getCurrentTitle	= mw.title.getCurrentTitle
local uri_fullUrl			= mw.uri.fullUrl
local uri_localUrl			= mw.uri.localUrl

--	Locaux (micro-optis)
local tostring		= tostring
local type			= type
local ipairs		= ipairs
local pairs			= pairs
local table_concat	= table.concat
local table_insert	= table.insert
local table_sort	= table.sort
local t_trim		= mw.text.trim
local t_gsplit		= mw.text.gsplit
local t_nowiki		= mw.text.nowiki
local t_jsonEncode	= mw.text.jsonEncode
local t_jsonDecode	= mw.text.jsonDecode
local html_create	= mw.html.create
local gsub			= string.gsub

--	Forward decl (utilisé avant définition)
local escapeAttr

local ITEM_SEP	= "⟭"
local FIELD_SEP	= "⟬"

--	wrappers VariablesLua (no-op si extension absente)
local function vset( name, val )
	if V and type( V.vardefine ) == "function" then
		pcall( V.vardefine, name, val ~= nil and tostring( val ) or "" )
	end
end
local function vget( name )
	if V and type( V.var ) == "function" then
		local ok, res = pcall( V.var, name )
		if ok then return res end
	end
	return nil
end

local function detectLang( args )
	return WD_I18N.getLangFromArgs( args or {} )
end

local function L( lang, section, key, ... )
	return WD_I18N.msg( "Debate." .. section, lang, key, ... )
end

local function smwProp( lang, key )
	return WD_I18N.msg( "Debate.props", lang, key )
end

local function isProgress( lang, val, key )
	if not val or val == "" then return false end
	local label = WD_I18N.msg( "Debate.progress", lang, key )
	return val == label
end

----------------------------------------------------------------------
--	Anchor intégré (remplace Module:Anchor)
----------------------------------------------------------------------

local function anchorId( s )
	s = tostring( s or "" )

	--	Normalisation des apostrophes HTML éventuelles
	s = gsub( s, "&#039;", "'" )
	s = gsub( s, "&#39;", "'" )
	s = gsub( s, "&apos;", "'" )

	--	Nettoyage minimal
	s = gsub( s, "%s+", " " )
	s = t_trim( s )

	--	Espaces → underscore
	s = gsub( s, " ", "_" )

	--	Suppression caractères cassants (HTML / wiki)
	s = gsub( s, '[%[%]{}|#<>"]', "" )

	return s
end

----------------------------------------------------------------------
--	Helpers
----------------------------------------------------------------------

local function getArgs( frame )
	local parent = frame:getParent()
	local A = {}

	local function add( k, v )
		if v == nil then return end
		v = t_trim( tostring( v ) )
		if v ~= "" then
			A[ k ] = v
		end
	end

	if parent and parent.args then
		for k, v in pairs( parent.args ) do
			add( k, v )
		end
	end

	if frame.args then
		for k, v in pairs( frame.args ) do
			add( k, v )
		end
	end

	return A
end

local function splitCSV( s, sep )
	if not s or s == "" then return {} end
	sep = sep or ","
	local out = {}
	for part in t_gsplit( s, sep, true ) do
		part = t_trim( part )
		if part ~= "" then table_insert( out, part ) end
	end
	return out
end

local function wkParseWikipediaMarkers( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	--	On attend maintenant une série de "Titre⟭"
	if not raw:find( ITEM_SEP, 1, true ) then
		return {}
	end

	local pages = {}

	for part in t_gsplit( raw, ITEM_SEP, true ) do
		part = t_trim( part )
		if part ~= "" then
			pages[ #pages + 1 ] = part
		end
	end

	return pages
end

local function hasSMW()
	return type( mw.smw ) == "table" and type( mw.smw.set ) == "function"
end

local function smwSetSafe( props )
	if not mw.smw or type( mw.smw.set ) ~= "function" then
		return
	end

	pcall( function()
		mw.smw.set( props )
	end )
end

local function smwAskSafe( q )
	if not hasSMW() then return nil end
	local ok, res = pcall( function() return mw.smw.ask( q ) end )
	return ok and res or nil
end

----------------------------------------------------------------------
--	SEO : cache “par rendu”
----------------------------------------------------------------------

local SEO_CACHE = {}

local function seoCacheKey( args, pv, lang )
	return table_concat( {
		tostring( lang or "" ),
		pv.pageId or "",
		tostring( pv.rawTitle or "" ),
		tostring( args[ "expanded-topic" ] or "" ),
		tostring( args[ "keywords" ] or "" ),
		tostring( args[ "sections" ] or "" ),
	}, "\n" )
end

----------------------------------------------------------------------
--	Wikiseo helpers
----------------------------------------------------------------------

local function wkSeoHas()
	return mw.ext
		and mw.ext.seo
		and type( mw.ext.seo.set ) == "function"
end

local function wkSeoSet( data )
	if not wkSeoHas() then
		return
	end
	pcall( mw.ext.seo.set, data )
end

local function seoL( lang, key, ... )
	return WD_I18N.msg( "Debate.seo", lang, key, ... ) or ""
end

local function seoJoinList( items )
	local out = {}
	local seen = {}

	for _, v in ipairs( items or {} ) do
		v = t_trim( tostring( v or "" ) )
		if v ~= "" and not seen[ v ] then
			seen[ v ] = true
			out[ #out + 1 ] = v
		end
	end

	return table_concat( out, ", " )
end

local function buildSeoKeywords( args, lang )
	local items = {}

	for _, k in ipairs( splitCSV( args[ "keywords" ], "," ) ) do
		items[ #items + 1 ] = k
	end

	for _, s in ipairs( splitCSV( args[ "sections" ], "," ) ) do
		items[ #items + 1 ] = s
	end

	return seoJoinList( items )
end

local function getPageCreation(pv, args)
	local v = args and args["creation-date"]
	v = (type(v) == "string") and t_trim(v) or ""

	if v ~= "" then
		return v .. "T00:00:00Z"
	end

	return nil
end

----------------------------------------------------------------------
--	SEO principal (avec cache)
----------------------------------------------------------------------

local function renderFullSEO( args, pv, lang )
	--	cache par rendu
	local ck = seoCacheKey( args, pv, lang )
	if SEO_CACHE[ ck ] then
		return
	end
	SEO_CACHE[ ck ] = true

	local pageTitle	= pv.rawTitle or ""

	local topic = t_trim( tostring( args[ "expanded-topic" ] or "" ) )
	if topic == "" then
		topic = pageTitle
	end

	local kw = buildSeoKeywords( args, lang )

	wkSeoSet{
		title				= pageTitle .. " " .. L(lang, "text", "shortdesc_plain") .. " – " .. L(lang, "seo", "site_name"),
		title_mode			= pageTitle .. " " .. L(lang, "text", "shortdesc_plain"),
		title_separator		= string_format( seoL( lang, "title_separator" ), pageTitle ),
		description			= string_format( seoL( lang, "description" ), topic ),
		keywords			= kw,
		image				= seoL( lang, "image" ),
		image_alt			= seoL( lang, "image_alt" ),
		section				= seoL( lang, "section" ),
		site_name			= seoL( lang, "site_name" ),
		twitter_site		= seoL( lang, "twitter_site" ),
		locale				= seoL( lang, "locale" ),
		author				= seoL( lang, "author" ),
		robots				= "index,follow",
		googlebot			= "index,follow",
	}
end

----------------------------------------------------------------------
--	Helpers PERF : preprocess conditionnel + cache Bandeau
----------------------------------------------------------------------

local function subMsg( lang, key, ... )
	return WD_I18N.msg( "Debate.subsections", lang, key, ... ) or ""
end

local function renderSubsectionBanner( pv, lang, key, opts, cats )
	key = tostring( key or "" )
	if key == "" then
		return ""
	end

	opts = opts or {}

	local isReorg		= opts.reorg == true
	local linkHtml		= ""
	local title			= ""
	local text			= ""

	--	Lien de réorganisation (optionnel) — AddData (même source que le H2)
	if isReorg then
		local formName	= tostring( opts.form or "" )
		local tooltip	= tostring( opts.tooltip or "" )
		local linkLabel	= tostring( opts.link_label or "" )

		if linkLabel == "" then
			linkLabel = L( lang, "text", "reorganize_link_label" )
		end

		if formName ~= "" then
			linkHtml = addDataLink( formName, pv.rawTitle, linkLabel, tooltip )
		end
	end

	--	Titre/texte (mêmes messages i18n que tes fonctions actuelles)
	if isReorg and linkHtml ~= "" then
		title = subMsg( lang, key .. "_title" )
		title = t_trim( tostring( title or "" ) )
		if title ~= "" then
			title = title .. "&nbsp;" .. linkHtml
		else
			title = linkHtml
		end
	else
		title = subMsg( lang, key .. "_title", pv.rawTitle )
	end

	text = subMsg( lang, key .. "_text", pv.rawTitle )

	if title == "" and text == "" then
		return ""
	end

	local html =
		'<div class="bandeau-avertissement wk-icon-before wk-icon-sensible">'
		.. title
		.. '<span class="smw-highlighter smwttinline wk-icon-before wk-icon-help mw-no-invert" data-state="inline">'
		.. '<div class="smwttcontent">' .. text .. '</div>'
		.. '</span>'
		.. '</div>'

	local cat = subMsg( lang, key .. "_category" )
	if cats and cat and cat ~= "" then
		table_insert( cats, "[[Category:" .. cat .. "]]" )
	end

	return html
end

----------------------------------------------------------------------
--	SMW batch : on accumule et on flush une fois à la fin
----------------------------------------------------------------------

local function smwQueueAdd( pending, key, val, multi )
	if not key or key == "" or val == nil then
		return
	end

	if type( val ) == "string" then
		val = t_trim( val )
		if val == "" then
			return
		end
	end

	local cur = pending[ key ]

	if multi then
		--	Multi-valeurs : toujours en table côté pending
		if cur == nil then
			pending[ key ] = ( type( val ) == "table" ) and val or { val }
			return
		end

		if type( cur ) ~= "table" then
			cur = { cur }
			pending[ key ] = cur
		end

		if type( val ) == "table" then
			for _, x in ipairs( val ) do
				if x ~= nil then
					cur[ #cur + 1 ] = x
				end
			end
		else
			cur[ #cur + 1 ] = val
		end

		return
	end

	--	Scalar : dernière valeur gagne (comportement le plus sûr)
	pending[ key ] = val
end

local function smwQueueAddFromSepString( pending, key, raw, sep )
	raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""
	if raw == "" then
		return
	end

	sep = sep or ITEM_SEP

	local vals = {}

	if raw:find( sep, 1, true ) then
		for part in t_gsplit( raw, sep, true ) do
			part = t_trim( part )
			if part ~= "" then
				vals[ #vals + 1 ] = part
			end
		end
	else
		vals[ 1 ] = raw
	end

	if #vals > 0 then
		smwQueueAdd( pending, key, vals, true )
	end
end

local function smwQueueAddFromCSV( pending, key, raw )
	smwQueueAddFromSepString( pending, key, raw, "," )
end

local function smwQueueMerge( dst, src )
	for k, v in pairs( src or {} ) do
		if type( v ) == "table" then
			smwQueueAdd( dst, k, v, true )
		else
			smwQueueAdd( dst, k, v, false )
		end
	end
end

----------------------------------------------------------------------
--	PageForms : utilitaires HTML / attributs
----------------------------------------------------------------------

escapeAttr = function( s )
	s = tostring( s or "" )
	return s
		:gsub( "[\r\n\t]", " " )
		:gsub( "&", "&amp;" )
		:gsub( "<", "&lt;" )
		:gsub( ">", "&gt;" )
		:gsub( '"', "&quot;" )
	-- pas besoin d'échapper ' si on entoure l'attribut avec des guillemets doubles
end

--	{{#tag}} utilitaire
local function tag( name, content, attrs )
	local parts = {}
	if attrs then
		for k, v in pairs( attrs ) do
			table_insert( parts, string_format( "%s=%s", k, v ) )
		end
	end
	local src
	if #parts > 0 then
		src = string_format( "{{#tag:%s|%s|%s}}", name, content or "", table_concat( parts, "|" ) )
	else
		src = string_format( "{{#tag:%s|%s}}", name, content or "" )
	end
	return F:preprocess( src )
end

local function pf_escape( v )
	if not v then return "" end
	v = tostring( v )
	v = v:gsub( "|", "&#124;" ):gsub( "=", "&#61;" )
	return v
end

----------------------------------------------------------------------
--	PageForms : remplacement des #formlink (link) par Special:AddData
----------------------------------------------------------------------

local function wkDbKey( page )
	local t = mw.title.new( page )
	if not t then
		return tostring( page or "" ):gsub( "%s", "_" )
	end
	return t.prefixedText:gsub( " ", "_" )
end

local function wkEncodeAddDataSegment( s )
	s = tostring( s or "" )
	s = s:gsub( "%%", "%%25" )
	s = s:gsub( "%?", "%%3F" )
	s = s:gsub( "#", "%%23" )
	s = s:gsub( "/", "%%2F" )
	return s
end

local function wkWikipediaUrl( page, lang )
	if not page or page == "" then
		return ""
	end

	local base = L( lang, "urls", "wikipedia" )
	if not base or base == "" then
		return ""
	end

	return base .. uri_encode( page, "WIKI" )
end

local function wkAddDataPath( formName, pageTitle )
	local form = wkEncodeAddDataSegment( wkDbKey( formName ) )
	local page = wkEncodeAddDataSegment( wkDbKey( pageTitle ) )
	return "Special:AddData/" .. form .. "/" .. page
end

local function addDataLink( formName, pageTitle, linktext, tooltip )
	formName = tostring( formName or "" )
	pageTitle = tostring( pageTitle or "" )
	if formName == "" or pageTitle == "" then
		return ""
	end

	local target = wkAddDataPath( formName, pageTitle )

	local lt = "&nbsp;"
	local tt = tostring( tooltip or "" )

	if tt ~= "" then
		return string_format(
			'<span class="wk-adddata-link bouton-modifier navigation-not-searchable noprint" data-wk-tooltip="%s">[[%s|%s]]</span>',
			escapeAttr( tt ),
			target,
			lt
		)
	end

	return string_format( '[[%s|%s]]', target, lt )
end

local function wkRunQueryPath( formName )
	local form = wkEncodeAddDataSegment( wkDbKey( formName ) )
	return "Special:RunQuery/" .. form
end

local function normalizeTooltipText( s )
	s = tostring( s or "" )

	--	Apostrophes
	s = s:gsub( "'", "’" )

	--	Guillemets droits → typographiques
	s = s:gsub( '"', '“' )

	return s
end

local function wkRunQueryHtmlTagButton( formName, label, tooltip, query )
	formName	= tostring( formName or "" )
	label		= tostring( label or "" )
	query		= ( type( query ) == "table" ) and query or {}
	tooltip		= tostring( tooltip or "" )

	tooltip = normalizeTooltipText( tooltip )

	if formName == "" then
		return ""
	end

	local title	= wkRunQueryPath( formName )
	local href	= tostring( uri_localUrl( title, query ) )

	local content = "＋ " .. label

	return F:preprocess(
		'<htmltag tagname="a"'
		.. ' href="javascript:void(0)"'
		.. ' data-href="' .. escapeAttr( href ) .. '"'
		.. ' class="wk-btn__a wk-js-nav"'
		.. ( tooltip ~= "" and ( ' title="' .. escapeAttr( tooltip ) .. '"' ) or "" )
		.. '>'
		.. content
		.. '</htmltag>'
	)
end

local function wkAddDataGetButton( formName, pageTitle, label, tooltip, query )
	formName	= tostring( formName or "" )
	pageTitle	= tostring( pageTitle or "" )
	label		= tostring( label or "" )
	tooltip		= tostring( tooltip or "" )
	query		= ( type( query ) == "table" ) and query or {}

	if formName == "" or pageTitle == "" then
		return ""
	end

	local title	= wkAddDataPath( formName, pageTitle )
	local url	= tostring( uri_fullUrl( title, query ) )	--	URL absolue obligatoire

	local linkInner =
		'<span class="wk-btn__icon" aria-hidden="true">➜</span>'
		.. '<span class="wk-btn__label">' .. label .. '</span>'

	local link = '[' .. url .. ' ' .. linkInner .. ']'

	return
		'<div class="wk-btn wk-auto-id mw-ui-button navigation-not-searchable"'
		.. ( tooltip ~= "" and ( ' title="' .. escapeAttr( tooltip ) .. '"' ) or "" )
		.. '>'
		.. link
		.. '</div>'
end

local function runQueryLink( formName, fieldLabel, fieldName, value, linktext, tooltip, extraParams )
	formName = tostring( formName or "" )
	fieldLabel = tostring( fieldLabel or "" )
	fieldName = tostring( fieldName or "" )
	value = tostring( value or "" )
	if formName == "" or fieldLabel == "" or fieldName == "" or value == "" then
		return ""
	end

	local title = wkRunQueryPath( formName )

	local q = {}
	q[ fieldLabel .. "[" .. fieldName .. "]" ] = value

	if type( extraParams ) == "table" then
		for k, v in pairs( extraParams ) do
			if k and k ~= "" and v ~= nil and tostring( v ) ~= "" then
				q[ k ] = tostring( v )
			end
		end
	end

	q[ "_run" ] = ""

	local url = tostring( uri_fullUrl( title, q ) )
	local label = linktext or value
	local tt = tostring( tooltip or "" )

	local link = "[" .. url .. " " .. label .. "]"

	if tt ~= "" then
		return string_format(
			'<span class="wk-adddata-link masquer-externe" data-wk-tooltip="%s">%s</span>',
			escapeAttr( tt ),
			link
		)
	end

	return string_format( '<span class="wk-adddata-link">%s</span>', link )
end

local function joinArticles( items, lang )
	local n = #items
	if n == 0 then return "" end
	if n == 1 then return items[ 1 ] end

	local conj = WD_I18N.msg( "Common.text", lang, "list_conjunction_text" )

	if n == 2 then
		return items[ 1 ] .. conj .. items[ 2 ]
	end

	local last = items[ n ]
	local first = {}
	for i = 1, n - 1 do first[ i ] = items[ i ] end
	return table_concat( first, ", " ) .. conj .. last
end

local function listBreaker()
	return "\n\n<!--__WD_LIST_BREAK__-->"
end

local function push( t, s )
	t[ #t + 1 ] = s
end

----------------------------------------------------------------------
--	Bandeaux de sous-section pour les titres affichés
----------------------------------------------------------------------

local ARGUMENT_WARNING_BANNER_KEYS = {
	"displayed_title_too_long",
	"displayed_title_unclear",
	"displayed_title_disadvantageous",
}

local ARGUMENT_WARNING_LOOKUPS = {}

local function buildSummaryLabelLookup( lang, keys )
	local lookup = {}
	for _, key in ipairs( keys or {} ) do
		local lab = t_trim( subMsg( lang, key .. "_label" ) )
		if lab ~= "" then
			lookup[ lab ] = key
		end
	end
	return lookup
end

local function getArgumentWarningLookup( lang )
	lang = tostring( lang or "" )

	if not ARGUMENT_WARNING_LOOKUPS[ lang ] then
		ARGUMENT_WARNING_LOOKUPS[ lang ] =
			buildSummaryLabelLookup( lang, ARGUMENT_WARNING_BANNER_KEYS )
	end

	return ARGUMENT_WARNING_LOOKUPS[ lang ]
end

local function resolveKeyFromLookup( raw, lookup, allowedKeys, aliases )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return ""
	end

	local k = ( lookup and lookup[ raw ] ) or ""
	if k ~= "" then
		return k
	end

	if aliases and aliases[ raw ] then
		return aliases[ raw ]
	end

	if allowedKeys then
		for _, kk in ipairs( allowedKeys ) do
			if raw == kk then
				return kk
			end
		end
	end

	return ""
end

local function renderArgumentItemBanners( itemWarnings, pv, lang, cats, out )
	local warnsArg = t_trim( tostring( itemWarnings or "" ) )
	if warnsArg == "" then
		return
	end

	local lookup = getArgumentWarningLookup( lang )
	local hasBanner = false

	for _, x in ipairs( splitCSV( warnsArg, "," ) ) do
		local key = resolveKeyFromLookup( x, lookup, ARGUMENT_WARNING_BANNER_KEYS )
		if key ~= "" then
			table_insert( out, renderSubsectionBanner( pv, lang, key, nil, nil ) )
			hasBanner = true
		end
	end

	if hasBanner and cats then
		table_insert( cats, "[[Category:" .. L( lang, "categories", "displayed_titles_to_review" ) .. "]]" )
	end
end

----------------------------------------------------------------------
--	Arguments : extraction depuis chaînes
----------------------------------------------------------------------

local function wkSplit2( s, sep )
	s = tostring( s or "" )
	sep = tostring( sep or "" )
	if sep == "" then
		return s, ""
	end

	local i = s:find( sep, 1, true )
	if not i then
		return s, ""
	end

	return s:sub( 1, i - 1 ), s:sub( i + #sep )
end

local function wkExtractArgumentItemsFromData( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	if not raw:find( ITEM_SEP, 1, true ) and not raw:find( FIELD_SEP, 1, true ) then
		return {}
	end

	local items = {}

	for block in t_gsplit( raw, ITEM_SEP, true ) do
		block = t_trim( block )
		if block ~= "" then
			local page, rest = wkSplit2( block, FIELD_SEP )
			local title, warnings = wkSplit2( rest, FIELD_SEP )

			page = t_trim( page )
			if page ~= "" then
				title = t_trim( title )
				warnings = t_trim( warnings )

				if title == "" then
					title = page
				end

				items[ #items + 1 ] = {
					page = page,
					title = title,
					warnings = warnings
				}
			end
		end
	end

	return items
end

local function wkComputeArgumentItems( raw )
	local items = wkExtractArgumentItemsFromData( raw )
	if #items > 0 then
		return items, "data"
	end
	return {}, "none"
end

local function wkBuildArgumentData( items, lang, kind )
	items = items or {}

	local idPrefix = ( kind == "pro" ) and "Pro_argument_" or "Con_argument_"

	local listLines = {}
	for _, it in ipairs( items ) do
		table_insert( listLines, "* " .. it.title )
	end
	local listText = table_concat( listLines, "\n" )

	local argsVarParts = {}
	for _, it in ipairs( items ) do
		if it.page == it.title then
			table_insert( argsVarParts, it.page )
		else
			table_insert( argsVarParts, it.page .. "⟬" .. it.title )
		end
	end
	local argsVar = table_concat( argsVarParts, " -;- " )

	local mapLines = {}
	for i, it in ipairs( items ) do
		table_insert( mapLines,
			'<div  id="' .. idPrefix .. i .. '_map" class="argument-title--map wk-icon-before wk-icon-argument">' .. it.title .. '</div>'
		)
	end
	local mapHtml = table_concat( mapLines, "\n" )

	local hoverLines = {}
	for _, it in ipairs( items ) do
		table_insert( hoverLines,
			'<div class="argument-title--map wk-icon-before wk-icon-argument">' .. '[[' .. it.page .. '|' .. it.title .. ']]</div>'
		)
	end
	local mapHoverHtml = table_concat( hoverLines, "\n" )

	return {
		items = items,
		list = listText,
		argsVar = argsVar,
		map = mapHtml,
		mapHover = mapHoverHtml
	}
end

local function computeArgumentMapsFromArgs( args, lang )
	local rawPro = args[ "pro-arguments" ] or ""
	local rawCon = args[ "con-arguments" ] or ""

	if rawPro ~= "" then
		rawPro = F:preprocess( rawPro )
	end
	if rawCon ~= "" then
		rawCon = F:preprocess( rawCon )
	end

	local pro, proMode = wkComputeArgumentItems( rawPro )
	local con, conMode = wkComputeArgumentItems( rawCon )

	return {
		pro = wkBuildArgumentData( pro, lang, "pro" ),
		con = wkBuildArgumentData( con, lang, "con" ),
		_mode = { pro = proMode, con = conMode }
	}
end

local function wkRenderArgumentLi( pv, lang, it, kind, idx, cats )
	local isPro = ( kind == "pro" )

	local idPrefix = isPro and "Pro_argument_" or "Con_argument_"

	local page = tostring( it.page or "" )
	local titleShown = tostring( it.title or "" )
	if titleShown == "" then
		titleShown = page
	end

	local aId = anchorId( titleShown )
	local warnings = tostring( it.warnings or "" )

	local banners = {}
	renderArgumentItemBanners( warnings, pv, lang, cats, banners )

	return
		'<li id="'
		.. idPrefix .. tostring( idx )
		.. '" class="argument level-1">'
		.. '<div id="'
		.. escapeAttr( aId )
		.. '" class="argument-title wk-icon-before wk-icon-argument wk-icon-after wk-carret">'
		.. '[[' .. page .. '|' .. titleShown .. ']]'
		.. ( #banners > 0 and table_concat( banners ) or "" )
		.. '</div>'
		.. '</li>'
end

----------------------------------------------------------------------
--	Bloc d’en-tête / variables de page
----------------------------------------------------------------------

local function computePageVars()
	local title = title_getCurrentTitle()
	local rawTitle = title.prefixedText
	local encoded = uri_encode( rawTitle, "WIKI" )
	local pageUrl = tostring( uri_fullUrl( title.prefixedText ) )
	local pageId = tostring( title.id or "" )

	return {
		title = title,
		rawTitle = rawTitle,
		encoded = encoded,
		pageUrl = pageUrl,
		pageId = pageId
	}
end

----------------------------------------------------------------------
--	Bandeau principal
----------------------------------------------------------------------

local function renderMetaWarningHtml( params )
	local color	= tostring( params.color or "" )
	local icon	= tostring( params.icon or "" )
	local title	= tostring( params.title or "" )
	local text	= tostring( params.text or "" )

	return
		'<div class="bandeau bandeau-' .. color .. ' wk-icon-before ' .. icon .. ' navigation-not-searchable">'
		.. '<div class="bandeau-titre">' .. title .. '</div>'
		.. '<div class="bandeau-texte">' .. text .. '</div>'
		.. '</div>'
end

local function renderMainBanner( frame, pv, lang )
	local titre = string_format( L( lang, "text", "main_banner_title" ), t_nowiki( pv.rawTitle ) )

	return renderMetaWarningHtml{
		color	= L( lang, "text", "main_banner_color" ),
		title	= titre,
		icon	= 'wk-icon-wikidebates',
		text	= '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">' .. L( lang, "text", "learn_more_label" ) .. L( lang, "text", "founding_principles_link" ) .. '</div>'
	}
end

----------------------------------------------------------------------
--	Bandeaux : rendu natif + résolution via label i18n (C optimisée)
----------------------------------------------------------------------

local PROGRESS_BANNER_KEYS = {
	"progress_under_construction",
	"progress_draft",
}

local TITLE_WARNING_BANNER_KEYS = {
	"title_non_standard",
	"title_to_explain",
	"title_to_simplify",
}

local DEBATE_WARNING_BANNER_KEYS = {
	"debate_unbalanced",
	"debate_redundant",
	"debate_fanciful",
	"debate_sensitive",
	"outline_to_improve",
	"debate_generated_by_ai",
}

local function bannerMsg( lang, key, ... )
	return WD_I18N.msg( "Debate.banners", lang, key, ... ) or ""
end

local function renderBannerByKey( pv, lang, key, cats )
	key = tostring( key or "" )
	if key == "" then
		return ""
	end

	local color	= bannerMsg( lang, key .. "_color" )
	local icon	= bannerMsg( lang, key .. "_icon" )
	local title	= bannerMsg( lang, key .. "_title", pv.rawTitle )
	local text	= bannerMsg( lang, key .. "_text", pv.rawTitle )

	if color == "" and icon == "" and title == "" and text == "" then
		return ""
	end

	local html = renderMetaWarningHtml{
		color	= color,
		icon	= icon,
		title	= title,
		text	= text
	}

	local cat = bannerMsg( lang, key .. "_category" )
	if cats and cat and cat ~= "" then
		table_insert( cats, "[[Category:" .. cat .. "]]" )
	end

	return html
end

local function buildBannerLabelLookup( lang, keys )
	local lookup = {}
	for _, key in ipairs( keys or {} ) do
		local lab = t_trim( bannerMsg( lang, key .. "_label" ) )
		if lab ~= "" then
			lookup[ lab ] = key
		end
	end
	return lookup
end

local function resolveBannerKeyFromLookup( raw, lookup )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return ""
	end
	return ( lookup and lookup[ raw ] ) or ""
end

local function renderWarningBanners( args, pv, cats, lang )
	local av = args[ "progress" ]
	local out = {}

	local titleLookup	= buildBannerLabelLookup( lang, TITLE_WARNING_BANNER_KEYS )
	local debateLookup	= buildBannerLabelLookup( lang, DEBATE_WARNING_BANNER_KEYS )
	local progLookup	= buildBannerLabelLookup( lang, PROGRESS_BANNER_KEYS )

	local function renderBannersFromList( list, lookup )
		local html = {}
		for _, x in ipairs( splitCSV( list, "," ) ) do
			local key = resolveBannerKeyFromLookup( x, lookup )
			if key ~= "" then
				table_insert( html, renderBannerByKey( pv, lang, key, cats ) )
			end
		end
		return table_concat( html )
	end

	if args[ "title-warnings" ] then
		table_insert( out, renderBannersFromList( args[ "title-warnings" ], titleLookup ) )
	end

	if av and t_trim( av ) ~= "" then
		local key = resolveBannerKeyFromLookup( av, progLookup )
		if key ~= "" then
			table_insert( out, renderBannerByKey( pv, lang, key, cats ) )
		end
	end

	if isProgress( lang, av, "constructed" ) then
		table_insert( cats, "[[Category:" .. L( lang, "categories", "constructed" ) .. "]]" )
	elseif not isProgress( lang, av, "draft" ) and not isProgress( lang, av, "building" ) and not isProgress( lang, av, "constructed" ) then
		table_insert( cats, "[[Category:" .. L( lang, "categories", "progress_missing" ) .. "]]" )
	end

	if args[ "debate-warnings" ] then
		table_insert( out, renderBannersFromList( args[ "debate-warnings" ], debateLookup ) )
	end

	return table_concat( out )
end

----------------------------------------------------------------------
--	Carte des arguments (en-tête)
----------------------------------------------------------------------

local function renderArgumentMapTop( lang, maps )
	maps = maps or {}
	local pro = ( maps.pro and maps.pro.map ) or ""
	local con = ( maps.con and maps.con.map ) or ""

	local labelPour		= L( lang, "text", "arg_map_pro_label" )
	local labelContre	= L( lang, "text", "arg_map_con_label" )
	local msgPour		= L( lang, "text", "none_pro_args_msg_short" )
	local msgContre		= L( lang, "text", "none_con_args_msg_short" )

	local root = html_create( "div" )
		:attr( "id", "Argument_map" )
		:addClass( "wk-arg-map" )
		:addClass( "wk-arg-map--top" )
		:addClass( "navigation-not-searchable" )

	local colP = html_create( "div" )
		:addClass( "wk-arg-map__col" )
		:addClass( "is-pro" )

	local colC = html_create( "div" )
		:addClass( "wk-arg-map__col" )
		:addClass( "is-con" )

	colP:node(
		html_create( "div" )
			:addClass( "wk-arg-map__th" )
			:wikitext( labelPour )
	)

	colP:node(
		html_create( "div" )
			:addClass( "wk-arg-map__td" )
			:wikitext( pro ~= "" and pro or ( '<div class="carte-vide navigation-not-searchable">' .. msgPour .. '</div>' ) )
	)

	colC:node(
		html_create( "div" )
			:addClass( "wk-arg-map__th" )
			:wikitext( labelContre )
	)

	colC:node(
		html_create( "div" )
			:addClass( "wk-arg-map__td" )
			:wikitext( con ~= "" and con or ( '<div class="carte-vide navigation-not-searchable">' .. msgContre .. '</div>' ) )
	)

	root:node( colP ):node( colC )

	return tostring( root )
end

----------------------------------------------------------------------
--	Sections
----------------------------------------------------------------------

----------------------------------------------------------------------
--	Sous-parties d’introduction (format "Sous-partie" optimisé)
----------------------------------------------------------------------

local INTRO_SUB_SEP		= "⟭⟭⟭"
local INTRO_FIELD_SEP	= "⟬⟬⟬"

local function wkParseIntroSubsections( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	if not raw:find( INTRO_SUB_SEP, 1, true ) then
		return {}
	end

	local items = {}

	for block in t_gsplit( raw, INTRO_SUB_SEP, true ) do
		block = t_trim( block )
		if block ~= "" then
			local f = {}
			for part in t_gsplit( block, INTRO_FIELD_SEP, true ) do
				f[ #f + 1 ] = t_trim( tostring( part or "" ) )
			end

			for i = #f + 1, 3 do
				f[ i ] = ""
			end

			items[ #items + 1 ] = {
				title		= f[ 1 ],
				content		= f[ 2 ],
				warnings	= f[ 3 ]
			}
		end
	end

	return items
end

local INTRO_SUB_WARNING_KEYS = {
	"too_long",
	"to_develop",
	"unclear",
	"biased",
	"refs_missing",
	"style_to_review",
}

local function buildIntroSubWarningLookup( lang )
	local lookup = {}
	for _, k in ipairs( INTRO_SUB_WARNING_KEYS ) do
		local lab = t_trim( subMsg( lang, "intro_subbanner_" .. k .. "_label" ) )
		if lab ~= "" then
			lookup[ lab ] = "intro_subbanner_" .. k
		end
	end
	return lookup
end

local function wkRenderIntroSubsection( pv, lang, it, cats, lookup )
	local titre = t_trim( tostring( it.title or "" ) )
	local contenu = t_trim( tostring( it.content or "" ) )
	local avert = t_trim( tostring( it.warnings or "" ) )

	if titre == "" then
		titre = L( lang, "text", "default_subsection_title" )
	end

	local out = {}

	table_insert( out, '<div class="fr-collapsible fr-collapsed">' )
	table_insert( out,
		'<h3 class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-question">'
		.. titre
		.. '</h3>'
	)
	table_insert( out, '<div class="fr-collapsible-content"><div class="encadre-section-deroulante">' )

	if avert ~= "" then
		local banners = {}

		for lab in t_gsplit( avert, ",", true ) do
			lab = t_trim( lab )
			if lab ~= "" then
				local k = lookup[ lab ] or ""
				if k ~= "" then
					local h = renderSubsectionBanner( pv, lang, k, {}, cats )
					if h and h ~= "" then
						banners[ #banners + 1 ] = h
					end
				end
			end
		end

		if #banners > 0 then
			table_insert( out, '<div class="minibandeaux">' .. table_concat( banners ) .. '</div>' )
		end
	end

	if contenu ~= "" then
		table_insert( out, contenu )
	else
		local editForm		= L( lang, "forms", "intro_edit_title" )
		local editTT		= L( lang, "text", "intro_edit_tt" )
		local linkLabel		= L( lang, "text", "intro_empty_link_label" )
		local emptyIntro	= L( lang, "text", "intro_empty_msg" )
		local emptyCat		= L( lang, "text", "intro_empty_category" )

		local link = addDataLink(
			editForm,
			pv.rawTitle,
			linkLabel,
			editTT
		)

		table_insert(
			out,
			"<i>" .. emptyIntro	.. "</i>"
		)

		if emptyCat ~= "" then
			table_insert( cats, "[[Category:" .. emptyCat .. "]]" )
		end
	end

	table_insert( out, "</div></div></div>" )

	return table_concat( out )
end

local function wkRenderIntroSubsectionsBlock( pv, lang, raw, cats )
	local items = wkParseIntroSubsections( raw )
	if #items == 0 then
		return ""
	end

	local lookup = buildIntroSubWarningLookup( lang )

	local out = {}
	for _, it in ipairs( items ) do
		out[ #out + 1 ] = wkRenderIntroSubsection( pv, lang, it, cats, lookup )
	end

	return table_concat( out )
end

----------------------------------------------------------------------
--	Section : Introduction (sans fallback)
----------------------------------------------------------------------

local function renderIntroduction( args, pv, lang, cats )
	local h2 =
		'<h2 class="section-modifiable wk-icon-before wk-icon-question">'
		.. L( lang, "text", "understand_debate_title" )
		.. addDataLink(
			L( lang, "forms", "intro_edit_title" ),
			pv.rawTitle,
			L( lang, "text", "edit_label" ),
			L( lang, "text", "intro_edit_tt" )
		)
		.. '</h2>'

	local raw = args[ "introduction" ]
	raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""

	if raw ~= "" then
		local expanded = F:preprocess( raw )
		local subs = wkRenderIntroSubsectionsBlock( pv, lang, expanded, cats or {} )

		if subs ~= "" then
			local s = ( h2 .. subs ):gsub( "%s*$", "" )
			return s
		end
	end

	local cat = "[[Category:" .. L( lang, "categories", "intro_missing" ) .. "]]"
	local body = '<div class="aucun-contenu navigation-not-searchable" style="margin-bottom: 1em;">' .. L( lang, "text", "none_intro_msg" ) .. '</div>' .. cat

	local s = ( h2 .. body ):gsub( "%s*$", "" )
	return s
end

local function renderWikipediaLinks( args, pv, lang )
	local raw = args[ "wikipedia-articles" ]
	raw = ( type( raw ) == "string" ) and t_trim( raw ) or ""

	local wrap = html_create( "div" ):addClass( "bandeau-section wk-icon-before wk-icon-wikipedia hover-top navigation-not-searchable" )

	local hasPages = false

	if raw ~= "" then
		local expanded = F:preprocess( raw )
		local pages = wkParseWikipediaMarkers( expanded )

		if #pages > 0 then
			hasPages = true
			local items = {}

			for _, page in ipairs( pages ) do
				local url = wkWikipediaUrl( page, lang )
				items[ #items + 1 ] = '<span class="hover-wikipedia">[' .. url .. ' ' .. page .. ']</span>'
			end

			wrap:wikitext(
				string_format(
					L( lang, "text", "wikipedia_seealso_msg" ),
					joinArticles( items, lang )
				)
			)
		else
			wrap:addClass( "aucun-contenu" )
				:wikitext( L( lang, "text", "none_wikipedia_msg" ) )
		end
	else
		wrap:addClass( "aucun-contenu" )
			:wikitext( L( lang, "text", "none_wikipedia_msg" ) )
	end

	wrap:wikitext(
		addDataLink(
			L( lang, "forms", "wiki_seealso_edit_title" ),
			pv.rawTitle,
			L( lang, "text", "edit_label" ),
			L( lang, "text", "wikipedia_edit_tt" )
		)
	)

	if raw == "" or not hasPages then
		return tostring( wrap ) .. "[[Category:" .. L( lang, "categories", "wikipedia_missing" ) .. "]]"
	end

	return tostring( wrap )
end

local function renderQuestionsFAQ( sujetComplet, sens, lang )
	local topic = ( sujetComplet and sujetComplet ~= "" ) and sujetComplet or ""
	local isCon = ( sens == "con" )
	local q
	if isCon then
		q = string_format( L( lang, "text", "faq_q_con" ), topic )
	else
		q = string_format( L( lang, "text", "faq_q_pro" ), topic )
	end
	return '<div class="question-FAQ">' .. q .. '</div>'
end

local function renderArgsList( args, pv, lang, sens, maps, cats )
	local isCon = ( sens == "con" )

	local hIconCon = 'is-con'
	local hIconPro = 'is-pro'
	local title = isCon and L( lang, "text", "args_con_title" ) or L( lang, "text", "args_pro_title" )

	local btnForm = addDataLink(
		isCon and L( lang, "forms", "args_con_edit_title" ) or L( lang, "forms", "args_pro_edit_title" ),
		pv.rawTitle,
		L( lang, "text", "edit_label" ),
		isCon and L( lang, "text", "args_con_edit_tt" ) or L( lang, "text", "args_pro_edit_tt" )
	)

	local out = {}
	push( out, listBreaker() )
	push( out,
		'<h2 class="section-modifiable wk-icon-before wk-icon-argument ' .. ( isCon and hIconCon or hIconPro ) .. '">'
		.. title
		.. btnForm
		.. '</h2>'
	)

	push( out, renderQuestionsFAQ( args[ "expanded-topic" ], sens, lang ) )

	local mode = maps and maps._mode and ( isCon and maps._mode.con or maps._mode.pro ) or "none"
	local data = maps and ( isCon and maps.con or maps.pro ) or nil
	local items = data and data.items or {}

	if ( mode == "data" ) and #items > 0 then
		local ul = html_create( "ul" )
			:addClass( "argument-list" )
			:addClass( isCon and "is-con" or "is-pro" )

		local lis = {}
		local anyTitleWarnings = false

		for i, it in ipairs( items ) do
			if it.warnings and t_trim( it.warnings ) ~= "" then
				anyTitleWarnings = true
			end
			table_insert( lis, wkRenderArgumentLi( pv, lang, it, isCon and "con" or "pro", i, cats ) )
		end

		ul:wikitext( table_concat( lis, "\n" ) )

		if anyTitleWarnings then
			table_insert( cats, "[[Category:" .. L( lang, "categories", "refs_to_review" ) .. "]]" )
		end

		push( out, tostring( ul ) )
	else
		local cat = isCon and ( "[[Category:" .. L( lang, "categories", "con_missing" ) .. "]]" ) or ( "[[Category:" .. L( lang, "categories", "pro_missing" ) .. "]]" )
		local msg = isCon and L( lang, "text", "none_con_args_msg" ) or L( lang, "text", "none_pro_args_msg" )
		table_insert( out, '<div class="aucun-argument navigation-not-searchable">' .. msg .. '</div>' .. cat )
	end

	local sideLabel = isCon and L( lang, "text", "side_con_word" ) or L( lang, "text", "side_pro_word" )
	local label = string_format( L( lang, "text", "args_add_label" ), sideLabel )

	local tooltip = string_format( L( lang, "text", "arg_add_tt" ), sideLabel, pv.rawTitle )

	local baseField = L( lang, "forms", "new_arg_title" )

	local rqTypeValue	= isCon and L( lang, "forms", "new_arg_con_type_label" ) or L( lang, "forms", "new_arg_pro_type_label" )

	local rqQuery = {}
	rqQuery[ baseField .. "[type]" ]	= rqTypeValue
	rqQuery[ baseField .. "[ID]" ]		= pv.pageId
	rqQuery[ "_run" ]					= "1"

	push( out,
		'<div class="bouton-ajouter wk-btn mw-ui-button navigation-not-searchable noprint">'
		.. wkRunQueryHtmlTagButton( baseField, label, tooltip, rqQuery )
		.. '</div>'
	)

	return table_concat( out )
end

----------------------------------------------------------------------
--	Sections de références
----------------------------------------------------------------------

----------------------------------------------------------------------
--	Helpers FACTORISÉS : warnings + rendu de listes <li>
----------------------------------------------------------------------

local REFERENCE_WARNING_BANNER_KEYS = {
	"reference_quality_insufficient",
	"reference_incomplete",
	"dead_link",
}

local function renderReferenceItemBanners( itemWarnings, pv, lang, cats, out )
	local warnsRef = t_trim( tostring( itemWarnings or "" ) )
	if warnsRef == "" then
		return
	end

	local lookup = buildSummaryLabelLookup( lang, REFERENCE_WARNING_BANNER_KEYS )

	for _, x in ipairs( splitCSV( warnsRef, "," ) ) do
		local key = resolveKeyFromLookup( x, lookup, REFERENCE_WARNING_BANNER_KEYS )
		if key ~= "" then
			table_insert( out, renderSubsectionBanner( pv, lang, key, nil, cats ) )
		end
	end
end

local function wkRenderLiListFromArg( raw, parseFn, renderOneFn )
	local items = parseFn( raw )
	if #items == 0 then
		return ""
	end

	local lis = {}
	for _, item in ipairs( items ) do
		local li = renderOneFn( item )
		if li and li ~= "" then
			lis[ #lis + 1 ] = li
		end
	end

	return table_concat( lis, "\n" )
end

local REF_SEP		= "⟭"
local REF_FIELD_SEP	= "⟬"

local function wkParseBiblioMarkers( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	if not raw:find( REF_FIELD_SEP, 1, true ) and not raw:find( REF_SEP, 1, true ) then
		return {}
	end

	local items = {}

	for block in t_gsplit( raw, REF_SEP, true ) do
		block = t_trim( block )
		if block ~= "" then
			local f = {}
			for part in t_gsplit( block, REF_FIELD_SEP, true ) do
				f[ #f + 1 ] = t_trim( tostring( part or "" ) )
			end

			for i = #f + 1, 12 do
				f[ i ] = ""
			end

			items[ #items + 1 ] = {
				authors		= f[ 1 ],
				article		= f[ 2 ],
				work		= f[ 3 ],
				volume		= f[ 4 ],
				number		= f[ 5 ],
				location	= f[ 6 ],
				page		= f[ 7 ],
				publisher	= f[ 8 ],
				place		= f[ 9 ],
				date		= f[ 10 ],
				link		= f[ 11 ],
				warnings	= f[ 12 ]
			}
		end
	end

	return items
end

local function wkRenderOneBiblioLi( pv, item, lang, categories, smwPending )
	local out = {}

	local authors = t_trim( item.authors or "" )
	if authors == "" then
		authors = L( lang, "text", "refs_to_review" )
		table_insert( categories, "[[Category:" .. L( lang, "categories", "refs_to_review" ) .. "]]" )
	end
	out[ #out + 1 ] = authors

	local article = t_trim( item.article or "" )
	local work = t_trim( item.work or "" )
	local link = t_trim( item.link or "" )

	if article ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "article_name" ), article, true )
	end
	if work ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "work_name" ), work, true )
	end

	if article ~= "" then
		if link ~= "" then
			out[ #out + 1 ] = ", « [" .. link .. " " .. article .. "] »"
		else
			out[ #out + 1 ] = ", « " .. article .. " »"
		end
	end

	if work ~= "" then
		if article ~= "" then
			out[ #out + 1 ] = ", ''" .. work .. "''"
		else
			if link ~= "" then
				out[ #out + 1 ] = ", ''[" .. link .. " " .. work .. "]''"
			else
				out[ #out + 1 ] = ", ''" .. work .. "''"
			end
		end
	end

	if work ~= "" then
		local volume = t_trim( item.volume or "" )
		if volume ~= "" then
			out[ #out + 1 ] = ", " .. volume
		end

		local number = t_trim( item.number or "" )
		if number ~= "" then
			out[ #out + 1 ] = ", n°" .. number
		end
	end

	local location = t_trim( item.location or "" )
	if location ~= "" then
		out[ #out + 1 ] = ", " .. location
	end

	local page = t_trim( item.page or "" )
	if page ~= "" then
		out[ #out + 1 ] = ", p." .. page
	end

	local publisher = t_trim( item.publisher or "" )
	if publisher ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "publishing_house" ), publisher, true )
		out[ #out + 1 ] = ", " .. publisher
	end

	local place = t_trim( item.place or "" )
	if place ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "place_of_publication" ), place, true )
		out[ #out + 1 ] = ", " .. place
	end

	local date = t_trim( item.date or "" )
	if date ~= "" then
		out[ #out + 1 ] = ", " .. date
	end

	renderReferenceItemBanners( item.warnings, pv, lang, categories, out )

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderBiblioListFromArg( raw, pv, lang, categories, smwPending )
	return wkRenderLiListFromArg(
		raw,
		wkParseBiblioMarkers,
		function( item )
			return wkRenderOneBiblioLi( pv, item, lang, categories, smwPending )
		end
	)
end

local function wkParseWeblioMarkers( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	if not raw:find( "⟭", 1, true ) and not raw:find( "⟬", 1, true ) then
		return {}
	end

	local items = {}

	for block in t_gsplit( raw, "⟭", true ) do
		block = t_trim( block )
		if block ~= "" then
			local f = {}
			for part in t_gsplit( block, "⟬", true ) do
				f[ #f + 1 ] = t_trim( tostring( part or "" ) )
			end

			for i = #f + 1, 6 do
				f[ i ] = ""
			end

			items[ #items + 1 ] = {
				page		= f[ 1 ],
				site		= f[ 2 ],
				link		= f[ 3 ],
				authors		= f[ 4 ],
				date		= f[ 5 ],
				warnings	= f[ 6 ]
			}
		end
	end

	return items
end

local function wkRenderOneWeblioLi( pv, item, lang, categories, smwPending, side )
	local out = {}

	local page		= t_trim( item.page or "" )
	local site		= t_trim( item.site or "" )
	local link		= t_trim( item.link or "" )
	local authors	= t_trim( item.authors or "" )
	local date		= t_trim( item.date or "" )

	if page ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "article_name" ), page, true )
	end
	if site ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "website_name" ), site, true )
	end

	if authors ~= "" then
		out[ #out + 1 ] = authors
	end

	do
		local label = ""
		if page ~= "" then
			label = page
		elseif site ~= "" then
			label = site
		end

		if label == "" then
			return ""
		end

		local renderedLabel
		if link ~= "" then
			renderedLabel = "[" .. link .. " " .. label .. "]"
		else
			renderedLabel = label
		end

		out[ #out + 1 ] = ( #out > 0 and ", " or "" ) .. renderedLabel
	end

	if site ~= "" and page ~= "" then
		out[ #out + 1 ] = ", ''" .. site .. "''"
	end

	if date ~= "" then
		out[ #out + 1 ] = ", " .. date
	end

	--	Bandeaux d’avertissement
	renderReferenceItemBanners( item.warnings, pv, lang, categories, out )

	--	SMW auteurs (par côté)
	if authors ~= "" then
		local propKey = "author"
		if side == "pro" then
			propKey = "author_pro"
		elseif side == "con" then
			propKey = "author_con"
		end

		for a in t_gsplit( authors, ",", true ) do
			a = t_trim( a )
			if a ~= "" then
				smwQueueAdd( smwPending, smwProp( lang, propKey ), a, true )
			end
		end
	end

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderWeblioListFromArg( raw, pv, lang, categories, smwPending, side )
	return wkRenderLiListFromArg(
		raw,
		wkParseWeblioMarkers,
		function( item )
			return wkRenderOneWeblioLi( pv, item, lang, categories, smwPending, side )
		end
	)
end

local function wkParseVideoMarkers( raw )
	raw = tostring( raw or "" )
	raw = t_trim( raw )
	if raw == "" then
		return {}
	end

	if not raw:find( "⟭", 1, true ) and not raw:find( "⟬", 1, true ) then
		return {}
	end

	local items = {}

	for block in t_gsplit( raw, "⟭", true ) do
		block = t_trim( block )
		if block ~= "" then
			local f = {}
			for part in t_gsplit( block, "⟬", true ) do
				f[ #f + 1 ] = t_trim( tostring( part or "" ) )
			end

			for i = #f + 1, 4 do
				f[ i ] = ""
			end

			items[ #items + 1 ] = {
				title		= f[ 1 ],
				link		= f[ 2 ],
				authors		= f[ 3 ],
				warnings	= f[ 4 ]
			}
		end
	end

	return items
end

local function wkRenderOneVideoLi( pv, item, lang, categories, smwPending )
	local title = t_trim( item.title or "" )
	if title == "" then
		return ""
	end

	local link = t_trim( item.link or "" )
	local authors = t_trim( item.authors or "" )

	local out = {}

	if authors ~= "" then
		out[ #out + 1 ] = authors
	end

	local renderedTitle
	if link ~= "" then
		renderedTitle = "[" .. link .. " " .. title .. "]"
	else
		renderedTitle = title
	end

	out[ #out + 1 ] = ( #out > 0 and ", " or "" ) .. renderedTitle

	renderReferenceItemBanners( item.warnings, pv, lang, categories, out )

	return "<li>" .. table_concat( out ) .. "</li>"
end

local function wkRenderVideoListFromArg( raw, pv, lang, categories, smwPending )
	return wkRenderLiListFromArg(
		raw,
		wkParseVideoMarkers,
		function( item )
			return wkRenderOneVideoLi( pv, item, lang, categories, smwPending )
		end
	)
end

local function renderRefBlock( pageVars, lang, iconClass, titleText, formKey, value )
	local out = {}
	table_insert( out,
		'<h4 class="titre-references wk-icon-before ' .. iconClass .. ' navigation-not-searchable">'
		.. titleText
		.. addDataLink(
			L( lang, "forms", formKey ),
			pageVars.rawTitle,
			L( lang, "text", "edit_label" ),
			L( lang, "text", "section_edit_tt" )
		)
		.. '</h4>'
	)
	if value and t_trim( value ) ~= "" then
		table_insert( out, "<ul>" .. value .. "</ul>" )
	else
		table_insert( out, '<div class="aucune-reference navigation-not-searchable">' .. L( lang, "text", "none_references_msg" ) .. '</div>' )
	end
	return table_concat( out )
end

local function renderReferences( args, pageVars, lang, categories, smwPending, refRaw )
	local wrap = {}

	refRaw = refRaw or {}
	refRaw.bibliography = refRaw.bibliography or {}
	refRaw.webliography = refRaw.webliography or {}
	refRaw.videography = refRaw.videography or {}

	table_insert( wrap, '<h2 class="wk-icon-before wk-icon-further">' .. L( lang, "text", "further_reading_title" ) .. '</h2>' )

	--	Bibliography
	do
		local h3 = '<h3 id="Bibliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-bibliography">'
			.. L( lang, "text", "bibliography_title" ) .. '</h3>'

		local content = {}

		local proRaw = args[ "pro-bibliography" ] or ""
		if proRaw ~= "" then
			refRaw.bibliography.pro = F:preprocess( proRaw )
			proRaw = wkRenderBiblioListFromArg( refRaw.bibliography.pro, pageVars, lang, categories, smwPending )
		else
			refRaw.bibliography.pro = ""
		end

		local conRaw = args[ "con-bibliography" ] or ""
		if conRaw ~= "" then
			refRaw.bibliography.con = F:preprocess( conRaw )
			conRaw = wkRenderBiblioListFromArg( refRaw.bibliography.con, pageVars, lang, categories, smwPending )
		else
			refRaw.bibliography.con = ""
		end

		local neuRaw = args[ "bibliography" ] or ""
		if neuRaw ~= "" then
			refRaw.bibliography.neutral = F:preprocess( neuRaw )
			neuRaw = wkRenderBiblioListFromArg( refRaw.bibliography.neutral, pageVars, lang, categories, smwPending )
		else
			refRaw.bibliography.neutral = ""
		end

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-pro",
			L( lang, "text", "side_pro_title" ),
			"biblio_pro_title",
			proRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-con",
			L( lang, "text", "side_con_title" ),
			"biblio_con_title",
			conRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-neutral mw-no-invert",
			L( lang, "text", "side_neutral_title" ),
			"biblio_neutral_title",
			neuRaw
		) )

		table_insert( wrap, '<div class="fr-collapsible fr-collapsed">' .. h3 .. '<div class="fr-collapsible-content contenu-references">' .. table_concat( content ) .. '</div></div>' )
	end

	--	Webliography
	do
		local h3 = '<h3 id="Webliography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-webliography">'
			.. L( lang, "text", "webliography_title" ) .. '</h3>'

		local content = {}

		local proRaw = args[ "pro-webliography" ] or ""
		if proRaw ~= "" then
			refRaw.webliography.pro = F:preprocess( proRaw )
			proRaw = wkRenderWeblioListFromArg( refRaw.webliography.pro, pageVars, lang, categories, smwPending, "pro" )
		else
			refRaw.webliography.pro = ""
		end

		local conRaw = args[ "con-webliography" ] or ""
		if conRaw ~= "" then
			refRaw.webliography.con = F:preprocess( conRaw )
			conRaw = wkRenderWeblioListFromArg( refRaw.webliography.con, pageVars, lang, categories, smwPending, "con" )
		else
			refRaw.webliography.con = ""
		end

		local neuRaw = args[ "webliography" ] or ""
		if neuRaw ~= "" then
			refRaw.webliography.neutral = F:preprocess( neuRaw )
			neuRaw = wkRenderWeblioListFromArg( refRaw.webliography.neutral, pageVars, lang, categories, smwPending, "neutral" )
		else
			refRaw.webliography.neutral = ""
		end

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-pro",
			L( lang, "text", "side_pro_title" ),
			"webliography_pro_title",
			proRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-con",
			L( lang, "text", "side_con_title" ),
			"webliography_con_title",
			conRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-neutral mw-no-invert",
			L( lang, "text", "side_neutral_title" ),
			"webliography_neutral_title",
			neuRaw
		) )

		table_insert( wrap, '<div class="fr-collapsible fr-collapsed">' .. h3 .. '<div class="fr-collapsible-content contenu-references">' .. table_concat( content ) .. '</div></div>' )
	end

	--	Videography
	do
		local h3 = '<h3 id="Videography" class="fr-collapsible-toggle wk-icon-before wk-carret wk-icon-after wk-icon-videography">'
			.. L( lang, "text", "videography_title" ) .. '</h3>'

		local content = {}

		local proRaw = args[ "pro-videography" ] or ""
		if proRaw ~= "" then
			refRaw.videography.pro = F:preprocess( proRaw )
			proRaw = wkRenderVideoListFromArg( refRaw.videography.pro, pageVars, lang, categories, smwPending )
		else
			refRaw.videography.pro = ""
		end

		local conRaw = args[ "con-videography" ] or ""
		if conRaw ~= "" then
			refRaw.videography.con = F:preprocess( conRaw )
			conRaw = wkRenderVideoListFromArg( refRaw.videography.con, pageVars, lang, categories, smwPending )
		else
			refRaw.videography.con = ""
		end

		local neuRaw = args[ "videography" ] or ""
		if neuRaw ~= "" then
			refRaw.videography.neutral = F:preprocess( neuRaw )
			neuRaw = wkRenderVideoListFromArg( refRaw.videography.neutral, pageVars, lang, categories, smwPending )
		else
			refRaw.videography.neutral = ""
		end

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-pro",
			L( lang, "text", "side_pro_title" ),
			"videography_pro_title",
			proRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-argument is-con",
			L( lang, "text", "side_con_title" ),
			"videography_con_title",
			conRaw
		) )

		table_insert( content, renderRefBlock( pageVars, lang,
			"wk-icon-neutral mw-no-invert",
			L( lang, "text", "side_neutral_title" ),
			"videography_neutral_title",
			neuRaw
		) )

		table_insert( wrap, '<div class="fr-collapsible fr-collapsed">' .. h3 .. '<div class="fr-collapsible-content contenu-references">' .. table_concat( content ) .. '</div></div>' )
	end

	return table_concat( wrap )
end

----------------------------------------------------------------------
--	JSON-LD : helpers QAPage / Breadcrumb / WebSite
----------------------------------------------------------------------

local function wkBuildWikiUrl( lang, prefixKey, labelKey )
	return L( lang, "urls", "wiki_base" )
		.. uri_encode(
			L( lang, "urls", prefixKey ) .. L( lang, "urls", labelKey ),
			"WIKI"
		)
end

local function wkBuildWikiUrlFromText( lang, prefixKey, text )
	text = t_trim( tostring( text or "" ) )
	if text == "" then
		return ""
	end

	return L( lang, "urls", "wiki_base" )
		.. uri_encode(
			L( lang, "urls", prefixKey ) .. text,
			"WIKI"
		)
end

local function wkFullUrl( titleText )
	titleText = t_trim( tostring( titleText or "" ) )
	if titleText == "" then
		return ""
	end
	return tostring( uri_fullUrl( titleText ) )
end

local function wkTakeFirst( items, limit )
	local out = {}
	limit = tonumber( limit ) or 0

	if limit <= 0 then
		return out
	end

	for i = 1, #items do
		if i > limit then
			break
		end
		out[ #out + 1 ] = items[ i ]
	end

	return out
end

local function wkBuildWebsiteNode( lang )
	local homeUrl = L( lang, "urls", "home" )
	local siteName = L( lang, "seo", "site_name" )

	return {
		[ "@type" ] = "WebSite",
		[ "@id" ] = homeUrl .. "#website",
		url = homeUrl,
		name = siteName
	}
end

local function wkBuildOrganizationNode( lang )
	local homeUrl = L( lang, "urls", "home" )
	local siteName = L( lang, "seo", "site_name" )

	return {
		[ "@type" ] = "Organization",
		[ "@id" ] = homeUrl .. "#organization",
		name = siteName,
		url = homeUrl
	}
end

local function wkBuildBreadcrumbNode( pv, lang )
	local homeLabel = L( lang, "urls", "home_label" )
	local homeUrl = L( lang, "urls", "home" )
	local debatesLabel = L( lang, "urls", "debates_label" )
	local debatesUrl = wkBuildWikiUrl( lang, "portal_prefix", "debates_label" )

	return {
		[ "@type" ] = "BreadcrumbList",
		[ "@id" ] = pv.pageUrl .. "#breadcrumb",
		itemListElement = {
			{
				[ "@type" ] = "ListItem",
				position = 1,
				item = {
					[ "@id" ] = homeUrl,
					name = homeLabel
				}
			},
			{
				[ "@type" ] = "ListItem",
				position = 2,
				item = {
					[ "@id" ] = debatesUrl,
					name = debatesLabel
				}
			},
			{
				[ "@type" ] = "ListItem",
				position = 3,
				item = {
					[ "@id" ] = pv.pageUrl,
					name = pv.rawTitle
				}
			}
		}
	}
end

local function wkSchemaPageLabel( it )
	local label = t_trim( tostring( ( it and it.page ) or "" ) )

	if label == "" then
		label = t_trim( tostring( ( it and it.title ) or "" ) )
	end

	return label
end

local function wkBuildAnswerNode( it, kind, lang )

	local pageLabel = t_trim( tostring( ( it and it.page ) or "" ) )
	if pageLabel == "" then
		return nil
	end

	local answerName = t_trim( tostring( ( it and it.title ) or "" ) )
	if answerName == "" then
		answerName = pageLabel
	end

	local url = wkFullUrl( pageLabel )
	if url == "" then
		return nil
	end

	local additionalType
	if kind == "pro" then
		additionalType = wkBuildWikiUrl( lang, "property_prefix", "pro_label" )
	else
		additionalType = wkBuildWikiUrl( lang, "property_prefix", "con_label" )
	end

	return {
		[ "@type" ] = "Answer",
		[ "@id" ] = url .. "#answer",
		url = url,

		name = answerName,
		text = pageLabel,

		author = {
			[ "@id" ] = L( lang, "urls", "home" ) .. "#organization"
		},

		additionalType = additionalType,

		about = {
			[ "@type" ] = "Claim",
			[ "@id" ] = url .. "#claim"
		}
	}
end

local function wkBuildSuggestedAnswers( maps, lang )
	local out = {}
	local position = 1

	local proItems = ( maps and maps.pro and maps.pro.items ) or {}
	local conItems = ( maps and maps.con and maps.con.items ) or {}

	for _, it in ipairs( wkTakeFirst( proItems, 3 ) ) do
		local node = wkBuildAnswerNode( it, "pro", lang )
		if node then
			out[ #out + 1 ] = node
			position = position + 1
		end
	end

	for _, it in ipairs( wkTakeFirst( conItems, 3 ) ) do
		local node = wkBuildAnswerNode( it, "con", lang )
		if node then
			out[ #out + 1 ] = node
			position = position + 1
		end
	end

	return out
end

local function wkBuildIsPartOf( lang )
	local out = {}

	out[ #out + 1 ] = {
		[ "@id" ] = wkBuildWikiUrl( lang, "category_prefix", "debates_label" )
	}

	out[ #out + 1 ] = {
		[ "@id" ] = L( lang, "urls", "home" ) .. "#website"
	}

	return out
end

local function wkBuildIsRelatedTo( args )
	local out = {}
	local raw = t_trim( tostring( ( args and args[ "related-debates" ] ) or "" ) )

	if raw == "" then
		return out
	end

	local count = 0
	for part in t_gsplit( raw, ITEM_SEP, true ) do
		part = t_trim( tostring( part or "" ) )
		if part ~= "" then
			count = count + 1
			if count > 5 then
				break
			end

			local url = wkFullUrl( part )
			if url ~= "" then
				out[ #out + 1 ] = {
					[ "@id" ] = url .. "#qa"
				}
			end
		end
	end

	return out
end

local function wkBuildKeywords( args, lang )
	local out = {}
	local sections = splitCSV( args and args[ "sections" ] or "", "," )
	local termSetId = wkBuildWikiUrl( lang, "category_prefix", "sections_label" )

	for i = 1, #sections do
		if i > 4 then
			break
		end

		local section = t_trim( tostring( sections[ i ] or "" ) )
		if section ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "DefinedTerm",
				[ "@id" ] = wkBuildWikiUrlFromText( lang, "category_prefix", section ),
				name = section,
				inDefinedTermSet = {
					[ "@id" ] = termSetId
				}
			}
		end
	end

	return out
end

local function wkBuildAboutAndMentions( args )
	local about = {}
	local mentions = {}

	local keywords = splitCSV( args and args[ "keywords" ] or "", "," )

	for i = 1, #keywords do
		local kw = t_trim( tostring( keywords[ i ] or "" ) )
		if kw ~= "" then
			local node = {
				[ "@type" ] = "Thing",
				name = kw
			}

			if i <= 2 then
				about[ #about + 1 ] = node
			else
				mentions[ #mentions + 1 ] = node
			end
		end
	end

	return about, mentions
end

local function wkBuildDiscussionUrl( pv, lang )
	local prefix = L( lang, "urls", "discussion_prefix" )
	return wkBuildWikiUrlFromText( lang, "discussion_prefix", pv.rawTitle )
end

local function tagHtmlTag(tagName, content, attrs)
	local src = "{{#tag:htmltag|" .. (content or "") .. "|tagname=" .. tostring(tagName)

	if attrs then
		local keys = {}
		local n = 0
		for k in pairs(attrs) do
			n = n + 1
			keys[n] = k
		end

		if n > 0 then
			table_sort(keys)
			for i = 1, n do
				local k = keys[i]
				src = src .. "|" .. tostring(k) .. "=" .. tostring(attrs[k])
			end
		end
	end

	src = src .. "}}"
	return F:preprocess(src)
end

local function wkProtectJsonLdForParser( json )
	if not json or json == "" then
		return ""
	end

	json = tostring( json )

	--	protéger les séparateurs de parser function
	json = json:gsub( "|", "&#124;" )
	json = json:gsub( "=", "&#61;" )
	json = json:gsub( "/", "\\/" )

	--	éviter de fermer prématurément {{#tag:...}}
	json = json:gsub( "}}", "} }" )

	return json
end

local function wkBuildQuoteAuthorValue( authors )
	authors = t_trim( tostring( authors or "" ) )
	if authors == "" then
		return nil
	end

	local out = {}

	for a in t_gsplit( authors, ",", true ) do
		a = t_trim( tostring( a or "" ) )
		if a ~= "" then
			out[ #out + 1 ] = {
				[ "@type" ] = "Person",
				name = a
			}
		end
	end

	if #out == 0 then
		return nil
	end

	if #out == 1 then
		return out[ 1 ]
	end

	return out
end

local function wkBuildSingleNodeFromFirstItem( items, builder )
	local out = {}

	for i = 1, #( items or {} ) do
		local node = builder( items[ i ] )
		if node then
			out[ 1 ] = node
			break
		end
	end

	return out
end

local function wkBuildClaimCitationsFromBibliography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseBiblioMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local authors = t_trim( tostring( item.authors or "" ) )
		local article = t_trim( tostring( item.article or "" ) )
		local work = t_trim( tostring( item.work or "" ) )
		local volume = t_trim( tostring( item.volume or "" ) )
		local number = t_trim( tostring( item.number or "" ) )
		local publisher = t_trim( tostring( item.publisher or "" ) )
		local date = t_trim( tostring( item.date or "" ) )
		local link = t_trim( tostring( item.link or "" ) )

		local node = nil

		if number ~= "" and article ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Article",
				name = article,
				isPartOf = {
					[ "@type" ] = "Periodical",
					name = work
				}
			}

			if volume ~= "" then
				node.volumeNumber = volume
			end

			node.issueNumber = number

		elseif publisher ~= "" and work ~= "" then
			node = {
				[ "@type" ] = "Book",
				name = work
			}

			if article ~= "" then
				node.description = article
			end

			if volume ~= "" then
				node.volumeNumber = volume
			end

			if number ~= "" then
				node.issueNumber = number
			end

		elseif link ~= "" and article ~= "" then
			node = {
				[ "@type" ] = "WebPage",
				name = article
			}

			if work ~= "" then
				node.isPartOf = {
					[ "@type" ] = "WebSite",
					name = work
				}
			end
		end

		if not node then
			return nil
		end

		local authorValue = wkBuildQuoteAuthorValue( authors )
		if authorValue then
			node.author = authorValue
		end

		if publisher ~= "" then
			node.publisher = {
				[ "@type" ] = "Organization",
				name = publisher
			}
		end

		if date ~= "" then
			node.datePublished = date
		end

		if link ~= "" then
			node.url = link
		end

		return node
	end )
end

local function wkBuildClaimCitationsFromWebliography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseWeblioMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local page = t_trim( tostring( item.page or "" ) )
		local site = t_trim( tostring( item.site or "" ) )
		local link = t_trim( tostring( item.link or "" ) )
		local date = t_trim( tostring( item.date or "" ) )

		if link == "" or page == "" then
			return nil
		end

		local node = {
			[ "@type" ] = "WebPage",
			name = page,
			url = link
		}

		if site ~= "" then
			node.isPartOf = {
				[ "@type" ] = "WebSite",
				name = site
			}
		end

		if date ~= "" then
			node.datePublished = date
		end

		return node
	end )
end

local function wkBuildClaimCitationsFromVideography( raw )
	raw = t_trim( tostring( raw or "" ) )
	if raw == "" then
		return {}
	end

	local items = wkParseVideoMarkers( raw )

	return wkBuildSingleNodeFromFirstItem( items, function( item )
		local title = t_trim( tostring( item.title or "" ) )
		local link = t_trim( tostring( item.link or "" ) )
		local authors = t_trim( tostring( item.authors or "" ) )

		if title == "" or link == "" then
			return nil
		end

		local node = {
			[ "@type" ] = "VideoObject",
			name = title,
			url = link
		}

		local authorValue = wkBuildQuoteAuthorValue( authors )
		if authorValue then
			node.author = authorValue
		end

		return node
	end )
end

local function renderDebateSchemaJSONLD( pv, lang, args, maps, refRaw )
	local websiteNode = wkBuildWebsiteNode( lang )
	local breadcrumbNode = wkBuildBreadcrumbNode( pv, lang )
	local organizationNode	= wkBuildOrganizationNode( lang )
	local about, mentions = wkBuildAboutAndMentions( args )
	local keywords = wkBuildKeywords( args, lang )
	local suggestedAnswer = wkBuildSuggestedAnswers( maps, lang )
	local isPartOf = wkBuildIsPartOf( lang )
	local isRelatedTo = wkBuildIsRelatedTo( args )
	local pageCreation = getPageCreation( pv, args )
	local questionText
	
	if args[ "expanded-topic" ] and t_trim( args[ "expanded-topic" ] ) ~= "" then
		questionText = string_format(
			L( lang, "text", "faq_q" ),
			t_trim( args[ "expanded-topic" ] )
		)
	else
		questionText = pv.rawTitle
	end

	refRaw = refRaw or {}
	refRaw.bibliography = refRaw.bibliography or {}
	refRaw.webliography = refRaw.webliography or {}
	refRaw.videography = refRaw.videography or {}

	local bibliographyRaw = ""
	if refRaw.bibliography.pro ~= "" then
		bibliographyRaw = refRaw.bibliography.pro
	end
	if refRaw.bibliography.con ~= "" then
		if bibliographyRaw ~= "" then
			bibliographyRaw = bibliographyRaw .. ITEM_SEP
		end
		bibliographyRaw = bibliographyRaw .. refRaw.bibliography.con
	end
	if refRaw.bibliography.neutral ~= "" then
		if bibliographyRaw ~= "" then
			bibliographyRaw = bibliographyRaw .. ITEM_SEP
		end
		bibliographyRaw = bibliographyRaw .. refRaw.bibliography.neutral
	end

	local webliographyRaw = ""
	if refRaw.webliography.pro ~= "" then
		webliographyRaw = refRaw.webliography.pro
	end
	if refRaw.webliography.con ~= "" then
		if webliographyRaw ~= "" then
			webliographyRaw = webliographyRaw .. ITEM_SEP
		end
		webliographyRaw = webliographyRaw .. refRaw.webliography.con
	end
	if refRaw.webliography.neutral ~= "" then
		if webliographyRaw ~= "" then
			webliographyRaw = webliographyRaw .. ITEM_SEP
		end
		webliographyRaw = webliographyRaw .. refRaw.webliography.neutral
	end

	local videographyRaw = ""
	if refRaw.videography.pro ~= "" then
		videographyRaw = refRaw.videography.pro
	end
	if refRaw.videography.con ~= "" then
		if videographyRaw ~= "" then
			videographyRaw = videographyRaw .. ITEM_SEP
		end
		videographyRaw = videographyRaw .. refRaw.videography.con
	end
	if refRaw.videography.neutral ~= "" then
		if videographyRaw ~= "" then
			videographyRaw = videographyRaw .. ITEM_SEP
		end
		videographyRaw = videographyRaw .. refRaw.videography.neutral
	end

	local citations = {}

	local b = wkBuildClaimCitationsFromBibliography( bibliographyRaw )
	if b[ 1 ] then
		citations[ #citations + 1 ] = b[ 1 ]
	end

	local w = wkBuildClaimCitationsFromWebliography( webliographyRaw )
	if w[ 1 ] then
		citations[ #citations + 1 ] = w[ 1 ]
	end

	local subjectOf = wkBuildClaimCitationsFromVideography( videographyRaw )

	local qaNode = {
		[ "@type" ] = "QAPage",
		[ "@id" ] = pv.pageUrl .. "#qa",
		url = pv.pageUrl,
		name = pv.rawTitle,
		inLanguage = lang,
		educationalUse = {
			"debate",
			"critical thinking"
		},
		learningResourceType = "debate",
		audience = {
			[ "@type" ] = "EducationalAudience",
			educationalLevel = {
				"general public",
				"secondary education"
			}
		},
		isPartOf = isPartOf,
		breadcrumb = {
			[ "@id" ] = pv.pageUrl .. "#breadcrumb"
		},
		discussionUrl = wkBuildDiscussionUrl( pv, lang ),
		publisher = {
			[ "@id" ] = L( lang, "urls", "home" ) .. "#organization"
		},
		mainEntity = {
			[ "@type" ] = "Question",
			[ "@id" ] = pv.pageUrl .. "#question",
			name = pv.rawTitle,
			text = questionText,
			answerCount = #suggestedAnswer,
			suggestedAnswer = suggestedAnswer
		}
	}

	if pageCreation and pageCreation ~= "" then
		qaNode.mainEntity.datePublished = pageCreation
	end

	if #isRelatedTo > 0 then
		qaNode.isRelatedTo = isRelatedTo
	end

	if #keywords > 0 then
		qaNode.keywords = keywords
	end

	if #about > 0 then
		qaNode.about = about
	end

	if #mentions > 0 then
		qaNode.mentions = mentions
	end

	if #citations > 0 then
		qaNode.citation = citations
	end

	if subjectOf[ 1 ] then
		qaNode.subjectOf = subjectOf[ 1 ]
	end

	local data = {
		[ "@context" ] = "https://schema.org",
		[ "@graph" ] = {
			websiteNode,
			organizationNode,
			breadcrumbNode,
			qaNode
		}
	}

	local json = t_jsonEncode( data )

	return tagHtmlTag( "script", wkProtectJsonLdForParser( json ), {
		type = "application/ld+json",
		["class"] = "navigation-not-searchable"
	} )
end

----------------------------------------------------------------------
--	Champs divers (mots-clés, rubriques, connexes, interlangue)
----------------------------------------------------------------------

local function renderKeywords( args, pv, cats, lang )
	local out = {}
	table_insert( out, '<div style="font-size: 95%; margin-top: 1em;">' .. L( lang, "text", "keywords_label_text" ) )

	if args[ "keywords" ] and t_trim( args[ "keywords" ] ) ~= "" then
		local rendered = {}

		local formName = L( lang, "forms", "search_by_keywords_title" )
		local fieldLabel = formName
		local fieldName = L( lang, "params", "keywords_field" )
		local tt = L( lang, "text", "search_by_keyword_tt" )

		local typeField = L( lang, "params", "search_type_field" )
		local typeValue = L( lang, "params", "search_type_value" )

		local extra = {
			[ fieldLabel .. "[" .. typeField .. "]" ] = typeValue
		}

		for _, k in ipairs( splitCSV( args[ "keywords" ], "," ) ) do
			k = t_trim( tostring( k or "" ) )
			if k ~= "" then
				local chip = runQueryLink( formName, fieldLabel, fieldName, k, k, tt, extra )
				if not chip or chip == "" then
					chip = k
				end
				if chip ~= "" then
					table_insert( rendered, chip )
				end
			end
		end

		if #rendered > 0 then
			table_insert( out, table_concat( rendered, ", " ) )
		else
			table_insert( out, "<strong>" .. L( lang, "text", "none_label" ) .. "</strong>" )
			table_insert( cats, "[[Category:" .. L( lang, "categories", "keywords_missing" ) .. "]]" )
		end
	else
		table_insert( out, "<strong>" .. L( lang, "text", "none_label" ) .. "</strong>" )
		table_insert( cats, "[[Category:" .. L( lang, "categories", "keywords_missing" ) .. "]]" )
	end

	table_insert( out,
		addDataLink(
			L( lang, "forms", "keywords_edit_title" ),
			pv.rawTitle,
			L( lang, "text", "edit_label" ),
			L( lang, "text", "keywords_edit_tt" )
		)
		.. '<span style="display: none;">.</span></div>'
	)

	return table_concat( out )
end

local function renderConnexes( args, pv, cats, lang )
	local h2 =
		'<h2 class="section-modifiable wk-icon-before wk-icon-parent">'
		.. L( lang, "forms", "related_edit_title" )
		.. addDataLink(
			L( lang, "forms", "related_edit_title" ),
			pv.rawTitle,
			L( lang, "text", "edit_label" ),
			L( lang, "text", "related_edit_tt" )
		)
		.. '</h2>'

	local raw = args[ "related-debates" ]
	if raw and t_trim( raw ) ~= "" then
		local items = {}
		local n = 0

		for part in t_gsplit( raw, ITEM_SEP, true ) do
			part = t_trim( tostring( part or "" ) )
			if part ~= "" then
				n = n + 1
				items[ n ] = '<li style="position: relative;"><span class="hover-map">[[' .. part .. ']]</span></li>'
			end
		end

		if n > 0 then
			return h2 .. '<ul class="hover-top searchaux">' .. table_concat( items ) .. '</ul>'
		end
	end

	table_insert( cats, "[[Category:" .. L( lang, "categories", "related_missing" ) .. "]]" )
	return h2 .. '<div class="aucun-contenu navigation-not-searchable">' .. L( lang, "text", "none_related_msg" ) .. '</div>'
end

local function renderRubriques( args, cats, lang )
	if args[ "sections" ] and t_trim( args[ "sections" ] ) ~= "" then
		local out = {}
		for _, r in ipairs( splitCSV( args[ "sections" ], "," ) ) do
			table_insert( out, "[[Category:" .. r .. "]]" )
		end
		return table_concat( out )
	end
	return "[[Category:" .. L( lang, "categories", "sections_missing" ) .. "]]"
end

----------------------------------------------------------------------
--	SMW (sans legacy)
----------------------------------------------------------------------

local function setSemanticData( args, pv, lang, maps, smwPending )
	maps = maps or {}
	local pro = maps.pro or {}
	local con = maps.con or {}

	local pourPages = {}
	local contrePages = {}

	for _, it in ipairs( pro.items or {} ) do
		if it.page and it.page ~= "" then
			table_insert( pourPages, it.page )
		end
	end

	for _, it in ipairs( con.items or {} ) do
		if it.page and it.page ~= "" then
			table_insert( contrePages, it.page )
		end
	end

	local props = {}

	local pourSurvol = t_trim( pro.mapHover or "" )
	local contreSurvol = t_trim( con.mapHover or "" )

	if pourSurvol == "" then
		pourSurvol = '<div class="carte-vide">' .. L( lang, "text", "none_pro_args_msg_short" ) .. '</div>'
	end
	if contreSurvol == "" then
		contreSurvol = '<div class="carte-vide">' .. L( lang, "text", "none_con_args_msg_short" ) .. '</div>'
	end

	local listePour = t_trim( pro.argsVar or "" )
	local listeContre = t_trim( con.argsVar or "" )

	props[ smwProp( lang, "debate_name" ) ]		= pv.rawTitle
	props[ smwProp( lang, "debate_number" ) ]	= pv.pageId
	props[ smwProp( lang, "breadcrumb" ) ]		= pv.rawTitle
	props[ smwProp( lang, "parent_debate" ) ]	= pv.rawTitle
	props[ smwProp( lang, "pro_list" ) ]		= listePour
	props[ smwProp( lang, "con_list" ) ]		= listeContre

	props[ smwProp( lang, "arg_map" ) ] =
		'<div class="wk-arg-map navigation-not-searchable">'
			.. '<div class="wk-arg-map__col is-pro">'
				.. '<div class="wk-arg-map__th">' .. L( lang, "text", "arg_map_pro_label" ) .. '</div>'
				.. '<div class="wk-arg-map__td">' .. pourSurvol .. '</div>'
			.. '</div>'
			.. '<div class="wk-arg-map__col is-con">'
				.. '<div class="wk-arg-map__th">' .. L( lang, "text", "arg_map_con_label" ) .. '</div>'
				.. '<div class="wk-arg-map__td">' .. contreSurvol .. '</div>'
			.. '</div>'
		.. '</div>'

	if #pourPages > 0 then
		props[ smwProp( lang, "arg_pro" ) ] = pourPages
	end

	if #contrePages > 0 then
		props[ smwProp( lang, "arg_con" ) ] = contrePages
	end

	if smwPending then
		smwQueueMerge( props, smwPending )
	end

	smwSetSafe( props )
end

----------------------------------------------------------------------
--	Rendu principal
----------------------------------------------------------------------

function p.render( frame )
	for k in pairs( SEO_CACHE ) do
		SEO_CACHE[ k ] = nil
	end

	local args	= getArgs( frame )
	local lang	= detectLang( args )
	local pv	= computePageVars()
	local pieces, cats = {}, {}
	local smwPending = {}
	local refRaw = {
		bibliography = {},
		webliography = {},
		videography = {}
	}

	smwQueueAddFromCSV(
		smwPending,
		smwProp( lang, "keyword" ),
		args[ "keywords" ]
	)

	local maps = computeArgumentMapsFromArgs( args, lang )

	table_insert( cats, "[[Category:" .. L( lang, "categories", "debates" ) .. "]]" )

	if args[ "topic" ] and t_trim( args[ "topic" ] ) ~= "" then
		smwQueueAdd( smwPending, smwProp( lang, "debate_topic" ), args[ "topic" ], false )
	else
		table_insert( cats, "[[Category:" .. L( lang, "categories", "topic_missing" ) .. "]]" )
	end

	if args[ "expanded-topic" ] and t_trim( args[ "expanded-topic" ] ) ~= "" then
		table_insert( pieces, F:preprocess( "{{SHORTDESC: " .. string_format( L( lang, "text", "shortdesc_topic" ), args[ "expanded-topic" ] ) .. "}}" ) )
	else
		table_insert( cats, "[[Category:" .. L( lang, "categories", "topic_complete_missing" ) .. "]]" )
		table_insert( pieces, F:preprocess( "{{SHORTDESC: " .. L( lang, "text", "shortdesc_plain" ) .. "}}" ) )
	end

	if not args["creation-date"] or t_trim(args["creation-date"]) == "" then
		table_insert(cats, "[[Category:" .. L(lang, "categories", "creation_date_missing") .. "]]")
	end

	table_insert( pieces, renderArgumentMapTop( lang, maps ) )
	table_insert( pieces, renderKeywords( args, pv, cats, lang ) )

	local btnForm = addDataLink(
		L( lang, "forms", "banners_edit_title" ),
		pv.rawTitle,
		L( lang, "text", "edit_label" ),
		L( lang, "text", "debate_banners_edit_tt" )
	)
	table_insert( pieces, '<h2 class="section-modifiable wk-icon-before wk-icon-flag">' .. L( lang, "text", "warnings_title" ) ..  btnForm .. '</h2>' )
	table_insert( pieces, renderMainBanner( F, pv, lang ) )
	table_insert( pieces, renderWarningBanners( args, pv, cats, lang ) )

	table_insert( pieces, renderIntroduction( args, pv, lang, cats ) )
	table_insert( pieces, renderWikipediaLinks( args, pv, lang ) )
	table_insert( pieces, renderArgsList( args, pv, lang, "pro", maps, cats ) )
	table_insert( pieces, renderArgsList( args, pv, lang, "con", maps, cats ) )

	if not isProgress( lang, args[ "progress" ], "draft" ) then
		local target = L( lang, "text", "self_eval_ns_label" ) .. ":" .. pv.rawTitle .. " (ID)"

		local ns			= L( lang, "text", "self_eval_ns_label" )
		local f_debat	= L( lang, "text", "self_eval_debate_field" )

		local box = {}
		table_insert( box, '<h2 class="navigation-not-searchable noprint wk-icon-before wk-icon-selfeval">' .. L( lang, "text", "self_eval_title" ) .. '</h2>' )

		local startBtn = wkAddDataGetButton(
			L( lang, "forms", "self_eval_home_title" ),
			target,
			L( lang, "text", "self_eval_start_label" ),
			L( lang, "text", "self_eval_start_tt" ),
			{
				[ ns .. "[" .. f_debat .. "]" ]	= pv.rawTitle
			}
		)
		table_insert(
			box,
			'<div class="boite-info navigation-not-searchable noprint">'
			.. L( lang, "text", "self_eval_blurb" )
			.. startBtn
			.. '</div>'
		)

		table_insert( pieces, table_concat( box ) )
	end

	table_insert( pieces, renderReferences( args, pv, lang, cats, smwPending, refRaw ) )
	table_insert( pieces, renderConnexes( args, pv, cats, lang ) )

	table_insert( pieces,
		'<h2 class="latest-changes-button navigation-not-searchable noprint wk-icon-before wk-icon-refresh">'
		.. L( lang, "text", "latest_changes_title" )
		.. '</h2>'
		.. '<div class="latest-changes navigation-not-searchable noprint">'
		.. '<div class="latest-changes-button wk-btn mw-ui-button"'
		.. ' title="' .. L( lang, "text", "latest_changes_show_tt" ) .. '"'
		.. ' data-page="' .. t_nowiki( pv.rawTitle ) .. '">'
		.. L( lang, "text", "latest_changes_show_label" )
		.. '</div>'
		.. '<div class="latest-changes-wrapper"></div>'
		.. '</div>'
	)

	table_insert( pieces, renderRubriques( args, cats, lang ) )

	setSemanticData( args, pv, lang, maps, smwPending )

	renderFullSEO( args, pv, lang )
	table_insert( pieces, table_concat( cats ) )

	local renameLink = string_format( L( lang, "text", "rename_special_page_text" ), pv.encoded )
	table_insert( pieces,
		'<span id="bouton-renommer" class="bouton-modifier navigation-not-searchable noprint" style="display: none;">[['
		.. renameLink .. '|' .. L( lang, "text", "rename_link_label" ) .. ']]</span>'
	)

	table_insert( pieces, '<span id="bouton-modifier-sujet" style="display: none;">'
		.. addDataLink( L( lang, "forms", "topic_edit_title" ), pv.rawTitle, L( lang, "text", "edit_label" ), L( lang, "text", "topic_edit_tt" ) )
		.. '</span>' )
	table_insert( pieces, '<span id="bouton-modifier-categories" style="display: none;">'
		.. addDataLink( L( lang, "forms", "sections_edit_title" ), pv.rawTitle, L( lang, "text", "edit_label" ), L( lang, "text", "sections_edit_tt" ) )
		.. '</span>' )
	if args["interlanguage"] and t_trim( tostring( args["interlanguage"] or "" ) ) ~= "" then
		table_insert( pieces, args[ "interlanguage" ] )
		table_insert( pieces,
			'<span id="bouton-modifier-interlangue" class="bouton-modifier navigation-not-searchable noprint" style="display: none;"'
			.. ' data-wk-tooltip="' .. escapeAttr( L( lang, "text", "interlanguage_edit_tt" ) ) .. '">'
			.. addDataLink(
				L( lang, "forms", "interlanguage_edit_title" ),
				pv.rawTitle,
				L( lang, "text", "edit_label" )
			)
			.. '</span>'
		)
	end

	table_insert( pieces, renderDebateSchemaJSONLD( pv, lang, args, maps, refRaw ) )

	return table_concat( pieces, "" )
end

return p
