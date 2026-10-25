<?php

namespace dokuwiki\plugin\gallerycollab\test;

use dokuwiki\plugin\gallerycollab\classes\CaptionStore;
use dokuwiki\plugin\gallerycollab\classes\Image;
use dokuwiki\plugin\gallerycollab\classes\NamespaceGallery;
use dokuwiki\plugin\gallerycollab\classes\Options;
use dokuwiki\plugin\gallerycollab\classes\XHTMLFormatter;
use DokuWikiTest;

/**
 * Tag (keyword) tests for the gallerycollab plugin
 *
 * @group plugin_gallerycollab
 * @group plugins
 * @author Bruce DuPlanty <bduplanty@gmail.com>
 */
class TagsTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['gallerycollab'];

    /** @inheritdoc */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $conf;
        \TestUtils::rcopy($conf['mediadir'], __DIR__ . '/data/media/gallery');
    }

    public function testNormalizeTags()
    {
        $this->assertSame(
            ['Family Reunion', 'summer', 'Café'],
            CaptionStore::normalizeTags(" Family   Reunion,summer ;\nfamily reunion, , Café,SUMMER")
        );
        $this->assertSame(['a', 'b'], CaptionStore::normalizeTags([' a ', 'A', 5, 'b']));
        $this->assertSame([], CaptionStore::normalizeTags(''));
    }

    public function testNormalizeTagsLimits()
    {
        $long = str_repeat('x', CaptionStore::MAX_TAG_LENGTH + 20);
        $this->assertSame([str_repeat('x', CaptionStore::MAX_TAG_LENGTH)], CaptionStore::normalizeTags($long));

        $many = [];
        for ($i = 0; $i < CaptionStore::MAX_TAGS + 10; $i++) $many[] = "tag$i";
        $this->assertCount(CaptionStore::MAX_TAGS, CaptionStore::normalizeTags($many));
    }

    public function testSameTagsIgnoresOrderAndCase()
    {
        $this->assertTrue(CaptionStore::sameTags(['b', 'A'], ['a', 'B']));
        $this->assertTrue(CaptionStore::sameTags([], []));
        $this->assertFalse(CaptionStore::sameTags(['a'], ['a', 'b']));
    }

    public function testTagOnlySuggestionIsAllowed()
    {
        $store = new CaptionStore('gallery:img_0153.jpg');
        $s = $store->addSuggestion('bob', '', '', ['one', 'two']);
        $this->assertSame(['one', 'two'], $s['tags']);

        $plain = $store->addSuggestion('bob', 'Title', '');
        $this->assertNull($plain['tags']);
    }

    public function testSuggestionWithoutAnythingIsRefused()
    {
        $this->expectException(\RuntimeException::class);
        (new CaptionStore('gallery:img_0153.jpg'))->addSuggestion('bob', '', '', null);
    }

    public function testEmptyTagListIsARealSuggestion()
    {
        $s = (new CaptionStore('gallery:img_0153.jpg'))->addSuggestion('bob', '', '', []);
        $this->assertSame([], $s['tags']);
    }

    public function testApprovedTagsReplaceFileTagsAndCanBeReverted()
    {
        $id = 'gallery:img_0373.jpg';
        $fileTags = (new Image($id, false))->getTags();

        $store = new CaptionStore($id);
        $store->approve('A title', 'A caption', 'admin', ['title' => '', 'description' => '', 'tags' => $fileTags], '', ['Family', 'Summer']);

        $image = new Image($id);
        $this->assertSame(['Family', 'Summer'], $image->getTags());
        $this->assertTrue($image->isOverridden('tags'));
        $this->assertSame($fileTags, (new Image($id, false))->getTags());

        // approving again without a tag list keeps the approved tags
        $store->approve('Another title', 'A caption', 'admin', ['title' => '', 'description' => '', 'tags' => ['Family', 'Summer']]);
        $this->assertSame(['Family', 'Summer'], (new Image($id))->getTags());

        // an explicit empty list removes all tags
        $store->approve('Another title', 'A caption', 'admin', ['title' => '', 'description' => '', 'tags' => ['Family']], '', []);
        $this->assertSame([], (new Image($id))->getTags());

        $store->revert('admin', ['title' => '', 'description' => '', 'tags' => []]);
        $this->assertSame($fileTags, (new Image($id))->getTags());
    }

    public function testTagsInHistory()
    {
        $id = 'gallery:img_1378.jpg';
        $store = new CaptionStore($id);
        $store->approve('T', 'D', 'admin', ['title' => 'x', 'description' => 'y', 'tags' => ['old']], '', ['new']);

        $file = CaptionStore::baseDir() . '/gallery/img_1378.jpg.json';
        $data = json_decode(file_get_contents($file), true);
        $this->assertSame(['old'], $data['history'][0]['previous']['tags']);
        $this->assertSame(['new'], $data['history'][0]['new']['tags']);
        $this->assertSame(['new'], $data['override']['tags']);
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
        (new XHTMLFormatter($renderer, $options))->render(new NamespaceGallery('gallery', $options));
        return $renderer->doc;
    }

    public function testPillsAreRenderedForApprovedTags()
    {
        $store = new CaptionStore('gallery:img_0153.jpg');
        $store->approve('T', 'D', 'admin', ['title' => '', 'description' => '', 'tags' => []], '', ['Family Reunion', 'a<b']);

        $html = $this->renderGallery('showtags');
        $this->assertNotFalse(strpos($html, '<ul class="gallery-tags">'));
        $this->assertNotFalse(strpos($html, '<li class="gallery-tag" data-tag="Family Reunion">Family Reunion</li>'));
        $this->assertNotFalse(strpos($html, 'a&lt;b'), 'tags must be escaped');
        $this->assertFalse(strpos($html, 'a<b'));
    }

    public function testNoPillsWithoutTheOption()
    {
        $store = new CaptionStore('gallery:img_0153.jpg');
        $store->approve('T', 'D', 'admin', ['title' => '', 'description' => '', 'tags' => []], '', ['Family Reunion']);

        $this->assertFalse(strpos($this->renderGallery(''), 'gallery-tags'));
    }

    public function testTagsAreSearchable()
    {
        $store = new CaptionStore('gallery:img_0153.jpg');
        $store->approve('T', 'D', 'admin', ['title' => '', 'description' => '', 'tags' => []], '', ['Family Reunion']);

        $html = $this->renderGallery('toolbar');
        $this->assertSame(1, preg_match('/data-search="[^"]*Family Reunion/', $html));
    }

    public function testShowtagsOptionParsing()
    {
        $options = new Options();
        $this->assertFalse($options->showtags);
        $options->parseParameters('showtags');
        $this->assertTrue($options->showtags);
        $options->parseParameters('noshowtags');
        $this->assertFalse($options->showtags);
    }
}
