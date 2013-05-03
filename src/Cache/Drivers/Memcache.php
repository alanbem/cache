<?php
/**
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Nikita Vershinin <endeveit@gmail.com>
 * @license MIT
 */
namespace Cache\Drivers;

use Cache\Exception;

/**
 * Driver that stores data in Memcached and uses \Memcache.
 * This driver was inspired by the TrekkSoft AG Zend_Cache memcached backend.
 *
 * @link https://github.com/bigwhoop/taggable-zend-memcached-backend
 */
class Memcache extends Common
{

    /**
     * Separator which concatenates tags.
     *
     * @const string
     */
    const TAG_SEPARATOR = '|';

    /**
     * Format of the tag name.
     *
     * @const string
     */
    const TAG_NAME_FORMAT = '_tag_%s';

    /**
     * \Memcache connection object.
     *
     * @var \Memcache
     */
    protected $client;

    /**
     * Store the items compressed or not.
     *
     * @var integer
     */
    protected $flag = 0;

    /**
     * The class constructor.
     * If $compress provided, the items will be stored compressed.
     *
     * @param \Memcache $client
     * @param boolean   $compress
     */
    public function __construct(\Memcache $client, $compress = false)
    {
        $this->client = $client;

        if ($compress) {
            $this->flag = MEMCACHE_COMPRESSED;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param  string  $id
     * @param  integer $value
     * @return integer
     */
    public function increment($id, $value = 1)
    {
        return $this->client->increment($id, $value);
    }

    /**
     * {@inheritdoc}
     *
     * @param  string  $id
     * @param  integer $value
     * @return integer
     */
    public function decrement($id, $value = 1)
    {
        return $this->client->decrement($id, $value);
    }

    /**
     * {@inheritdoc}
     *
     * @param  string      $id
     * @return mixed|false
     */
    protected function doLoad($id)
    {
        $result = $this->client->get($id, intval($this->flag));

        if (is_array($result) && isset($result[0])) {
            return $result[0];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array $identifiers
     * @return array
     */
    protected function doLoadMany(array $identifiers)
    {
        $result = array();

        foreach ($this->client->get($identifiers, intval($this->flag)) as $identifier => $row) {
            if (is_array($row) && isset($row[0])) {
                $result[$identifier] = $row[0];
            }
        }

        $this->fillNotFoundKeys($result, $identifiers);

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @param  mixed           $data
     * @param  string          $id
     * @param  array           $tags
     * @param  integer|boolean $lifetime
     * @return boolean
     */
    protected function doSave($data, $id, array $tags = array(), $lifetime = false)
    {
        $this->validateIdentifier($id);

        if (!empty($tags)) {
            $this->saveTagsForId($id, $tags);
        }

        return $this->client->set(
            $id,
            array($data, time(), $lifetime),
            $this->flag,
            (integer) $lifetime
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param  string  $id
     * @return boolean
     */
    protected function doRemove($id)
    {
        return $this->client->delete($id);
    }

    /**
     * Remove an items by cache tags.
     *
     * @param  array   $tags
     * @return boolean
     */
    protected function doRemoveByTags(array $tags)
    {
        foreach ($this->getIdsMatchingAnyTags($tags) as $entryId) {
            $this->remove($entryId);
            $this->removeIdFromTags($tags, $entryId);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param  string  $id
     * @param  integer $extraLifetime
     * @return boolean
     */
    protected function doTouch($id, $extraLifetime)
    {
        $tmp = $this->client->get($id);

        if (is_array($tmp)) {
            list($data, $mtime, $lifetime) = $tmp;

            // Calculate new lifetime
            $newLT = $lifetime - (time() - $mtime) + $extraLifetime;

            if ($newLT <= 0) {
                return false;
            }

            $data = array($data, time(), $newLT);

            // We try replace() first becase set() seems to be slower
            $result = $this->client->replace($id, $data, $this->flag, $newLT);

            if (!$result) {
                $result = $this->client->set($id, $data, $this->flag, $newLT);
            }

            return $result;
        }

        return false;
    }

    /**
     * Validates cache identifier or a tag, throws an exception in
     * case of a problem.
     *
     * @param  string           $id
     * @throws \Cache\Exception
     */
    protected function validateIdentifier($id)
    {
        if (!preg_match('#^[a-zA-Z0-9_]+$#D', $id)) {
            throw new Exception(
                'Invalid identifier: must use only [a-zA-Z0-9_].'
            );
        }
    }

    /**
     * Save the tags for identifier.
     *
     * @param string $id
     * @param array  $tags
     */
    protected function saveTagsForId($id, array $tags)
    {
        foreach ($tags as $tag) {
            $idsInTag = $this->getIdentifiersForTag($tag);

            if (!in_array($id, $idsInTag)) {
                $idsInTag[] = $id;

                $this->save(
                    implode(self::TAG_SEPARATOR, $idsInTag),
                    $this->getIdentifierForTag($tag)
                );
            }
        }
    }

    /**
     * Removes identifier from the tags.
     *
     * @param array  $tags
     * @param string $id
     */
    protected function removeIdFromTags(array $tags, $id)
    {
        foreach ($tags as $tag) {
            $this->removeIdFromTag($tag, $id);
        }
    }

    /**
     * Removes identifier from the tag.
     *
     * @param string $tag
     * @param string $id
     */
    protected function removeIdFromTag($tag, $id)
    {
        $identifiers = $this->getIdentifiersForTag($tag);
        $indexOfId   = array_search($id, $identifiers);

        if ($indexOfId > -1) {
            unset($identifiers[$indexOfId]);

            $tagValue = implode(self::TAG_SEPARATOR, $identifiers);
            $this->save($tagValue, $this->getIdentifierForTag($tag));
        }
    }

    /**
     * Return an array of stored cache ids which match given tags.
     *
     * @param  array $tags
     * @return array
     */
    protected function getIdsMatchingAnyTags($tags = array())
    {
        $result = array();

        foreach ($tags as $tag) {
            $result = array_merge($result, $this->getIdentifiersForTag($tag));
        }

        return array_unique($result);
    }

    /**
     * Returns list of identifiers for tag.
     *
     * @param  string $tag
     * @return array
     */
    protected function getIdentifiersForTag($tag)
    {
        $identifiers = $this->load($this->getIdentifierForTag($tag));

        if (empty($identifiers)) {
            return array();
        }

        return explode(self::TAG_SEPARATOR, (string) $identifiers);
    }

    /**
     * Returns identifier for tag.
     *
     * @param  string $tag
     * @return string
     */
    protected function getIdentifierForTag($tag)
    {
        return sprintf(self::TAG_NAME_FORMAT, $tag);
    }

}
