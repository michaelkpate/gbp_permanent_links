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

@require_plugin('gbp_admin_library');
if (!class_exists('GBPPlugin')) return;

class GBPPermanentLinks extends GBPPlugin {
  var $cache;
  var $preferences = array(
    'unsaved_rule_storage_engine' => array('value' => 'Session', 'type' => 'gbp_popup', 'options' => array('Session', 'Database')),
  );

  function initialize() {
    global $gbp_pl;
    $gbp_pl = $this;

    // Register the default route models and fields
    // Articles
    new GBPPermanentLinksModel('Article',   'textpattern',
      new GBPPermanentLinksField('ID',        'integer'),
      new GBPPermanentLinksField('Date',      'date',         'Posted'),
      new GBPPermanentLinksField('Author',    'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'AuthorID'),
      new GBPPermanentLinksField('Category',  'has_many',     array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "article" AND name != "root"'), 'Category1', 'Category2'),
      new GBPPermanentLinksField('Section',   'has_one',      array('model' => 'txp_section',  'key' => 'name', 'when' => 'name != "default"'), 'Section'),
      new GBPPermanentLinksField('Keywords',  'csv'),
      new GBPPermanentLinksField('Title',     'string',       'url_title')
    );
    // Images
    new GBPPermanentLinksModel('Image',     'txp_image',
      new GBPPermanentLinksField('ID',        'integer',      'id'),
      new GBPPermanentLinksField('Name',      'string',       'name'),
      new GBPPermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "image" AND name != "root"'), 'category'),
      new GBPPermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Files
    new GBPPermanentLinksModel('File',      'txp_file',
      new GBPPermanentLinksField('ID',        'integer',      'id'),
      new GBPPermanentLinksField('Name',      'string',       'filename'),
      new GBPPermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "file" AND name != "root"'), 'category'),
      new GBPPermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Links
    new GBPPermanentLinksModel('Link',      'txp_link',
      new GBPPermanentLinksField('ID',        'integer',      'id'),
      new GBPPermanentLinksField('Name',      'string',       'linkname'),
      new GBPPermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "link" AND name != "root"'), 'category'),
      new GBPPermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Author
    new GBPPermanentLinksModel('Author',    'txp_users',
      new GBPPermanentLinksField('Login',     'string',       'name'),
      new GBPPermanentLinksField('Full name', 'string',       'RealName')
    );
    // Category
    new GBPPermanentLinksModel('Category',  'txp_category',
      new GBPPermanentLinksField('Title',     'string',       'name')
    );
    // Section
    new GBPPermanentLinksModel('Section',   'txp_section',
      new GBPPermanentLinksField('Title',     'string',       'name')
    );

    new GBPPermanentLinksField('Regexp',    'global',       'regexp');
    new GBPPermanentLinksField('Text',      'global',       'text');

    // TODO: Register custom route models and fields from other plugins
  }

  function preload () {
    $cache = "GBPPermanentLinksCache".$this->pref('unsaved_rule_storage_engine');
    if (class_exists($cache)) $this->cache = new $cache();
    else $this->cache = new GBPPermanentLinksCacheSession();

    new GBPPermanentLinksRulesTabView('rules', 'rules', $this);
    new GBPPreferenceTabView($this);
  }

  function main() {
    require_privs($this->event);
  }

  function _recognise_url() {
    global $pretext;

    $rules = GBPPermanentLinksRule::recognise_url(ltrim($pretext['req'], '/'));
    if ($rule = current($rules)) $pretext = array_merge($pretext, $rule['pretext']);

    # Ensure if_individual_article works correctly
    global $is_article_list;
    $is_article_list = empty($pretext['id']);
  }

  function _generate_url($args, $type) {
    global $pretext;

    foreach ($args as $key => $value) {
      if (is_string($value) and empty($value)) unset($args[$key]);
    }

    $urls = GBPPermanentLinksRule::generate_urls($args, $type);
    return (count($urls) > 0) ? $pretext['pfr'] . ltrim(current($urls), '/') : false;
  }
}

class GBPPermanentLinksRulesTabView extends GBPAdminTabView {
  function current($object) {
    static $memorised_data = array();
    if (!array_key_exists($object, $memorised_data) or array_key_exists($object, $this->reload)) {
      $data = null;
      switch ($object) {
        case 'model':
          $table = gps('model');
          if (!$table) $table = $_SESSION['GBPPermanentLinksModel'];
          $_SESSION['GBPPermanentLinksModel'] = $table;

          $data = GBPPermanentLinksModel::find_by_table($table);
          if (!isset($data)) $data = GBPPermanentLinksModel::find_by_table('textpattern');
          break;

        case 'fields':
          $data = array_merge($this->current('model')->fields, $GLOBALS['GBPPermanentLinksFields']);
          break;

        case 'rules':
          $data = GBPPermanentLinksRule::find_all(gps('model'));
          break;

        case 'rule':
          if ($id = gps('rule')) {
            if ($id == 'new') {
              $data = new GBPPermanentLinksRule(gps('model'), current($this->current('fields'))->name);
            } else {
              $data = GBPPermanentLinksRule::find_by_id($id);
            }
          }
          break;

        case 'segments':
          $data = $this->current('rule')->segments;
          break;

        case 'segment':
          $segments = $this->current('segments');
          $data = $segments[gps('segment')];
          break;

        case 'model':
          $data = $this->current('rule')->model();
          break;

        case 'field':
          $data = $this->current('segment')->field();
          break;
      }

      if ($data) {
        unset($this->reload[$object]);
        $memorised_data[$object] = $data;
      }
    }

    return @$memorised_data[$object];
  }

  var $reload = array();
  function reload($object) {
    $this->reload[$object] = true;
  }

  /* PRELOAD */
  function preload() {
    # Process AJAX requests
    if ($xhr = gps('xhr')) {
      if (serverSet('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest') {
        # Unset null POST data sent
        foreach ($_POST as $key => $value) {
          if ($value == 'null')
            unset($_POST[$key]);
        }

        $rs = @call_user_func(array(&$this, '_ajax_'.$xhr));
        exit($rs);

      } else {
        txp_status_header('403 Forbidden');
        exit('403 Forbidden');
      }
    }

    # Process requests for embedded assets
    if ($asset = @$this->assets[gps('asset')]) {
      header('Content-type: '. $asset['type']);
      header('Content-Disposition: attachment; filename='. gps('asset'));
      if ($asset['embed'])
        $asset['data'] = implode('', file(dirname(__FILE__).'/'.$asset['embed']));
      else
        $asset['data'] = base64_decode($asset['data']);
      exit(str_replace('{{URL}}', $this->url(), $asset['data']));
    }

    # Inject JS and CSS into the page head
    register_callback(array(&$this, '_head_end'), 'admin_side', 'head_end');
  }

  function _head_end($event, $step) {
    echo $this->js() . $this->css();
  }

  /* MAIN */
  function main() {
    echo tag(
      '<noscript><p id="warning">Javascript is required in-order to use '.$this->parent->plugin_name.' </p></noscript>'.
      '<div id="models"></div>'.
      '<div id="rules" class="split-view"></div>'.
      '<div id="current-rule" class="split-view"></div>',
    'div', ' id="permanent-links-container"');
  }

  function js() {
    return '<script type="text/javascript" src="'.$this->asset('jquery-ui.js').'"></script>'.
           '<script type="text/javascript" src="'.$this->asset('master.js').'"></script>';
  }

  function css() {
    return '<link rel="stylesheet" href="'.$this->asset('master.css').'" type="text/css" charset="utf-8">';
  }

  /* ASSETS */
  var $assets = array(
    'master.css'        => array('type' => 'text/css',               'embed' => 'style/master.css'),
    'master.js'         => array('type' => 'application/javascript', 'embed' => 'javascript/master.js'),
    'jquery-ui.js'      => array('type' => 'application/javascript', 'embed' => 'javascript/jquery-ui.js'),
    'segment-arrow.gif' => array('type' => 'image/gif',              'embed' => 'images/segment-arrow.gif'),
  );

  function asset($asset) {
    return $this->url(array('asset' => $asset), true);
  }

  /* AJAX */
  function _ajax_load_models() {
    return '<p align="center">Filter rules by type: <select>'.
      $this->options_for_select(GBPPermanentLinksModel::find_all(), $this->current('model')).
      '</select></p>';
  }

  function _ajax_load_rules() {
    echo '<p align="center">'.$this->_create_new_rule().'</p>';

    if (count($this->current('rules')) == 0) {
      echo '<p id="warning">No <strong>'.$this->current('model')->name.'</strong> rules have been created</p>';

    } else {
      echo startTable('list');

      echo tr(
        column_head($this->current('model')->name.' rules', 'rule', $event, false).
        hCell()
      );

      foreach ($this->current('rules') as $rule) {
        echo tr($this->_rule_list_row($rule), ' id="'.$rule->id.'"');
      }

      echo endTable();
    }
  }

  function _ajax_edit_rule() {
    echo '<p align="center">'.$this->_back_to_rules().'</p>';

    echo '<div id="rule"><ul id="'. $this->current('rule')->id .'" class="sortable">';

    foreach ($this->current('segments') as $segment) {
      echo $this->_rule_segment($segment);
    }

    echo '</ul></div>';

    echo '<div id="current-segment">';

    echo '<div id="segment-actions">'.$this->_add_segment().$this->_remove_segment().'</div>';

    echo '<div class="arrow" />';
    echo '<div id="segment"></div>';

    echo '</div>';
  }

  function _ajax_delete_rule() {
    $this->current('rule')->delete();
  }

  function _ajax_revert_rule() {
    $this->current('rule')->revert();
    $this->reload('rule');
    if (!$this->current('rule')->new_record)
      echo $this->_rule_list_row($this->current('rule'));
  }

  function _ajax_save_rule() {
    $this->current('rule')->save();
    echo $this->_rule_list_row($this->current('rule'));
  }

  function _ajax_load_segment() {
    echo '<p>Field: <select id="segment-field">'. $this->options_for_select($this->current('fields'), $this->current('field')) .'</select></p>';

    echo '<span id="segment-field-options">';
    $this->_ajax_change_segment_type();
    echo '</span>';
  }

  function _ajax_change_segment_type() {
    if (gps('field')) {
      $this->current('segment')->update_attributes(array(
        'field' => gps('field')
      ));
    }

    if (count($this->current('field')->columns) > 1)
      echo '<p>Column: <select id="segment-column">'. $this->options_for_select($this->current('field')->columns, $this->current('segment')->column) .'</select></p>';

    if (count($this->current('field')->formats()) > 1)
      echo '<p>Format: <select id="segment-format">'. $this->options_for_select($this->current('field')->formats(), $this->current('segment')->format) .'</select></p>';

    echo br;
  }

  function _ajax_change_segment_options() {
    $this->current('segment')->update_attributes(gpsa(array('column', 'format')));
  }

  function _ajax_reorder_segments() {
    $this->current('rule')->reorder_segments(explode(':', gps('order')));
  }

  function _ajax_add_segment() {
    $unused_fields = $this->current('fields');
    foreach ($this->current('segments') as $segmnet) {
      unset($unused_fields[$segmnet->field]);
    }

    if ($field = current($unused_fields)) {
      $segment = new GBPPermanentLinksRuleSegment($field);
      $this->current('rule')->add_segment($segment);
      echo $this->_rule_segment($segment);
    }
  }

  function _ajax_remove_segment() {
    $this->current('rule')->remove_segment($this->current('segment'));
  }

  /* HELPERS */
  function options_for_select($collection, $selected_item = null, $title_method = null) {
    $out = array();
    foreach ($collection as $key => $item) {
      $vars = get_object_vars($item);
      if (isset($title_method)) {
        $title = $vars[$title_method];
      } elseif ($vars['name']) {
        $title = $item->name;
      } else {
        $title = $item;
      }

      $selected = ($item === $selected_item || $key === $selected_item) ? ' selected="selected"' : '';

      $out[] = '<option value="'.htmlspecialchars($key).'"'.$selected.'>'.htmlspecialchars($title).'</option>';
    }
    return join('', $out);
  }

  function link_to_remote($text, $method, $attributes = array()) {
    return href(
      gTxt($text),
      $this->url(array_merge(array('xhr' => $method), $attributes), true),
      ' class="remote"'
    );
  }

  function button_to_function($text, $method, $title = '') {
    return fInput('button', $method, gTxt($text), 'smallerboxsp', $title, "$method();");
  }

  function _create_new_rule() {
    return $this->button_to_function('Create new', 'create_new_rule', 'Create new rule');
  }

  function _back_to_rules() {
    return $this->button_to_function('Back', 'back_to_rules', 'Go back to list of rules');
  }

  function _add_segment() {
    return $this->button_to_function('Add', 'add_segment', 'Add a segment');
  }

  function _remove_segment() {
    return $this->button_to_function('Remove', 'remove_segment', 'Remove selected segment');
  }

  function _rule_list_row($rule) {
    $attr = array('rule' => $rule->id);
    $unsaved = '';
    $actions = array($this->link_to_remote('Edit', 'edit_rule', $attr));

    if (!$rule->new_record)
      $actions[] = $this->link_to_remote('Delete', 'delete_rule', $attr);

    if ($rule->is_dirty) {
      $unsaved = '&bull; ';
      $actions[] = $this->link_to_remote($rule->new_record ? 'Discard' : 'Revert', 'revert_rule', $attr);
      $actions[] = $this->link_to_remote('Save', 'save_rule', $attr);
    }

    return
      td($unsaved.$this->link_to_remote($rule->to_s(), 'edit_rule', $attr), "100%").
      tda(join('&nbsp;&nbsp;', array_reverse($actions)), ' width="150" style="text-align: right;"');
  }

  function _rule_segment($segment) {
    return '<li id="'. $segment->id .'" class="segment">'. $segment->field .'</li>';
  }
}

class GBPPermanentLinksCacheSession {
  var $key = 'GBP_PL';

  function GBPPermanentLinksCacheSession() {
    if (session_id() == '') {
      session_name($this->key);
      session_start();
    }

    if (!isset($_SESSION[$this->key])) $this->reset();
  }

  function reset($rule = null) {
    if ($rule)
      unset($_SESSION[$this->key][$rule->id]);
    else
      $_SESSION[$this->key] = array();
  }

  function store($rule) {
    if (is_a($rule, 'GBPPermanentLinksRule'))
      $_SESSION[$this->key][$rule->id] = $rule;
  }

  function find_by_id($id) {
    return $_SESSION[$this->key][$id];
  }

  function find_all() {
    return $_SESSION[$this->key];
  }
}

class GBPPermanentLinksCacheDatabase extends GBPPreferenceStore {
  function key($id = null) {
    global $gbp_pl;
    $base = "{$gbp_pl->plugin_name}_unsaved_";
    return $id ? $base.$id : $base;
  }

  function reset($rule = null) {
    global $gbp_pl;
    if ($rule)
      $this->db_remove($this->key($rule->id), $gbp_pl->event);
    else
      $this->db_remove($this->key('rule_%'), $gbp_pl->event);
  }

  function store($rule) {
    global $gbp_pl;
    if (is_a($rule, 'GBPPermanentLinksRule'))
      $this->db_write($this->key($rule->id), $rule, $gbp_pl->event, 'gbp_serialized');
  }

  function find_by_id($id) {
    return $this->db_read($this->key($id), 'gbp_serialized');
  }

  function find_all() {
    global $gbp_pl;

    static $ids;
    if (!isset($ids)) {
      $key = $this->key();
      $ids = safe_column(
        "REPLACE(name, '$key', '') AS id", 'txp_prefs',
        "`event` = '{$gbp_pl->event}' AND `name` REGEXP '^{$key}rule_.{6}$'"
      );
    }

    $out = array();
    foreach ($ids as $id) {
      $out[$id] = $this->find_by_id($id);
    }
    return $out;
  }
}

class GBPPermanentLinksModel {
  var $name;
  var $table;
  var $fields = array();

  function GBPPermanentLinksModel($name, $table) {
    $this->name = $name;
    $this->table = $table;

    $i = 2;
    $args = func_get_args();
    do {
      $field = @$args[$i++];
      if ($field === null) break;
      $this->add_field($field);
    } while (1);

    // Store a reference back to the class
    if (!isset($GLOBALS['GBPPermanentLinksModels'])) $GLOBALS['GBPPermanentLinksModels'] = array();
    $GLOBALS['GBPPermanentLinksModels'][$table] = &$this;
    end($GLOBALS['GBPPermanentLinksModels']);
  }

  function add_field($field) {
    $field->parent_model = $this->table;
    $this->fields[$field->name] = $field;
  }

  function find_by_table($table) {
    return $GLOBALS['GBPPermanentLinksModels'][$table];
  }

  function find_all() {
    return $GLOBALS['GBPPermanentLinksModels'];
  }
}

class GBPPermanentLinksField {
  var $name;
  var $kind;
  var $columns = array();
  var $model;
  var $key;
  var $when;
  var $parent_model;

  function GBPPermanentLinksField($name, $kind) {
    $this->name = $name;
    $this->kind = $kind;

    $args = func_get_args();
    switch ($kind) {
      case 'has_one':
      case 'has_many':
        $association = $args[2];
        $i = 3;
        do {
          $column = @$args[$i++];
          if ($column === null) break;
          $this->add_column($column);
        } while (1);

        $this->model = $association['model'];
        $this->key   = $association['key'];
        $this->when  = array_key_exists('when', $association) ? $association['when'] : '1 = 1';

        break;
      case 'global':
        $this->kind = $args[2];

        // Store a reference back to the class
        if (!isset($GLOBALS['GBPPermanentLinksFields'])) $GLOBALS['GBPPermanentLinksFields'] = array();
        $GLOBALS['GBPPermanentLinksFields'][$name] = &$this;
        end($GLOBALS['GBPPermanentLinksFields']);

        break;
      default:
        $column = @$args[2];
        $this->add_column(($column === null) ? $name : $column);

        break;
    }
  }

  function add_column($column = null) {
    if (is_string($column)) $this->columns[$column] = $column;
  }

  function model() {
    return GBPPermanentLinksModel::find_by_table($this->model);
  }

  function parent() {
    return GBPPermanentLinksModel::find_by_table($this->parent_model);
  }

  function formats() {
    return ($this->model() === null) ? array() : $this->model()->fields;
  }

  function options_from_db() {
    return safe_column($this->key, $this->model, $this->when);
  }

  function url($args, $segment) {
    global $thisarticle, $pretext;
    $out = null;
    switch ($this->name) {
      case 'Title':
        $out = @$args['url_title'];
      break;
      case 'Date':
        $out = @$args['posted'];
        if (!isset($out)) $out = @$thisarticle['posted'];
        if (!isset($out) and !empty($pretext['month'])) {
          @list($year, $month, $day) = explode('-', $pretext['month']);
          $out = mktime(0, 0, 0, (int)$month, (int)$day, (int)$year);
        }
        if (isset($out)) $out = date("Y/m/d", $out);
      break;
      case 'Author':
        $out = @$args['authorid'];
        if (!isset($out)) $out = @$thisarticle['authorid'];
        if (!isset($out)) $out = @$pretext['author'];
      break;
      case 'Category':
        $out = @$args['category1'];
        if (!isset($out)) $out = @$args['category2'];
        if (!isset($out)) $out = @$args['c'];
        if (!isset($out)) $out = @$thisarticle['category1'];
        if (!isset($out)) $out = @$thisarticle['category2'];
        if (!isset($out)) $out = @$pretext['c'];
      break;
      case 'Section':
        $out = @$args['section'];
        if (!isset($out) or $out == 'default') $out = @$args['s'];
        if (!isset($out) or $out == 'default') $out = @$thisarticle['section'];
        if ((!isset($out) and $pretext['s'] != 'default') or $out == 'default') $out = @$pretext['s'];
      break;
      case 'ID':
        $out = @$args['thisid'];
      break;
      case 'Keywords':
        $out = @$args['keywords'];
        if (!isset($out)) $out = @$thisarticle['keywords'];
      break;
    }
    return $out;
  }

  function pretext($arg, $segment) {
    switch ($this->name) {
      case 'Title':
        if ($rs = safe_row('ID', 'textpattern', "`url_title` like '$arg' and `Status` >= 4 limit 1"))
          return array('id' => $rs['ID']);
      break;
      case 'Date':
        @list($year, $month, $day) = explode($this->separator, $arg);
        return array('month' =>
          ((isset($year))  ? $year.'-'  : '____-').
          ((isset($month)) ? $month.'-' : '__-').
          ((isset($day))   ? $day       : '__')
        );
      break;
      case 'Author':
        return array('author' => $arg);
      break;
      case 'Category':
        return array('c' => $arg);
      break;
      case 'Section':
        return array('s' => $arg);
      break;
      case 'ID':
        return array('id' => $arg);
      break;
      case 'Keywords':
        return array('gbp_pl_textpattern_keywords' => $arg);
      break;
    }
  }
}

class GBPPermanentLinksRule {
  var $id;
  var $model;
  var $segments = array();
  var $is_dirty = true;
  var $new_record = true;

  function GBPPermanentLinksRule($model) {
    $this->id    = 'rule_'.substr(sha1(time() + rand()), 0, 6);
    $this->model = $model;

    $i = 1;
    $args = func_get_args();
    do {
      $segment = @$args[$i++];
      if (is_string($segment) && $field = @$this->model()->fields[$segment])
        $segment = new GBPPermanentLinksRuleSegment($field);
      else if (!is_a($segment, 'GBPPermanentLinksRuleSegment'))
        $segment = null;

      if ($segment === null) break;
      $this->add_segment($segment);
    } while (1);

    // Store a reference to the rule in the session
    $this->set_dirty();
  }

  function model() {
    return GBPPermanentLinksModel::find_by_table($this->model);
  }

  function add_segment($segment) {
    $segment->rule_id = $this->id;
    $this->segments[$segment->id] = $segment;
    $this->set_dirty();
  }

  function remove_segment($segment) {
    unset($this->segments[$segment->id]);
    $this->set_dirty();
  }

  function recognition_pattern() {
    $pattern = '';
    $require = false;
    $i = count($this->segments);
    foreach (array_reverse($this->segments, true) as $segment) {
      $require = (!$require) ? !$segment->is_optional : $require;
      $pattern = $segment->build_pattern($pattern, --$i, !$require);
    }
    return '@^(?i-:' . $pattern . ')@';
  }

  function to_s() {
    $string = '/';
    foreach ($this->segments as $segment) {
      $string .= $segment->to_s();
    }
    return substr($string, 0, -1);
  }

  function save() {
    return ($this->new_record) ? $this->create() : $this->update();
  }

  function create() {
    $this->new_record = false;
    $this->update();
  }

  function update() {
    global $gbp_pl;
    if ($gbp_pl->cache) {
      $this->is_dirty = false;
      $gbp_pl->set_preference($this->id, $this, 'gbp_serialized');
      $gbp_pl->cache->reset($this);
    }
  }

  function revert() {
    global $gbp_pl;
    if ($gbp_pl->cache) {
      $this->is_dirty = false;
      $gbp_pl->cache->reset($this);
    }
  }

  function delete() {
    global $gbp_pl;
    safe_delete('txp_prefs', "`event` = '{$gbp_pl->event}' AND `name` LIKE '{$gbp_pl->plugin_name}_{$this->id}%'");
    $this->revert();
  }

  function set_dirty() {
    global $gbp_pl;
    if ($gbp_pl->cache) {
      $this->is_dirty = true; // Todo - set to false if any changes have been reverted
      $gbp_pl->cache->store($this);
    }
  }

  function reorder_segments($segment_keys = array()) {
    if (is_array($segment_keys)) {
      $new_segments = array();
      foreach ($segment_keys as $key) {
        $new_segments[$key] = $this->segments[$key];
      }
      $this->segments = $new_segments;
      $this->set_dirty();
    }
  }

  function find_by_id($id) {
    global $gbp_pl;
    if ($gbp_pl->cache and array_key_exists($id, $gbp_pl->cache->find_all())) {
      $rule = $gbp_pl->cache->find_by_id($id);
    } else {
      $rule = $gbp_pl->pref($id);
    }
    return is_a($rule, 'GBPPermanentLinksRule') ? $rule : null;
  }

  function find_all($model = null) {
    global $gbp_pl;

    static $ids;
    if (!isset($ids))
      $ids = safe_column(
        "REPLACE(name, '{$gbp_pl->plugin_name}_', '') AS id", 'txp_prefs',
        "`event` = '{$gbp_pl->event}' AND `name` REGEXP '^{$gbp_pl->plugin_name}_rule_.{6}$'"
      );

    if ($model != null and !is_array($model))
      $model = array($model);

    $rules = array();
    foreach ($ids as $id) {
      $rule = GBPPermanentLinksRule::find_by_id($id);
      if ($model == null or in_array($rule->model, $model)) $rules[$id] = $rule;
    }

    if ($gbp_pl->cache) {
      foreach ($gbp_pl->cache->find_all() as $id => $rule) {
        if ($model == null or in_array($rule->model, $model)) $rules[$id] = $rule;
      }
    }

    return $rules;
  }

  function generate_urls($args, $type) {
    switch ($type) {
      case PERMLINKURL:
        $models = 'textpattern';
      break;
      case PAGELINKURL:
        $models = array_keys(GBPPermanentLinksModel::find_all());
      break;
    }

    $urls = array();
    foreach (GBPPermanentLinksRule::find_all($models) as $id => $rule) {
      if ($url = $rule->generate($args)) $urls[] = $url;
    }
    return $urls;
  }

  function generate($args) {
    $url = '';
    $feed = '';

    if (isset($args['pg'])) {
      global $pretext;
      $page = $args['pg'] > 0 ? '/'.$args['pg'] : '';
      return ($pretext['pg'] > 0) ?
        preg_replace('/\/\d+$/', $page, $pretext['req']) :
        rtrim($pretext['req'], '/').$page;
    }

    $require = false;
    $i = count($this->segments);
    foreach (array_reverse($this->segments, true) as $segment) {
      $require = (!$require) ? !$segment->is_optional : $require;
      $url = $segment->build_url($url, $args, --$i, !$require);
      if ($url === false) return false;
    }

    if (isset($args['rss']))
      $feed = '/rss';
    else if (isset($args['atom']))
      $feed = '/atom';

    return $url.$feed;
  }

  function recognise_url($url) {
    $rules = array();
    foreach (GBPPermanentLinksRule::find_all() as $id => $rule) {
      $pretext = $rule->recognise($url);
      if ($pretext) {
        $function = $rule->model.'_post_recognise';
        if (method_exists($rule, $function)) $rule->$function($pretext);
        $rules[$id] = array($rule, 'pretext' => $pretext);
      }
    }
    return $rules;
  }

  function recognise($url) {
    preg_match($this->recognition_pattern(), $url, $matches);

    $pretext = array();
    if ($unmatched = preg_replace('/^'.preg_quote($matches[0], '/').'\//', '', $url)) {
      $pretext['status'] = 200;
      switch ($unmatched) {
        case 'rss':
        case 'atom':
          $pretext['feed'] = $unmatched;
        break;
        default:
          if (is_numeric($unmatched)) $pretext['pg'] = $unmatched;
        break;
      }
    }

    if (count($matches) <= 1) {
      return (count($pretext) > 1) ? $pretext : null;
    }

    $pretext['status'] = 200;
    $pretext['id'] = '';
    $i = count($this->segments);
    foreach (array_reverse($this->segments, true) as $segment) {
      $pretext = array_merge($pretext, (array)$segment->build_pretext(@$matches[$i--]));
    }
    return $pretext;
  }

  function textpattern_post_recognise(&$pretext) {
    # Set the section from the matched article ID
    if (!isset($pretext['s'])) {
      if ($id = @$pretext['id']) {
        if ($rs = safe_row('Section', 'textpattern', "id = '{$id}' limit 1"))
          $pretext['s'] = $rs['Section'];
      } else
        $pretext['s'] = 'default';
    }

    # Set the page and css templates from the matched section
    if ($s = @$pretext['s']) {
      if ($rs = safe_row('css, page', 'txp_section', "name = '{$s}' limit 1")) {
        $pretext['page'] = $rs['page'];
        $pretext['css'] = $rs['css'];
      }
    }
  }
}

class GBPPermanentLinksRuleSegment {
  var $id;
  var $rule_id;
  var $model;
  var $field;
  var $separator;
  var $is_optional;
  var $prefix;
  var $suffix;
  var $conditions = array();
  var $column = '';
  var $format = '';

  function GBPPermanentLinksRuleSegment($field, $separator = '/', $is_optional = true, $prefix = null, $suffix = null) {
    $this->id          = 'segment_'.substr(sha1(time() + rand()), 0, 6);
    $this->model       = $field->parent_model;
    $this->field       = $field->name;
    $this->separator   = $separator;
    $this->is_optional = $is_optional;
    $this->prefix      = $prefix;
    $this->suffix      = $suffix;
  }

  function rule() {
    return GBPPermanentLinksRule::find_by_id($this->rule_id);
  }

  function model() {
    return GBPPermanentLinksModel::find_by_table($this->model);
  }

  function field() {
    $field = @$this->model()->fields[$this->field];
    if (!isset($field)) $field = $GLOBALS['GBPPermanentLinksFields'][$this->field];
    return $field;
  }

  function regexp() {
    if ($field = $this->field()) {
      switch ($field->kind) {
        case 'has_one':
        case 'has_many':
          $regex = join('|', $field->options_from_db());
        break;
        case 'string':
          $regex = '[^' . $this->separator . '?]+';
        break;
        case 'integer':
          $regex = '\d+';
        break;
        case 'date':
          $regex = '\d{4}(?:' . $this->separator . '\d{2}(?:' . $this->separator . '\d{2})?)?';
        break;
        case 'csv':
          $regex = '[^,' . $this->separator . '?]+';
        break;
      }

      if (isset($regex)) {
        $regex = '(' . $regex . ')';
        // Add the prefix and suffix regex as not captured groups
        $regex = $this->prefix ? '(?:' . preg_quote($this->prefix) . ')' . $regex : $regex;
        $regex = $this->suffix ? $regex . '(?:' . preg_quote($this->suffix) . ')' : $regex;
        return '\b' . $regex . '\b';
      }
    }
  }

  function build_pattern($pattern, $index, $optional) {
    // Build the regex with the given pattern. we're going through the segments in reserve
    if ($regexp = $this->regexp()) {
      $is_first = ($index == 0);
      $is_last  = empty($pattern);

      // Make the last separator optional
      $pattern = $regexp . ($is_last ? $this->separator . '?' : $pattern);
      // Don't need to check for the separator before the first segment
      $pattern = (!$is_first) ? $this->separator . $pattern : $pattern;
      // Wrap optional segments in an optional not captured group
      $pattern = ($optional) ? '(?:' . $pattern . ')?' : $pattern;
    }
    return $pattern;
  }

  function build_url($url, $args, $index, $optional) {
    $out = (method_exists($this->field(), 'url')) ? $this->field()->url($args, $this) : null;

    if ($out)
      return $this->separator . $out . $url;
    else if (!$optional)
      return false;
    else
      return '';
  }

  function build_pretext($arg) {
    return (method_exists($this->field(), 'pretext')) ? $this->field()->pretext($arg, $this) : null;
  }

  function update_attributes($attributes = array()) {
    if (is_array($attributes)) {
      foreach ($attributes as $key => $value) {
        $this->$key = $value;
      }
      $this->rule()->set_dirty();
    }
  }

  function to_s() {
    return $this->prefix.strtolower($this->field).$this->suffix.$this->separator;
  }
}

new GBPPermanentLinks('Permanent Links', 'permlinks', 'admin');

if (@txpinterface == 'public') {
  global $gbp_pl, $prefs;
  register_callback(array(&$gbp_pl, '_recognise_url'), 'pretext_end');
  $prefs['custom_url_func'] = array(&$gbp_pl, '_generate_url');
}

# --- END PLUGIN CODE ---

?>
