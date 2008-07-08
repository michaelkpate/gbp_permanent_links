<?php

$plugin['url'] = '$HeadURL$';
$plugin['date'] = '$LastChangedDate$';
$plugin['revision'] = '$LastChangedRevision$';
$plugin['name'] = 'gbp_permanent_links';
$plugin['version'] = '0.11'.(preg_match('/: (\d+) \$$/', $plugin['revision'], $revision) ? '.'.$revision[1] : '');
$plugin['author'] = 'Graeme Porteous';
$plugin['author_uri'] = 'http://rgbp.co.uk/projects/textpattern/gbp_permanent_links/';
$plugin['description'] = 'Custom permanent links rules';
$plugin['type'] = '1';

@include_once('../zem_tpl.php');

if (0) {
?>
<!-- HELP SECTION
# --- BEGIN PLUGIN HELP ---
h1. gbp_permanent_links.

There is no plugin documentation. For help please use the "forum thread":http://forum.textpattern.com/viewtopic.php?id=18918.
# --- END PLUGIN HELP ---
-->
<?php
}
# --- BEGIN PLUGIN CODE ---

require_plugin('gbp_admin_library');

$GLOBALS['PermanentLinksModels'] = array();

class PermanentLinks extends GBPPlugin {
  function preload () {
    // Register the default route models and fields
    // Articles
    new PermanentLinksModel('Article',   'textpattern');
    new PermanentLinksField('ID',        'integer');
    new PermanentLinksField('Date',      'date',         'Posted');
    new PermanentLinksField('Author',    'has_one',      array('model' => 'txp_users',    'key' => 'user_id', 'AuthorID'));
    new PermanentLinksField('Category',  'has_many',     array('model' => 'txp_category', 'key' => 'name',    'Category1', 'Category2'));
    new PermanentLinksField('Section',   'has_one',      array('model' => 'txp_section',  'key' => 'name',    'Section'));
    new PermanentLinksField('Keywords',  'csv');
    new PermanentLinksField('Title',     'string',       'url_title');
    // Images
    new PermanentLinksModel('Image',     'txp_image');
    new PermanentLinksField('ID',        'integer',      'id');
    new PermanentLinksField('Name',      'string',       'name');
    new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'category'));
    new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name', 'author'));
    // Files
    new PermanentLinksModel('File',      'txp_file');
    new PermanentLinksField('ID',        'integer',      'id');
    new PermanentLinksField('Name',      'string',       'filename');
    new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'category'));
    new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name', 'author'));
    // Links
    new PermanentLinksModel('Link',      'txp_link');
    new PermanentLinksField('ID',        'integer',      'id');
    new PermanentLinksField('Name',      'string',       'linkname');
    new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'category'));
    new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name', 'author'));
    // Author   (archive)
    new PermanentLinksModel('Author',    'txp_users',    'archive');
    new PermanentLinksField('Login',     'string',       'name');
    new PermanentLinksField('Full name', 'string',       'RealName');
    // Category (archive)
    new PermanentLinksModel('Category',  'txp_category', 'archive');
    new PermanentLinksField('Title',     'string',       'name');
    // Section  (archive)
    new PermanentLinksModel('Section',   'txp_section',  'archive');
    new PermanentLinksField('Title',     'string',       'name');

    // TODO: Register custom route models and fields from other plugins
  }

  function main() {
    require_privs($this->event);
  }
}

class PermanentLinksModel {
  var $model;
  var $table;
  var $type = 'content';
  var $fields = array();

  function PermanentLinksModel($model, $table, $type = null) {
    $this->model = $model;
    $this->table = $table;
    if ($type !== null) $this->type = $type;

    // Store a reference back to the class
    $GLOBALS['PermanentLinksModels'][$table] = &$this;
    end($GLOBALS['PermanentLinksModels']);
  }

  function add_field($field) {
    $field->parent_model = $this->table;
    $this->fields[] = $field;
  }
}

class PermanentLinksField {
  var $name;
  var $kind;
  var $fields = array();
  var $model;
  var $key;
  var $parent_model;

  function PermanentLinksField($name, $kind, $association = null, $parent = null) {
    $this->name = $name;
    $this->kind = $kind;

    switch ($kind) {
      case 'has_one':
        $this->add_field_key($association[0]);
        $this->model = $association['model'];
        $this->key   = $association['key'];

        break;
      case 'has_many':
        $i = 0;
        do {
          $field = @$association[$i++];
          if ($field === null) break;
          $this->add_field_key($field);
        } while (1);

        $this->model = $association['model'];
        $this->key   = $association['key'];

        break;
      default:
        $this->add_field_key(($association === null) ? $name : $association);

        break;
    }

    // Check we've got everything we need to continue
    if (is_string($this->name) and is_string($this->kind) and count($this->fields)) {
      // Add field to the parent model
      if ($parent === null) $parent = current($GLOBALS['PermanentLinksModels']);
      $parent->add_field(&$this);
    }
    else die('Something went wrong with a PermanentLinksField constructor');
  }

  function add_field_key($key = null) {
    // check field key is a string
    if (is_string($key)) $this->fields[] = $key;
  }

  function parent() {
    return $GLOBALS['PermanentLinksModels'][$this->parent_model];
  }
}

if (@txpinterface == 'admin') {
  new PermanentLinks('Permanent Links', 'permlinks', 'admin');
}

# --- END PLUGIN CODE ---

?>
