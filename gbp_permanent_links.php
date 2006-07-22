<?php

$plugin['name'] = 'gbp_permanent_links';
$plugin['version'] = '0.7';
$plugin['author'] = 'Graeme Porteous';
$plugin['author_uri'] = 'http://porteo.us/projects/textpattern/gbp_permanent_links/';
$plugin['description'] = 'Custom permanent links formats';
$plugin['type'] = '1';

@include_once('../zem_tpl.php');

if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---

# --- END PLUGIN HELP ---
-->
<?php
}
# --- BEGIN PLUGIN CODE ---

// Constants
if (!defined('gbp_save'))
	define('gbp_save', 'save');
if (!defined('gbp_post'))
	define('gbp_post', 'post');
if (!defined('gbp_separator'))
	define('gbp_separator', '&~&~&');

// require_plugin() will reset the $txp_current_plugin global
global $txp_current_plugin;
$gbp_current_plugin = $txp_current_plugin;
require_plugin('gbp_admin_library');
$txp_current_plugin = $gbp_current_plugin;

class PermanentLinks extends GBPPlugin
{
	var $preferences = array(
		'show_prefix' => array('value' => 0, 'type' => 'yesnoradio'),
		'show_suffix' => array('value' => 0, 'type' => 'yesnoradio'),
		'debug' => array('value' => 0, 'type' => 'yesnoradio'),
	);
	var $matched_permalink_id;

	function preload()
	{
		require_privs('publisher');

		new PermanentLinksListTabView('list', 'list', $this);
		new PermanentLinksBuildTabView('build', 'build', $this);
		new GBPPreferenceTabView('preferences', 'preference', $this);
	}

	function get_all_permalinks()
		{
		$rs = safe_column(
			"REPLACE(name, '{$this->plugin_name}_', '') AS id", 'txp_prefs',
			"`event` = '{$this->event}' AND `name` REGEXP '^{$this->plugin_name}_.{13}$'"
		);

		$permalinks = array();
		foreach ($rs as $id)
			$permalinks[$id] = $this->get_permalink($id);

		return $permalinks;
		}

	function get_permalink($id)
		{
		global $prefs;
		$name = $this->plugin_name.'_'.$id;
		return array_key_exists($name, $prefs) ? $prefs[$name] : array();
		}

	function remove_permalink($id)
	{
		$permalink = $this->get_permalink($id);
		safe_delete('txp_prefs', "`event` = '{$this->event}' AND `name` LIKE '{$this->plugin_name}_{$id}%'");
		return $permalink['settings']['pl_name'];
	}

	function _textpattern()
	{
		global $pretext, $s, $c, $prefs;

		$this->debug('Plugin: '.$this->plugin_name);
		$this->debug('Function: '.__FUNCTION__.'()');

		// Permanent links
		$permalinks = $this->get_all_permalinks();

		if (count($permalinks) > 1)
			{
			// Sort the permalinks via their precedence value.
			foreach ($permalinks as $key => $pl) {
			    $precedence[$key]  = $pl['settings']['pl_precedence'];
			}
			array_multisort($precedence, SORT_DESC, $permalinks);
			}

		foreach($permalinks as $id => $pl)
		{
			$pl_components = $pl['components'];

			// URI components
			$uri_components = explode('/', trim($pretext['req'], '/'));

			// Are we expecting a date component? If so the number of pl and uri components won't match
			foreach($pl_components as $pl_c)
				if ($pl_c['type'] == 'date')
				 	$date = true;

			// Exit early if the number of components doesn't match, taking into account whether there is a data component
			if (count($uri_components) != count($pl_components) + (isset($date) ? 2 : 0))
				continue;

			// Extract the permalink settings
			$pl_settings = $pl['settings'];
			extract($pl_settings);

			$this->debug('Permalink name: '.$pl_name);

			// Reset pretext_replacement as we are about to start another comparison
			$pretext_replacement = array();

			$i = 0;
			// Lopp through the URI components
			while (list($j, $uri_c) = each($uri_components))
			{
				// Extract the permalink components which corresponds to this URI component
				$pl_c = $pl_components[$i++];
				extract($pl_c);

				// If it's a data, grab and combine the next two uri components
				if ($type == 'date')
				{
					$uri_c .= '/'.current($uri_components).'/'.next($uri_components);
					next($uri_components);
				}

				// Assume there is no match
				$match = false;

				// Always check the type unless the prefix or suffix aren't there
				$check_type = true;

				// Check prefix
				if ($prefix && $this->preferences['show_prefix']['value']) {
					if (($pos = strpos($uri_c, $prefix)) === false || $pos != 0) {
						$check_type = false;
						$this->debug('Can\'t find prefix: '.$prefix);
					}
					else
						// Check passed, remove prefix ready for the next check
						$uri_c = substr_replace($uri_c, '', 0, strlen($prefix));
				}

				// Check suffix
				if ($check_type && $suffix && $this->preferences['show_suffix']['value']) {
					if (($pos = strrpos($uri_c, $suffix)) === false) {
						$check_type = false;
						$this->debug('Can\'t find suffix: '.$suffix);
					}
					else
						// Check passed, remove suffix ready for the next check
						$uri_c = substr_replace($uri_c, '', $pos, strlen($prefix));
				}

				if ($check_type) {
					$this->debug('Checking if "'.$uri_c.'" is of type "'.$type.'"');

					// Compare based on type
					switch ($type)
					{
						case 'section':
							if (safe_field('name', 'txp_section', "`name` like '$uri_c' limit 1")) {
								$pretext_replacement['s'] = $uri_c;
								$s = $uri_c;
								$match = true;
							}
						break;
						case 'category':
							if (safe_field('name', 'txp_category', "`name` like '$uri_c' and `type` = 'article' limit 1")) {
								$pretext_replacement['c'] = $uri_c;
								$c = $uri_c;
								$match = true;
							}
						break;
						case 'title':
							if ($id = safe_field('ID', 'textpattern', "`url_title` like '$uri_c' and `Status` >= 4 limit 1")) {
								$pretext_replacement['id'] = $id;
								$match = true;
							}
						break;
						case 'author':
							$uri_c = urldecode($uri_c);
							if ($author = safe_field('name', 'txp_users', "RealName like '$uri_c'")) {
								$pretext_replacement['author'] = $author;
								$match = true;
							}
						break;
						case 'custom':
							if (safe_field("custom_$custom", 'textpattern', "custom_$custom like '$uri_c'")) {
								$match = true;
							}
						break;
						case 'date':
							if (preg_match('/\d{4}\/\d{2}\/\d{2}/', $uri_c)) {
								$pretext_replacement['date'] = str_replace('/', '-', $uri_c);
								$match = true;
							}
						break;
						case 'year':
							if (preg_match('/\d{4}/', $uri_c)) {
								$pretext_replacement['year'] = $uri_c;
								$match = true;
							}
						break;
						case 'month':
						case 'day':
							if (preg_match('/\d{2}/', $uri_c)) {
								$pretext_replacement[$type] = $uri_c;
								$match = true;
							}
						break;
						case 'page':
							if (is_numeric($uri_c)) {
								$pretext_replacement['pg'] = $uri_c;
								$match = true;
							}
						break;
						case 'search':
								$pretext_replacement['q'] = $uri_c;
								$match = true;
						break;
						case 'text':
							if ($text == $uri_c) {
								$match = true;
							}
						break;
						case 'regex':
							if (preg_match($regex, $uri_c)) {
								$match = true;
							}
						break;
					} // switch type end

					$this->debug(($match == true) ? 'YES' : 'NO');
				}

				// Break early if it's not a match, as there is no point continuing
				if ($match == false) {
					// Unset pretext_replacement as changes could of been made in a preceding component
					unset($pretext_replacement);
					break;
				}
			} // foreach uri end

			// If pretext_replacement is still set here then we have a match
			if (isset($pretext_replacement)) {
				$this->debug('We have a match!');

				// If there is a match then we most set the http status correctly as txp's pretext might set it to 404
				$pretext_replacement['status'] = '200';

				// Txp only looks at the month, but due to how we phase the month we can manipulate the sql to our needs
				if (array_key_exists('date', $pretext_replacement)) {
					$pretext_replacement['month'] = $pretext_replacement['date'];
					unset($pretext_replacement['date']);
				}
				elseif (array_key_exists('year', $pretext_replacement) || 
					array_key_exists('month', $pretext_replacement) || 
					array_key_exists('day', $pretext_replacement)
				) {
					$month = '';
					$month .= (array_key_exists('year', $pretext_replacement))
						? $pretext_replacement['year'].'-' : '____-';
					$month .= (array_key_exists('month', $pretext_replacement))
						? $pretext_replacement['month'].'-' : '__-';
					$month .= (array_key_exists('day', $pretext_replacement))
						? $pretext_replacement['day'].' ' : '__ ';

					$pretext_replacement['month'] = $month;
					unset($pretext_replacement['year']);
					unset($pretext_replacement['day']);
				}

				// Section needs to be defined so we can always get a page template.
				if (!array_key_exists('s', $pretext_replacement))
					$pretext_replacement['s'] = 'default';

				// Set the page template, otherwise we get an unknown section error
				$page = safe_field('page', 'txp_section', "name = '{$pretext_replacement['s']}' limit 1");
				$pretext_replacement['page'] = $page;
				$pretext_replacement['permalink'] = $pl_name;

				// Merge pretext_replacement with pretext
				$pretext = array_merge($pretext, $pretext_replacement);

				// We have a match but this is no use if we don't register an override for permlinkurl()
				$prefs['custom_url_func'] = array(&$this, '_permlinkurl');

				$this->matched_permalink_id = $id;

				// We're done - no point check the other permalinks
				break;
			}

		} // foreach permalinks end

		if (isset($pretext_replacement))
			{
			global $plugin_callback, $permlink_mode;

			// Force Textpattern and tags to use messy URLs - these are to find in regex
			$pretext['permlink_mode'] =
			$pref['permlink_mode'] =
			$permlink_mode = 'messy';

			$this->debug('Pretext Replacement '.print_r($pretext, 1));

			ob_start(array(&$this, '_textpattern_end'));

			$new_callbacks = array();
			$found_this = false;
			foreach ($plugin_callback as $callback)
				{
				if ($found_this)
					$new_callbacks = $callback;
				if ( $callback['event'] == 'textpattern' 
					&& is_array( $callback['function'] )
					&& count( $callback['function'] )
					&& $callback['function'][0] === $this )
					{
					$found_this = true;
					}
				}
			$plugin_callback = $new_callbacks;

			textpattern();

			ob_end_flush();

		    die();
			}

	} // function _textpattern end

	function _textpattern_end( $html )
		{

		$html = preg_replace_callback(
			'%(href="'.hu.')([^"]*)(")%',
			array(&$this, '_pagelinkurl'),
			$html);

		return $html;
		}

	function _permlinkurl( $article_array )
		{
		global $prefs;
		$prefs['custom_url_func'] = '';
		// $pl = $this->get_permalink( $this->matched_permalink_id );
		return permlinkurl( $article_array );
		}

	function _pagelinkurl( $parts, $inherit=array() )
		{
		return $parts[0];
		}

	function debug()
	{
		if ($this->preferences['debug']['value']) {
			global $production_status;
			$a = func_get_args();

			if (@txpinterface == 'admin')
				foreach ($a as $thing)
					dmp($thing);

			if (@txpinterface == 'public' && in_array($production_status, array('debug', 'testing')))
				foreach ($a as $thing)
					echo comment(is_scalar($thing) ? strval($thing) : var_export($thing, true)),n;
		}
	}
}

class PermanentLinksBuildTabView extends GBPAdminTabView
{
	function preload()
		{
		register_callback(array($this, 'post_save_permalink'), $this->parent->event, gbp_save, 1);
		register_callback(array($this, 'post_save_permalink'), $this->parent->event, gbp_post, 1);
		}

	function main()
		{
		global $prefs;
		extract(gpsa(array('step', gbp_id)));

		// With have an ID, either the permalink has just been saved or the user wants to edit it
		if ($id)
			{
			// Newly saved or beening edited, either way we're editing a permalink
			$step = gbp_save;

			// Use the ID to grab the permalink data (components & settings) 
			$permalink = $this->parent->get_permalink($id);
			$components = $this->phpArrayToJsArray('components', $permalink['components']);
			$settings = $permalink['settings'];
			}
		else
			{
			// Creating a new ID and permalink.
			$step = gbp_post;
			$id = uniqid('');

			// Set the default set of components depending on whether there is parent permalink 
			$components = $this->phpArrayToJsArray('components', array(
				array('type' => 'section', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
				array('type' => 'category', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
				array('type' => 'title', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
			));

			$settings = array(
				'pl_name' => 'Untitled', 'pl_precedence' => '0',
				'con_section' => '', 'con_category' => '',
				'des_section' => '', 'des_category' => '', 'des_feed' => '', 'des_location' => '',
			);
			}

		// Extract settings - this will be useful when creating the user interface
		extract($settings);

		// PHP & Javascript constants;
		$separator = gbp_separator;
		$components_div = 'permalink_components_ui';
		$components_form = 'permalink_components';
		$settings_form = 'permalink_settings';

		// A little credit here and there doesn't hurt
		$out[] = "<!-- {$this->parent->plugin_name} by Graeme Porteous -->";

		// The Javascript
		$out[] = <<<HTML
	<script type="text/javascript" language="javascript" charset="utf-8">
	// <![CDATA[

	// Global variables
var {$components}// components array for all the data

	var _current = 0; // Index of the components array, of the currently selected component
	var c_vals = new Array('type', 'custom', 'name', 'prefix', 'suffix', 'regex', 'text');

	window.onload = function()
	{
		component_refresh_all();
		component_switch(component(_current));
	}

	function component_add()
	{
		// Create data set
		var data = new Array();
		for (key in c_vals) {
			data[c_vals[key]] = '';
		}

		// Add data
		components.push(data);

		// Reset component type list
		form('{$components_form}').type.value = '';

		// Switch to the new component
		_current = components.length - 1;

		// Refresh UI
		component_refresh_all();
		component_update();
	}

	function component_refresh(element)
	{
		var c = components[element.id];

		// CSS
		if (_current == element.id)
			element.style['backgroundColor'] = 'rgb(249, 206, 73)';
		else
			element.style['backgroundColor'] = 'rgb(255, 254, 211)';
		element.style['color'] = 'rgb(0, 0, 0)';
		element.style['fontFamily'] = 'Arial';
		element.style['fontWeight'] = 'bold';
		element.style['verticalAlign'] = 'middle';
		element.style['textAlign'] = 'center';
		element.style['lineHeight'] = '1.5em';
		element.style['height'] = '1.5em';
		element.style['padding'] = '0 5px';
		element.style['marginRight'] = '5px';
		element.style['cssFloat'] = 'left';

		// Remove all child nodes
		while (element.hasChildNodes())
			{ element.removeChild(element.firstChild); }

		// Create the visible string representing this component
		switch (c['type']) {
			case '' : 
				string = '/';
				break;
			case 'regex' :
			case 'text' :
				string = c[c['type']] + ' /';
				break;
			case 'date' :
				string = c['type'] + ' /';
				break;
			case 'custom' :
				string = c['prefix'] + 'custom_' + c['custom'] + c['suffix'] + ' /';
				break;
			default :
				string = c['prefix'] + c['type'] + c['suffix'] + ' /';
			break;
		}

		// Set the visible string
		element.appendChild(document.createTextNode(string));

		return element;
	}

	function component_refresh_all()
	{
		// Remove all child nodes
		while (permalink_div().hasChildNodes())
			{ permalink_div().removeChild(permalink_div().firstChild); }

		for (var i = 0; i < components.length; i++)
		{
			var c = components[i];

			// Create the new component
			var new_component = document.createElement('div');

			// Set the id interger for this component
			new_component.id = i;

			// Javascript, onmouseup setting
			new_component.setAttribute('onmousedown', 'component_switch(this);');

			// Refresh the look of the component
			new_component = component_refresh(new_component);

			// And add the new component to the ui
			permalink_div().appendChild(new_component);
		}
	}

	function component_remove()
	{
		if (components.length > 1)
		{
			components.splice(_current, 1);

			if (_current >= components.length)
				_current = components.length - 1;

			component_refresh_all();
		}
	}

	function component_switch(element)
	{
		// Update current index
		_current = element.id;

		// Refresh UI
		component_refresh_all();

		// Set form input values
		var c = components[_current];
		for (key in c_vals) {
			var k = c_vals[key];
			var e = form('{$components_form}').elements.namedItem(k);

			if (c[k]) e.value = c[k];
			else e.value = '';
		}

		// Hide unneeded form inputs
		component_update();
	}

	function component_update()
	{
		// Store the data in form inputs, and hide all form inputs
		var c = new Array()
		for (key in c_vals) {
			var k = c_vals[key];
			var e = form('{$components_form}').elements.namedItem(k);

			c[k] = e.value;

			e.parentNode.style['display'] = 'none';
		}

		// Reshow type option list
		form('{$components_form}').type.parentNode.style['display'] = '';

		// Set other form inputs to the correct visibility state, dependent on type
		switch (c['type']) {
			case '' :
			case 'date' : break;
			case 'regex' :
				form('{$components_form}').name.parentNode.style['display'] = '';
				form('{$components_form}').regex.parentNode.style['display'] = '';
			break;
			case 'text' :
				form('{$components_form}').name.parentNode.style['display'] = '';
				form('{$components_form}').text.parentNode.style['display'] = '';
			break;
			case 'custom' :
				form('{$components_form}').custom.parentNode.style['display'] = '';
			default :
				if ({$this->parent->preferences['show_prefix']['value']})
					form('{$components_form}').prefix.parentNode.style['display'] = '';
				if ({$this->parent->preferences['show_suffix']['value']})
					form('{$components_form}').suffix.parentNode.style['display'] = '';
			break;
		}

		// Save data
		components[_current] = c;

		// Refresh component to reflect new data
		component_refresh(component(_current));
	}

	function component_left()
	{
		if (components.length > 1 && _current > 0)
		{
			// Store current component
			var c = components[_current];

			// Remove current component
			components.splice(_current, 1);

			// Update current index
			_current--;

			// Re-add current component
			components.splice(_current, 0, c);

			// Refresh UI
			component_refresh_all();
		}
	}

	function component_right()
	{
		if (_current < components.length - 1)
		{
			// Store current component
			var c = components[_current];

			// Remove current component
			components.splice(_current, 1);

			// Update current index
			_current++;

			// Re-add current component
			components.splice(_current, 0, c);

			// Refresh UI
			component_refresh_all();
		}
	}

	function save(form)
	{
		var c = ''; var is_permalink = false; var has_page_or_search = false;
		for (var i = 0; i < components.length; i++) {
			if (components[i]['type'] == 'title')
				is_permalink = true;
			if (components[i]['type'] == 'page' || components[i]['type'] == 'search')
				has_page_or_search = true;
			c = c + jsArrayToPhpArray(components[i]) + '{$separator}';
		}

		if (is_permalink && has_page_or_search)
			alert("Your permanent link can't contain either a 'page' or a 'search' component with a 'title' component.");

		else if (is_permalink && (form.pl_name.value == '' || form.pl_name.value == 'Untitled'))
		{
			document.getElementById('settings').style['display'] = '';
			form.pl_name.style['border'] = '3px solid rgb(221, 0, 0)';
			alert('Please enter a name for this permanent link format.');
		}
		else
		{
			form.components.value = c;
			form.pl_preview.value = permalink_div().textContent;
			return true;
		}

		return false;
	}

	function jsArrayToPhpArray(array)
	{
		// http://farm.tucows.com/blog/_archives/2005/5/30/895901.html
		var array_php = "";
		var total = 0;
		for (var key in array) {
			++ total;
			array_php = array_php + "s:" +
				String(key).length + ":\"" + String(key) + "\";s:" +
				String(array[key]).length + ":\"" + String(array[key]) + "\";";
		}
		array_php = "a:" + total + ":{" + array_php + "}";
		return array_php;
	}

	function permalink_div()
	{
		// Return the permalink format element
		return document.getElementById('{$components_div}');
	}

	function form(name)
	{
		if (!name)
			name = '{$components_form}'
		// Return the form element with name
		return document.forms.namedItem(name);
	}

	function component(index)
	{
		// Return component with index
		return permalink_div().childNodes[index];
	}

	// ]]>
	</script>
HTML;

		function gbpFLabel( $label, $contents='' )
			{
			// <label> the contents with the name $lable
			$contents = ($contents ? ': '.$contents : '');
			return tag( $label.$contents, 'label' );
			}

		function gbpFBoxes( $name='', $value='', $checked_value='', $on=array(), $label='' )
			{
			$out = array();
			if (is_array($value))
				{
				$i = 0;
				foreach ($value as $val)
					{
					$o = '<input type="radio" name="'.$name.'" value="'.$val.'"';
					$o .= ($checked_value == $val) ? ' checked="checked"' : '';
					if (is_array($on)) foreach($on as $k => $v)
						$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
					$o .= ' />';
					$out[] = $o.gbpFLabel($label[$i++]);
					}
				}
			else
				{
				$o = '<input type="checkbox" name="'.$name.'" value="'.$value.'"';
				$o .= ($checked_value == $value) ? ' checked="checked"' : '';
				if (is_array($on)) foreach($on as $k => $v)
					$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
				$o .= ' />';
				$out[] = $o.gbpFLabel($label);
				}

			return join(br, $out);
			}

		function gbpFInput( $type, $name='', $value='', $on=array(), $label='' )
			{
			if ($type == 'radio' || $type == 'checkbox')
				return gbpFBoxes($name, $value, $on, $label);

			$o = '<input type="'.$type.'" name="'.$name.'" value="'.$value.'"';
			if (is_array($on)) foreach($on as $k => $v)
					$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
			$o .= ' />';
			return ($label) ? gbpFLabel($label, $o) : $o;
			}

		function gbpFSelect( $name='', $array='', $value='', $blank_first='', $label='', $on_submit='' )
			{
			$o = selectInput($name, $array, $value, $blank_first, $on_submit);
			return ($label ? gbpFLabel($label, $o) : $o);
			}

		// --- Format --- //

		$out[] = hed('Permanent link format', 2);
		$out[] = '<div id="'.$components_div.'" style="background-color: rgb(230, 230, 230); width: auto; height: 1.5em; margin: 10px 0; padding: 5px;"></div>';
		$out[] = graf
			(
			gbpFInput('button', 'component_add', 'Add component', array('click' => 'component_add();')).n.
			gbpFInput('button', 'component_remove', 'Remove component', array('click' => 'component_remove();')).n.
			gbpFInput('button', 'component_left', 'Move left', array('click' => 'component_left();')).n.
			gbpFInput('button', 'component_right', 'Move right', array('click' => 'component_right();'))
			);

		// --- Component form --- //

		$out[] = '<form action="index.php" name="'.$components_form.'" onsubmit="return false;">';

		// --- Component type --- //

		$component_types = array
			(
			'section' => 'Section', 'category' => 'Category',
			'title' => 'Title', 'date' => 'Date (yyyy/mm/dd)',
			'year' => 'Year', 'month' => 'Month', 'day' => 'Day',
			'author' => 'Author', 'custom' => 'Custom Field',
			'page' => 'Page Number', 'search' => 'Search request',
			'text' => 'Plain Text', 'regex' => 'Regular Expression'
			);
		$out[] = graf(gbpFSelect('type', $component_types, '', 1, 'Component type', ' onchange="component_update();"'));

		// --- Component data --- //

		// Grab the custom field titles
		$custom_fields = array();
		for ($i = 1; $i <= 10; $i++)
			{ 
			if ($v = $prefs["custom_{$i}_set"])
				$custom_fields[$i] = $v;
			}

		$out[] = graf(
			gbpFSelect('custom', $custom_fields, '', 0, 'Custom', ' onchange="component_update();"').n.
			gbpFInput('text', 'name', '', array('keyup' => 'component_update();'), 'Name').n.
			gbpFInput('text', 'prefix', '', array('keyup' => 'component_update();'), 'Prefix').n.
			gbpFInput('text', 'regex', '', array('keyup' => 'component_update();'), 'Regular Expression').n.
			gbpFInput('text', 'suffix', '', array('keyup' => 'component_update();'), 'Suffix').n.
			gbpFInput('text', 'text', '', array('keyup' => 'component_update();'), 'Text')
		);
		$out[] = '<hr />';

		$out[] = '</form>';

		// --- Settings form --- //

		$out[] = '<form action="index.php" method="post" name="'.$settings_form.'" onsubmit="return save(this);">';

		// --- Settings --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'settings\'); return false;">Settings</a>', 2);
		$out[] = '<div id="settings">';
		$out[] = graf(gbpFInput('text', 'pl_name', $pl_name, NULL, 'Name'));
		$out[] = graf(gbpFInput('text', 'pl_precedence', $pl_precedence, NULL, 'Precedence'));
		$out[] = '<hr />';
		$out[] = '</div>';

		// --- Conditions --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'conditions\'); return false;">Conditions</a>', 2);
		$out[] = '<div id="conditions" style="display:none">';
		$out[] = graf(small('Only use this permanent link if the following conditions apply:'));

		// Generate a sections array (name=>title) 
		$sections = array();
		$rs = safe_rows('name, title', 'txp_section', "name != 'default' order by name");
		foreach ($rs as $sec)
			{
			$sections[$sec['name']] = $sec['title'];
			}

		// Generate a categories array (name=>title) 
		$categories = array();
		$rs = safe_rows('name, title', 'txp_category', "type = 'article' and name != 'root' order by name");
		foreach ($rs as $sec)
			{
			$categories[$sec['name']] = $sec['title'];
			}

		$out[] = graf
			(
			gbpFSelect('con_section', $sections, $con_section, 1, 'Within section').n.
			gbpFSelect('con_category', $categories, $con_category, 1, 'Within category')
			);
		$out[] = '<hr />';
		$out[] = '</div>';

		// --- Destination --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'destination\'); return false;">Destination</a>', 2);
		$out[] = '<div id="destination" style="display:none">';
		$out[] = graf(small('Redirect this permanent link and forward to:'));
		$out[] = graf
			(
			gbpFSelect('des_section', $sections, $des_section, 1, 'Section').n.
			gbpFSelect('des_category', $categories, $des_category, 1, 'Category')
			);
		$out[] = graf(gbpFBoxes('des_feed', array('', 'rss', 'atom'), $des_feed, NULL, array('None', 'RSS feed', 'Atom feed')));
		$out[] = graf(gbpFInput('text', 'des_location', $des_location, NULL, 'HTTP location'));
		$out[] = '<hr />';
		$out[] = '</div>';

		// Save button
		$out[] = fInput('submit', '', 'Save permanent link');

		// Extra form inputs which get filled on submit
		$out[] = hInput('components', '');
		$out[] = hInput('pl_preview', '');
		// Event and tab form inputs 
		$out[] = $this->parent->form_inputs();
		// Step and ID form inputs
		$out[] = sInput($step);
		$out[] = hInput(gbp_id, $id);

		$out[] = '</form>';

		// Lets echo everything out. Yah!
		echo join(n, $out);
		}

	function post_save_permalink()
		{
		// The function posts or saves a permanent link to txp_prefs

		global $prefs;
		extract(gpsa(array('step', gbp_id)));

		// Grab the user defined settings from the form POST data
		$settings = gpsa(array(
			'pl_name', 'pl_precedence', 'pl_preview',
			'con_section', 'con_category',
			'des_section', 'des_category', 'des_feed', 'des_location',
		));

		// Remove spaces from the permanent link preview
		$settings['pl_preview'] = str_replace(' /', '/', $settings['pl_preview']);

		// Explode the separated string of serialize components - this was made by JavaScript. 
		$serialize_components = explode(gbp_separator, rtrim(gps('components'), gbp_separator));

		// Unserialize the components
		$components = array();
		foreach ($serialize_components as $c)
			$components[] = unserialize(urldecode(stripslashes($c)));
		
		// Complete the permanent link array - this is exactly what needs to be stored in the db
		$permalink = array('settings' => $settings, 'components' => $components);

		// Save it
		$this->parent->set_preference($id, $permalink, 'gbp_serialized');

		$this->parent->message = messenger('', $settings['pl_name'], 'saved');
		}

	function phpArrayToJsArray($name, $array)
	{
		// From PHP.net
		if (is_array($array))
		{
			$result = $name.' = new Array();'.n;
			foreach ($array as $key => $value)
				$result .= $this->phpArrayToJsArray($name.'[\''.$key.'\']',$value,'').n;
		} else {
			$result = $name.' = \''.$array.'\';';
		}
		return $result;
	}
}

class PermanentLinksListTabView extends GBPAdminTabView
{
	function preload()
	{
		register_callback(array($this, 'multi_edit'), $this->parent->event, $this->parent->event.'_multi_edit', 1);
	}

	function main()
	{
		$permalinks = $this->parent->get_all_permalinks();

		if (count($permalinks))
		{
			$out[] = '<table align="center">'.n;

			$out[] = '<tr>';
			$out[] = '<th>Name</th>';
			$out[] = '<th>Preview</th>';
			$out[] = '<th>Precedence</th>';
			$out[] = '<th></th>';
			$out[] = '</tr>'.n;

			// Sort the permalinks via their precedence and then names.
			foreach ($permalinks as $key => $pl) {
		    	$name[$key]  = $pl['settings']['pl_name'];
			    $precedence[$key]  = $pl['settings']['pl_precedence'];
			}
			array_multisort($precedence, SORT_DESC, $name, SORT_ASC, $permalinks);

			foreach ($permalinks as $id => $permalink) {
				$out[] = '<tr>';
				$out[] = '<td>
					<a href="'.$this->parent->url(array(gbp_tab => 'build'), true).'&'.gbp_id.'='.$id.'">'.$permalink['settings']['pl_name'].'</a>
					</td>';
				$out[] = '<td>'.$permalink['settings']['pl_preview'].'</td>';
				$out[] = '<td>'.$permalink['settings']['pl_precedence'].'</td>';
				$out[] = '<td>'.
					fInput('checkbox', 'selected[]', $id).'
					</td>';
				$out[] = '</tr>'.n;
			}

			$out[] = '<tr><td colspan="4" style="border:0px;text-align:right">'.event_multiedit_form($this->parent->event, NULL, '', '', '', '', '').'</td></tr>'.n;

			$out[] = '</table>';

			$out[] = eInput($this->parent->event);
			$out[] = $this->parent->form_inputs();

			echo form(join('', $out), '', "verify('".gTxt('are_you_sure')."')");
		}
		else {
			echo '<p>You haven\'t created any custom permanent links formats yet.</p>'.
				'<p><a href="'.$this->parent->url(array(gbp_tab => 'build')).'">Click here</a> to add one.</p>';
		}
	}

	function multi_edit()
	{
		$method = gps('edit_method')
			? gps('edit_method') // From Txp 4.0.4 and greater
			: gps('method'); // Up to Txp 4.0.3

		switch ($method) {
			case 'delete':
				foreach (gps('selected') as $id) {
							$deleted[] = $this->parent->remove_permalink($id);
				}
			break;
		}

		$this->parent->message = (isset($deleted) && is_array($deleted) && count($deleted))
			? messenger('', join(', ', $deleted) ,'deleted')
			: messenger('an error occurred', '', '');
	}
}

$gbp_pl = new PermanentLinks('permanent links', 'permalinks', 'admin');
if (@txpinterface == 'public')
	register_callback(array(&$gbp_pl, '_textpattern'), 'textpattern');

# --- END PLUGIN CODE ---

?>
