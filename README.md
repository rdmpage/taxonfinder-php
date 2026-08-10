# taxonfinder-php

Finds scientific names in text. A PHP 7 port of Patrick Leary's
[node-taxonfinder](https://github.com/pleary/node-taxonfinder), dictionaries and
all. No web server, no service, no dependencies — just a function you call.

```php
require '/path/to/taxonfinder-php/autoload.php';

$names = taxonfinder_find('Wow, Felis leo rocks');
// array(
//   array('name' => 'Felis leo', 'offsets' => array(5, 14)),
// )
```

## Installing

Clone it and require `autoload.php`. That's the whole installation.

```php
require '/path/to/taxonfinder-php/autoload.php';
```

Or with Composer, if you prefer:

```
composer require rdmpage/taxonfinder
```

Needs PHP 7.1 or later (tested on 7.4 and 8.x). `ext-mbstring` is optional and
only affects lowercasing of accented characters.

## Using it

The three convenience functions cover most needs:

```php
taxonfinder_find($text, $isHtml = false);   // names with offsets
taxonfinder_names($text, $isHtml = false);  // just the unique names
taxonfinder_tag($text, $isHtml = false);    // $text with <name> elements added
```

`taxonfinder_find()` returns one entry per name found:

```php
taxonfinder_find('Pomatomus; P. saltator');
// array(
//   array('name' => 'Pomatomus',          'offsets' => array(0, 9)),
//   array('name' => 'Pomatomus saltator', 'offsets' => array(11, 22),
//         'original' => 'P. saltator'),
// )
```

`offsets` are byte offsets into the string you passed in, so
`substr($text, $start, $end - $start)` gets you the text that was matched.
`original` only appears when an abbreviated genus was expanded from an earlier
mention.

Pass `true` as the second argument when the input is HTML. Tags are then
stripped before parsing, and `<p>`, `<td>`, `<tr>`, `<table>`, `<hr>`, `<ul>`
and `<li>` stop a name from running across them.

If you are processing many documents, keep one `Finder` so the dictionaries are
only loaded once:

```php
$finder = new Taxonfinder\Finder();
foreach ($documents as $document) {
    $names = $finder->find($document);
}
```

### From the command line

```
bin/taxonfinder paper.txt              # name, start, end, original (tab separated)
bin/taxonfinder --names paper.txt      # just the unique names
bin/taxonfinder --json paper.txt
bin/taxonfinder --html --tag page.html
cat paper.txt | bin/taxonfinder
```

## The dictionaries

Almost all of taxonfinder's behaviour comes from the plain text files in
`dictionaries/`, one term per line:

| file | what it holds |
| --- | --- |
| `genera.txt` | genus names (437,063) |
| `species.txt` | species epithets (660,012) |
| `family.txt` | family names and above (55,231) |
| `genera_new.txt`, `species_new.txt`, `family_new.txt` | somewhere to put additions |
| `genera_family.txt` | names that are both a genus and a family; treated as a family |
| `ranks.txt` | rank words — `var`, `subsp`, `sp`, … |
| `dict_ambig.txt` | names that are also everyday words, scored lower |
| `overlap_new.txt` | words that look like names but never are — `Goliath`, `Data` |
| `species_bad.txt` | epithets never to accept — `phobia`, `drill` |
| `dict_bad.txt` | whole names never to report — `Stella marina` |

Case and order don't matter, blank lines are ignored, duplicates are fine.

### Adding your own names

Three ways, in increasing order of intrusiveness:

**1. Append to the `_new` files.** They exist for exactly this.

```
echo 'Spamalotus' >> dictionaries/genera_new.txt
echo 'montypythonae' >> dictionaries/species_new.txt
```

**2. Keep your additions separate, in `dictionaries/local/`.** Any dictionary
file you put there is merged on top of the shipped ones, so your edits survive
a `git pull`.

```
mkdir -p dictionaries/local
echo 'Spamalotus' >> dictionaries/local/genera_new.txt
```

**3. At runtime**, when the terms come from a database or an API:

```php
$finder = new Taxonfinder\Finder();
$finder->dictionaries()->add('genera_new', 'Spamalotus');
$finder->dictionaries()->addTerms('species_new', array('montypythonae', 'cleesei'));
$finder->dictionaries()->addFile('genera_new', '/tmp/my-genera.txt');
$finder->dictionaries()->remove('overlap_new', 'goliath');  // for this process only
```

Or point it at a completely different set of files:

```php
$finder = new Taxonfinder\Finder(new Taxonfinder\Dictionaries('/my/dictionaries'));
```

### Why there is a cache directory

`genera.txt` and `species.txt` hold 1.1 million terms between them. Loading
those into a PHP hash costs about 110 MB, which is more than PHP's default
`memory_limit` of 128M. So each dictionary is compiled once into a sorted index
under `dictionaries/cache/` and searched with a binary search: about 14 MB of
memory, 4 ms to load, a few microseconds per lookup.

You never have to think about it. Edit a dictionary and the index rebuilds
itself on the next run (a second or so for the big files). If
`dictionaries/cache/` isn't writable the system temp directory is used instead.
`$finder->dictionaries()->clearCache()` forces a rebuild.

## Differences from node-taxonfinder

The port is deliberately faithful, down to the regular expressions. It is
checked against the original by running both over a few thousand generated
documents, in plain text and in HTML mode, plus any real documents you name:

```
tools/compare.sh [path-to-node-taxonfinder] [real-text-file ...]
```

Every name is classified individually, and anything not on the list below fails
the run. The two implementations find the same names in every document; four
kinds of difference are deliberate.

**Offsets are byte offsets, not UTF-16 offsets.** For ASCII text the two are
identical. For text containing an em dash or an accented letter they drift
apart, because JavaScript counts UTF-16 code units. Byte offsets are what PHP's
`substr()` wants. `tools/compare.php` converts between the two and checks they
agree exactly.

**Names that run to the end of the text** get a real end offset. The JavaScript
reads it off a sentinel that has no offset and returns `NaN`.

**Leading punctuation is skipped completely.** The JavaScript skips a single
character before the name, which is one character short in `Upolu :—Vailima`,
and lands in the middle of a multi-byte character in `1.—Onconotellus`. This
port skips the whole run — the same run `clean()` strips before matching — so a
reported span always brackets its name exactly.

**Trailing nomenclatural annotations are all removed.** The JavaScript strips at
most one rank word, and only when the name ends in exactly `rank` or `rank.`, so
`Amanita muscaria gen. nov.` keeps `gen.` and the OCR'd
`Pseudoneoborus samoanus, gen. ., sp. 0.` yields `Pseudoneoborus samoanus gen. .`.
This port strips every trailing rank and ignores punctuation around it. Ranks
*inside* a name are untouched: `Amanita muscaria var. formosa` is unchanged.

One quirk carried over from the original is worth knowing about: offsets for
names in comma-separated lists are loose. In `Felis leo, chaus, catus`,
`Felis chaus` is reported at offsets 11–23, which covers `chaus, catus`. The
name is right, the end offset is generous, and occasionally it runs past the
end of the string. Clamp it if that matters to you.

## Tests

```
php tests/run.php
```

117 tests, a port of the original mocha suite plus tests for the PHP-specific
parts. No test framework required.

## Licence

MIT, as node-taxonfinder. Dictionaries and algorithm by Patrick Leary.
