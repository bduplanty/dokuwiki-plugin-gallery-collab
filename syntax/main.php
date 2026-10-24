<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\File\MediaResolver;
use dokuwiki\plugin\gallerycollab\classes\BasicFormatter;
use dokuwiki\plugin\gallerycollab\classes\FeedGallery;
use dokuwiki\plugin\gallerycollab\classes\ListGallery;
use dokuwiki\plugin\gallerycollab\classes\NamespaceGallery;
use dokuwiki\plugin\gallerycollab\classes\Options;
use dokuwiki\plugin\gallerycollab\classes\XHTMLFormatter;

/**
 * Embed an image gallery
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Joe Lapp <joe.lapp@pobox.com>
 * @author     Dave Doyle <davedoyle.canadalawbook.ca>
 * @author     Bruce DuPlanty <bduplanty@gmail.com>
 */
class syntax_plugin_gallerycollab_main extends SyntaxPlugin
{
    /** @inheritdoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 301;
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('\{\{gallery>[^}]*\}\}', $mode, 'plugin_gallerycollab_main');
    }

    /** @inheritdoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;
        $match = substr($match, 10, -2); //strip markup from start and end

        $options = new Options();

        // unique gallery ID
        $options->galleryID = substr(md5($match), 0, 4);

        // alignment
        if (substr($match, 0, 1) == ' ') $options->align += Options::ALIGN_RIGHT;
        if (substr($match, -1, 1) == ' ') $options->align += Options::ALIGN_LEFT;

        // extract src and params
        [$src, $params] = sexplode('?', $match, 2);
        $src = trim($src);

        // resolve relative namespace
        if (!preg_match('/^https?:\/\//i', $src)) {
            $mediaResolver = new MediaResolver($ID);
            $src = $mediaResolver->resolveId($src);
        }

        // parse parameters
        $options->parseParameters($params);

        return [
            $src, $options->toArray()
        ];
    }

    /** @inheritdoc */
    public function render($mode, Doku_Renderer $R, $data)
    {
        [$src, $options] = $data;
        $options = Options::fromArray($options);

        try {
            if (is_array($src)) {
                $gallery = new ListGallery($src, $options);
            } elseif (preg_match('/^https?:\/\//i', $src)) {
                $gallery = new FeedGallery($src, $options);
            } else {
                $gallery = new NamespaceGallery($src, $options);
            }

            // may only disable caching, never re-enable it after ~~NOCACHE~~ or another plugin turned it off
            if (!$options->cache) $R->info['cache'] = false;
            if ($mode == 'xhtml') {
                $formatter = new XHTMLFormatter($R, $options);
            } else {
                $formatter = new BasicFormatter($R, $options);
            }
            $formatter->render($gallery);
        } catch (Exception $e) {
            msg(hsc($e->getMessage()), -1);
            $R->cdata($this->getLang('fail'));
        }
        return true;
    }
}
