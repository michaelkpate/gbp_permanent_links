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

$GLOBALS['PermanentLinksModels'] = array();
$GLOBALS['PermanentLinksRules']  = array();

class PermanentLinks extends GBPPlugin {
  function preload () {
    global $PermanentLinks;
    $PermanentLinks = $this;

    new PermanentLinksRulesTabView('rules', 'rules', $this);

    // Register the default route models and fields
    // Articles
    new PermanentLinksModel('Article',   'textpattern',
      new PermanentLinksField('ID',        'integer'),
      new PermanentLinksField('Date',      'date',         'Posted'),
      new PermanentLinksField('Author',    'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'AuthorID'),
      new PermanentLinksField('Category',  'has_many',     array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "article" AND name != "root"'), 'Category1', 'Category2'),
      new PermanentLinksField('Section',   'has_one',      array('model' => 'txp_section',  'key' => 'name', 'when' => 'name != "default"'), 'Section'),
      new PermanentLinksField('Keywords',  'csv'),
      new PermanentLinksField('Title',     'string',       'url_title')
    );
    // Images
    new PermanentLinksModel('Image',     'txp_image',
      new PermanentLinksField('ID',        'integer',      'id'),
      new PermanentLinksField('Name',      'string',       'name'),
      new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "image" AND name != "root"'), 'category'),
      new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Files
    new PermanentLinksModel('File',      'txp_file',
      new PermanentLinksField('ID',        'integer',      'id'),
      new PermanentLinksField('Name',      'string',       'filename'),
      new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "file" AND name != "root"'), 'category'),
      new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Links
    new PermanentLinksModel('Link',      'txp_link',
      new PermanentLinksField('ID',        'integer',      'id'),
      new PermanentLinksField('Name',      'string',       'linkname'),
      new PermanentLinksField('Category',  'has_one',      array('model' => 'txp_category', 'key' => 'name', 'when' => 'type = "link" AND name != "root"'), 'category'),
      new PermanentLinksField('Uploader',  'has_one',      array('model' => 'txp_users',    'key' => 'name'), 'author')
    );
    // Author
    new PermanentLinksModel('Author',    'txp_users',
      new PermanentLinksField('Login',     'string',       'name'),
      new PermanentLinksField('Full name', 'string',       'RealName')
    );
    // Category
    new PermanentLinksModel('Category',  'txp_category',
      new PermanentLinksField('Title',     'string',       'name')
    );
    // Section
    new PermanentLinksModel('Section',   'txp_section',
      new PermanentLinksField('Title',     'string',       'name')
    );

    // TODO: Register custom route models and fields from other plugins
  }

  function main() {
    require_privs($this->event);
  }
}

class PermanentLinksRulesTabView extends GBPAdminTabView {
  function current($object) {
    static $memorised_data = array();
    if (!array_key_exists($object, $memorised_data)) {
      $data = null;
      switch ($object) {
        case 'model':
          $data = ($table = gps('model')) ?
            $GLOBALS['PermanentLinksModels'][$table] :
            $GLOBALS['PermanentLinksModels']['textpattern'];
          break;

        case 'rules':
          $data = PermanentLinksRule::find_all(gps('model'));
          break;

        case 'rule':
          if ($id = gps('rule')) {
            if (array_key_exists($id, $_SESSION['PermanentLinksRules'])) {
              $data = $_SESSION['PermanentLinksRules'][$id];
            } else {
              $data = PermanentLinksRule::find_by_id($id);
            }
          } else {
            $data = new PermanentLinksRule(gps('model'));
          }
          break;

        case 'segments':
          $data = $this->current('rule')->segments;
          break;

        case 'segment':
          $segments = $this->current('segments');
          $data = $segments[str_replace('segment-', '', gps('segment'))];
          break;

        case 'model':
          $data = $this->current('rule')->model();
          break;

        case 'field':
          $data = $this->current('segment')->field();
          break;
      }

      if ($data)
        $memorised_data[$object] = $data;
    }

    return @$memorised_data[$object];
  }

  /* PRELOAD */
  function preload() {
    if (session_id() == "") {
      session_name("GBPPermanentLinks");
      session_start();
    }

    # Process AJAX requests
    if ($xhr = gps('xhr')) {
      if (serverSet('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest') {
        # Unset null POST data sent
        foreach ($_POST as $key => $value) {
          if ($value == 'null')
            unset($_POST[$key]);
        }

        $rs = @call_user_func(array(&$this, '_ajax_'.$xhr));
        $this->store_modified_rules();
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

  /* SESSION */
  function store_modified_rules() {
    if (!isset($_SESSION['PermanentLinksRules']))
      $_SESSION['PermanentLinksRules'] = array();

    # Store dirty rules in a sessions variable
    if ($rule = $this->current('rule')) {
      if ($rule->is_dirty)
        $_SESSION['PermanentLinksRules'][gps('rule')] = $rule;
    }
  }

  /* AJAX */
  function _ajax_load_models() {
    return '<p align="center">Filter rules by type: <select>'.
      $this->options_for_select($GLOBALS['PermanentLinksModels']).
      '</select></p>';
  }

  function _ajax_load_rules() {
    if (count($this->current('rules')) == 0) {
      echo '<p id="warning">No <strong>'.$this->current('model')->name.'</strong> rules have been created</p>'.
           '<p align="center">'.$this->_create_new_rule().'</p>';

    } else {

      echo startTable('list');

      echo tr(
        column_head('Rule', 'rule', $event, false).
        hCell()
      );

      foreach ($this->current('rules') as $rule) {
        $attr = array('rule' => $rule->id);
        echo tr(
          td($this->link_to_remote($rule->to_s(), 'rule_form', $attr), 400).
          td($this->link_to_remote(gTxt('edit'),  'rule_form', $attr), 35)
        );
      }

      echo tr(tda($this->_create_new_rule(),' colspan="2" style="text-align: right; border: none;"'));

      echo endTable();
    }
  }

  function _ajax_rule_form() {
    echo '<p align="center">'.$this->_cancel_rule().$this->_save_rule().'</p>';

    echo '<div id="rule"><ul id="'. $this->current('rule')->id .'" class="sortable">';

    foreach ($this->current('segments') as $index => $segment) {
      echo '<li id="segment-'. $index .'" class="segment">'. $segment->field .'</li>';
    }

    echo '</ul></div>';

    echo '<div id="current-segment">';

    echo '<div class="arrow" />';
    echo '<div id="segment"></div>';

    echo '</div>';
  }

  function _ajax_load_segment() {
    echo '<p>';

    echo 'Field: <select id="segment-field">'. $this->options_for_select($this->current('model')->fields, $this->current('field')) .'</select>';

    echo '<span id="segment-field-options">';

    $this->_ajax_change_segment_type();

    echo '</span>';
    echo '</p>';
  }

  function _ajax_change_segment_type() {
    if (gps('field')) {
      $this->current('segment')->update_attributes(array(
        'field' => gps('field')
      ));
    }

    if (count($this->current('field')->columns) > 1)
      echo ' Column: <select>'. $this->options_for_select($this->current('field')->columns) .'</select> ';

    if (count($this->current('field')->formats()) > 1)
      echo ' Format: <select>'. $this->options_for_select($this->current('field')->formats()) .'</select> ';
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

  function _cancel_rule() {
    return $this->button_to_function('Cancel', 'cancel_rule', 'Go back to list of rules');
  }

  function _save_rule() {
    return $this->button_to_function('Save', 'save_rule', 'Save current rule');
  }
}

class PermanentLinksModel {
  var $name;
  var $table;
  var $fields = array();

  function PermanentLinksModel($name, $table) {
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
    $GLOBALS['PermanentLinksModels'][$table] = &$this;
    end($GLOBALS['PermanentLinksModels']);
  }

  function add_field($field) {
    $field->parent_model = $this->table;
    $this->fields[$field->name] = $field;
  }
}

class PermanentLinksField {
  var $name;
  var $kind;
  var $columns = array();
  var $model;
  var $key;
  var $when;
  var $parent_model;

  function PermanentLinksField($name, $kind) {
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
      default:
        $column = @$args[2];
        $this->add_column(($column === null) ? $name : $column);

        break;
    }
  }

  function add_column($column = null) {
    if (is_string($column)) $this->columns[] = $column;
  }

  function model() {
    return $GLOBALS['PermanentLinksModels'][$this->model];
  }

  function parent() {
    return $GLOBALS['PermanentLinksModels'][$this->parent_model];
  }

  function formats() {
    return ($this->model() === null) ? array() : $this->model()->fields;
  }

  function options_from_db() {
    return safe_column($this->key, $this->model, $this->when);
  }
}

class PermanentLinksRule {
  var $id = null;
  var $model;
  var $segments = array();
  var $is_dirty = false;

  function PermanentLinksRule($model) {
    $this->model = $model;

    $i = 1;
    $args = func_get_args();
    do {
      $segment = @$args[$i++];
      if (is_string($segment) && $field = @$this->model()->fields[$segment])
        $segment = new PermanentLinksRuleSegment($field);
      else if (!is_a($segment, 'PermanentLinksRuleSegment'))
        $segment = null;

      if ($segment === null) break;
      $this->add_segment($segment);
    } while (1);

    // Store a reference back to the class
    $GLOBALS['PermanentLinksRules'][] = &$this;
    end($GLOBALS['PermanentLinksRules']);
  }

  function model () {
    return $GLOBALS['PermanentLinksModels'][$this->model];
  }

  function add_segment($segment) {
    $this->segments[] = $segment;
  }
  
  function recognition_pattern() {
    $pattern = '';
    $require = false;
    foreach (array_reverse($this->segments, true) as $index => $segment) {
      $require = (!$require) ? !$segment->is_optional : $require;
      $pattern = $segment->build_pattern($pattern, $index, !$require);
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

  function new_record() {
    return ($this->id == null) ? true : false;
  }

  function save() {
    return ($this->new_record()) ? $this->create() : $this->update();
  }

  function create() {
    $this->id = sha1(time());
    foreach ($this->segments as $segment)
      $segment->rule_id = $id;
    $this->update();
  }

  function update() {
    global $PermanentLinks;
    $PermanentLinks->set_preference($this->id, $this, 'gbp_serialized');
  }

  function find_by_id($id) {
    global $PermanentLinks;
    $rule = $PermanentLinks->pref($id);
    return is_a($rule, 'PermanentLinksRule') ? $rule : null;
  }

  function find_all($model = null) {
    global $PermanentLinks;

    static $ids;
    if (!isset($ids))
      $ids = safe_column(
        "REPLACE(name, '{$PermanentLinks->plugin_name}_', '') AS id", 'txp_prefs',
        "`event` = '{$PermanentLinks->event}' AND `name` REGEXP '^{$PermanentLinks->plugin_name}_.{40}$'"
      );

    $rules = array();
    foreach ($ids as $id) {
      $rule = PermanentLinksRule::find_by_id($id);
      if ($model == null or strtolower(@$rule->segments[0]->model) == strtolower($model))
        $rules[] = $rule;
    }

    return $rules;
  }
}

class PermanentLinksRuleSegment {
  var $rule_id;
  var $model;
  var $field;
  var $separator;
  var $is_optional;
  var $prefix;
  var $suffix;
  var $conditions = array();

  function PermanentLinksRuleSegment($field, $separator = '/', $is_optional = true, $prefix = null, $suffix = null) {
    $this->model       = $field->parent_model;
    $this->field       = $field->name;
    $this->separator   = $separator;
    $this->is_optional = $is_optional;
    $this->prefix      = $prefix;
    $this->suffix      = $suffix;
  }

  function rule() {
    return PermanentLinksRule::find_by_id($this->rule_id);
  }

  function model() {
    return $GLOBALS['PermanentLinksModels'][$this->model];
  }

  function field() {
    return $this->model()->fields[$this->field];
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
          $regex = '\d{4}' . $this->separator . '\d{2}' . $this->separator . '\d{2}';
        break;
        case 'csv':
          $regex = '[^,]+';
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

  function update_attributes($attributes = array()) {
    if (is_array($attributes)) {
      foreach ($attributes as $key => $value) {
        if ($this->$key != $value)
          $this->rule()->is_dirty = true;
        $this->$key = $value;
      }
    }
  }

  function to_s() {
    return $this->prefix.strtolower($this->field).$this->suffix.$this->separator;
  }
}

if (@txpinterface == 'admin') {
  new PermanentLinks('Permanent Links', 'permlinks', 'admin');
}

# --- END PLUGIN CODE ---

?>
