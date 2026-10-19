#!/usr/bin/env python3
"""Lexical scope cases that broad capture pruning can easily get wrong."""
import sys
from pathlib import Path
from luaparser import ast

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'tools'))
from closureCaptures import free_variables

cases = [
	('function(p) local x = p; return x + outer end', {'outer'}),
	('function() local x = outer; do local outer = 1; x = x + outer end; return outer end', {'outer'}),
	('function() local x = x; return x end', {'x'}),
	('function() for key, value in pairs(items) do sink(value) end; return key end', {'pairs', 'items', 'sink', 'key'}),
	('function() return {chain = "x", [key] = outer} end', {'key', 'outer'}),
	('function() return object:method(value) end', {'object', 'value'}),
	('function(p) local value = p; local reader = function() return value + outer end; return reader end', {'outer'}),
	('function() local function loop(n) if n == 0 then return seed end; return loop(n - 1) end; return loop end', {'seed'}),
	('function() shared = shared + 1; return shared end', {'shared'}),
	('function() for i = start, stop, step do sink(i) end end', {'start', 'stop', 'step', 'sink'}),
]
for text, expected in cases:
	function = ast.parse('return ' + text).body.body[0].values[0]
	actual = free_variables(function)
	assert actual == expected, (text, expected, actual)
print(str(len(cases)) + ' closure scope cases passed: recursion, nesting, shadowing, initializers, field names and shared writes')
