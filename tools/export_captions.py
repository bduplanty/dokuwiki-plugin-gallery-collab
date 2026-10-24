#!/usr/bin/env python3
"""Archive the latest approved titles and captions of a DokuWiki gallerycollab wiki.

Reads a downloaded copy of the wiki's media folder and of the plugin's sidecar folder
(data/gallery-collab) and writes, for every image:

  * a Markdown file with the same name as the image (extension .md) holding the title and
    caption that the gallery shows, plus where they came from and how they changed,
  * one index.html (local viewing in a browser) and one index.json (for other scripts).

Nothing in the media or sidecar folders is ever modified.

Python 3.8+, standard library only. See docs/BACKUP_AND_ARCHIVE.md.
"""

import argparse
import datetime
import html
import json
import os
import re
import sys
from pathlib import Path
from urllib.parse import quote

__version__ = "1.0.0"

IMAGE_EXTENSIONS = (".jpg", ".jpeg", ".png", ".gif", ".svg", ".webp")
DEFAULT_OUT = "titlesandcaptions"
JPEG_READ_LIMIT = 4 * 1024 * 1024  # metadata lives at the start of the file


# region Embedded metadata (JPEG IPTC / XMP, standard library only)

def _jpeg_segments(data):
    """Yield (marker, payload) for the segments before the image data of a JPEG."""
    if data[:2] != b"\xff\xd8":
        return
    i, n = 2, len(data)
    while i + 4 <= n:
        if data[i] != 0xFF:
            i += 1
            continue
        marker = data[i + 1]
        if marker == 0xFF:  # fill byte
            i += 1
            continue
        if marker == 0x01 or 0xD0 <= marker <= 0xD8:  # markers without payload
            i += 2
            continue
        if marker in (0xD9, 0xDA):  # end of image / start of scan
            return
        length = int.from_bytes(data[i + 2:i + 4], "big")
        if length < 2:
            return
        yield marker, data[i + 4:i + 2 + length]
        i += 2 + length


def _photoshop_iptc_blocks(segment):
    """Yield raw IPTC-IIM data from an APP13 'Photoshop 3.0' segment."""
    header = b"Photoshop 3.0\x00"
    if not segment.startswith(header):
        return
    i, n = len(header), len(segment)
    while i + 12 <= n and segment[i:i + 4] == b"8BIM":
        resource_id = int.from_bytes(segment[i + 4:i + 6], "big")
        name_len = segment[i + 6]
        name_total = 1 + name_len
        if name_total % 2:
            name_total += 1
        j = i + 6 + name_total
        size = int.from_bytes(segment[j:j + 4], "big")
        j += 4
        if resource_id == 0x0404:
            yield segment[j:j + size]
        i = j + size + (size % 2)


def _parse_iim(data):
    """Parse IPTC-IIM data into {(record, dataset): [bytes, ...]}."""
    out = {}
    i, n = 0, len(data)
    while i + 5 <= n and data[i] == 0x1C:
        record, dataset = data[i + 1], data[i + 2]
        size = int.from_bytes(data[i + 3:i + 5], "big")
        i += 5
        if size & 0x8000:  # extended dataset: the size field holds the length of the length
            count = size & 0x7FFF
            size = int.from_bytes(data[i:i + count], "big")
            i += count
        out.setdefault((record, dataset), []).append(data[i:i + size])
        i += size
    return out


def _decode(raw, utf8_declared):
    if utf8_declared:
        try:
            return raw.decode("utf-8")
        except UnicodeDecodeError:
            pass
    try:
        return raw.decode("utf-8")
    except UnicodeDecodeError:
        return raw.decode("cp1252", errors="replace")


def _clean(text):
    return re.sub(r"\s+", " ", text or "").strip()


def read_embedded(path):
    """Return the title and caption embedded in an image file, like the plugin does.

    The title is the first non-empty of IPTC Headline, IPTC Caption and XMP dc:title (the same
    order DokuWiki's JpegMeta uses); the caption is the IPTC Caption. Only JPEG files carry
    this data here, all other formats return empty strings.

    :return: dict with 'title' and 'caption'
    """
    result = {"title": "", "caption": ""}
    if path.suffix.lower() not in (".jpg", ".jpeg"):
        return result
    try:
        with open(path, "rb") as handle:
            data = handle.read(JPEG_READ_LIMIT)
    except OSError:
        return result

    headline = caption = xmp_title = ""
    for marker, segment in _jpeg_segments(data):
        if marker == 0xED:
            for block in _photoshop_iptc_blocks(segment):
                iim = _parse_iim(block)
                utf8 = b"\x1b%G" in iim.get((1, 90), [b""])[0]
                if (2, 105) in iim:
                    headline = _clean(_decode(iim[(2, 105)][0], utf8))
                if (2, 120) in iim:
                    caption = _clean(_decode(iim[(2, 120)][0], utf8))
        elif marker == 0xE1 and segment.startswith(b"http://ns.adobe.com/xap/1.0/\x00"):
            xmp = segment[len(b"http://ns.adobe.com/xap/1.0/\x00"):].decode("utf-8", errors="replace")
            found = re.search(r"<dc:title>.*?<rdf:li[^>]*>(.*?)</rdf:li>", xmp, re.S)
            if found:
                xmp_title = _clean(html.unescape(found.group(1)))

    result["caption"] = caption
    result["title"] = headline or caption or xmp_title
    return result


def title_from_filename(filename):
    """The fallback title the gallery shows for images without any title."""
    name = re.sub(r"\.(jpe?g|gif|png|svg|webp)$", "", filename, flags=re.I).replace("_", " ")
    return re.sub(r"(^|\s)(\S)", lambda m: m.group(1) + m.group(2).upper(), name)

# endregion


# region Collecting the data

def find_images(media_dir):
    """Return a sorted list of (media_id, path) for all images below media_dir."""
    found = []
    for root, dirs, files in os.walk(media_dir):
        dirs[:] = sorted(d for d in dirs if not d.startswith("."))
        for name in sorted(files):
            if name.startswith(".") or not name.lower().endswith(IMAGE_EXTENSIONS):
                continue
            path = Path(root) / name
            media_id = ":".join(path.relative_to(media_dir).parts)
            found.append((media_id, path))
    found.sort(key=lambda item: item[0].lower())
    return found


def sidecar_path(sidecars_dir, media_id):
    return Path(sidecars_dir).joinpath(*media_id.split(":")).with_name(media_id.split(":")[-1] + ".json")


def load_sidecar(sidecars_dir, media_id):
    """Load a sidecar file; the plugin lower-cases IDs, so try that spelling as well."""
    for candidate in (media_id, media_id.lower()):
        path = sidecar_path(sidecars_dir, candidate)
        if path.is_file():
            try:
                with open(path, encoding="utf-8") as handle:
                    data = json.load(handle)
                return data if isinstance(data, dict) else {}
            except (OSError, ValueError):
                print(f"warning: cannot read {path}, ignoring it", file=sys.stderr)
    return {}


def iso(timestamp):
    try:
        return datetime.datetime.fromtimestamp(int(timestamp), datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    except (TypeError, ValueError, OverflowError, OSError):
        return ""


def build_record(media_id, path, sidecars_dir):
    """Work out what the gallery shows for one image and where it comes from."""
    filename = path.name
    namespace = ":".join(media_id.split(":")[:-1])
    embedded = read_embedded(path)
    sidecar = load_sidecar(sidecars_dir, media_id)
    override = sidecar.get("override") if isinstance(sidecar.get("override"), dict) else None

    title, title_source = "", "filename"
    caption, caption_source = "", "none"
    embedded_title = embedded["title"] if embedded["title"] != filename else ""

    if embedded_title:
        title, title_source = embedded_title, "embedded"
    if embedded["caption"]:
        caption, caption_source = embedded["caption"], "embedded"
    if override:
        if _clean(override.get("title")):
            title, title_source = _clean(override["title"]), "approved"
        if _clean(override.get("description")):
            caption, caption_source = _clean(override["description"]), "approved"
    if not title:
        title = title_from_filename(filename)

    history = []
    for entry in sidecar.get("history") or []:
        if not isinstance(entry, dict):
            continue
        new = entry.get("new") if isinstance(entry.get("new"), dict) else None
        previous = entry.get("previous") if isinstance(entry.get("previous"), dict) else {}
        history.append({
            "time": iso(entry.get("time")),
            "by": entry.get("by") or "",
            "action": "approved" if new else "reverted",
            "title": (new or {}).get("title") or "",
            "caption": (new or {}).get("description") or "",
            "previous_title": previous.get("title") or "",
            "previous_caption": previous.get("description") or "",
        })

    return {
        "id": media_id,
        "filename": filename,
        "namespace": namespace,
        "title": title,
        "caption": caption,
        "title_source": title_source,
        "caption_source": caption_source,
        "embedded_title": embedded_title,
        "embedded_caption": embedded["caption"],
        "approved_by": (override or {}).get("by") or "",
        "approved_at": iso((override or {}).get("time")) if override else "",
        "history": history,
    }

# endregion


# region Writing the output

def markdown_paths(images, out_dir):
    """Map media ID to the .md path: <image stem>.md, or <image name>.md if stems collide."""
    counts = {}
    for media_id, path in images:
        key = (tuple(media_id.split(":")[:-1]), path.stem.lower())
        counts[key] = counts.get(key, 0) + 1
    result = {}
    for media_id, path in images:
        parts = media_id.split(":")
        key = (tuple(parts[:-1]), path.stem.lower())
        name = path.stem if counts[key] == 1 else path.name
        result[media_id] = Path(out_dir).joinpath(*parts[:-1], name + ".md")
    return result


def _cell(text):
    return (text or "").replace("|", "\\|").replace("\n", " ")


def render_markdown(record):
    q = lambda value: json.dumps(value, ensure_ascii=False)  # JSON strings are valid YAML
    lines = ["---"]
    for key in ("id", "filename", "namespace", "title", "caption", "title_source", "caption_source",
                "embedded_title", "embedded_caption", "approved_by", "approved_at"):
        lines.append(f"{key}: {q(record[key])}")
    lines += ["---", "", f"# {record['title']}", ""]
    lines += [record["caption"] or "*(no caption)*", "", "## Details", ""]
    lines.append(f"- **Image file:** `{record['filename']}`")
    lines.append(f"- **Media ID:** `{record['id']}`")
    lines.append(f"- **Title source:** {record['title_source']}")
    lines.append(f"- **Caption source:** {record['caption_source']}")
    if record["approved_by"] or record["approved_at"]:
        lines.append(f"- **Approved by:** {record['approved_by'] or 'unknown'} ({record['approved_at'] or 'unknown date'})")
    lines.append(f"- **Title in the file itself:** {record['embedded_title'] or '*(none)*'}")
    lines.append(f"- **Caption in the file itself:** {record['embedded_caption'] or '*(none)*'}")
    if record["history"]:
        lines += ["", "## History", "",
                  "| When (UTC) | By | Action | Title | Caption | Previously shown |",
                  "|---|---|---|---|---|---|"]
        for h in record["history"]:
            previous = " / ".join(part for part in (h["previous_title"], h["previous_caption"]) if part)
            lines.append("| " + " | ".join(_cell(x) for x in (
                h["time"], h["by"], h["action"], h["title"], h["caption"], previous)) + " |")
    return "\n".join(lines) + "\n"


def relative_url(target, start):
    """URL-quoted relative path for links in generated files, with a file:// fallback."""
    try:
        rel = os.path.relpath(target, start)
    except ValueError:  # e.g. different drives on Windows
        return Path(target).resolve().as_uri()
    return quote(Path(rel).as_posix(), safe="/")


INDEX_CSS = """
:root{--bg:#fff;--fg:#1d1d1f;--muted:#6e6e73;--card:#f5f5f7;--line:#d2d2d7;--accent:#0a63c9}
@media (prefers-color-scheme:dark){:root{--bg:#161618;--fg:#f5f5f7;--muted:#a1a1a6;--card:#232326;--line:#3a3a3d;--accent:#6ab0ff}}
*{box-sizing:border-box}body{margin:0;padding:1rem 1rem 3rem;background:var(--bg);color:var(--fg);
font:16px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
header{max-width:80rem;margin:0 auto 1rem}h1{font-size:1.5rem;margin:.2rem 0}
.meta{color:var(--muted);font-size:.9rem}
input[type=search]{width:100%;max-width:28rem;margin:.8rem 0;padding:.5rem .7rem;font:inherit;
border:1px solid var(--line);border-radius:.4rem;background:var(--card);color:var(--fg)}
main{max-width:80rem;margin:0 auto}section{margin-top:1.6rem}
h2{font-size:1.15rem;border-bottom:1px solid var(--line);padding-bottom:.3rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem}
figure{margin:0;background:var(--card);border:1px solid var(--line);border-radius:.5rem;overflow:hidden}
figure img{display:block;width:100%;height:12rem;object-fit:cover;background:var(--line)}
figcaption{padding:.6rem .7rem}.t{font-weight:600;overflow-wrap:anywhere}.c{margin:.25rem 0}
.s{color:var(--muted);font-size:.8rem}a{color:var(--accent)}
.badge{display:inline-block;padding:0 .4em;border:1px solid var(--line);border-radius:1em;font-size:.75rem}
[hidden]{display:none!important}
"""

INDEX_JS = """
const box=document.getElementById('q'),cards=[...document.querySelectorAll('figure')],
sections=[...document.querySelectorAll('section')],count=document.getElementById('shown');
box.addEventListener('input',()=>{const q=box.value.trim().toLowerCase();let n=0;
cards.forEach(c=>{const ok=!q||c.dataset.search.includes(q);c.hidden=!ok;if(ok)n++});
sections.forEach(s=>{s.hidden=!s.querySelector('figure:not([hidden])')});count.textContent=n});
"""


def render_index_html(records, md_paths, image_paths, out_dir, generated):
    groups = {}
    for record in records:
        groups.setdefault(record["namespace"], []).append(record)

    body = []
    for namespace in sorted(groups, key=str.lower):
        body.append(f'<section><h2>{html.escape(namespace or "(root)")}</h2><div class="grid">')
        for r in groups[namespace]:
            search = " ".join((r["title"], r["caption"], r["filename"], r["namespace"])).lower()
            image_url = relative_url(image_paths[r["id"]], out_dir)
            md_url = relative_url(md_paths[r["id"]], out_dir)
            approved = ""
            if r["title_source"] == "approved" or r["caption_source"] == "approved":
                who = f" by {html.escape(r['approved_by'])}" if r["approved_by"] else ""
                approved = f' <span class="badge">approved{who}</span>'
            body.append(
                f'<figure data-search="{html.escape(search, quote=True)}">'
                f'<a href="{image_url}"><img src="{image_url}" alt="{html.escape(r["filename"], quote=True)}" loading="lazy"></a>'
                f'<figcaption><div class="t">{html.escape(r["title"])}</div>'
                f'<div class="c">{html.escape(r["caption"])}</div>'
                f'<div class="s">{html.escape(r["filename"])}{approved} · <a href="{md_url}">.md</a></div>'
                f'</figcaption></figure>')
        body.append("</div></section>")

    return (
        '<!doctype html>\n<html lang="en"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        "<title>Titles and Captions</title>"
        f"<style>{INDEX_CSS}</style></head><body>"
        '<header><h1>Titles and Captions</h1>'
        f'<div class="meta">{len(records)} images · generated {html.escape(generated)} · '
        'showing <span id="shown">' + str(len(records)) + "</span></div>"
        '<input id="q" type="search" placeholder="Filter by title, caption, file name or namespace" autofocus>'
        "</header><main>" + "".join(body) + f"</main><script>{INDEX_JS}</script></body></html>\n"
    )


class Writer:
    """Writes text files, only touching files whose content changed, honouring --dry-run."""

    def __init__(self, dry_run):
        self.dry_run = dry_run
        self.written = 0
        self.unchanged = 0

    def write(self, path, text):
        path = Path(path)
        if path.is_file():
            try:
                if path.read_text(encoding="utf-8") == text:
                    self.unchanged += 1
                    return
            except (OSError, UnicodeDecodeError):
                pass
        self.written += 1
        if self.dry_run:
            return
        path.parent.mkdir(parents=True, exist_ok=True)
        with open(path, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(text)

# endregion


def parse_args(argv):
    p = argparse.ArgumentParser(
        description="Write Markdown, HTML and JSON archives of the approved titles and captions "
                    "from a downloaded DokuWiki gallerycollab wiki.")
    p.add_argument("--data", metavar="DIR",
                   help="the downloaded wiki 'data' folder; media is read from DIR/media and the "
                        "plugin's sidecars from DIR/gallery-collab")
    p.add_argument("--media", metavar="DIR", help="media folder (overrides the one derived from --data)")
    p.add_argument("--sidecars", metavar="DIR", help="gallery-collab folder (overrides the one derived from --data)")
    p.add_argument("--out", metavar="DIR", default=DEFAULT_OUT,
                   help=f"output folder (default: ./{DEFAULT_OUT})")
    p.add_argument("--no-html", action="store_true", help="skip index.html")
    p.add_argument("--allow-missing-sidecars", action="store_true",
                   help="continue if the gallery-collab folder does not exist (no approved captions then)")
    p.add_argument("--dry-run", action="store_true", help="show what would be written, change nothing")
    p.add_argument("--version", action="version", version=__version__)
    args = p.parse_args(argv)
    if args.data:
        args.media = args.media or str(Path(args.data) / "media")
        args.sidecars = args.sidecars or str(Path(args.data) / "gallery-collab")
    if not args.media or not args.sidecars:
        p.error("give --data, or both --media and --sidecars")
    return args


def main(argv=None):
    args = parse_args(sys.argv[1:] if argv is None else argv)
    media_dir, sidecars_dir, out_dir = Path(args.media), Path(args.sidecars), Path(args.out)

    if not media_dir.is_dir():
        print(f"error: media folder not found: {media_dir}", file=sys.stderr)
        return 2
    if not sidecars_dir.is_dir():
        message = (f"gallery-collab folder not found: {sidecars_dir}\n"
                   "  Download <savedir>/gallery-collab from the server (see docs/BACKUP_AND_ARCHIVE.md).\n"
                   "  Older versions wrote it to <wiki>/lib/exe/data/gallery-collab.")
        if not args.allow_missing_sidecars:
            print("error: " + message + "\n  Use --allow-missing-sidecars to export without approved captions.",
                  file=sys.stderr)
            return 2
        print("warning: " + message, file=sys.stderr)

    images = find_images(media_dir)
    if not images:
        print(f"error: no images found in {media_dir}", file=sys.stderr)
        return 2

    md_paths = markdown_paths(images, out_dir)
    image_paths = dict(images)
    records = [build_record(media_id, path, sidecars_dir) for media_id, path in images]
    generated = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    writer = Writer(args.dry_run)
    for record in records:
        writer.write(md_paths[record["id"]], render_markdown(record))

    index = {
        "generator": "export_captions.py " + __version__,
        "generated": generated,
        "count": len(records),
        "images": [dict(r, image=relative_url(image_paths[r["id"]], out_dir),
                        markdown=relative_url(md_paths[r["id"]], out_dir)) for r in records],
    }
    writer.write(out_dir / "index.json", json.dumps(index, indent=2, ensure_ascii=False) + "\n")
    if not args.no_html:
        writer.write(out_dir / "index.html", render_index_html(records, md_paths, image_paths, out_dir, generated))

    known = {media_id.lower() for media_id, _ in images}
    orphans = []
    for path in sorted(sidecars_dir.rglob("*.json")):
        media_id = ":".join(path.relative_to(sidecars_dir).parts)[:-len(".json")]
        if media_id.lower() not in known:
            orphans.append(media_id)

    approved = sum(1 for r in records if "approved" in (r["title_source"], r["caption_source"]))
    verb = "would write" if args.dry_run else "wrote"
    print(f"{len(records)} images, {approved} with approved captions")
    print(f"{verb} {writer.written} files, {writer.unchanged} already up to date, in {out_dir}")
    if orphans:
        print(f"warning: {len(orphans)} sidecar file(s) have no matching image in the media folder:", file=sys.stderr)
        for media_id in orphans[:10]:
            print(f"  {media_id}", file=sys.stderr)
        if len(orphans) > 10:
            print(f"  ... and {len(orphans) - 10} more", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
