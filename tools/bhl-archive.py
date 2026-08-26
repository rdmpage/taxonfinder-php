#!/usr/bin/env python3
"""
Pull the OCR text of one BHL item or part out of the monthly bz2 archive.

The archive is ~45 GB compressed and unpacks to several hundred, so the point
of this is to never write the whole thing anywhere. bzip2 carries no index, so
the first time you go looking for something the stream still has to be read up
to it - there is no seeking to a member of a plain .tar.bz2. Two ways round
that, and the tool does both:

  extract   stream the tar past the decompressor, keep only the members that
            match, and stop once the item's directory has gone by. Costs a
            partial pass and needs nothing kept on disk. An item that is not
            there costs a full one, unless you accept --stop-early.

  index     one full pass (~16 min here) that records the bzip2 block offsets
            and the tar offset of every item and part. After that `extract`
            seeks straight to the member and returns in under a second, and
            an item that is not there is answered at once rather than read
            for.

Decompression is parallel, which is what makes the pass tolerable: stock
bzip2 manages ~15 MB/s of compressed input, eight threads ~85 MB/s.

    bhl-archive.py extract 273748 out/
    bhl-archive.py extract --part 447237 out/
    bhl-archive.py index

Note the layout differs from BHL's data dictionary, which documents
'<root>/item-27/item-273748/'. The archive actually has a kind directory
above the buckets: '<root>/item/item-27/item-273748/'. Pages within an item
are not in sequence order either, so sort on the trailing number.
"""
import argparse, io, os, pickle, sys, tarfile, time
import indexed_bzip2

DEFAULT_ARCHIVE = '/Volumes/Acer/bhl-ocr-20260514.tar.bz2'


def wanted_key(kind, ident):
    """The directory an item lives in: 'item-273748'. Zero padded to six."""
    return f'{kind}-{str(ident).zfill(6)}'


def item_key(name):
    """'<root>/item/item-27/item-273748/xxx.txt' -> 'item-273748'.

    Matching on this component rather than on the whole path keeps us
    independent of what the archive and its root directory are called.
    """
    parts = name.split('/')
    return parts[3] if len(parts) > 3 else None


def section(name):
    """'item' or 'part', the kind directory above the buckets."""
    parts = name.split('/')
    return parts[1] if len(parts) > 1 else None


def open_archive(archive, jobs, index=None):
    fh = indexed_bzip2.IndexedBzip2File(archive, parallelization=jobs)
    if index:
        fh.set_block_offsets(index)
    return fh


def load_index(archive):
    """The block offsets and the id -> tar offset map, if a pass has been made."""
    path = archive + '.index'
    if not os.path.exists(path):
        return None, None
    with open(path, 'rb') as fh:
        saved = pickle.load(fh)
    return saved['blocks'], saved['members']


# ------------------------------------------------------------------ extract

def extract(args):
    kind = 'part' if args.part else 'item'
    wanted = wanted_key(kind, args.id)
    os.makedirs(args.outdir, exist_ok=True)

    blocks, members = load_index(args.archive)
    if members is not None:
        if wanted not in members:
            print(f'{wanted} is not in the archive', file=sys.stderr)
            return 1
        pages = seek_to(args, wanted, blocks, members[wanted])
    else:
        pages = stream_to(args, wanted, kind)

    print(f'\n{pages} pages -> {args.outdir}', file=sys.stderr)
    return 0 if pages else 1


def seek_to(args, wanted, blocks, span):
    """With an index, read only the bytes the item occupies."""
    offset, length = span
    fh = open_archive(args.archive, args.jobs, blocks)
    fh.seek(offset)
    chunk = fh.read(length)
    fh.close()
    # A run of members lifted out of the middle of a tar has no end-of-archive
    # marker; without one tarfile reads off the end of the slice and raises.
    tar = tarfile.open(fileobj=io.BytesIO(chunk + b'\0' * 1024), mode='r|')
    return write_members(tar, wanted, args.outdir)


def stream_to(args, wanted, kind):
    """No index: read the stream looking for the item.

    Two ways to stop before the end. Once a page has been written the rest of
    the directory follows it and the next member outside it ends the job: tar
    keeps the files of a directory together, so that one is free.

    Giving up early on an item we have *not* seen is different. It needs the
    archive to be in sorted order, which this one is, but nothing guarantees
    it, and checking as we go does not help: the first key above the one we
    want is exactly one member before disorder would show itself. Get it wrong
    and a present item is reported missing, which is the worst answer of the
    three, so it is behind --stop-early. Without it a miss costs a full pass -
    build the index instead and misses become instant and certain.
    """
    total = os.path.getsize(args.archive)
    fh = open_archive(args.archive, args.jobs)
    tar = tarfile.open(fileobj=fh, mode='r|')

    found, seen, stopped_early = 0, None, False
    t0, nextreport = time.time(), time.time() + 10
    for member in tar:
        key = item_key(member.name)
        if key == wanted:
            if member.isfile():
                save(tar.extractfile(member).read(), args.outdir, member.name)
                found += 1
            continue
        if found:
            break                       # a directory is contiguous; we are past it
        if section(member.name) == kind and key:
            seen = key
        if args.stop_early and past(member.name, wanted, kind, seen):
            stopped_early = True
            break
        if time.time() >= nextreport:
            progress(fh, total, t0, found)
            nextreport = time.time() + 10
    tar.close(); fh.close()

    if not found and stopped_early:
        print(f'\ngave up at {seen}, assuming sorted order. Re-run without '
              f'--stop-early to be sure {wanted} is really absent.',
              file=sys.stderr)
    return found


def write_members(tar, wanted, outdir):
    found = 0
    for member in tar:
        if member.isfile() and item_key(member.name) == wanted:
            save(tar.extractfile(member).read(), outdir, member.name)
            found += 1
    return found


def save(data, outdir, name):
    with open(os.path.join(outdir, os.path.basename(name)), 'wb') as out:
        out.write(data)


def past(name, wanted, kind, seen):
    """Have we walked beyond where the item would have been?

    Ids are zero padded to a fixed width, so comparing the directory names as
    strings orders them. item/ sorts before part/, so an item still unseen by
    the time the parts begin is not there - but only trust that once we have
    actually seen an item, or an archive that happens to put parts first would
    send us home empty on the first member.
    """
    if section(name) == kind:
        here = item_key(name)
        return here is not None and here > wanted
    return kind == 'item' and section(name) == 'part' and seen is not None


# -------------------------------------------------------------------- index

def build_index(args):
    """One pass, recording where every item and part sits in the tar."""
    total = os.path.getsize(args.archive)
    fh = open_archive(args.archive, args.jobs)
    tar = tarfile.open(fileobj=fh, mode='r|')

    members, current, start, end = {}, None, 0, 0
    t0, nextreport = time.time(), time.time() + 15
    for member in tar:
        key = item_key(member.name)
        if key != current:
            if current:
                members[current] = (start, end - start)
            current, start = key, member.offset
        # a member ends after its header and its data, padded to 512 bytes
        end = member.offset_data + (member.size + 511) // 512 * 512
        if time.time() >= nextreport:
            progress(fh, total, t0, len(members))
            nextreport = time.time() + 15
    if current:
        members[current] = (start, end - start)
    tar.close()

    if not fh.block_offsets_complete():
        print('\nblock index incomplete', file=sys.stderr)
        return 1
    blocks = fh.block_offsets()
    fh.close()

    path = args.archive + '.index'
    with open(path, 'wb') as out:
        pickle.dump({'blocks': blocks, 'members': members}, out, protocol=4)
    print(f'\n{len(members)} items and parts, {len(blocks)} bzip2 blocks'
          f'\nindex -> {path} ({os.path.getsize(path)/1e6:.1f} MB)'
          f'\n{(time.time()-t0)/60:.1f} min', file=sys.stderr)
    return 0


def progress(fh, total, t0, found):
    done = fh.tell_compressed() // 8
    dt = time.time() - t0
    if not (done and dt):
        return
    eta = (total - done) / (done / dt) / 60
    print(f'\r{100.0*done/total:5.1f}%  {done/dt/1e6:5.1f} MB/s  '
          f'{dt/60:4.1f} min in, {eta:5.1f} min left  ({found} found)',
          end='', file=sys.stderr, flush=True)


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--archive', default=DEFAULT_ARCHIVE)
    ap.add_argument('--jobs', type=int, default=os.cpu_count())
    sub = ap.add_subparsers(dest='command', required=True)

    e = sub.add_parser('extract', help='pull out one item or part')
    e.add_argument('id')
    e.add_argument('outdir')
    e.add_argument('--part', action='store_true', help='a PartID, not an ItemID')
    e.add_argument('--stop-early', action='store_true',
                   help='give up once the ids sort past the one wanted. Assumes '
                        'the archive is ordered; a wrong guess reports a present '
                        'item as missing')
    e.set_defaults(func=extract)

    i = sub.add_parser('index', help='one full pass, for instant lookups after')
    i.set_defaults(func=build_index)

    args = ap.parse_args()
    return args.func(args)


if __name__ == '__main__':
    sys.exit(main())
