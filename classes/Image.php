<?php

namespace dokuwiki\plugin\gallerycollab\classes;

use dokuwiki\Utf8\PhpString;

class Image
{
    public const IMG_REGEX = '/\.(jpe?g|gif|png|svg|webp)$/i';

    protected $isExternal = false;
    protected $src;
    protected $filename;
    protected $localfile;
    protected $title;
    protected $description;
    protected $width;
    protected $height;
    protected $created = 0;
    protected $modified = 0;
    protected $detaillink;
    protected $tags = [];
    protected $overridden = ['title' => false, 'description' => false, 'tags' => false];


    /**
     * @param string $src local ID or external URL to image
     * @param bool $applyOverride use the reviewed caption (if any) instead of the file's own metadata
     * @throws \Exception
     */
    public function __construct($src, $applyOverride = true)
    {
        $this->src = $src;
        if (preg_match('/^https:\/\//i', $src)) {
            $this->isExternal = true;
            $path = parse_url($src, PHP_URL_PATH);
            $this->filename = basename($path);
        } else {
            $this->localfile = mediaFN($src);
            if (!file_exists($this->localfile)) throw new \Exception('File not found: ' . $this->localfile);
            $this->filename = basename($this->localfile);
            $this->modified = filemtime($this->localfile);

            $jpegMeta = new \JpegMeta($this->localfile);
            $this->title = $jpegMeta->getField('Simple.Title');
            $this->description = $jpegMeta->getField('Iptc.Caption');
            $this->created = $jpegMeta->getField('Date.EarliestTime');
            $this->width = $jpegMeta->getField('File.Width');
            $this->height = $jpegMeta->getField('File.Height');
            $this->tags = $this->readTags($jpegMeta);

            if ($applyOverride) $this->applyOverride();
        }
    }

    /**
     * Read the keywords stored in the image: IPTC Keywords and XMP dc:subject
     *
     * Editors often write both, so the lists are merged without duplicates (ignoring case).
     *
     * @param \JpegMeta $jpegMeta
     * @return string[]
     */
    protected function readTags(\JpegMeta $jpegMeta)
    {
        $found = [];
        foreach ([$jpegMeta->getIPTCField('Keywords'), $jpegMeta->getXmpField('dc:subject')] as $field) {
            if (!is_array($field)) $field = [$field];
            array_walk_recursive($field, function ($value) use (&$found) {
                if (is_string($value)) $found[] = $value;
            });
        }
        return CaptionStore::normalizeTags($found);
    }

    /**
     * Replace title, description and tags with the reviewed values from the caption store
     */
    protected function applyOverride()
    {
        $override = (new CaptionStore($this->src))->getOverride();
        if (!$override) return;
        if ($override['title'] !== '') {
            $this->title = $override['title'];
            $this->overridden['title'] = true;
        }
        if ($override['description'] !== '') {
            $this->description = $override['description'];
            $this->overridden['description'] = true;
        }
        if (is_array($override['tags'] ?? null)) {
            $this->tags = CaptionStore::normalizeTags($override['tags']);
            $this->overridden['tags'] = true;
        }
    }

    /**
     * Was the given field ('title', 'description' or 'tags') replaced by a reviewed caption?
     *
     * @param string $field
     * @return bool
     */
    public function isOverridden($field)
    {
        return $this->overridden[$field] ?? false;
    }

    public function isExternal()
    {
        return $this->isExternal;
    }

    public function getSrc()
    {
        return $this->src;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    public function setFilename(string $filename)
    {
        $this->filename = $filename;
    }

    public function getLocalfile()
    {
        return $this->localfile;
    }

    public function setLocalfile(string $localfile)
    {
        $this->localfile = $localfile;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        if (empty($this->title) || $this->title == $this->filename) {
            $title = str_replace('_', ' ', $this->filename);
            $title = preg_replace(self::IMG_REGEX, '', $title);
            $title = PhpString::ucwords($title);
            return $title;
        }
        return $this->title;
    }

    /**
     * @param string $title
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return trim(str_replace("\n", ' ', $this->description));
    }

    /**
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * @return string[] the tags to show: approved ones if there are any, else the keywords of the file
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * @param string[] $tags
     */
    public function setTags(array $tags)
    {
        $this->tags = $tags;
    }

    /**
     * @return int
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @param int $width
     */
    public function setWidth($width)
    {
        $this->width = $width;
    }

    /**
     * @return int
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * @param int $height
     */
    public function setHeight($height)
    {
        $this->height = $height;
    }

    /**
     * @return int
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * @param int $created
     */
    public function setCreated($created)
    {
        $this->created = $created;
    }

    /**
     * @return int
     */
    public function getModified()
    {
        return $this->modified;
    }

    /**
     * @param int $modified
     */
    public function setModified($modified)
    {
        $this->modified = $modified;
    }

    public function getDetaillink()
    {
        return $this->detaillink;
    }

    public function setDetaillink($detaillink)
    {
        $this->detaillink = $detaillink;
    }
}
