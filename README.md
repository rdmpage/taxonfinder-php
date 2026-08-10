# taxonfinder-php

Finds scientific names in text. A PHP 7 port of Patrick Leary's
[node-taxonfinder](https://github.com/pleary/node-taxonfinder), dictionaries and
all. No web server, no service, no dependencies — just a function you call.

```php
require '/path/to/taxonfinder-php/autoload.php';

$annotations = taxonfinder_find('Lygus buxtoni, sp. n.');
```

```json
[{
  "type": "Annotation",
  "body": { "type": "TextualBody", "purpose": "identifying",
            "value": "Lygus buxtoni" },
  "target": { "selector": [
    { "type": "TextQuoteSelector",
      "prefix": "", "exact": "Lygus buxtoni", "suffix": ", sp. n." },
    { "type": "TextPositionSelector", "start": 0, "end": 13 }
  ]},
  "nomenclature": { "verbatim": "sp. n.", "acts": ["sp. nov."],
                    "start": 15, "end": 21 }
}]
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
taxonfinder_find($text, $isHtml = false);   // annotation records
taxonfinder_names($text, $isHtml = false);  // just the unique names
taxonfinder_tag($text, $isHtml = false);    // $text with <name> elements added
```

Pass `true` as the second argument when the input is HTML. Tags are then
stripped before parsing, and `<p>`, `<td>`, `<tr>`, `<table>`, `<hr>`, `<ul>`
and `<li>` stop a name from running across them.

### The annotation record

One record per name found, loosely modelled on the
[W3C Web Annotation Data Model](https://www.w3.org/TR/annotation-model/).

| where | what |
| --- | --- |
| `body.value` | the **interpreted** name — capitalisation normalised, abbreviated genus expanded, trailing `sp. nov.` removed |
| `target.selector[0].exact` | the **original** string, exactly as it appears in the text |
| `target.selector[0].prefix` / `.suffix` | 32 bytes either side, so the name can be found again if the offsets go stale |
| `target.selector[1].start` / `.end` | byte offsets, such that `substr($text, $start, $end - $start)` is `exact` |
| `nomenclature` | present only when an annotation follows the name — see below |

The two selectors are the two ways of finding the same span, as the model
intends: positions are fast but break when the OCR is re-run, the quote
survives that. Across the two Samoa fascicles, all 290 `prefix + exact +
suffix` strings occur exactly once in their document, so re-anchoring by quote
alone recovers the original offsets.

`P. saltator` in `Pomatomus; P. saltator` gives `exact` of `"P. saltator"` and
`body.value` of `"Pomatomus saltator"` — the abbreviation as printed, and what
it means.

### Nomenclatural annotations

When a name is followed by a nomenclatural act, it gets its own object with its
own span, so the name span stays clean for markup:

```php
taxonfinder_find('Pseudoneoborus samoanus, gen. n., sp. n.')[0]['nomenclature'];
// array(
//   'verbatim' => 'gen. n., sp. n.',
//   'acts'     => array('gen. nov.', 'sp. nov.'),
//   'start'    => 25,
//   'end'      => 40,
// )
```

Acts are reported canonically — `sp. nov.`, `gen. nov.`, `comb. nov.`,
`syn. nov.`, `stat. nov.`, `nom. nov.`, `subsp. nov.`, `var. nov.`, `fam. nov.`
— in both the `sp. n.` and `n. sp.` orders. So a new name is
`substr($act, -4) === 'nov.'`.

Spelled-out English forms are read too, so `Hypogastrura simsi NEW SPECIES`
gives `sp. nov.`, and `NEW SYNONYM`, `NEW SYNONYMY` and `NEW SYNONYMIES` all
give `syn. nov.` Those words only count next to `new`, since `synonym`,
`status` and `combination` are ordinary prose on their own —
`Hypogastrura indiana synonym of harveyi` reports nothing.

An **author citation** between the name and its annotation is stepped over, so
both of these attach:

```
Alabameubria starki Brown, 1980:188. NEW SYNONYMY
Merragata quieta Drake, new species
```

That reach is fenced in four ways, because it is the part most likely to grab
something it shouldn't:

* only surnames, numbers and a few connecting words (`and`, `et al.`, `in`,
  `ex`, `von`, …) may appear in the gap — prose ends it, so
  `Alabameubria starki Brown, by original designation. NEW SYNONYMY.` is
  rejected at `by`;
* the gap must hold at least one surname, so a bare year is not enough;
* the gap must stay on one line, so a page number and the heading after it
  cannot pass as a citation;
* the act must finish its line, so `Felis leo Smith. New species were described
  from Brazil.` attaches nothing.

Two of those came from real false positives: without the line rule, `201` and
`Onconotellus` across a page break read as a citation and the next heading's
`gen. n.` was taken by the last name on the previous page. The rejected
`by original designation` case is also the right answer rather than a missed
one — that annotation belongs to the name opening the entry, not to the
nearest name before it.

### The annotation vocabulary

The words above live in `dictionaries/annotations.txt`, not in the code, so you
can add to them the same way you add names:

```
new   nov
act   sp            sp.        bare
act   synonymy      syn.
cite  et
```

`new` is a word meaning new. `act` is a nomenclatural act and how to report it;
`bare` means it may stand on its own, which is right for `sp.` (indeterminate)
but not for English words like `synonym`. `cite` is a lowercase word allowed
inside an author citation. Put your own in `dictionaries/local/annotations.txt`
and they are merged on top, or add them at runtime:

```php
Taxonfinder\Nomenclature::add('act', 'nudum', 'nom. nud.', true);
Taxonfinder\Nomenclature::addFile('/path/to/more-annotations.txt');
```

An act without the "new" marker is reported bare: `Amanita sp.` gives `sp.`,
meaning indeterminate rather than new. That also covers OCR damage — the real
line `Pseudoneoborus samoanus, gen. ., sp. 0.` reports `gen.` and `sp.` rather
than guessing that the lost characters were `n.` The `verbatim` text is always
there so you can judge.

A capitalised `N.` is treated as an author's initial, not as `novum`, so
`Amanita sp. N. Smith` gives `sp.` and not `sp. nov.`

Running this over the 80 KB *Insects of Samoa* fascicle finds 285 names, 58 of
them annotated: 45 `sp. nov.`, 8 `gen. nov.`, 2 `var. nov.`, 6 bare `sp.`

### Keeping an instance

If you are processing many documents, keep one `Finder` so the dictionaries are
only loaded once. The second constructor argument is how much context each
`TextQuoteSelector` carries, in bytes:

```php
$finder = new Taxonfinder\Finder(null, 48);
foreach ($documents as $document) {
    $annotations = $finder->find($document);
}
```

If you only want names and offsets, without the annotation wrapper, the layer
below is `Taxonfinder\Parser::findNamesAndOffsets()`. That is also the method
checked against the JavaScript original.

### From the command line

```
bin/taxonfinder paper.txt                 # JSON annotations
bin/taxonfinder --compact paper.txt       # one line of JSON
bin/taxonfinder --context=64 paper.txt    # more context in the quote selector
bin/taxonfinder --names paper.txt         # just the unique names
bin/taxonfinder --html --tag page.html    # mark up the text in place
cat paper.txt | bin/taxonfinder
```

Listing the new names in a paper is then a one-liner:

```
bin/taxonfinder --compact paper.txt \
  | jq -r '.[] | select(.nomenclature.acts // [] | any(endswith("nov.")))
           | "\(.body.value)\t\(.nomenclature.acts | join(" "))"'
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
| `annotations.txt` | nomenclatural annotation vocabulary — `sp. nov.`, `NEW SYNONYMY`, … |

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
the run. The two implementations agree on every document; five kinds of difference are
deliberate.

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

**Qualifiers are read through.** `(s. str.)`, `(s.l.)`, `(sensu stricto)` and
their variants end the name in the JavaScript, so
`Hypogastrura (s. str.) simsi` yields only the genus. Here they are skipped and
the name comes out whole, with the reported span covering the qualifier — the
original string is `Hypogastrura (s. str.) simsi`, the interpreted one
`Hypogastrura simsi`. A real subgenus, `Felis (Felis) leo`, is untouched, and so
is anything else in brackets, including `(sensu Christiansen and Bellinger)`,
which qualifies a group rather than a name.

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

148 tests, a port of the original mocha suite plus tests for the PHP-specific
parts. No test framework required.

## Licence

MIT, as node-taxonfinder. Dictionaries and algorithm by Patrick Leary.
