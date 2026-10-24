<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\gallerycollab\classes\CaptionStore;
use dokuwiki\plugin\gallerycollab\classes\Image;

/**
 * Collaborative captions: group members suggest titles/captions, reviewers approve them
 *
 * Suggestions live in JSON sidecar files (see CaptionStore). An approved caption overrides the file's
 * metadata when galleries are rendered and, optionally, is written into the IPTC fields of JPEGs.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Bruce DuPlanty <bduplanty@gmail.com>
 */
class action_plugin_gallerycollab_collab extends ActionPlugin
{
    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'exportToJS');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'addCacheDependency');
    }

    // region Event handlers

    /**
     * Tell the JavaScript what the current user may do
     */
    public function exportToJS(Event $event, $param)
    {
        global $JSINFO;
        if (!$this->canSuggest()) return;
        $JSINFO['plugins']['gallerycollab_access'] = [
            'sectok' => getSecurityToken(),
            'review' => $this->isReviewer(),
        ];
    }

    /**
     * Rendered gallery pages must be refreshed when a caption was approved
     */
    public function addCacheDependency(Event $event, $param)
    {
        $cache = $event->data;
        if (!$cache instanceof \dokuwiki\Cache\CacheParser || $cache->mode !== 'xhtml') return;

        $stamp = CaptionStore::stampFile();
        if (!file_exists($stamp)) return;
        // no check for gallery syntax in the page source: galleries may come in through included pages
        $cache->depends['files'][] = $stamp;
    }

    /**
     * AJAX endpoint: ajax.php?call=gallerycollab, op = get|suggest|approve|reject|delete|revert
     */
    public function handleAjax(Event $event, $param)
    {
        if ($event->data !== 'gallerycollab') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;
        header('Content-Type: application/json');
        try {
            echo json_encode($this->dispatch($INPUT));
        } catch (\RuntimeException $e) {
            http_response_code($e->getCode() ?: 400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // endregion

    // region Operations

    /**
     * @param \dokuwiki\Input\Input $INPUT
     * @return array
     */
    protected function dispatch($INPUT)
    {
        if ($INPUT->server->str('REQUEST_METHOD') !== 'POST' || !checkSecurityToken()) {
            throw new \RuntimeException($this->getLang('err_token'), 403);
        }
        if (!$this->canSuggest()) throw new \RuntimeException($this->getLang('err_perm'), 403);

        $user = $INPUT->server->str('REMOTE_USER');
        if ($INPUT->str('op') === 'pending') return $this->pendingCounts($INPUT->arr('ids'), $user);

        $id = cleanID($INPUT->str('id'));
        $this->checkImage($id);
        $sid = $INPUT->str('sid');
        $store = new CaptionStore($id);

        switch ($INPUT->str('op')) {
            case 'get':
                break;

            case 'suggest':
                try {
                    $store->addSuggestion($user, $INPUT->str('title'), $INPUT->str('description'));
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException($this->getLang('err_' . $e->getMessage()), 400);
                }
                break;

            case 'delete':
                // authors may withdraw their own suggestions, reviewers any
                if (!$this->isReviewer() && !$this->isOwnPending($store, $sid, $user)) {
                    throw new \RuntimeException($this->getLang('err_perm'), 403);
                }
                $store->delete($sid);
                break;

            case 'reject':
                $this->requireReviewer();
                $store->reject($sid, $user);
                break;

            case 'approve':
                $this->requireReviewer();
                $title = $INPUT->str('title');
                $description = $INPUT->str('description');
                if (trim($title) === '' && trim($description) === '') {
                    throw new \RuntimeException($this->getLang('err_empty'), 400);
                }
                $store->approve($title, $description, $user, $this->currentCaption($id), $sid);
                CaptionStore::touchStamp();
                $result = $this->state($id, $user);
                $result['iptc'] = $this->writeIptc($id, $title, $description);
                return $result;

            case 'revert':
                $this->requireReviewer();
                $store->revert($user, $this->currentCaption($id));
                CaptionStore::touchStamp();
                break;

            default:
                throw new \RuntimeException('unknown op', 400);
        }

        return $this->state($id, $user);
    }

    /**
     * Number of pending suggestions per image, for the badges on the thumbnails
     *
     * Reviewers get the count of all pending suggestions, contributors only of their own.
     * Images without pending suggestions, unreadable or unknown images are left out.
     *
     * @param string[] $ids media IDs
     * @param string $user
     * @return array map of media ID to count
     */
    protected function pendingCounts($ids, $user)
    {
        $reviewer = $this->isReviewer();
        $counts = [];
        foreach (array_slice(array_unique($ids), 0, 500) as $raw) {
            $id = cleanID($raw);
            if ($id === '' || !preg_match(Image::IMG_REGEX, $id)) continue;
            if (auth_quickaclcheck(getNS($id) . ':*') < AUTH_READ) continue;

            $count = 0;
            foreach ((new CaptionStore($id))->getSuggestions() as $s) {
                if ($s['status'] !== CaptionStore::PENDING) continue;
                if ($reviewer || $s['user'] === $user) $count++;
            }
            if ($count) $counts[$id] = $count;
        }
        return ['pending' => $counts];
    }

    /**
     * State of an image as shown in the dialog
     *
     * Reviewers see all suggestions, contributors only their own.
     *
     * @param string $id
     * @param string $user
     * @return array
     */
    protected function state($id, $user)
    {
        $store = new CaptionStore($id);
        $current = new Image($id);
        $original = new Image($id, false);

        $suggestions = array_filter($store->getSuggestions(), function ($s) use ($user) {
            return $this->isReviewer() || $s['user'] === $user;
        });

        return [
            'id' => $id,
            'name' => $current->getFilename(),
            'current' => ['title' => $current->getTitle(), 'description' => $current->getDescription()],
            'original' => ['title' => $original->getTitle(), 'description' => $original->getDescription()],
            'overridden' => $store->getOverride() !== null,
            'suggestions' => array_values($suggestions),
        ];
    }

    /**
     * The caption currently displayed for the image (for the history)
     *
     * @param string $id
     * @return array
     */
    protected function currentCaption($id)
    {
        $current = new Image($id);
        return ['title' => $current->getTitle(), 'description' => $current->getDescription()];
    }

    /**
     * Write title and caption into the IPTC fields of a JPEG, if enabled and possible
     *
     * The sidecar override stays in place regardless, it is what galleries show.
     *
     * @param string $id
     * @param string $title
     * @param string $description
     * @return bool true if the file was changed
     */
    protected function writeIptc($id, $title, $description)
    {
        if (!$this->getConf('write_iptc')) return false;
        if (!preg_match('/\.jpe?g$/i', $id)) return false;
        if (auth_quickaclcheck(getNS($id) . ':*') < AUTH_UPLOAD) return false;

        $file = mediaFN($id);
        if (!is_writable($file)) return false;

        try {
            $meta = new \JpegMeta($file);
            if (trim($title) !== '') $meta->setField('Iptc.Headline', trim($title));
            if (trim($description) !== '') $meta->setField('Iptc.Caption', trim($description));
            return (bool)$meta->save();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // endregion

    // region Permissions

    /**
     * @param string $id
     * @throws \RuntimeException
     */
    protected function checkImage($id)
    {
        if ($id === '' || !preg_match(Image::IMG_REGEX, $id) || !media_exists($id)) {
            throw new \RuntimeException($this->getLang('err_notfound'), 404);
        }
        // media files have no ACL of their own, the namespace decides
        if (auth_quickaclcheck(getNS($id) . ':*') < AUTH_READ) {
            throw new \RuntimeException($this->getLang('err_perm'), 403);
        }
    }

    protected function requireReviewer()
    {
        if (!$this->isReviewer()) throw new \RuntimeException($this->getLang('err_perm'), 403);
    }

    protected function isOwnPending(CaptionStore $store, $sid, $user)
    {
        foreach ($store->getSuggestions() as $s) {
            if ($s['id'] === $sid) {
                return $s['user'] === $user && $s['status'] === CaptionStore::PENDING;
            }
        }
        return false;
    }

    /**
     * Reviewers are superusers and members of the reviewer groups
     *
     * @return bool
     */
    public function isReviewer()
    {
        global $INPUT;
        if (!$INPUT->server->str('REMOTE_USER')) return false;
        return auth_isadmin() || $this->inGroups($this->getConf('reviewer_groups'));
    }

    /**
     * Contributors are logged in members of the contributor groups, reviewers can always contribute
     *
     * @return bool
     */
    public function canSuggest()
    {
        global $INPUT;
        if (!$INPUT->server->str('REMOTE_USER')) return false;
        return $this->isReviewer() || $this->inGroups($this->getConf('contributor_groups'));
    }

    /**
     * Is the current user in any of the comma separated groups?
     *
     * @param string $groups
     * @return bool
     */
    protected function inGroups($groups)
    {
        global $USERINFO;
        $wanted = array_filter(array_map(function ($g) {
            return ltrim(trim($g), '@');
        }, explode(',', $groups)));
        return (bool)array_intersect($wanted, $USERINFO['grps'] ?? []);
    }

    // endregion
}
