local p = {}

function p.write(frame)
	local variant = frame.args[1]
	if variant == 'initial' then
		mw.smw.set({
			['WD FS Text'] = {'first', 'A|B=C & é', '0'},
			['WD FS Number'] = 42,
			['WD FS Date'] = '2026-01-01',
			['WD FS Page'] = 'WD FS target'
		})
	elseif variant == 'updated' then
		mw.smw.set({
			['WD FS Text'] = 'updated',
			['WD FS Number'] = 0,
			['WD FS Date'] = '2026-02-02',
			['WD FS Page'] = 'WD FS replacement'
		})
	end
	return ''
end

function p.read()
	return mw.text.jsonEncode(mw.smw.ask({
		'[[WD FS Text::updated]]', '?WD FS Text=text', '?WD FS Number=number',
		'?WD FS Page=target', 'mainlabel=page', 'link=none', 'limit=10'
	}))
end

function p.variables()
	local v = mw.ext.VariablesLua
	v.vardefine('WD FS shared', 'Lua → PHP')
	v.vardefine('WD FS zero', '0')
	return v.var('WD FS shared') .. '/' .. v.var('WD FS zero')
end

function p.get(frame)
	return mw.ext.VariablesLua.var(frame.args[1], 'MISSING')
end

return p
