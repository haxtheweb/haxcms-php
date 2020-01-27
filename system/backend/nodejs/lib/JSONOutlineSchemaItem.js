const uuidv4 = require('uuid/v4');
/**
 * JSONOutlineSchemaItem - a single item without an outline of items.
 */
class JSONOutlineSchemaItem
{
    /**
     * Establish defaults for a new item
     */
    __construct()
    {
        this.id = 'item-' + uuidv4();
        this.indent = 0;
        this.location = '';
        this.order = 0;
        this.parent = '';
        this.title = 'New item';
        this.description = '';
        this.metadata = {};
    }
    /**
     * Load data from the location specified
     */
    readLocation(basePath = '')
    {
        if (file_exists(basePath + this.location)) {
            return file_get_contents(basePath + this.location);
        }
        return false;
    }
    /**
     * Load data from the location specified
     */
    writeLocation(body, basePath = '')
    {
        // ensure we have a blank set
        if (body == '') {
            body = '<p></p>';
        }
        if (file_exists(basePath + this.location)) {
            return file_put_contents(basePath + this.location, body);
        }
        return false;
    }
}
