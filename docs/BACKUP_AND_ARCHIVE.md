# Backing up and archiving gallerycollab titles, captions and tags

This guide is for the administrator of a DokuWiki that runs the **gallerycollab** plugin. It explains:

1. what data the plugin keeps, and where,
2. how to download it from the server,
3. how to turn the download into a long-term archive (one Markdown file per image plus an HTML index) with `tools/export_captions.py`,
4. how to restore.

You do not need to run anything on the server. Everything below the download step happens on your own computer.

## 1. Why this matters

gallerycollab never changes the image file when someone suggests a caption or tags. Suggestions, approvals and the history of changes live in small JSON files next to the wiki's media. When `write_iptc` is switched off (the default), or when the image is not a JPEG, the approved title and caption exist **only** in those JSON files. Approved **tags** are never written into the image files at all, so they always exist only in the JSON files. If they are lost, the approved text is lost, and the images alone do not contain it.

Archiving them as Markdown gives you a copy that is readable without DokuWiki or this plugin.

## 2. What to back up

All paths are relative to your wiki's root folder. The data folder is called `data` unless `savedir` says otherwise (see section 3).

| Path | What it holds | Priority |
|---|---|---|
| `data/gallery-collab/` | **Suggestions, approved titles, captions and tags, and change history.** One `<image name>.json` file per image, in folders that mirror the media namespaces. | **Essential** |
| `data/media/` | The images. Their embedded titles, captions and keywords (JPEG IPTC/XMP) are the "original" text and tags. | **Essential** |
| `conf/local.php` | Plugin settings (`contributor_groups`, `reviewer_groups`, `write_iptc`) and `savedir`. | Recommended |
| `conf/users.auth.php`, `conf/acl.auth.php` | Who is in which group, and namespace permissions. Reviewers and contributors are defined by group membership. | Recommended |
| `data/pages/` | The wiki pages that contain `{{gallery>...}}`. | Recommended |

You do not need `data/cache/`, `data/index/` or `data/meta/`.

`data/gallery-collab/` contains the login names of the people who made and reviewed suggestions. Keep that in mind before sharing an archive outside your organisation (the "Limits" part of section 5 says which output fields carry names).

## 3. Find the right data folder

1. Open `conf/local.php` on the server and look for a line like `$conf['savedir'] = '...';`. If there is none, the data folder is `data/` inside the wiki root.
2. Check that `<data folder>/gallery-collab/` exists. It appears after the first suggestion is submitted.

**Older installs.** Before the fix described in `README2` (2026-10-24, "Fix sidecar location with relative savedir"), a wiki with a relative `savedir` such as `./data` saved the files to `lib/exe/data/gallery-collab/` instead. If that folder exists on your server:

1. Copy its contents into `data/gallery-collab/`, keeping the sub-folders.
2. If a file with the same name exists in both places, keep the one whose `"override"` → `"time"` value is newer (or the one that has an `"override"` at all).
3. Keep the old folder until you have checked the export (section 5), then remove it.

## 4. Download from the server

Pick whichever method your hosting allows. Download **both** `data/media` and `data/gallery-collab`, and keep their folder names. A good layout on your computer is:

```
wiki-backup/
  data/
    media/            <- downloaded copy of the wiki's data/media
    gallery-collab/   <- downloaded copy of the wiki's data/gallery-collab
```

### SSH access (macOS, Linux, Windows with OpenSSH)

```bash
mkdir -p wiki-backup/data
rsync -av user@example.org:/var/www/dokuwiki/data/media/          wiki-backup/data/media/
rsync -av user@example.org:/var/www/dokuwiki/data/gallery-collab/ wiki-backup/data/gallery-collab/
```

Run the same commands again later; `rsync` only transfers what changed. Without `rsync`, use `scp -r` the same way. To copy the configuration files as well:

```bash
rsync -av user@example.org:/var/www/dokuwiki/conf/ wiki-backup/conf/
```

### SFTP/FTP client (FileZilla, Cyberduck, WinSCP)

Connect, browse to the wiki's `data` folder, and drag `media` and `gallery-collab` onto your computer. Make sure the client transfers hidden files and does not skip existing ones.

### Hosting control panel (cPanel, Plesk and similar)

In the file manager, select `data/media` and `data/gallery-collab`, choose **Compress** (zip), download the archive, and extract it so that the layout above results.

### Check the download

- `wiki-backup/data/gallery-collab/` contains `.json` files in sub-folders, for example `family/summer_picnic_1.jpeg.json`.
- The number of images in `wiki-backup/data/media/` matches the server.

## 5. Create the archive with `export_captions.py`

### Requirements

- **Python 3.8 or newer.** Check with `python3 --version` (macOS, Linux) or `py --version` (Windows). Download from <https://www.python.org/downloads/> if needed.
- **No additional libraries.** The script uses only the Python standard library, so there is nothing to `pip install`. Pillow, exiftool and similar tools are not needed.
- The script file `tools/export_captions.py` from this plugin. Copy that single file to your computer.

### Run it

```bash
# macOS / Linux
python3 export_captions.py --data wiki-backup/data --out wiki-backup/titlesandcaptions

# Windows
py export_captions.py --data wiki-backup\data --out wiki-backup\titlesandcaptions
```

`--data` is the downloaded `data` folder: the script reads images from `<data>/media` and sidecars from `<data>/gallery-collab`. If your folders are laid out differently, name them directly with `--media FOLDER` and `--sidecars FOLDER`.

| Option | Meaning |
|---|---|
| `--out DIR` | Output folder. Default: `titlesandcaptions` in the current folder. |
| `--no-html` | Do not create `index.html`. |
| `--dry-run` | Show what would be written and change nothing. |
| `--allow-missing-sidecars` | Continue even if the `gallery-collab` folder is missing. Without this flag the script stops, so an archive without approved captions is never created by accident. |
| `--version`, `--help` | Version and usage. |

The script never changes the media or sidecar folders. It prints the number of images, how many have approved captions, how many files it wrote, and warns about sidecar files whose image no longer exists.

You can run it as often as you like. Files whose content did not change are left untouched, so a folder under version control or a sync tool only sees real differences.

### What you get

```
titlesandcaptions/
  index.html                          <- open this in a browser
  index.json                          <- for scripts
  family/
    summer_picnic_1.md
    ...
```

- The folder structure mirrors the wiki's media namespaces (`family:name.jpeg` becomes `family/name.md`).
- Each image gets a Markdown file with the **same name and the extension `.md`**.
- If two images in one folder share a name and differ only in extension (`scan.jpg`, `scan.png`), both keep their full name so nothing is overwritten: `scan.jpg.md`, `scan.png.md`.
- **Every** image gets a file, whether or not anyone suggested a caption.

### `index.html`

A single page for local viewing: images grouped by namespace, each with its title, caption, tags, file name, a badge when the text was approved by a reviewer, and a link to its `.md` file. The filter box at the top searches titles, captions, file names and namespaces. It needs no internet connection and no web server; double-click it.

The page shows each image's tags as small pills and its search box also matches tags. The page links to the images in your downloaded `media` folder by relative path, computed when the script ran. If you move the `titlesandcaptions` folder relative to the media folder, run the script again to refresh the links. Thumbnails are the original image files scaled down by the browser, so very large photo collections load slowly.

### Markdown files

Each file starts with a YAML header (so other tools can read it) followed by readable text:

```markdown
---
id: "family:summer_picnic_1.jpeg"
filename: "summer_picnic_1.jpeg"
namespace: "family"
title: "Summer Picnic at the Lake"
caption: "Summer 1952, Lakeside Park"
tags: ["family", "summer", "picnic"]
title_source: "approved"
caption_source: "approved"
tags_source: "approved"
embedded_title: "Picnic at the lake"
embedded_caption: "Picnic at the lake"
embedded_tags: ["family"]
approved_by: "reviewer1"
approved_at: "2026-01-15T14:30:00Z"
---

# Summer Picnic at the Lake

Summer 1952, Lakeside Park

**Tags:** family, summer, picnic

## Details
...
## History
| When (UTC) | By | Action | Title | Caption | Tags | Previously shown |
```

| Field | Meaning |
|---|---|
| `title`, `caption`, `tags` | What the gallery shows today. `tags` is a list. |
| `title_source`, `caption_source`, `tags_source` | `approved` (a reviewer's text from the sidecar), `embedded` (the image file's own metadata), `filename` (no title anywhere, derived from the file name like the gallery does), or `none` (no caption or no tags). |
| `embedded_title`, `embedded_caption`, `embedded_tags` | The text and keywords stored in the image file itself, kept for comparison. |
| `approved_by`, `approved_at` | Reviewer login name and UTC time of the latest approval (empty if none). |
| History table | Every approval and revert: who, when, the new text and the text it replaced. |

Pending and rejected suggestions are **not** exported; they are only in the sidecar files, which you keep in the backup.

How the title and caption are chosen (the same order the gallery uses):

1. Title: the approved title, else the image's IPTC Headline, IPTC Caption or XMP `dc:title`, else a title made from the file name.
2. Caption: the approved caption, else the image's IPTC Caption, else empty.
3. Tags: the approved tag list if a reviewer approved one (it replaces the file's tags, and may be empty), else the image's IPTC Keywords merged with its XMP `dc:subject` list.

### `index.json` (for your own scripts)

```json
{
  "generator": "export_captions.py 1.1.0",
  "generated": "2026-10-24T19:00:00Z",
  "count": 1,
  "images": [
    {
      "id": "family:name.jpeg", "filename": "name.jpeg", "namespace": "family",
      "title": "...", "caption": "...", "tags": ["..."],
      "title_source": "approved", "caption_source": "approved", "tags_source": "approved",
      "embedded_title": "...", "embedded_caption": "...", "embedded_tags": ["..."],
      "approved_by": "...", "approved_at": "...",
      "history": [ { "time": "...", "by": "...", "action": "approved", "title": "...", "caption": "...", "tags": ["..."],
                     "previous_title": "...", "previous_caption": "...", "previous_tags": ["..."] } ],
      "image": "../data/media/family/name.jpeg",
      "markdown": "family/name.md"
    }
  ]
}
```

`image` and `markdown` are URL-quoted paths relative to the output folder.

### Limits to be aware of

- **Embedded text and tags are read from JPEG IPTC and XMP only.** Keywords are the IPTC Keywords and the XMP `dc:subject` list; other tag fields (for example Windows `XPKeywords`) are not read. Titles that DokuWiki would take from the EXIF `UserComment` or `ImageDescription` fields are not read; those images get a title made from their file name unless a reviewer approved one. PNG, GIF, WebP and SVG files have no embedded text in this context.
- **The archive is a snapshot.** Run the export again after new approvals, then keep the dated copy you want.
- **Names are included.** `approved_by` and the history table contain login names. Remove them before sharing the archive publicly if that matters to you.
- **The Markdown files are not a replacement for the sidecar backup.** They omit pending and rejected suggestions and cannot be imported back into the plugin.

## 6. Restoring

1. Reinstall the plugin in `lib/plugins/gallerycollab/` and re-enter its settings (or restore `conf/local.php`).
2. Copy the backed-up `gallery-collab` folder to `<data folder>/gallery-collab/` on the server. The web server user must be able to read and write it.
3. Restore `data/media` if it was lost as well.
4. Open a gallery page with `?purge=true` appended to the URL. Approved captions appear again.

The archive Markdown files cannot be re-imported. If only the archive survives, a reviewer has to re-approve the text from the `.md` files, or you can write the sidecar JSON yourself (see `classes/CaptionStore.php` for the format: an `override` object with `title`, `description`, `tags` (a list, or `null` to keep the file's tags), `by` and `time`).

If the plugin's `write_iptc` option was on, the approved text was also written into the JPEG files themselves, and those files carry it in any backup of `data/media`. This is only a convenience; the sidecar stays the authoritative copy.

## 7. Troubleshooting

| Problem | Likely cause |
|---|---|
| `error: media folder not found` | `--data` does not point at the folder that contains `media`. Use `--media`/`--sidecars`, or check the download layout. |
| `error: gallery-collab folder not found` | The sidecars were not downloaded, or the wiki still wrote them to `lib/exe/data/gallery-collab` (section 3). |
| `warning: N sidecar file(s) have no matching image` | The image was deleted or renamed in the wiki. The sidecar is still in your backup; nothing is exported for it. |
| Approved text missing in an `.md` file | The sidecar has no `override` for that image (the suggestion was never approved), or the sidecar file name does not match the image name exactly. |
| `index.html` shows broken images | The media folder is not where it was when the script ran. Run the script again from the new location. |
| `python3: command not found` on Windows | Use `py` instead, or install Python from python.org and tick "Add to PATH". |

## 8. Checking the script

`tools/test_export_captions.py` builds a small fake wiki and checks the output. Run it from the `tools` folder:

```bash
python3 -m unittest discover -s .
```
