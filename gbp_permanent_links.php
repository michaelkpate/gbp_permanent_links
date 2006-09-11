<?php

$plugin['name'] = 'gbp_permanent_links';
$plugin['version'] = '0.7';
$plugin['author'] = 'Graeme Porteous';
$plugin['author_uri'] = 'http://porteo.us/projects/textpattern/gbp_permanent_links/';
$plugin['description'] = 'Custom permanent links formats';
$plugin['type'] = '1';

$plugin['url'] = '$HeadURL$';
$plugin['date'] = '$LastChangedDate$';
$plugin['revision'] = '$LastChangedRevision$';

@include_once('../zem_tpl.php');

if (0) {
?>
<!-- CSS SECTION
# --- BEGIN PLUGIN CSS ---
<style type="text/css">
div#permlink_help td { vertical-align:top; }
div#permlink_help code { font-weight:bold; font: 105%/130% "Courier New", courier, monospace; background-color: #FFFFCC;}
div#permlink_help code.code_tag { font-weight:normal; border:1px dotted #999; background-color: #f0e68c; display:block; margin:10px 10px 20px; padding:10px; }
div#permlink_help a:link, div#permlink_help a:visited { color: blue; text-decoration: none; border-bottom: 1px solid blue; padding-bottom:1px;}
div#permlink_help a:hover, div#permlink_help a:active { color: blue; text-decoration: none; border-bottom: 2px solid blue; padding-bottom:1px;}
div#permlink_help h1 { color: #369; font: 20px Georgia, sans-serif; margin: 0; text-align: center; }
div#permlink_help h2 { border-bottom: 1px solid black; padding:10px 0 0; color: #369; font: 17px Georgia, sans-serif; }
div#permlink_help h3 { color: #693; font: bold 12px Arial, sans-serif; letter-spacing: 1px; margin: 10px 0 0;text-transform: uppercase;}
</style>
# --- END PLUGIN CSS ---
-->
<!-- HELP SECTION
# --- BEGIN PLUGIN HELP ---
<div id="permlink_help">
 
h1(#top). gbp_permanent_links.

Provides custom, user defined, permanent links formats.

</div>
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

// Tidy up the DB preference after r179 (permalink -> permlink) for testers - remove before public release.
safe_update('txp_prefs', "`event` = REPLACE(`event`, 'permalinks', 'permlinks')", "`name` REGEXP '^{$txp_current_plugin}'");

class PermanentLinks extends GBPPlugin
{
	var $preferences = array(
		'show_prefix' => array('value' => 0, 'type' => 'yesnoradio'),
		'show_suffix' => array('value' => 0, 'type' => 'yesnoradio'),
		'debug' => array('value' => 0, 'type' => 'yesnoradio'),
	);
	var $matched_permlink = array();
	var $partial_matches = array();
	// var $buffer_debug = array();

	function preload()
	{
		new PermanentLinksListTabView('list', 'list', $this);
		new PermanentLinksBuildTabView('build', 'build', $this);
		new GBPPreferenceTabView($this);
	}

	function main()
	{
	require_privs('publisher');
	}

	function get_all_permlinks( $sort=0, $exclude=array() )
		{
		$rs = safe_column(
			"REPLACE(name, '{$this->plugin_name}_', '') AS id", 'txp_prefs',
			"`event` = '{$this->event}' AND `name` REGEXP '^{$this->plugin_name}_.{13}$'"
		);

		$permlinks = array();
		foreach ($rs as $id)
			{
			$pl = $this->get_permlink($id);

			if (count($exclude) > 0)
				foreach ($pl['components'] as $pl_c)
				{
					if (is_array($exclude) && in_array($pl_c['type'], $exclude))
						continue 2;
					if (is_string($exclude) && $pl_c['type'] === $exclude)
						continue 2;
				}

			$permlinks[$id] = $pl;
			if ($sort)
				$precedence[$id] = $permlinks[$id]['settings']['pl_precedence'];
			}

		// If more than one permanent link, sort by their precedence value.
		if ($sort && count($permlinks) > 1)
			array_multisort($precedence, SORT_DESC, $permlinks);

		return $permlinks;
		}

	function get_permlink($id)
		{
		$permlink = $this->pref($id);
		return is_array($permlink) ? $permlink : array();
		}

	function remove_permlink($id)
	{
		$permlink = $this->get_permlink($id);
		safe_delete('txp_prefs', "`event` = '{$this->event}' AND `name` LIKE '{$this->plugin_name}_{$id}%'");
		return $permlink['settings']['pl_name'];
	}

	function _textpattern()
	{
		global $pretext, $prefs, $plugin_callback;

		$this->debug('Plugin: '.$this->plugin_name);
		$this->debug('Function: '.__FUNCTION__.'()');

		// URI
		$uri = explode('/', trim($pretext['req'], '/'));

		// The number of components comes in useful when determining the best partial match.
		$uri_component_count = count($uri);

		// Permanent links
		$permlinks = $this->get_all_permlinks(1);

		// We also want to match the front page of the site (for page numbers / feeds etc..).
		// Add a permlinks format which will do that.
		$permlinks['deafult'] = array(
			'components' => array(),
			'settings' => array(
				'pl_name' => 'gbp_permanent_links_deafult', 'pl_precedence' => '', 'pl_preview' => '',
				'con_section' => '', 'con_category' => '', 'des_section' => '', 'des_category' => '',
				'des_feed' => '', 'des_location' => '',
		));

		foreach($permlinks as $id => $pl)
		{
			$pl_components = $pl['components'];

			// URI components
			$uri_components = $uri;

			// Are we expecting a date component? If so the number of pl and uri components won't match
			$date = false; $title_page_feed = false;
			foreach($pl_components as $pl_c)
				if ($pl_c['type'] == 'date')
				 	$date = true;
				else if (in_array($pl_c['type'], array('title', 'page', 'feed')))
					$title_page_feed = true;

			if (!$title_page_feed)
				// If there isn't a title component then append on to the end.
				$pl_components[] = array('type' => 'title_page_feed', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => '');
			
			// Exit early if there are more URL components than PL components,
			// taking into account whether there is a data component
			if (!$uri_components[0] || count($uri_components) > count($pl_components) + ($date ? 2 : 0))
				continue;

			// Extract the permlink settings
			$pl_settings = $pl['settings'];
			extract($pl_settings);

			$this->debug('Permlink name: '.$pl_name);
			$this->debug('Preview: '.$pl_preview);

			// Reset pretext_replacement as we are about to start another comparison
			$pretext_replacement = array('permlink_id' => $id);

			// Loop through the permlink components
			foreach ( $pl_components as $pl_c_index=>$pl_c )
			{
				// Assume there is no match
				$match = false;

				// Check to see if there are still URI components to be checked.
				if (count( $uri_components ))
					// Get the next component.
					$uri_c = array_shift( $uri_components );

				else if (!$title_page_feed && count($pl_components) - 1 == $uri_component_count)	
					{
					// If we appended a title_page_feed component earlier and permlink and URI components
					// counts are equal, we must of finished checking this permlink, and it matches so break.
					$match = true;
					break;
					}

				else
					{
					// If there are no more URI components then we have a partial match.
					$this->debug( 'We have a partial match (No more URI components)' );

					// Store the partial match data unless there has been a preceding permlink with the
					// same number of components, as permlink have already been sorted by precedence.
					if (!array_key_exists($uri_component_count, $this->partial_matches))
						$this->partial_matches[$uri_component_count] = $pretext_replacement;

					// Unset pretext_replacement as changes could of been made in a preceding component
					unset( $pretext_replacement );

					// Break early form the foreach permlink components loop.
					$match = true;
					break;
					}

				// Extract the permlink components.
				extract($pl_c);

				// If it's a date, grab and combine the next two URI components.
				if ($type == 'date')
					$uri_c .= '/'.array_shift( $uri_components ).'/'.array_shift( $uri_components );

				$uri_c = urldecode($uri_c);

				// Always check the type unless the prefix or suffix aren't there
				$check_type = true;

				// Check prefix
				if ($prefix && $this->pref('show_prefix')) {
					if (($pos = strpos($uri_c, $prefix)) === false || $pos != 0) {
						$check_type = false;
						$this->debug('Can\'t find prefix: '.$prefix);
					}
					else
						// Check passed, remove prefix ready for the next check
						$uri_c = substr_replace($uri_c, '', 0, strlen($prefix));
				}

				// Check suffix
				if ($check_type && $suffix && $this->pref('show_suffix')) {
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
								$match = true;
							}
						break;
						case 'category':
							if (safe_field('name', 'txp_category', "`name` like '$uri_c' and `type` = 'article' limit 1")) {
								$pretext_replacement['c'] = $uri_c;
								$match = true;
							}
						break;
						case 'title':
							if ($ID = safe_field('ID', 'textpattern', "`url_title` like '$uri_c' and `Status` >= 4 limit 1")) {
								$pretext_replacement['id'] = $ID;
								$pretext['numPages'] = 1;
								$pretext['is_article_list'] = true;
								$match = true;
							}
						break;
						case 'id':
							if ($ID = safe_field('ID', 'textpattern', "`ID` = '$uri_c' and `Status` >= 4 limit 1")) {
								$pretext_replacement['id'] = $ID;
								$pretext['numPages'] = 1;
								$pretext['is_article_list'] = true;
								$match = true;
							}
						break;
						case 'author':
							if ($author = safe_field('name', 'txp_users', "RealName like '$uri_c' limit 1")) {
								$pretext_replacement['author'] = $author;
								$match = true;
							}
						break;
						case 'login':
							if ($author = safe_field('name', 'txp_users', "name like '$uri_c' limit 1")) {
								$pretext_replacement['author'] = $author;
								$match = true;
							}
						break;
						case 'custom':
							if (safe_field("custom_$custom", 'textpattern', "custom_$custom like '$uri_c' limit 1") !== false) {
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
						case 'feed':
							if (in_array($uri_c, array('rss', 'atom'))) {
								$pretext_replacement[$uri_c] = 1;
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
							// Check to see if regex is valid without outputting error messages.
							ob_start();
							preg_match($regex, $uri_c, $regex_matches);
							$is_valid_regex = !(ob_get_clean());
							if ($is_valid_regex && @$regex_matches[0]) {
								$match = true;
								$pretext_replacement["permlink_regex_{$name}"] = $regex_matches[0];
							}
						break;
					} // switch type end

					$this->debug(($match == true) ? 'YES' : 'NO');

					if (!$match)
					{
						// There hasn't been a match. Lets try to be cleaver and check to see if this
						// component is either a title, page or a feed. This makes it more probable a
						// successful match for a given permlink format occurs.
						$this->debug('Checking if "'.$uri_c.'" is of type "title_page_feed"');
						if ($type != 'title' && $ID = safe_field('ID', 'textpattern', "`url_title` like '$uri_c' and `Status` >= 4 limit 1")) {
							$pretext_replacement['id'] = $ID;
							$pretext['numPages'] = 1;
							$pretext['is_article_list'] = true;
							$match = true;
						}
						else if ($type != 'page' && is_numeric($uri_c)) {
							$pretext_replacement['pg'] = $uri_c;
							$match = true;
						}
						else if ($type != 'feed' && in_array($uri_c, array('rss', 'atom'))) {
							$pretext_replacement[$uri_c] = 1;
							$match = true;
						}
						$this->debug(($match == true) ? 'YES' : 'NO');
					}
				}

				// Break early if it's not a match, as there is no point continuing
				if ($match == false) {
					// Unset pretext_replacement as changes could of been made in a preceding component
					unset($pretext_replacement);
					break;
				}
			} // foreach permlink component end

			// If pretext_replacement is still set here then we have a match or a partial match
			if ($match) {
				if (isset($pretext_replacement))
					{
					$this->debug('We have a match!');
					$this->matched_permlink = $pretext_replacement;
					}
				else
					// Restore the partial match. Sorted by number of components and then precedence
					$pretext_replacement = array_shift(array_slice($this->partial_matches, -1));

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

				if (!empty($this->matched_permlink))
					// We're done - no point checking the other permlinks
					break;
			}

		} // foreach permlinks end

		if (count($permlinks) > 1)
		{
			// Force Textpattern and tags to use messy URLs - these are easier to
			// find in regex
			$this->set_permlink_mode();

			if (isset($pretext_replacement) || count($this->partial_matches))
				{
				global $permlink_mode;

				if (!isset($pretext_replacement))
					$pretext_replacement = array_shift(array_slice($this->partial_matches, -1));
			
				// Merge pretext_replacement with pretext
				$pretext = array_merge($pretext, $pretext_replacement);

				if (@$pretext['rss']) {
					ob_clean();
					include txpath.'/publish/rss.php';
					exit(rss());
				}

				if (@$pretext['atom']) {
					ob_clean();
					include txpath.'/publish/atom.php';
					exit(atom());
				}

				// Export required values to the global namespace
				foreach (array('id', 's', 'c', 'is_article_list') as $key)
					{
					if (array_key_exists($key, $pretext_replacement))
						$GLOBALS[$key] = $pretext_replacement[$key];
					}

				$this->debug('Pretext Replacement '.print_r($pretext, 1));
				}

			// Start output buffering and pseudo callback to textpattern_end
			ob_start(array(&$this, '_textpattern_end'));

			// Remove the plugin callbacks which have already been called
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

			// Re-call textpattern
			textpattern();

			// Stop output buffering, this sends the buffer to _textpattern_end()
			ob_end_flush();

			// textpattern() has run, kill the connection
		    die();
		}

	} // function _textpattern end

	function _textpattern_end( $html )
		{
		$html = preg_replace_callback(
			'%href="('.hu.'|\?)([^"]*)"%',
			array(&$this, '_pagelinkurl'),
			$html
		);

		// $html = tag(join(n, $this->buffer_debug), 'pre') . $html;
		return $html;
		}

	function _permlinkurl( $article_array )
		{
		global $pretext, $prefs;

		if (empty($article_array)) return;

		// Get the matched pretext replacement array.
		$matched = ( count($this->matched_permlink) )
		? $this->matched_permlink
		: array_shift(array_slice($this->partial_matches, -1));

		$uri = '';

		if ($matched && array_key_exists('id', $matched))	
			{
			// The permlink id is stored in the pretext replacement array, so we can find the permlink. 
			$pl = $this->get_permlink( $matched['permlink_id'] );
			foreach ($pl['components'] as $pl_c)
				if ( in_array($pl_c['type'], array('feed', 'page')) )
					{
					unset($pl);
					break;
					}
			}

		if (!isset($pl))
			// We have no permlink id so grab the permlink with the highest precedence.
			$pl = array_shift( $this->get_all_permlinks(1, array('feed', 'page')) );

		if (is_array($pl) && array_key_exists('components', $pl))
			{
			extract($article_array);

			if (!isset($title)) $title = $Title;
			if (empty($url_title)) $url_title = stripSpace($title);
			if (empty($section)) $section = $Section;
			if (empty($posted)) $posted = $Posted;
			if (empty($authorid)) $authorid = $AuthorID;
			if (empty($category1)) $category1 = @$Category1;
			if (empty($category2)) $category2 = @$Category2;
			if (empty($thisid)) $thisid = $ID;

			$pl_components = $pl['components'];

			// Check to see if there is a title component.
			$title = false;
			foreach($pl_components as $pl_c)
				if ($pl_c['type'] == 'title' || $pl_c['type'] == 'id')
					$title = true;

			// If there isn't a title component then we need to append one to the end of the URI
			if (!$title)
				$pl_components[] = array('type' => 'title', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => '');

			$uri = rtrim(doStrip($pretext['subpath']), '/');
			foreach ( $pl_components as $pl_c )
				{
				$uri .= '/';

				$type = $pl_c['type'];
				switch ($type)
					{
					case 'category':
						if ($uri_c = $category1);
						else if ($uri_c = $category2);
						else $uri_c = '--INVALID_CATEGORY--';
					break;
					case 'section': $uri_c = $section; break;
					case 'title': $uri_c = $url_title; break;
					case 'id': $uri_c = $thisid; break;
					case 'author': $uri_c = safe_field('RealName', 'txp_users', "name like '{$authorid}'"); break;
					case 'login': $uri_c = $authorid; break;
					case 'date': $uri_c = date('Y/m/d', $posted); break;
					case 'year': $uri_c = date('Y', $posted); break;
					case 'month': $uri_c = date('m', $posted); break;
					case 'day': $uri_c = date('d', $posted); break;
					case 'custom':
						if ($uri_c = @$article_array[$prefs["custom_{$pl_c['custom']}_set"]]);
						else if ($uri_c = @$article_array["custom_{$pl_c['custom']}"]);
						else $uri_c = '--UNSET_CUSTOM_FIELD--';
					break;
					case 'text': $uri_c = $pl_c['text']; break;
					case 'regex':
						// Check to see if regex is valid without outputting error messages.
						ob_start();
						preg_match($pl_c['regex'], $pl_c['regex'], $regex_matches);
						$is_valid_regex = !(ob_get_clean());
						if ($is_valid_regex)
							{
							$key = "permlink_regex_{$pl_c['name']}";
							$uri_c = (array_key_exists($key, $pretext)) ? $pretext[$key] : $regex_matches[0];
							}
						else
							$uri_c = '--INVALID_REGEX--';	
					break;
					}

				if (empty($uri_c))
					$uri_c = '--PERMLINK_FORMAT_ERROR--';

				$uri .= urlencode($uri_c);
				unset($uri_c);
				}

				$uri .= '/';
			}

		if (empty($uri))
			{
			// It is possible the uri is still empty if there is no match or if we're using
			// strict matching if so try the default permlink mode. 
			$this->reset_permlink_mode();
			$uri = permlinkurl( $article_array );
			$this->set_permlink_mode();
			}

		return $uri;
		}

	function _pagelinkurl( $parts, $inherit=array() )
		{
		extract(lAtts(array(
			'path'		=> 'index.php',
			'query'		=> '',
			'fragment'	=> '',
		), parse_url($parts[2])));

		// Tidy up links back to the site homepage
		if ($path == 'index.php' && empty($query))
			return 'href="' .hu. '"';

		// Fix matches like href="?s=foo"
		elseif ($path && empty($query) && $parts[1] == '?')
			{
			$query = $path;
			$path = 'index.php';
			}
		
		// Check to see if there is query to work with.
		elseif (empty($query) || $path != 'index.php' || strpos($query, '/') === true)
			return $parts[0];

		// '&amp;' will break parse_str() if they are found in a query string
		$query = str_replace('&amp;', '&', $query);

		// Make sure variables are set, saves using isset()
		extract('id', 's', 'c', 'rss', 'atom', 'pg', 'q', 'month', 'author');
		parse_str($query);

		// // Debugging for buffers
		// // $this->buffer_debug[] = $parts[0];
		// $this->buffer_debug[] = $path;
		// $this->buffer_debug[] = $query;
		// $this->buffer_debug[] = $fragment;
		// $this->buffer_debug[] = '----';

		// We have a id, pass to permlinkurl()
		if ($id)
			{
			$rs = safe_row('*, ID as thisid, unix_timestamp(Posted) as posted', 'textpattern', "ID = '{$id}'");
			return 'href="'. $this->_permlinkurl($rs) .'"';
			}

		// Some TxP tags, e.g. <txp:feed_link /> use 'section' or 'category' inconsistent
		// with most other tags. Process these now so we only have to check $s and $c.
		if (isset($section) && !$s)
			$s = $section;
		if (isset($category) && !$c)
			$c = $category;

		$out = hu;
		$out .= ($s ? $s.'/' : '');
		$out .= ($c ? $c.'/' : '');

		if ($atom)
			return 'href="'. $out .'atom"';

		if ($rss)
			return 'href="'. $out .'rss"';

		if ($pg)
			return 'href="'. $out . $pg .'"';

		if ($path == 'index.php' && $out != hu)
			return 'href="'. $out .'"';

		/*
		1 = index, textpattern/css, NULL (=index)
		2 = id, s, section, c, category, rss, atom, pg, q, n, p, month, author
		*/

		return $parts[0];
		}

	function set_permlink_mode( $function_only=NULL )
		{
		global $prefs, $pretext, $permlink_mode;
		$prefs['custom_url_func'] = array(&$this, '_permlinkurl');
		if (!$function_only)
			$pretext['permlink_mode'] = $permlink_mode = 'messy';
		}

	function reset_permlink_mode()
		{
		global $prefs, $pretext, $permlink_mode;
		$prefs['custom_url_func'] = '';
		$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];
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
		register_callback(array(&$this, 'post_save_permlink'), $this->parent->event, gbp_save, 1);
		register_callback(array(&$this, 'post_save_permlink'), $this->parent->event, gbp_post, 1);
		}

	function main()
		{
		global $prefs;
		extract(gpsa(array('step', gbp_id)));

		// With have an ID, either the permlink has just been saved or the user wants to edit it
		if ($id)
			{
			// Newly saved or beening edited, either way we're editing a permlink
			$step = gbp_save;

			// Use the ID to grab the permlink data (components & settings) 
			$permlink = $this->parent->get_permlink($id);
			$components = $this->phpArrayToJsArray('components', $permlink['components']);
			$settings = $permlink['settings'];
			}
		else
			{
			// Creating a new ID and permlink.
			$step = gbp_post;
			$id = uniqid('');

			// Set the default set of components depending on whether there is parent permlink 
			$components = $this->phpArrayToJsArray('components', array(
				array('type' => 'section', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
				array('type' => 'category', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
				array('type' => 'title', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
			));

			$settings = array(
				'pl_name' => 'Untitled', 'pl_precedence' => '',
				'con_section' => '', 'con_category' => '',
				'des_section' => '', 'des_category' => '', 'des_feed' => '', 'des_location' => '',
			);
			}

		// Extract settings - this will be useful when creating the user interface
		extract($settings);

		// PHP & Javascript constants;
		$separator = gbp_separator;
		$components_div = 'permlink_components_ui';
		$components_form = 'permlink_components';
		$settings_form = 'permlink_settings';
		$show_prefix = $this->pref('show_prefix');
		$show_suffix = $this->pref('show_suffix');

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
		element.style['display'] = 'inline';

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
		while (permlink_div().hasChildNodes())
			{ permlink_div().removeChild(permlink_div().firstChild); }

		for (var i = 0; i < components.length; i++)
		{
			var c = components[i];

			// Create the new component
			var new_component = document.createElement('div');

			// Set the id interger for this component
			new_component.id = i;

			// Javascript, onmouseup setting
			new_component.setAttribute('onmousedown', 'component_switch(this);');
			new_component.onmousedown = function() { component_switch(this); };

			// Refresh the look of the component
			new_component = component_refresh(new_component);

			// And add the new component to the ui
			permlink_div().appendChild(new_component);
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

	function component_update(element)
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
				if ('{$show_prefix}')
					form('{$components_form}').prefix.parentNode.style['display'] = '';
				if ('{$show_suffix}')
					form('{$components_form}').suffix.parentNode.style['display'] = '';
			break;
		}

		// Save data
		components[_current] = c;

		// Refresh component to reflect new data
		component_refresh(component(_current));

		// Re-focus the active form input
		if (element)
			element.focus();
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
		var c = ''; var is_permlink = false; var has_page_or_search = false;
		for (var i = 0; i < components.length; i++) {
			if (components[i]['type'] == 'title')
				is_permlink = true;
			if (components[i]['type'] == 'page' || components[i]['type'] == 'search')
				has_page_or_search = true;
			c = c + jsArrayToPhpArray(components[i]) + '{$separator}';
		}

		if (is_permlink && has_page_or_search)
			alert("Your permanent link can't contain either a 'page' or a 'search' component with a 'title' component.");

		else if (is_permlink && (form.pl_name.value == '' || form.pl_name.value == 'Untitled'))
		{
			document.getElementById('settings').style['display'] = '';
			form.pl_name.style['border'] = '3px solid rgb(221, 0, 0)';
			alert('Please enter a name for this permanent link format.');
		}
		else
		{
			form.components.value = c;
			if (permlink_div().textContent)
				form.pl_preview.value = permlink_div().textContent;
			else if (permlink_div().innerText)
				form.pl_preview.value = permlink_div().innerText;
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

	function permlink_div()
	{
		// Return the permlink format element
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
		return permlink_div().childNodes[index];
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
			'title' => 'Title', 'id' => 'ID',
			'date' => 'Date (yyyy/mm/dd)', 'year' => 'Year',
			'month' => 'Month', 'day' => 'Day',
			'author' => 'Author (Real name)', 'login' => 'Author (Login)',
			'custom' => 'Custom Field', 'page' => 'Page Number',
			'feed' => 'Feed', 'search' => 'Search request',
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
			gbpFSelect('custom', $custom_fields, '', 0, 'Custom', ' onchange="component_update(this);"').n.
			gbpFInput('text', 'name', '', array('keyup' => 'component_update(this);'), 'Name').n.
			gbpFInput('text', 'prefix', '', array('keyup' => 'component_update(this);'), 'Prefix').n.
			gbpFInput('text', 'regex', '', array('keyup' => 'component_update(this);'), 'Regular Expression').n.
			gbpFInput('text', 'suffix', '', array('keyup' => 'component_update(this);'), 'Suffix').n.
			gbpFInput('text', 'text', '', array('keyup' => 'component_update(this);'), 'Text')
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
		$out[] = $this->form_inputs();
		// Step and ID form inputs
		$out[] = sInput($step);
		$out[] = hInput(gbp_id, $id);

		$out[] = '</form>';

		// Lets echo everything out. Yah!
		echo join(n, $out);
		}

	function post_save_permlink()
		{
		// The function posts or saves a permanent link to txp_prefs

		extract(gpsa(array('step', gbp_id)));

		// Grab the user defined settings from the form POST data
		$settings = gpsa(array(
			'pl_name', 'pl_precedence', 'pl_preview',
			'con_section', 'con_category',
			'des_section', 'des_category', 'des_feed', 'des_location',
		));

		// Remove spaces from the permanent link preview
		$settings['pl_preview'] = preg_replace('%\s*/\s*%', '/', $settings['pl_preview']);

		// Explode the separated string of serialize components - this was made by JavaScript. 
		$serialize_components = explode(gbp_separator, rtrim(gps('components'), gbp_separator));

		// Unserialize the components
		$components = array();
		foreach ($serialize_components as $c)
			$components[] = unserialize(urldecode(stripslashes($c)));
		
		// Complete the permanent link array - this is exactly what needs to be stored in the db
		$permlink = array('settings' => $settings, 'components' => $components);

		// Save it
		$this->set_preference($id, $permlink, 'gbp_serialized');

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
		register_callback(array(&$this, $this->parent->event.'_multi_edit'), $this->parent->event, $this->parent->event.'_multi_edit', 1);
		register_callback(array(&$this, $this->parent->event.'_change_pageby'), $this->parent->event, $this->parent->event.'_change_pageby', 1);
	}

	function main()
		{
		extract(gpsa(array('page', 'sort', 'dir', 'crit', 'search_method')));
		
		$event = $this->parent->event;

		$permlinks = $this->parent->get_all_permlinks();
		$total = count($permlinks);

		if ($total < 1)
			{
			echo graf('You haven\'t created any custom permanent links formats yet.', ' style="text-align: center;"').
				 graf('<a href="'.$this->url(array(gbp_tab => 'build'), true).'">Click here</a> to add one.', ' style="text-align: center;"');
			return;
			}

		$limit = max($this->pref('list_pageby'), 15);

		if (!function_exists('pager'))
			{
			// This is taken from txplib_misc.php r1588 it is required for 4.0.3 compatibitly
			function pager($total, $limit, $page)
				{
				$num_pages = ceil($total / $limit);
				$page = $page ? (int) $page : 1;
				$page = min(max($page, 1), $num_pages);
				$offset = max(($page - 1) * $limit, 0);
				return array($page, $offset, $num_pages);
				}
			}

		list($page, $offset, $numPages) = pager($total, $limit, $page);

		if (empty($sort))
			$sort = 'pl_precedence';

		if (empty($dir))
			$dir = 'desc';

		$dir = ($dir == 'desc') ? 'desc' : 'asc';

		// Sort the permlinks via the selected column and then their names.
		foreach ($permlinks as $id => $permlink)
			{
			$sort_keys[$id] = $permlink['settings'][$sort];
			$name[$id] = $permlink['settings']['pl_name'];
			}
		array_multisort($sort_keys, (($dir == 'desc') ? SORT_DESC : SORT_ASC), $name, SORT_ASC, $permlinks);

		$switch_dir = ($dir == 'desc') ? 'asc' : 'desc';
		
		$permlinks = array_slice($permlinks, $offset, $limit);

		if (count($permlinks))
			{
			echo n.n.'<form name="longform" method="post" action="index.php" onsubmit="return verify(\''.gTxt('are_you_sure').'\')">'.

				n.startTable('list').
				n.tr(
					n.column_head('name', 'pl_name', $event, true, $switch_dir, $crit, $search_method).
					hCell().
					column_head('preview', 'pl_preview', $event, true, $switch_dir, $crit, $search_method).
					column_head('precedence', 'pl_precedence', $event, true, $switch_dir, $crit, $search_method).
					hCell()
				);

			include_once txpath.'/publish/taghandlers.php';

			foreach ($permlinks as $id => $permlink)
				{
				extract($permlink['settings']);

				$manage = n.'<ul'.(version_compare($GLOBALS['thisversion'], '4.0.3', '<=') ? ' style="margin:0;padding:0;list-style-type:none;">' : '>').
						n.t.'<li>'.href(gTxt('edit'), $this->url(array(gbp_tab => 'build', gbp_id => $id), true)).'</li>'.
						n.'</ul>';

				echo n.n.tr(

					td(
						href($pl_name, $this->url(array(gbp_tab => 'build', gbp_id => $id), true))
					, 75).

					td($manage, 35).

					td($pl_preview, 175).
					td($pl_precedence, 50).

					td(
						fInput('checkbox', 'selected[]', $id)
					)
				);
				}

			if (!function_exists('nav_form'))
				{
				// This is basically stolen from the 4.0.3 version of includes/txp_list.php 
				// - list_nav_form() for 4.0.3 compatibitly
				function nav_form($event, $page, $numPages, $sort, $dir, $crit, $method)
					{
						$nav[] = ($page > 1) 
						? PrevNextLink($event, $page-1, gTxt('prev'), 'prev', $sort, $dir)
						: '';
						$nav[] = sp.small($page. '/'.$numPages).sp;
						$nav[] = ($page != $numPages) 
						? PrevNextLink($event, $page+1, gTxt('next'), 'next', $sort, $dir)
						: '';
						return ($nav)
						? graf(join('', $nav), ' align="center"')
						: '';
					}
				}

			echo n.n.tr(
				tda(
					select_buttons().
					$this->permlinks_multiedit_form($page, $sort, $dir, $crit, $search_method)
				,' colspan="4" style="text-align: right; border: none;"')
			).

			n.endTable().
			n.'</form>'.

			n.nav_form($event, $page, $numPages, $sort, $dir, $crit, $search_method).

			n.pageby_form($event, $this->pref('list_pageby'));
			}
		}

	function permlinks_multiedit_form($page, $sort, $dir, $crit, $search_method)
	{
		$methods = array(
			'delete' => gTxt('delete'),
		);

		return event_multiedit_form($this->parent->event, $methods, $page, $sort, $dir, $crit, $search_method);
	}

	function permlinks_change_pageby() 
	{
		$this->set_preference('list_pageby', gps('qty'));
	}

	function permlinks_multi_edit()
	{
		$method = gps('edit_method')
			? gps('edit_method') // From Txp 4.0.4 and greater
			: gps('method'); // Up to Txp 4.0.3

		switch ($method) {
			case 'delete':
				foreach (gps('selected') as $id) {
							$deleted[] = $this->parent->remove_permlink($id);
				}
			break;
		}

		$this->parent->message = (isset($deleted) && is_array($deleted) && count($deleted))
			? messenger('', join(', ', $deleted) ,'deleted')
			: messenger('an error occurred', '', '');
	}
}

$gbp_pl = new PermanentLinks('permanent links', 'permlinks', 'admin');
if (@txpinterface == 'public')
	register_callback(array(&$gbp_pl, '_textpattern'), 'textpattern');

# --- END PLUGIN CODE ---

?>
