"use strict";

var esprima = require('esprima');
var esgraph = require('esgraph');

var ast = esprima.parse(PHP.code, {loc: true, range: true});
var cfg = esgraph(ast);

cfg[2].forEach(function(flowNode) {
    flowNode.id = Math.random();
});

cfg;
