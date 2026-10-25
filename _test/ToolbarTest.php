<?php

namespace dokuwiki\plugin\gallerycollab\test;

use dokuwiki\plugin\gallerycollab\classes\NamespaceGallery;
use dokuwiki\plugin\gallerycollab\classes\Options;
use dokuwiki\plugin\gallerycollab\classes\XHTMLFormatter;
use DokuWikiTest;

/**
 * Search/sort toolbar tests for the gallerycollab plugin
 *
 * @group plugin_gallerycollab
 * @group plugins
 * @author Bruce DuPlanty <bduplanty@gmail.com>
 */
class ToolbarTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['gallerycollab'];

    /** @inheritdoc */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $conf;
        \TestUtils::rcopy($conf['mediadir'], __DIR__ . '/data/media/gallery');
    }

    /**
     * Render the test gallery with the given option string
     *
     * @param string $params
     * @return string
     */
    protected function renderGallery($params)
    {
        $options = new Options();
        $options->galleryID = 'abcd';
        $options->parseParameters($params);

        $renderer = new \Doku_Renderer_xhtml();
        $formatter = new XHTMLFormatter($renderer, $options);
        $formatter->render(new NamespaceGallery('gallery', $options));
        return $renderer->doc;
    }

    public function testOptionParsing()
    {
        $options = new Options();
        $this->assertFalse($options->toolbar);
        $options->parseParameters('toolbar');
        $this->assertTrue($options->toolbar);
        $options->parseParameters('notoolbar');
        $this->assertFalse($options->toolbar);
    }

    public function testToolbarAndFigureData()
    {
        $html = $this->renderGallery('toolbar ~2');

        $this->assertNotFalse(strpos($html, 'class="gallery-toolbar"'));
        $this->assertNotFalse(strpos($html, 'data-paginate="2"'));
        $this->assertNotFalse(strpos($html, 'gallery-sortby'));
        $this->assertNotFalse(strpos($html, 'gallery-sortdir'));
        $this->assertNotFalse(strpos($html, 'data-filename="img_0153.jpg"'));
        $this->assertNotFalse(strpos($html, 'data-title="'));
        $this->assertNotFalse(strpos($html, 'data-date="'));
        $this->assertNotFalse(strpos($html, 'data-search="'));
    }

    public function testNoToolbarByDefault()
    {
        $html = $this->renderGallery('');

        $this->assertFalse(strpos($html, 'gallery-toolbar'));
        $this->assertFalse(strpos($html, 'data-search'));
    }
}
