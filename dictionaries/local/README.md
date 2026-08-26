# Local dictionaries

Two ways to add names here, and the loader ignores anything that is not named
after a dictionary, so notes and queries can sit beside the data.

## A file per dictionary

`genera_new.txt`, `species_new.txt`, `family_new.txt` and the rest, straight in
this directory. Read every run, which is fine for a handful of names.

## A folder per source and date

For a harvest from a database, a folder named for where it came from and when:

    local/
      bionames-2026-08-26/
        genera_new.txt        one genus per line
        species_new.txt       one epithet per line
        query.sql             what was asked for, ignored by the loader
      indexfungorum-2026-09-01/
        species_new.txt

Every folder in here is picked up automatically - no flag, nothing to register
- and each is compiled into a sorted index and cached, so a large harvest costs
its sorting once rather than every run. Change a file and only that folder's
index is rebuilt.

Dating the folder is the point of it. When a name turns out to be a false
positive you want to know which harvest brought it in, and to re-run the query
that found it rather than today's.

## What goes in the files

Terms, one per line, lowercased and trimmed as they load. Blank lines are
skipped; `#` is *not* a comment and would be read as a term, which is why the
notes belong in a file of their own.

The dictionaries are combinatorial - genera and epithets are looked up
separately - so two flat lists cover every combination between them. There is
no need for, and no way to use, a list of binomials.

Names are taken as given. A harvest carrying hybrid formulae, `sp.`
placeholders or author strings in the epithet column will widen the false
positives, so it is worth looking at what a list adds before adding it.
