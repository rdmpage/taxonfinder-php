// Run the original node-taxonfinder over a corpus and print one JSON result
// per line, in the same shape as tools/dump.php. Used by tools/compare.sh.
//
//   node tools/dump.js /tmp/corpus.txt [--html] [--taxonfinder ~/Development/node-taxonfinder]

var fs = require('fs');
var path = require('path');

var args = process.argv.slice(2);
var file = args[0];
var isHtml = args.indexOf('--html') !== -1;
var libIndex = args.indexOf('--taxonfinder');
var libPath = libIndex === -1 ?
  path.join(process.env.HOME, 'Development', 'node-taxonfinder') : args[libIndex + 1];

var dictionaries = require(path.join(libPath, 'lib', 'dictionaries.js'));
var parser = require(path.join(libPath, 'lib', 'parser.js'));
dictionaries.load();

var lines = fs.readFileSync(file, 'utf8').split('\n');
if (lines[lines.length - 1] === '') lines.pop();

var out = [];
lines.forEach(function(line) {
  var document = line.replace(/\\n/g, '\n');
  var results = parser.findNamesAndOffsets(document, isHtml);
  out.push(JSON.stringify(results.map(function(result) {
    return [ result['name'], result['offsets'][0], result['offsets'][1],
             result['original'] === undefined ? null : result['original'] ];
  })));
});
process.stdout.write(out.join('\n') + '\n');
