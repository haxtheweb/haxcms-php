<?php
include_once dirname(__FILE__) . '/Entity.php';
/**
 * A single file entity record (Phase 2, issue #3043).
 *
 * Fields: uuid, path, name, mimetype, size, width, height, dateCreated,
 * fullUrl, url. save()/delete() are inherited from Entity, which enforces
 * the read-only guard (file is datastore => writable) and delegates to the
 * FileStorage adapter registered on the EntityRegistry.
 *
 * Constructed by FileStorage->load() with the EntityDefinition for 'file'
 * (so $entity->save() resolves back to the same FileStorage via the
 * definition's storage resolver).
 */
class FileEntity extends Entity
{
    /** @return string|null The stable files.json-sourced UUID. */
    public function getUuid()
    {
        return $this->get('uuid');
    }

    /** @return string|null The API path, e.g. 'files/banner.jpg'. */
    public function getPath()
    {
        return $this->get('path');
    }

    /** @return string|null The file name (basename of path). */
    public function getName()
    {
        return $this->get('name');
    }

    /** @return string|null The MIME type. */
    public function getMimetype()
    {
        return $this->get('mimetype');
    }

    /** @return int|null The file size in bytes. */
    public function getSize()
    {
        $value = $this->get('size');
        return $value === null ? null : (int) $value;
    }

    /** @return int|null Pixel width for images; null/0 for non-images. */
    public function getWidth()
    {
        $value = $this->get('width');
        return $value === null ? null : (int) $value;
    }

    /** @return int|null Pixel height for images; null/0 for non-images. */
    public function getHeight()
    {
        $value = $this->get('height');
        return $value === null ? null : (int) $value;
    }

    /** @return int|null Unix timestamp (seconds) of file creation/modification. */
    public function getDateCreated()
    {
        $value = $this->get('dateCreated');
        return $value === null ? null : (int) $value;
    }

    /** @return string|null The full URL (basePath + sitesDirectory + siteName + path). */
    public function getFullUrl()
    {
        return $this->get('fullUrl');
    }

    /** @return string|null The relative URL (same as path). */
    public function getUrl()
    {
        return $this->get('url');
    }

    /**
     * Whether this record represents an image (by mimetype prefix).
     *
     * @return bool
     */
    public function isImage()
    {
        $mimetype = (string) $this->getMimetype();
        return strpos($mimetype, 'image/') === 0 && $mimetype !== 'image/svg+xml';
    }
}
