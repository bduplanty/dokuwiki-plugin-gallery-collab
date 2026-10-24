<?php

namespace dokuwiki\plugin\gallerycollab\test;

use dokuwiki\plugin\gallerycollab\classes\CaptionStore;
use dokuwiki\plugin\gallerycollab\classes\Image;
use DokuWikiTest;

/**
 * Caption suggestion/review storage tests for the gallery plugin
 *
 * @group plugin_gallerycollab
 * @group plugins
 *
 * @author Bruce DuPlanty <bduplanty@gmail.com>
 */
class CaptionStoreTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['gallerycollab'];

    /** @inheritdoc */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $conf;
        \TestUtils::rcopy($conf['mediadir'], __DIR__ . '/data/media/gallery');
    }

    public function testSuggestionLifecycle()
    {
        $store = new CaptionStore('gallery:img_0153.jpg');
        $this->assertSame([], $store->getSuggestions());

        $s = $store->addSuggestion('bob', 'A title', "multi\nline  caption");
        $this->assertSame(CaptionStore::PENDING, $s['status']);
        $this->assertSame('multi line caption', $s['description']);

        $this->assertTrue($store->reject($s['id'], 'admin'));
        $this->assertSame(CaptionStore::REJECTED, $store->getSuggestions()[0]['status']);

        $this->assertTrue($store->delete($s['id']));
        $this->assertSame([], $store->getSuggestions());
    }

    public function testEmptySuggestionIsRefused()
    {
        $this->expectException(\RuntimeException::class);
        (new CaptionStore('gallery:img_0153.jpg'))->addSuggestion('bob', ' ', '');
    }

    public function testPendingLimit()
    {
        $store = new CaptionStore('gallery:img_0373.jpg');
        for ($i = 0; $i < CaptionStore::MAX_PENDING; $i++) {
            $store->addSuggestion('bob', "t$i", '');
        }
        $this->expectException(\RuntimeException::class);
        $store->addSuggestion('bob', 'one too many', '');
    }

    public function testApprovedOverrideReplacesMetadataAndCanBeReverted()
    {
        $id = 'gallery:img_1378.jpg';
        $store = new CaptionStore($id);
        $s = $store->addSuggestion('bob', 'New title', 'New caption');
        $store->approve('New title', 'Edited caption', 'admin', ['title' => 'old', 'description' => ''], $s['id']);

        $this->assertSame(CaptionStore::APPROVED, $store->getSuggestions()[0]['status']);

        $image = new Image($id);
        $this->assertSame('New title', $image->getTitle());
        $this->assertSame('Edited caption', $image->getDescription());
        $this->assertTrue($image->isOverridden('title'));

        $original = new Image($id, false);
        $this->assertFalse($original->isOverridden('title'));
        $this->assertNotSame('New title', $original->getTitle());

        $store->revert('admin', ['title' => 'New title', 'description' => 'Edited caption']);
        $this->assertNull($store->getOverride());
        $this->assertSame($original->getTitle(), (new Image($id))->getTitle());
    }
}
