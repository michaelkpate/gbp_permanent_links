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
  /* PRELOAD */
  function preload() {
    $GLOBALS['PermanentLinksCurrentRules'] = $this->preload_rules();

    # Process AJAX requests
    if ($xhr = gps('xhr')) {
      if (serverSet('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest')
        exit(@call_user_func(array(&$this, '_ajax_'.$xhr)));
      else {
        txp_status_header('403 Forbidden');
        exit('403 Forbidden');
      }
    }

    # Inject JS and CSS into the page head
    register_callback(array(&$this, '_head_end'), 'admin_side', 'head_end');
  }

  function preload_rules() {
    if ($id = gps('rule'))
      return array(PermanentLinksRule::find_by_id($id));
    elseif ($model = gps('model'))
      return PermanentLinksRule::find_all($model);

    return array();
  }

  function _head_end($event, $step) {
    $this_event = 'index.php?event='.$this->parent->event.'&tab='.$this->event;
    echo $this->js($this_event) . $this->css($this_event);
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

  function js($event) {
return <<<HTML
<script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jqueryui/1.5.2/jquery-ui.min.js"></script>
<script type="text/javascript">
<!--
  function toggle_view(visible) {
    $("#permanent-links-container > div.split-view:visible:not(#"+visible+")").hide();
    $("#permanent-links-container #"+visible).show();
  }

  function create_new_rule() {
    $("#current-rule").load('$event', { xhr: "rule_form", model: $("#models").attr('value') }, function () { toggle_view('current-rule'); });
  }

  function cancel_rule() {
    toggle_view('rules');
  }

  function save_rule() {
    // TODO
    cancel_rule();
  }

  function rule_loaded() {
    $("ul.sortable li").hover(
      function() { $(this).addClass('hover'); },
      function() { $(this).removeClass('hover'); }
    ).click(function() {
      if ($(this).hasClass('selected')) return;
      $("ul.sortable li").removeClass('selected');
      $(this).addClass('selected');
      $("#current-segment").load('$event', { xhr: "load_segment", rule: $(this).parent("ul").attr('id'), index: this.id.replace('segment-', '') });
    });
    $("ul.sortable").sortable();
    // Trigger the loading to the segment options
    $("ul.sortable li:first").click();
    // Hide the rules table and display the current rule form
    toggle_view('current-rule');
  }

  $(document).ready(function () {
    $("#models").load('$event', { xhr: "load_models" }, function () {
      $("#models select").change(function () {
        $("#rules").load('$event', { xhr: "load_rules", model: this.value }, function () { toggle_view('rules'); });
      }).change();
    });
  });

  $(document).ajaxComplete(function (request, settings) {
    $("a.remote").unbind('click').click(function () {
      var xhr_method = new RegExp("[\\?&]xhr=([^&#]*)").exec(this.href)[1];
      switch (xhr_method) {
        case 'rule_form':
          $("#current-rule").load(this.href, {}, function () { rule_loaded(); });
        break;
        default:
          $.post(this.href, function (data) { eval(data); });
        break;
      }
      return false;
    });
  });
-->
</script>
HTML;
  }

  function css($event) {
return <<<HTML
<style type="text/css" media="screen">
#rule {
	margin: 0 auto;
	padding: 0;
	width: 600px;
	background-color: #F3F3F3;
	display: block;
	border: 1px solid #999;
}

#rule ul {
	list-style: none;
	border: 4px solid #F3F3F3;
	border-bottom-width: 6px;
	height: 3em;
	margin: 0;
	padding: 0;
}

#rule li.segment {
	float: left;
	line-height: 3em;
	margin: 0 5px 0 0;
	padding: 0 1.5em;
	background-color: #FFEAB1;
	border: 1px solid #FFCB2F;
}

#rule li.segment.selected {
	background-color: #FFCB2F;
}

#rule li.segment.hover {
	cursor: move;
}
</style>
HTML;
  }

  /* AJAX */
  function _ajax_load_models() {
    foreach ($GLOBALS['PermanentLinksModels'] as $key => $model) {
      $out[] = '<option value="'.htmlspecialchars($key).'">'.htmlspecialchars($model->model).'</option>';
    }
    return '<p align="center">Filter rules by type: <select>'.
      ( $out ? join('', $out) : '').
      '</select></p>';
  }

  function _ajax_load_rules() {
    if (count($GLOBALS['PermanentLinksCurrentRules']) == 0) {
      echo '<p id="warning">No <strong>'.gps('model').'</strong> rules have been created</p>'.
           '<p align="center">'.$this->_create_new_rule().'</p>';

    } else {

      echo startTable('list');

      echo tr(
        column_head('Rule', 'rule', $event, false).
        hCell()
      );

      foreach ($GLOBALS['PermanentLinksCurrentRules'] as $rule) {
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
    $rule = gps('rule')
      ? $GLOBALS['PermanentLinksCurrentRules'][0]
      : new PermanentLinksRule(gps('model'));

    echo '<p align="center">'.$this->_cancel_rule().$this->_save_rule().'</p>';

    echo '<div id="rule"><ul id="'. $rule->id .'" class="sortable">';

    foreach ($rule->segments as $index => $segment) {
      echo '<li id="segment-'. $index .'" class="segment">'. $segment->field .'</li>';
    }

    echo '</ul></div>';
    echo '<div id="current-segment"></div>';
  }

  function _ajax_load_segment() {
    $rule = $GLOBALS['PermanentLinksCurrentRules'][0];
    $segment = $rule->segments[gps('index')];
  }

  /* HELPERS */
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
  var $model;
  var $table;
  var $fields = array();

  function PermanentLinksModel($model, $table) {
    $this->model = $model;
    $this->table = $table;

    $i = 2;
    $args = func_get_args();
    do {
      $field = @$args[$i++];
      if ($field === null) break;
      $this->add_field($field);
    } while (1);

    // Store a reference back to the class
    $GLOBALS['PermanentLinksModels'][strtolower($model)] = &$this;
    end($GLOBALS['PermanentLinksModels']);
  }

  function add_field($field) {
    $field->parent_model = $this->model;
    $this->fields[strtolower($field->name)] = $field;
  }
}

class PermanentLinksField {
  var $name;
  var $kind;
  var $fields = array();
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
        $association = $args[2];
        $field       = $args[3];

        $this->add_field_key($field);
        $this->model = $association['model'];
        $this->key   = $association['key'];
        $this->when  = array_key_exists('when', $association) ? $association['when'] : '1 = 1';

        break;
      case 'has_many':
        $association = $args[2];
        $i = 3;
        do {
          $field = @$args[$i++];
          if ($field === null) break;
          $this->add_field_key($field);
        } while (1);

        $this->model = $association['model'];
        $this->key   = $association['key'];
        $this->when  = array_key_exists('when', $association) ? $association['when'] : '1 = 1';

        break;
      default:
        $field = @$args[2];
        $this->add_field_key(($field === null) ? $name : $field);

        break;
    }
  }

  function add_field_key($key = null) {
    // check field key is a string
    if (is_string($key)) $this->fields[] = strtolower($key);
  }

  function parent() {
    return $GLOBALS['PermanentLinksModels'][$this->parent_model];
  }

  function options_from_db() {
    return safe_column($this->key, $this->model, $this->when);
  }
}

class PermanentLinksRule {
  var $id = null;
  var $segments = array();

  function PermanentLinksRule($model) {
    $i = 1;
    $model = $GLOBALS['PermanentLinksModels'][strtolower($model)];
    $args = func_get_args();
    do {
      $segment = @$args[$i++];
      if (is_string($segment) && $field = @$model->fields[strtolower($segment)])
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
    $this->update();
  }

  function update() {
    global $PermanentLinks;
    $PermanentLinks->set_preference($this->id, &$this, 'gbp_serialized');
  }

  function find_by_id($id) {
    global $PermanentLinks;
    $rule = $PermanentLinks->pref($id);
    return is_a($rule, 'PermanentLinksRule') ? $rule : nil;
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

  function model() {
    return @$GLOBALS['PermanentLinksModels'][strtolower($this->model)];
  }

  function field() {
    return $this->model()->fields[strtolower($this->field)];
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

  function to_s() {
    return $this->prefix.strtolower($this->field).$this->suffix.$this->separator;
  }
}

if (@txpinterface == 'admin') {
  new PermanentLinks('Permanent Links', 'permlinks', 'admin');
}

# --- END PLUGIN CODE ---

?>
