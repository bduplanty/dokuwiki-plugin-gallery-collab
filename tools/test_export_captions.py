"""Tests for export_captions.py - run with: python3 -m unittest discover -s tools"""

import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

import export_captions as ec


def iim(record, dataset, value):
    raw = value.encode("utf-8")
    return b"\x1c" + bytes([record, dataset]) + len(raw).to_bytes(2, "big") + raw


def make_jpeg(path, headline=None, caption=None):
    """Smallest JPEG-like file that carries IPTC data in an APP13 Photoshop block."""
    data = b"\x1c\x01Z\x00\x03\x1b%G"  # 1:90 coded character set = UTF-8
    if headline:
        data += iim(2, 105, headline)
    if caption:
        data += iim(2, 120, caption)
    if len(data) % 2:
        data += b"\x00"
    resource = b"8BIM" + b"\x04\x04" + b"\x00\x00" + len(data).to_bytes(4, "big") + data
    payload = b"Photoshop 3.0\x00" + resource
    segment = b"\xff\xed" + (len(payload) + 2).to_bytes(2, "big") + payload
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"\xff\xd8" + segment + b"\xff\xd9")


def make_sidecar(root, media_id, **data):
    parts = media_id.split(":")
    path = root.joinpath(*parts[:-1], parts[-1] + ".json")
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data), encoding="utf-8")


class ExportTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.data = root / "data"
        self.media = self.data / "media"
        self.sidecars = self.data / "gallery-collab"
        self.out = root / "out"

        make_jpeg(self.media / "family" / "bar_1.jpeg", headline="Original Tavern", caption="Original caption ü")
        make_jpeg(self.media / "family" / "plain.jpg")
        (self.media / "family" / "scan.png").write_bytes(b"\x89PNG")
        (self.media / "family" / "scan.jpg").write_bytes(b"not really a jpeg")  # stem collision
        (self.media / "family" / "notes.txt").write_text("ignored")
        self.sidecars.mkdir(parents=True)
        make_sidecar(self.sidecars, "family:bar_1.jpeg",
                     suggestions=[{"id": "x", "status": "pending", "title": "NOPE", "description": "NOPE"}],
                     override={"title": "Approved Title", "description": "Approved caption | pipe",
                               "by": "reviewer", "time": 1792867525},
                     history=[{"time": 1792867525, "by": "reviewer", "suggestion": "",
                               "previous": {"title": "Original Tavern", "description": "Original caption ü"},
                               "new": {"title": "Approved Title", "description": "Approved caption | pipe"}}])
        make_sidecar(self.sidecars, "family:gone.jpg", override={"title": "t", "description": "d"})

    def run_export(self, *extra):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = ec.main(["--data", str(self.data), "--out", str(self.out), *extra])
        return code, out.getvalue(), err.getvalue()

    def test_export(self):
        code, out, err = self.run_export()
        self.assertEqual(0, code, err)
        self.assertIn("4 images, 1 with approved captions", out)
        self.assertIn("family:gone.jpg", err)  # orphan sidecar is reported

        md = (self.out / "family" / "bar_1.md").read_text(encoding="utf-8")
        self.assertIn('title: "Approved Title"', md)
        self.assertIn("# Approved Title", md)
        self.assertIn("Approved caption | pipe", md)
        self.assertIn('embedded_title: "Original Tavern"', md)
        self.assertIn("Approved caption \\| pipe", md)  # escaped inside the history table
        self.assertNotIn("NOPE", md)  # pending suggestions are not exported

        # no sidecar: embedded metadata, then file name
        plain = (self.out / "family" / "plain.md").read_text(encoding="utf-8")
        self.assertIn("# Plain", plain)
        self.assertIn('title_source: "filename"', plain)

        # stems collide -> full file names
        self.assertTrue((self.out / "family" / "scan.png.md").is_file())
        self.assertTrue((self.out / "family" / "scan.jpg.md").is_file())
        self.assertFalse((self.out / "family" / "scan.md").exists())

        index = json.loads((self.out / "index.json").read_text(encoding="utf-8"))
        self.assertEqual(4, index["count"])
        bar = next(i for i in index["images"] if i["id"] == "family:bar_1.jpeg")
        self.assertEqual("approved", bar["title_source"])
        self.assertEqual("../data/media/family/bar_1.jpeg", bar["image"])
        self.assertEqual("family/bar_1.md", bar["markdown"])

        page = (self.out / "index.html").read_text(encoding="utf-8")
        self.assertIn("Approved Title", page)
        self.assertIn('src="../data/media/family/bar_1.jpeg"', page)

    def test_embedded_metadata_used_without_sidecar(self):
        (self.sidecars / "family" / "bar_1.jpeg.json").unlink()
        self.assertEqual(0, self.run_export()[0])
        md = (self.out / "family" / "bar_1.md").read_text(encoding="utf-8")
        self.assertIn('title: "Original Tavern"', md)
        self.assertIn('caption: "Original caption ü"', md)
        self.assertIn('caption_source: "embedded"', md)

    def test_rerun_is_idempotent_and_dry_run_writes_nothing(self):
        code, out, _ = self.run_export("--dry-run")
        self.assertEqual(0, code)
        self.assertIn("would write", out)
        self.assertFalse(self.out.exists())

        self.run_export()
        code, out, _ = self.run_export()
        # only index.html embeds the generation time, so at most that one file may change
        written = int(out.split("wrote ")[1].split(" ")[0])
        self.assertLessEqual(written, 1)
        self.assertIn("already up to date", out)

    def test_missing_sidecars_is_an_error_unless_allowed(self):
        for child in sorted(self.sidecars.rglob("*"), reverse=True):
            child.unlink() if child.is_file() else child.rmdir()
        self.sidecars.rmdir()
        code, _, err = self.run_export()
        self.assertEqual(2, code)
        self.assertIn("gallery-collab folder not found", err)
        self.assertEqual(0, self.run_export("--allow-missing-sidecars")[0])


if __name__ == "__main__":
    unittest.main()
