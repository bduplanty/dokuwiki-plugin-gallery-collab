<?php

namespace dokuwiki\plugin\gallerycollab\classes;

use dokuwiki\Utf8\PhpString;

/**
 * Sidecar storage for caption suggestions and reviewed caption overrides
 *
 * One JSON file per image below data/gallery-collab/. The image file itself is never touched here.
 *
 * Layout of a sidecar file:
 *   suggestions: list of {id, user, time, title, description, tags, status, reviewer, reviewed}
 *                tags is the proposed complete tag list, or null if the suggestion does not touch tags
 *   override:    null or {title, description, tags, by, time} - the approved caption and tags shown in
 *                galleries; tags is null (keep the file's tags) or a complete list (may be empty)
 *   history:     list of {time, by, suggestion, previous: {title, description, tags},
 *                new: {title, description, tags}}
 *
 * @author Bruce DuPlanty <bduplanty@gmail.com>
 */
class CaptionStore
{
    public const MAX_TITLE = 200;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_PENDING = 10;
    public const MAX_TAGS = 30;
    public const MAX_TAG_LENGTH = 50;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $id;
    protected $file;

    /**
     * @param string $id media ID of the (local) image
     */
    public function __construct($id)
    {
        $this->id = cleanID($id);
        $this->file = self::baseDir() . '/' . str_replace(':', '/', $this->id) . '.json';
    }

    /**
     * Folder holding all sidecar files
     *
     * $conf['savedir'] may be relative ("./data") and is then relative to the running script
     * (doku.php vs. lib/exe/ajax.php), so it has to be anchored at the wiki root.
     *
     * @return string absolute path without trailing slash
     */
    public static function baseDir()
    {
        global $conf;
        $dir = $conf['savedir'];
        if (!preg_match('~^(/|[a-zA-Z]:[\\\\/])~', $dir)) $dir = DOKU_INC . $dir;
        return rtrim(fullpath($dir), '/') . '/gallery-collab';
    }

    /**
     * File whose modification time changes whenever a displayed caption changes
     *
     * Rendered gallery pages depend on it, see action_plugin_gallerycollab_collab::addCacheDependency()
     *
     * @return string
     */
    public static function stampFile()
    {
        return self::baseDir() . '/.stamp';
    }

    /**
     * Mark all cached gallery pages as outdated
     */
    public static function touchStamp()
    {
        io_saveFile(self::stampFile(), (string)microtime(true));
    }

    /**
     * @return array|null the approved override {title, description, by, time} or null
     */
    public function getOverride()
    {
        return $this->load()['override'];
    }

    /**
     * @return array[] all suggestions, oldest first
     */
    public function getSuggestions()
    {
        return $this->load()['suggestions'];
    }

    /**
     * Add a new pending suggestion
     *
     * @param string $user login name of the contributor
     * @param string $title suggested title (may be empty)
     * @param string $description suggested caption (may be empty)
     * @param string[]|null $tags the proposed complete tag list, null if tags are not part of the suggestion
     * @return array the new suggestion
     * @throws \RuntimeException with message 'empty' or 'limit'
     */
    public function addSuggestion($user, $title, $description, $tags = null)
    {
        $title = $this->cleanTitle($title);
        $description = $this->cleanDescription($description);
        if ($tags !== null) $tags = self::normalizeTags($tags);
        if ($title === '' && $description === '' && $tags === null) throw new \RuntimeException('empty');

        return $this->update(function (&$data) use ($user, $title, $description, $tags) {
            $pending = 0;
            foreach ($data['suggestions'] as $s) {
                if ($s['user'] === $user && $s['status'] === self::PENDING) $pending++;
            }
            if ($pending >= self::MAX_PENDING) throw new \RuntimeException('limit');

            $suggestion = [
                'id' => substr(md5(uniqid('', true)), 0, 8),
                'user' => $user,
                'time' => time(),
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'status' => self::PENDING,
                'reviewer' => '',
                'reviewed' => 0,
            ];
            $data['suggestions'][] = $suggestion;
            return $suggestion;
        });
    }

    /**
     * Mark a suggestion as rejected
     *
     * @param string $sid suggestion ID
     * @param string $reviewer
     * @return bool false if the suggestion does not exist
     */
    public function reject($sid, $reviewer)
    {
        return $this->update(function (&$data) use ($sid, $reviewer) {
            foreach ($data['suggestions'] as &$s) {
                if ($s['id'] !== $sid) continue;
                $s['status'] = self::REJECTED;
                $s['reviewer'] = $reviewer;
                $s['reviewed'] = time();
                return true;
            }
            return false;
        });
    }

    /**
     * Remove a suggestion completely
     *
     * @param string $sid
     * @return bool false if the suggestion does not exist
     */
    public function delete($sid)
    {
        return $this->update(function (&$data) use ($sid) {
            $count = count($data['suggestions']);
            $data['suggestions'] = array_values(array_filter(
                $data['suggestions'],
                function ($s) use ($sid) {
                    return $s['id'] !== $sid;
                }
            ));
            return count($data['suggestions']) !== $count;
        });
    }

    /**
     * Make the given text the displayed caption of the image
     *
     * Records the replaced values in the history. An empty field means "keep the file's own value".
     *
     * @param string $title
     * @param string $description
     * @param string $reviewer login name of the reviewer
     * @param array $previous {title, description} that were shown before the change
     * @param string $sid ID of the suggestion this is based on, empty for a direct edit
     * @param string[]|null $tags the approved complete tag list; null leaves the tags as they are
     *                            (an earlier approved list stays, otherwise the file's tags are used)
     */
    public function approve($title, $description, $reviewer, array $previous, $sid = '', $tags = null)
    {
        $title = $this->cleanTitle($title);
        $description = $this->cleanDescription($description);
        if ($tags !== null) $tags = self::normalizeTags($tags);

        $this->update(function (&$data) use ($title, $description, $reviewer, $previous, $sid, $tags) {
            $now = time();
            foreach ($data['suggestions'] as &$s) {
                if ($s['id'] !== $sid) continue;
                $s['status'] = self::APPROVED;
                $s['reviewer'] = $reviewer;
                $s['reviewed'] = $now;
            }
            unset($s);

            if ($tags === null) $tags = $data['override']['tags'] ?? null;

            $data['override'] = [
                'title' => $title,
                'description' => $description,
                'tags' => $tags,
                'by' => $reviewer,
                'time' => $now,
            ];
            $data['history'][] = [
                'time' => $now,
                'by' => $reviewer,
                'suggestion' => $sid,
                'previous' => $previous,
                'new' => ['title' => $title, 'description' => $description, 'tags' => $tags],
            ];
        });
    }

    /**
     * Drop the approved override so the file's own metadata is shown again
     *
     * @param string $reviewer
     * @param array $previous {title, description} that were shown before the change
     */
    public function revert($reviewer, array $previous)
    {
        $this->update(function (&$data) use ($reviewer, $previous) {
            if ($data['override'] === null) return;
            $data['override'] = null;
            $data['history'][] = [
                'time' => time(),
                'by' => $reviewer,
                'suggestion' => '',
                'previous' => $previous,
                'new' => null,
            ];
        });
    }

    // region Tags

    /**
     * Turn user input into a clean tag list
     *
     * Accepts a list or a string separated by commas, semicolons or line breaks. Tags are trimmed, have
     * inner whitespace collapsed, are cut to MAX_TAG_LENGTH, and duplicates (ignoring case) are dropped;
     * at most MAX_TAGS are kept. The order of first appearance is preserved.
     *
     * @param string|string[] $tags
     * @return string[]
     */
    public static function normalizeTags($tags)
    {
        if (!is_array($tags)) $tags = preg_split('/[,;\r\n]+/', (string)$tags);

        $result = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) continue;
            $tag = trim(preg_replace('/[\s\p{Cc}]+/u', ' ', $tag));
            $tag = PhpString::substr($tag, 0, self::MAX_TAG_LENGTH);
            $key = PhpString::strtolower($tag);
            if ($tag === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $tag;
            if (count($result) >= self::MAX_TAGS) break;
        }
        return $result;
    }

    /**
     * Do two tag lists contain the same tags, ignoring order and case?
     *
     * @param string[] $a
     * @param string[] $b
     * @return bool
     */
    public static function sameTags(array $a, array $b)
    {
        $fold = function (array $tags) {
            $tags = array_map([PhpString::class, 'strtolower'], self::normalizeTags($tags));
            sort($tags);
            return $tags;
        };
        return $fold($a) === $fold($b);
    }

    // endregion

    // region Internals

    protected function cleanTitle($title)
    {
        $title = trim(preg_replace('/\s+/', ' ', (string)$title));
        return PhpString::substr($title, 0, self::MAX_TITLE);
    }

    protected function cleanDescription($description)
    {
        $description = trim(preg_replace('/\s+/', ' ', (string)$description));
        return PhpString::substr($description, 0, self::MAX_DESCRIPTION);
    }

    /**
     * @return array
     */
    protected function load()
    {
        $json = io_readFile($this->file, false);
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data)) $data = [];
        return $data + ['suggestions' => [], 'override' => null, 'history' => []];
    }

    /**
     * Run a callback on the loaded data under a lock and save the result
     *
     * @param callable $fn receives the data by reference, its return value is returned
     * @return mixed
     */
    protected function update(callable $fn)
    {
        io_lock($this->file);
        try {
            $data = $this->load();
            $result = $fn($data);
            io_saveFile($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $result;
        } finally {
            io_unlock($this->file);
        }
    }

    // endregion
}
