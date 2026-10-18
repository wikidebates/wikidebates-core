local p = {}
function p.setupInterface()
	local php = mw_interface
	mw_interface = nil
	mw.smw = { set = function(data) return php.set(data) end }
	mw.debateTest = { trace = function(input, output) return php.trace(input, output) end }
end
return p
