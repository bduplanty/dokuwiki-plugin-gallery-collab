<?php

namespace dokuwiki\plugin\gallerycollab\test;

use dokuwiki\plugin\gallerycollab\classes\NamespaceGallery;
use dokuwiki\plugin\gallerycollab\classes\Options;
use DokuWikiTest;

/**
 * Namespace Gallery tests for the gallery plugin
 *
 * @group plugin_gallerycollab
 * @group plugins
 */
class NamespaceGalleryTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['gallerycollab'];

    /**
     * Copy demo images to the media directory
     *
     * @inheritdoc
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $conf;
        \TestUtils::rcopy($conf['mediadir'], __DIR__ . '/data/media/gallery');
    }


    /**
     * Check that the images are returned correctly
     */
    public function testGetImages()
    {
        $gallery = new NamespaceGallery('gallery', new Options());

        $images = $gallery->getImages();
        $this->assertIsArray($images);
        $this->assertCount(3, $images);
    }
}
