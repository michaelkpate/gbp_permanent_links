<?php

$plugin['name'] = 'gbp_permanent_links';
$plugin['version'] = '0.14';
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

// Constants
@define('gbp_save', 'save');
@define('gbp_post', 'post');
@define('gbp_separator', '&~&~&');

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
		'omit_trailing_slash' => array('value' => 0 , 'type' => 'yesnoradio'),
		'redirect_mt_style_links' => array('value' => 1 , 'type' => 'yesnoradio'),
		'clean_page_archive_links' => array('value' => 1 , 'type' => 'yesnoradio'),
		'join_pretext_to_pagelinks' => array('value' => 1 , 'type' => 'yesnoradio'),
		'check_pretext_category_context' => array('value' => 0 , 'type' => 'yesnoradio'),
		'check_pretext_section_context' => array('value' => 0 , 'type' => 'yesnoradio'),
		'force_lowercase_urls' => array('value' => 1 , 'type' => 'yesnoradio'),
		'automatically_append_title' => array('value' => 1 , 'type' => 'yesnoradio'),
		'permlink_redirect_http_status' => array('value' => '301' , 'type' => 'text_input'),
		'url_redirect_http_status' => array('value' => '302' , 'type' => 'text_input'),
		'text_and_regex_segment_scores' => array('value' => '0' , 'type' => 'text_input'),
		'debug' => array('value' => 0, 'type' => 'yesnoradio'),
	);
	var $matched_permlink = array();
	var $partial_matches = array();
	var $cleaver_partial_match;
	var $buffer_debug = array();

	function preload () {
		new PermanentLinksListTabView('list', 'list', $this);
		new PermanentLinksBuildTabView('build', 'build', $this);
		new GBPPreferenceTabView($this);
	}

	function main () {
		require_privs($this->event);
	}

	function get_all_permlinks ($sort = 0, $exclude = array()) {
		static $rs;
		if (!isset($rs))
			$rs = safe_column(
				"REPLACE(name, '{$this->plugin_name}_', '') AS id", 'txp_prefs',
				"`event` = '{$this->event}' AND `name` REGEXP '^{$this->plugin_name}_.{13}$'"
			);

		if (@txpinterface == 'public')
			$this->debug('Loading permlinks from db');
		$i = 0;

		$permlinks = array();
		foreach ($rs as $id) {
			$pl = $this->get_permlink($id);

			if (count($exclude) > 0)
				foreach ($pl['components'] as $pl_c) {
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

		if (@txpinterface == 'public') {
			foreach ($permlinks as $pl)
				$this->debug($pl['settings']['pl_precedence'].': '.$pl['settings']['pl_name'].' ('.$pl['settings']['pl_preview'].')');
		}

		return $permlinks;
	}

	function get_permlink ($id) {
		$permlink = $this->pref($id);
		return is_array($permlink) ? $permlink : array();
	}

	function remove_permlink ($id) {
		$permlink = $this->get_permlink($id);
		safe_delete('txp_prefs', "`event` = '{$this->event}' AND `name` LIKE '{$this->plugin_name}_{$id}%'");
		return $permlink['settings']['pl_name'];
	}

	function _feed_entry () {
		static $set;
		if (!isset($set)) {
			// We're in a feed force debugging off.
			$this->preferences['debug']['value'] = $GLOBALS['prefs'][$this->plugin_name.'_debug'] = 0;

			$this->set_permlink_mode(true);
			$set = true;
		}
	}

	function _textpattern () {
		global $plugins_ver, $pretext, $prefs, $plugin_callback;

		$this->debug('Plugin: '.$this->plugin_name.' - '.$plugins_ver[$this->plugin_name]);
		$this->debug('Function: '.__FUNCTION__.'()');

		// URI
		$req = $pretext['req'];
		$req = preg_replace('%\?[^\/]+$%', '', $req);
		$this->debug('Request URI: '.$req);
		$uri = explode('/', trim($req, '/'));

		// The number of components comes in useful when determining the best partial match.
		$uri_component_count = count($uri);

		// Permanent links
		$permlinks = $this->get_all_permlinks(1);

		// Force Textpattern and tags to use messy URLs - these are easier to
		// find in regex
		$this->set_permlink_mode();

		if (count($permlinks)) {

			// We also want to match the front page of the site (for page numbers / feeds etc..).
			// Add a permlinks rule which will do that.
			$permlinks['default'] = array(
				'components' => array(),
				'settings' => array(
					'pl_name' => 'gbp_permanent_links_default', 'pl_precedence' => '', 'pl_preview' => '/',
					'con_section' => '', 'con_category' => '', 'des_section' => '', 'des_category' => '',
					'des_permlink' => '', 'des_feed' => '', 'des_location' => '', 'des_page' => ''
			));

			// Extend the pretext_replacement scope outside the foreach permlink loop
			$pretext_replacement = NULL;

			foreach($permlinks as $id => $pl) {
				// Extract the permlink settings
				$pl_settings = $pl['settings'];
				extract($pl_settings);

				$this->debug('Permlink name: '.$pl_name);
				$this->debug('Permlink id: '.$id);
				$this->debug('Preview: '.$pl_preview);

				$pl_components = $pl['components'];

				// URI components
				$uri_components = $uri;

				$this->debug('PL component count: '.count($pl_components));
				$this->debug('URL component count: '.count($uri_components));

				$date = false; $title_page_feed = false;
				foreach($pl_components as $pl_c)
					// Are we expecting a date component? If so the number of pl and uri components won't match
					if ($pl_c['type'] == 'date')
					 	$date = true;
					// Do we have either a title, page or a feed component?
					else if (in_array($pl_c['type'], array('title', 'page', 'feed')))
						$title_page_feed = true;

				if (!$title_page_feed)
					// If there isn't a title, page or feed component then append a special type for cleaver partial matching
					$pl_components[] = array('type' => 'title_page_feed', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => '');

				// Exit early if there are more URL components than PL components,
				// taking into account whether there is a data component
				if (!$uri_components[0] || count($uri_components) > count($pl_components) + ($date ? 2 : 0)) {
					$this->debug('More URL components than PL components');
					continue;
				}

				// Reset pretext_replacement as we are about to start another comparison
				$pretext_replacement = array('permlink_id' => $id);

				// Reset the article context string
				$context = array();
				unset($context_str);
				if (!empty($des_section))
					$context[] = "`Section` = '$des_section'";
				if (!empty($des_category))
					$context[] = "(`Category1` = '$des_category' OR `Category2` = '$des_category')";
				$context_str = (count($context) > 0 ? 'and '.join(' and ', $context) : '');

				// Assume there is no match
				$partial_match = false;
				$cleaver_partial_match = false;

				// Loop through the permlink components
				foreach ($pl_components as $pl_c_index => $pl_c) {
					// Assume there is no match
					$match = false;

					// Check to see if there are still URI components to be checked.
					if (count($uri_components))
						// Get the next component.
						$uri_c = array_shift($uri_components);

					else if (!$title_page_feed && count($pl_components) - 1 == $uri_component_count) {
						// If we appended a title_page_feed component earlier and permlink and URI components
						// counts are equal, we must of finished checking this permlink, and it matches so break.
						$match = true;
						break;

					} else {
						// If there are no more URI components then we have a partial match.
						// Store the partial match data unless there has been a preceding permlink with the
						// same number of components, as permlink have already been sorted by precedence.
						if (!array_key_exists($uri_component_count, $this->partial_matches))
							$this->partial_matches[$uri_component_count] = $pretext_replacement;

						// Unset pretext_replacement as changes could of been made in a preceding component
						unset($pretext_replacement);

						// Break early form the foreach permlink components loop.
						$partial_match = true;
						break;
					}

					// Extract the permlink components.
					extract($pl_c);

					// If it's a date, grab and combine the next two URI components.
					if ($type == 'date')
						$uri_c .= '/'.array_shift($uri_components).'/'.array_shift($uri_components);

					// Decode the URL
					$uri_c = urldecode($uri_c);

					// Always check the type unless the prefix or suffix aren't there
					$check_type = true;

					// Check prefix
					if ($prefix && $this->pref('show_prefix')) {
						$sanitized_prefix = urldecode($this->encode_url($prefix));
						if (($pos = strpos($uri_c, $sanitized_prefix)) === false || $pos != 0) {
							$check_type = false;
							$this->debug('Can\'t find prefix: '.$prefix);
						} else
							// Check passed, remove prefix ready for the next check
							$uri_c = substr_replace($uri_c, '', 0, strlen($sanitized_prefix));
					}

					// Check suffix
					if ($check_type && $suffix && $this->pref('show_suffix')) {
						$sanitized_suffix = urldecode($this->encode_url($suffix));
						if (($pos = strrpos($uri_c, $sanitized_suffix)) === false) {
							$check_type = false;
							$this->debug('Can\'t find suffix: '.$suffix);
						} else
							// Check passed, remove suffix ready for the next check
							$uri_c = substr_replace($uri_c, '', $pos, strlen($sanitized_suffix));
					}

					// Both the prefix and suffix settings have passed
					if ($check_type) {
						$this->debug('Checking if "'.$uri_c.'" is of type "'.$type.'"');
						$uri_c = doSlash($uri_c);

						//
						if ($prefs['permalink_title_format']) {
							$mt_search = array('/_/', '/\.html$/');
							$mt_replace = array('-', '');
						} else {
							$mt_search = array('/(?:^|_)(.)/e', '/\.html$/');
							$mt_replace = array("strtoupper('\\1')", '');
						}
						$mt_uri_c = $this->pref('redirect_mt_style_links')
							? preg_replace($mt_search, $mt_replace, $uri_c)
							: '';

						// Compare based on type
						switch ($type) {
							case 'section':
								if ($rs = safe_row('name', 'txp_section', "(`name` like '$uri_c' or `name` like '$mt_uri_c') limit 1")) {
									$this->debug('Section name: '.$rs['name']);
									$pretext_replacement['s'] = $rs['name'];
									$context[] = "`Section` = '{$rs['name']}'";
									$match = true;
								}
							break;
							case 'category':
								if ($rs = safe_row('name', 'txp_category', "(`name` like '$uri_c' or `name` like '$mt_uri_c') and `type` = 'article' limit 1")) {
									$this->debug('Category name: '.$rs['name']);
									$pretext_replacement['c'] = $rs['name'];
									$context[] = "(`Category1` = '{$rs['name']}' OR `Category2` = '$uri_c')";
									$match = true;
								}
							break;
							case 'title':
								if ($rs = safe_row('ID, Posted', 'textpattern', "(`url_title` like '$uri_c' or `url_title` like '$mt_uri_c') $context_str and `Status` >= 4 limit 1")) {
									$this->debug('Article id: '.$rs['ID']);
									$mt_redirect = ($uri_c != $mt_uri_c);
									$pretext_replacement['id'] = $rs['ID'];
									$pretext_replacement['Posted'] = $rs['Posted'];
									$pretext['numPages'] = 1;
									$pretext['is_article_list'] = false;
									$match = true;
								}
							break;
							case 'id':
								if ($rs = safe_row('ID, Posted', 'textpattern', "`ID` = '$uri_c' $context_str and `Status` >= 4 limit 1")) {
									$pretext_replacement['id'] = $rs['ID'];
									$pretext_replacement['Posted'] = $rs['Posted'];
									$pretext['numPages'] = 1;
									$pretext['is_article_list'] = false;
									$match = true;
								}
							break;
							case 'author':
								if ($author = safe_field('name', 'txp_users', "RealName like '$uri_c' limit 1")) {
									$pretext_replacement['author'] = $author;
									$context[] = "`AuthorID` = '$author'";
									$match = true;
								}
							break;
							case 'login':
								if ($author = safe_field('name', 'txp_users', "name like '$uri_c' limit 1")) {
									$pretext_replacement['author'] = $author;
									$context[] = "`AuthorID` = '$author'";
									$match = true;
								}
							break;
							case 'custom':
								$custom_options = array_values(array_map(array($this, "encode_url"), safe_column("custom_$custom", 'textpattern', "custom_$custom != ''")));
								if ($this->pref('force_lowercase_urls'))
									$custom_options = array_map("strtolower", $custom_options);
								if (in_array($uri_c, $custom_options)) {
									$match = true;
								}
							break;
							case 'date':
								if (preg_match('/^\d{4}\/\d{2}\/\d{2}$/', $uri_c)) {
									$pretext_replacement['date'] = str_replace('/', '-', $uri_c);
									$match = true;
								}
							break;
							case 'year':
								if (preg_match('/^\d{4}$/', $uri_c)) {
									$pretext_replacement['year'] = $uri_c;
									$match = true;
								}
							break;
							case 'month':
							case 'day':
								if (preg_match('/^\d{2}$/', $uri_c)) {
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
								if ($this->encode_url($text) == $uri_c) {
									$match = true;
									$pretext_replacement["permlink_text_{$name}"] = $uri_c;
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

						// Update the article context string
						$context_str = (count($context) > 0 ? 'and '.join(' and ', $context) : '');

						$this->debug(($match == true) ? 'YES' : 'NO');

						if (!$match && !$cleaver_partial_match) {
							// There hasn't been a match or a complete cleaver partial match. Lets try to be cleaver and
							// check to see if this component is either a title, page or a feed. This makes it more probable
							// a successful match for a given permlink rule occurs.
							$this->debug('Checking if "'.$uri_c.'" is of type "title_page_feed"');

							if ($type != 'title' && $ID = safe_field('ID', 'textpattern', "`url_title` like '$uri_c' $context_str and `Status` >= 4 limit 1")) {
								$pretext_replacement['id'] = $ID;
								$pretext['numPages'] = 1;
								$pretext['is_article_list'] = false;
								$cleaver_partial_match = true;
							} else if ($this->pref('clean_page_archive_links') && $type != 'page' && is_numeric($uri_c)) {
								$pretext_replacement['pg'] = $uri_c;
								$cleaver_partial_match = true;
							} else if ($type != 'feed' && in_array($uri_c, array('rss', 'atom'))) {
								$pretext_replacement[$uri_c] = 1;
								$cleaver_partial_match = true;
							}

							$this->debug(($cleaver_partial_match == true) ? 'YES' : 'NO');

							if ($cleaver_partial_match) {
								$this->cleaver_partial_match = $pretext_replacement;

								// Unset pretext_replacement as changes could of been made in a preceding component
								unset($pretext_replacement);

								$cleaver_partial_match = true;
								continue 2;
							}
						}
					} // check type end

					// Break early if the component doesn't match, as there is no point continuing
					if ($match == false) {
						// Unset pretext_replacement as changes could of been made in a preceding component
						unset($pretext_replacement);
						break;
					}
				} // foreach permlink component end

				if ($match || $partial_match || $cleaver_partial_match) {
					// Extract the settings for this permlink
					@extract($permlinks[$pretext_replacement['permlink_id']]['settings']);

					// Check the permlink section and category conditions
					if ((!empty($con_section) && $con_section != @$pretext_replacement['s']) ||
					(!empty($con_category) && $con_category != @$pretext_replacement['c'])) {
						$this->debug('Permlink conditions failed');
						if (@$con_section)  $this->debug('con_section = '. $con_section);
						if (@$con_category) $this->debug('con_category = '. $con_category);

						unset($pretext_replacement);
					}
					else if ($match && isset($pretext_replacement))
						$this->debug('We have a match!');

					else if ($partial_match && count($this->partial_matches))
						$this->debug('We have a \'partial match\'');

					else if ($cleaver_partial_match && isset($cleaver_partial_match))
						$this->debug('We have a \'cleaver partial match\'');

					else {
						$this->debug('Error: Can\'t determine the correct type match');
						// This permlink has failed, continue execution of the foreach permlinks loop
						unset($pretext_replacement);
					}
				}

				// We have a match
				if (@$pretext_replacement)
					break;

			} // foreach permlinks end

			// If there is no match restore the most likely partial match. Sorted by number of components and then precedence
			if (!@$pretext_replacement && count($this->partial_matches)) {
				$pt_slice = array_slice($this->partial_matches, -1);
				$pretext_replacement = array_shift($pt_slice);
			}
			unset($this->partial_matches);

			// Restore the cleaver_partial_match if there is no other matches
			if (!@$pretext_replacement && $this->cleaver_partial_match)
				$pretext_replacement = $this->cleaver_partial_match;
			unset($this->cleaver_partial_match);

			// Extract the settings for this permlink
			@extract($permlinks[$pretext_replacement['permlink_id']]['settings']);

			// If pretext_replacement is still set here then we have a match
			if (@$pretext_replacement) {
				$this->debug('Pretext Replacement '.print_r($pretext_replacement, 1));

				if (!empty($des_section))
					$pretext_replacement['s'] = $des_section;
				if (!empty($des_category))
					$pretext_replacement['c'] = $des_category;
				if (!empty($des_feed))
					$pretext_replacement[$des_feed] = 1;

				if (@$pretext_replacement['id'] && @$pretext_replacement['Posted']) {
				 	if ($np = getNextPrev($pretext_replacement['id'], $pretext_replacement['Posted'], @$pretext_replacement['s']))
						$pretext_replacement = array_merge($pretext_replacement, $np);
				}
				unset($pretext_replacement['Posted']);

				// If there is a match then we most set the http status correctly as txp's pretext might set it to 404
				$pretext_replacement['status'] = '200';

				// Store the orginial HTTP status code
				// We might need to log the page hit if it equals 404
				$orginial_status = $pretext['status'];

				// Txp only looks at the month, but due to how we phase the month we can manipulate the sql to our needs
				if (array_key_exists('date', $pretext_replacement)) {
					$pretext_replacement['month'] = $pretext_replacement['date'];
					unset($pretext_replacement['date']);
				} else if (array_key_exists('year', $pretext_replacement) ||
				array_key_exists('month', $pretext_replacement) ||
				array_key_exists('day', $pretext_replacement)) {
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
				{
					if (!@$pretext_replacement['id'])
						$pretext_replacement['s'] = 'default';
					else
						$pretext_replacement['s'] = safe_field('Section', 'textpattern', 'ID = '.$pretext_replacement['id']);
				}

				// Set the css and page template, otherwise we get an unknown section
				$section_settings = safe_row('css, page', 'txp_section', "name = '{$pretext_replacement['s']}' limit 1");
				$pretext_replacement['page'] = (@$des_page) ? $des_page : $section_settings['page'];
				$pretext_replacement['css']  = $section_settings['css'];

				$this->matched_permlink = $pretext_replacement;

				global $permlink_mode;

				if (in_array($prefs['permlink_mode'], array('id_title', 'section_id_title')) && @$pretext_replacement['pg'] && !@$pretext_replacement['id']) {
					$pretext_replacement['id'] = '';
					$pretext_replacement['is_article_list'] = true;
				}

				// Merge pretext_replacement with pretext
				$pretext = array_merge($pretext, $pretext_replacement);

				if (is_numeric(@$pretext['id'])) {
					$a = safe_row('*, unix_timestamp(Posted) as uPosted, unix_timestamp(Expires) as uExpires, unix_timestamp(LastMod) as uLastMod', 'textpattern', 'ID='.intval($pretext['id']).' and Status >= 4');
					populateArticleData($a);
				}

				// Export required values to the global namespace
				foreach (array('id', 's', 'c', 'pg', 'is_article_list', 'prev_id', 'prev_title', 'next_id', 'next_title', 'css') as $key) {
					if (array_key_exists($key, $pretext))
						$GLOBALS[$key] = $pretext[$key];
				}

				if (count($this->matched_permlink) || @$mt_redirect) {
					$pl_index = $pretext['permlink_id'];
					if (!@$mt_redirect || !$this->pref('redirect_mt_style_links')) {
						$pl = $this->get_permlink($pretext['permlink_id']);
						$pl_index = @$pl['settings']['des_permlink'];
					}

					if (@$pretext['id'] && $pl_index) {
						if (count($this->get_permlink($pl_index)) > 0) {
							ob_clean();
							global $siteurl;
							$rs = safe_row('*, ID as thisid, unix_timestamp(Posted) as posted', 'textpattern', "ID = '{$pretext['id']}'");
							$host = rtrim(str_replace(rtrim(doStrip($pretext['subpath']), '/'), '', hu), '/');
							$this->redirect($host.$this->_permlinkurl($rs, PERMLINKURL, $pl_index), $this->pref('permlink_redirect_http_status'));
						}
					} else if ($url = @$pl['settings']['des_location']) {
						ob_clean();
						$this->redirect($url, $this->pref('url_redirect_http_status'));
					}
				}

				if (@$pretext['rss']) {
					if (@$pretext['s'])
						$_POST['section'] = $pretext['s'];
					if (@$pretext['c'])
						$_POST['category'] = $pretext['c'];
					ob_clean();
					include txpath.'/publish/rss.php';
					exit(rss());
				}

				if (@$pretext['atom']) {
					if (@$pretext['s'])
						$_POST['section'] = $pretext['s'];
					if (@$pretext['c'])
						$_POST['category'] = $pretext['c'];
					ob_clean();
					include txpath.'/publish/atom.php';
					exit(atom());
				}

				$this->debug('Pretext '.print_r($pretext, 1));
			} else {
				$this->debug('NO CHANGES MADE');
			}

			// Log this page hit
			if (@$orginial_status == 404)
				log_hit($pretext['status']);

			// Start output buffering and pseudo callback to textpattern_end
			ob_start(array(&$this, '_textpattern_end_callback'));

			// TxP 4.0.5 (r2436) introduced the textpattern_end callback making the following redundant
			$version = array_sum(array_map(
				create_function('$line', 'if (preg_match(\'/^\$'.'LastChangedRevision: (\w+) \$/\', $line, $match)) return $match[1];'),
				@file(txpath . '/publish.php')
			));
			if ($version >= '2436') return;

			// Remove the plugin callbacks which have already been called
			function filter_callbacks($c) {
				if ($c['event']!='textpattern') return true;
				if (@$c['function'][0]->plugin_name == 'gbp_permanent_links' &&
						@$c['function'][1] == '_textpattern')
				{
					$GLOBALS['gbp_found_self'] = true;
					return false;
				}
				return @$GLOBALS['gbp_found_self'];
			}
			$plugin_callback = array_filter($plugin_callback, 'filter_callbacks');
			unset($GLOBALS['gbp_found_self']);

			// Re-call textpattern
			textpattern();

			// Call custom textpattern_end callback
			$this->_textpattern_end();

			// textpattern() has run, kill the connection
			die();
		}

	} // function _textpattern end

	function _textpattern_end () {
		// Redirect to a 404 if the page number is greater than the max number of pages
		// Has to be after textpattern() as $thispage is set during <txp:article />
		global $thispage, $pretext;
		if ((@$pretext['pg'] && isset($thispage)) &&
		($thispage['numPages'] < $pretext['pg'])) {
			ob_end_clean();
			txp_die(gTxt('404_not_found'), '404');
		}

		// Stop output buffering, this sends the buffer to _textpattern_end_callback()
		while (@ob_end_flush());

	} // function _textpattern_end end

	function _textpattern_end_callback ($html, $override = '') {
		global $pretext, $production_status;

		if ($override) $pretext['permlink_override'] = $override;
		$html = preg_replace_callback(
			'%href="('.hu.'|\?)([^"]*)"%',
			array(&$this, '_pagelinkurl'),
			$html
		);
		unset($pretext['permlink_override']);

		if ($this->pref('debug') && in_array($production_status, array('debug', 'testing'))) {
			$debug = join(n, $this->buffer_debug);
			$this->buffer_debug = array();
			if ($debug)
				$html = comment(n.$debug.n) . $html;
		}

		return $html;
	} // function _textpattern_end_callback end

	function check_permlink_conditions ($pl, $article_array) {
		if (empty($article_array['section'])) $article_array['section'] = @$article_array['Section'];
		if (empty($article_array['category1'])) $article_array['category1'] = @$article_array['Category1'];
		if (empty($article_array['category2'])) $article_array['category2'] = @$article_array['Category2'];

		if (@$pl['settings']['con_category'] && ($pl['settings']['con_category'] != $article_array['category1'] || $pl['settings']['con_category'] != $article_array['category2']))
			return false;
		if (@$pl['settings']['con_section'] && $pl['settings']['con_section'] != $article_array['section'])
			return false;

		return true;
	}

	function _permlinkurl ($article_array, $type = PERMLINKURL, $pl_index = NULL) {
		global $pretext, $prefs, $production_status;

		if ($type == PAGELINKURL)
			return $this->toggle_custom_url_func('pagelinkurl', $article_array);

		if (empty($article_array)) return;

		if ($pl_index)
			$pl = $this->get_permlink($pl_index);
		else {
			// Get the matched pretext replacement array.
			$matched = (count($this->matched_permlink))
			? $this->matched_permlink
			: @array_shift(array_slice($this->partial_matches, -1));

			if (!isset($pl) && $matched && array_key_exists('id', $matched)) {
				// The permlink id is stored in the pretext replacement array, so we can find the permlink.
				$pl = $this->get_permlink($matched['permlink_id']);
				foreach ($pl['components'] as $pl_c)
					if (in_array($pl_c['type'], array('feed', 'page')) || !$this->check_permlink_conditions($pl, $article_array)) {
						unset($pl);
						break;
					}
			}

			if (!isset($pl)) {
				// We have no permlink id so grab the permlink with the highest precedence.
				$permlinks = $this->get_all_permlinks(1, array('feed', 'page'));
				foreach ($permlinks as $key => $pl)
					if (!$this->check_permlink_conditions($pl, $article_array))
						unset($permlinks[$key]);
				$pl = array_shift($permlinks);
			}
		}

		$uri = '';

		if (is_array($pl) && array_key_exists('components', $pl)) {
			extract($article_array);

			if (!isset($title)) $title = $Title;
			if (empty($url_title)) $url_title = stripSpace($title);
			if (empty($section)) $section = $Section;
			if (empty($posted)) $posted = $Posted;
			if (empty($authorid)) $authorid = @$AuthorID;
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
			if (!$title && $this->pref('automatically_append_title'))
				$pl_components[] = array('type' => 'title', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => '');

			$uri = rtrim(doStrip(@$pretext['subpath']), '/');
			foreach ($pl_components as $pl_c) {
				$uri .= '/';

				$type = $pl_c['type'];
				switch ($type) {
					case 'category':
						if (!@$pl_c['category']) $pl_c['category'] = 1;
						$primary = 'category'. $pl_c['category'];
						$secondary = 'category'. (3-(int)$pl_c['category']);
						$check_context = ($this->pref('join_pretext_to_pagelinks') && $this->pref('check_pretext_category_context'));
						if (!$check_context || $$primary == $pretext['c'])
							$uri_c = $$primary;
						else if (!$check_context || $$secondary == $pretext['c'])
							$uri_c = $$secondary;
						else if ($this->pref('debug') && in_array($production_status, array('debug', 'testing')))
							$uri_c = '--INVALID_CATEGORY--';
						else {
							unset($uri);
							break 2;
						}
					break;
					case 'section':
						$check_context = ($this->pref('join_pretext_to_pagelinks') && $this->pref('check_pretext_section_context'));
						if (!$check_context || $section == $pretext['s'])
							$uri_c = $section;
						else {
							unset($uri);
							break 2;
						}
					break;
					case 'title': $uri_c = $url_title; break;
					case 'id': $uri_c = $thisid; break;
					case 'author': $uri_c = safe_field('RealName', 'txp_users', "name like '{$authorid}'"); break;
					case 'login': $uri_c = $authorid; break;
					case 'date': $uri_c = explode('/', date('Y/m/d', $posted)); break;
					case 'year': $uri_c = date('Y', $posted); break;
					case 'month': $uri_c = date('m', $posted); break;
					case 'day': $uri_c = date('d', $posted); break;
					case 'custom':
						if ($uri_c = @$article_array[$prefs["custom_{$pl_c['custom']}_set"]]);
						else if ($uri_c = @$article_array["custom_{$pl_c['custom']}"]);
						else if ($this->pref('debug') && in_array($production_status, array('debug', 'testing')))
							$uri_c = '--UNSET_CUSTOM_FIELD--';
						else {
							unset($uri);
							break 2;
						}
					break;
					case 'text': $uri_c = $pl_c['text']; break;
					case 'regex':
						// Check to see if regex is valid without outputting error messages.
						ob_start();
						preg_match($pl_c['regex'], $pl_c['regex'], $regex_matches);
						$is_valid_regex = !(ob_get_clean());
						if ($is_valid_regex) {
							$key = "permlink_regex_{$pl_c['name']}";
							$uri_c = (array_key_exists($key, $pretext)) ? $pretext[$key] : $regex_matches[0];
						} else if ($this->pref('debug'))
							$uri_c = '--INVALID_REGEX--';
					break;
				}

				if (empty($uri_c))
					if ($this->pref('debug') && in_array($production_status, array('debug', 'testing')))
						$uri_c = '--PERMLINK_FORMAT_ERROR--';
					else {
						unset($uri);
						break;
					}

				if (@$pl_c['prefix'])
					$uri .= $this->encode_url($pl_c['prefix']);

				if (is_array($uri_c)) {
					foreach ($uri_c as $uri_c2)
						$uri .= $this->encode_url($uri_c2) . '/';
					$uri = rtrim($uri, '/');
				} else
					$uri .= $this->encode_url($uri_c);

				if (@$pl_c['suffix'])
					$uri .= $this->encode_url($pl_c['suffix']);

				unset($uri_c);
			}

			if (isset($uri))
				$uri .= '/';
		}

		if ($uri_empty = empty($uri)) {
			// It is possible the uri is still empty if there is no match or if we're using
			// strict matching if so try the default permlink mode.
			$uri = $this->toggle_permlink_mode('permlinkurl', $article_array);
		}

		if ($this->pref('omit_trailing_slash'))
			$uri = rtrim($uri, '/');

		if (!$uri_empty && in_array(txpath.'/publish/rss.php', get_included_files()) || in_array(txpath.'/publish/atom.php', get_included_files()) || txpinterface == 'admin') {
			$host = rtrim(str_replace(rtrim(doStrip(@$pretext['subpath']), '/'), '', hu), '/');
			$uri = $host . $uri;
		}

		return ($this->pref('force_lowercase_urls')) ? strtolower($uri) : $uri;
	}

	function _pagelinkurl ($parts) {
		extract(lAtts(array(
			'path'		=> 'index.php',
			'query'		=> '',
			'fragment'	=> '',
		), parse_url(html_entity_decode(str_replace('&#38;', '&', $parts[2])))));

		// Tidy up links back to the site homepage
		if ($path == 'index.php' && empty($query))
			return 'href="' .hu. '"';

		// Fix matches like href="?s=foo"
		else if ($path && empty($query) && $parts[1] == '?') {
			$query = $path;
			$path = 'index.php';
		}

		// Check to see if there is query to work with.
		else if (empty($query) || $path != 'index.php' || strpos($query, '/') === true)
			return $parts[0];

		// '&amp;' will break parse_str() if they are found in a query string
		$query = str_replace('&amp;', '&', $query);

		if ($fragment)
			$fragment = '#'.$fragment;

		global $pretext;
		parse_str($query, $query_part);
		if (!array_key_exists('pg', $query_part))
			$query_part['pg'] = 0;
		if (!array_key_exists('id', $query_part))
			$query_part['id'] = 0;
		if (!array_key_exists('rss', $query_part))
			$query_part['rss'] = 0;
		if (!array_key_exists('atom', $query_part))
			$query_part['atom'] = 0;
		if ($this->pref('join_pretext_to_pagelinks'))
			extract(array_merge($pretext, $query_part));
		else
			extract($query_part);

		// We have a id, pass to permlinkurl()
		if ($id) {
			if (@$s == 'file_download') {
				$title = (version_compare($dbversion, '4.2', '>=')) ? NULL : safe_field('filename', 'txp_file', "id = '{$id}'");
				$url = $this->toggle_permlink_mode('filedownloadurl', array($id, $title), true);
			} else {
				$rs = safe_row('*, ID as thisid, unix_timestamp(Posted) as posted', 'textpattern', "ID = '{$id}'");
				$url = $this->_permlinkurl($rs, PERMLINKURL) . $fragment;
			}
			return 'href="'. $url .'"';
		}

		if (@$s == 'default')
			unset($s);

		// Some TxP tags, e.g. <txp:feed_link /> use 'section' or 'category' inconsistent
		// with most other tags. Process these now so we only have to check $s and $c.
		if (@$section)  $s = $section;
		if (@$category) $c = $category;

		// Debugging for buffers
		$this->buffer_debug[] = 'url: '.str_replace('&amp;', '&', $parts[1].$parts[2]);
		$this->buffer_debug[] = 'path: '.$path;
		$this->buffer_debug[] = 'query: '.$query;
		if ($fragment) $this->buffer_debug[] = 'fragment: '.$fragment;

		if (@$id) $this->buffer_debug[] = 'id: '.$id;
		if (@$s) $this->buffer_debug[] = 's: '.$s;
		if (@$c) $this->buffer_debug[] = 'c: '.$c;
		if (@$rss) $this->buffer_debug[] = 'rss: '.$rss;
		if (@$atom) $this->buffer_debug[] = 'atom: '.$atom;
		if (@$pg) $this->buffer_debug[] = 'pg: '.$pg;
		if (@$q) $this->buffer_debug[] = 'q: '.$q;

		if (@$pretext['permlink_override']) {
			$override_ids = explode(',', $pretext['permlink_override']);
			foreach ($override_ids as $override_id) {
				$pl = $this->get_permlink($override_id);
				if (count($pl) > 0) $permlinks[] = $pl;
			}
		}

		if (empty($permlinks)) {
			$permlinks = $this->get_all_permlinks(1);

			$permlinks['gbp_permanent_links_default'] = array(
				'components' => array(
					array('type' => 'text', 'text' => strtolower(urlencode(gTxt('category')))),
					array('type' => 'category'),
				),
				'settings' => array(
					'pl_name' => 'gbp_permanent_links_default', 'pl_precedence' => '', 'pl_preview' => '',
					'con_section' => '', 'con_category' => '', 'des_section' => '', 'des_category' => '',
					'des_permlink' => '', 'des_feed' => '', 'des_location' => '',
			));
		}

		$current_segments = explode('/', ltrim($pretext['request_uri'], '/'));

		$highest_match_count = null;
		foreach ($permlinks as $key => $pl) {
			$this->buffer_debug[] = 'Testing permlink: '. $pl['settings']['pl_name'] .' - '. $key;
			$this->buffer_debug[] = 'Preview: '. $pl['settings']['pl_preview'];
			$out = array(); $keys = array(); $match_count = 0;
			foreach ($pl['components'] as $i => $pl_c) {
				switch ($pl_c['type']) {
					case 'text':
						$out[] = $pl_c['text'];
					break;
					case 'regex':
						$out[] = $pretext['permlink_regex_'.$pl_c['name']];
					break;
					case 'section':
						if (@$s) $out[] = $s;
						else break 2;
					break;
					case 'category':
						if (@$c) $out[] = $c;
						else break 2;
					break;
					case 'feed':
						if (@$rss) $keys[] = $out[] = 'rss';
						else if (@$atom) $keys[] = $out[] = 'atom';
						else break 2;
					break;
					case 'search':
						if (@$q) $out[] = $q;
						else break 2;
					break;
					case 'page':
						if (@$pg) {
							$out[] = $pg;
							$keys[] = 'pg';
						}
						else break 2;
					break;
					default: break 2;
				}
				if (in_array($pl_c['type'], array('text', 'regex'))) {
					if ($current_segments[$i] == end($out) && strlen(end($out)) > 0)
						$match_count += $this->pref('text_and_regex_segment_scores');
				}
				elseif (!in_array($pl_c['type'], array('title', 'id')))
					$match_count++;
				else break;
			}

			$this->buffer_debug[] = 'Match count: '. $match_count;

			// Todo: Store according to the precedence value
			if (count($out) > 0 && ($match_count > $highest_match_count || !isset($highest_match_count)) &&
			!($key == 'gbp_permanent_links_default' && !$match_count)) {
				extract($pl['settings']);
				if ((empty($s) && empty($c)) ||
				(empty($con_section) || @$s == $con_section) ||
				(empty($con_category) || @$c == $con_category)) {
					$this->buffer_debug[] = 'New highest match! '. implode('/', $out);
					$highest_match_count = $match_count;
					$match = $out;
					$match_keys = $keys;
				}
			}
		}

		if (empty($match) && (!(@$pg && $this->pref('clean_page_archive_links')) || (@$pg && @$q))) {
			global $prefs, $pretext, $permlink_mode;
			$this->buffer_debug[] = 'No match';
			$this->buffer_debug[] = '----';
			$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];
			$url = pagelinkurl($query_part);
			$pretext['permlink_mode'] = $permlink_mode = 'messy';
			return 'href="'. $url .'"';
		}

		$this->buffer_debug[] = 'match: '.      serialize($match);
		$this->buffer_debug[] = 'match_keys: '. serialize($match_keys);

		$url = '/'.join('/', $match);
		$url = rtrim(hu, '/').rtrim($url, '/').'/';

		if ($rss && !in_array('rss', $match_keys))
			$url .= 'rss';
		else if ($atom && !in_array('atom', $match_keys))
			$url .= 'atom';
		else if ($pg && !in_array('pg', $match_keys)) {
			if ($this->pref('clean_page_archive_links'))
				$url .= $pg;
			else {
				$url .= '?pg='. $pg;
				$omit_trailing_slash = true;
			}
		}

		$url = rtrim($url, '/') . '/';

		if (@$omit_trailing_slash || $this->pref('omit_trailing_slash'))
			$url = rtrim($url, '/');

		$this->buffer_debug[] = $url;
		$this->buffer_debug[] = '----';

		if ($path == 'index.php' && $url != hu)
			return 'href="'. $url . $fragment .'"';

		/*
		1 = index, textpattern/css, NULL (=index)
		2 = id, s, section, c, category, rss, atom, pg, q, (n, p, month, author)
		*/

		return ($this->pref('force_lowercase_urls')) ? strtolower($parts[0]) : $parts[0];
	}

	function set_permlink_mode ($reset_function = false) {
		global $prefs, $pretext, $permlink_mode;
		$prefs['custom_url_func'] = array(&$this, '_permlinkurl');

		if (!$reset_function)
			$pretext['permlink_mode'] = $permlink_mode = 'messy';
		else
			$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];
	}

	function reset_permlink_mode () {
		global $prefs, $pretext, $permlink_mode;
		unset($prefs['custom_url_func']);
		$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];
	}

	function toggle_custom_url_func ($func, $atts = NULL, $toogle_permlink_mode = false, $expand_arguments = false) {
		global $prefs, $pretext;

		if ($toogle_permlink_mode) {
			global $permlink_mode;
			$_permlink_mode = $permlink_mode;
		}

		$_call_user_func = @$prefs['custom_url_func'];

		unset($prefs['custom_url_func']);
		if ($toogle_permlink_mode)
			$pretext['permlink_mode'] = $permlink_mode = $prefs['permlink_mode'];

		if (is_callable($func)) {
			if (is_array($atts) and $expand_arguments)
				$rs = call_user_func_array($func, $atts);
			else
				$rs = call_user_func($func, $atts);
		}

		$prefs['custom_url_func'] = $_call_user_func;

		if ($toogle_permlink_mode)
			$pretext['permlink_mode'] = $permlink_mode = $_permlink_mode;

		return $rs;
	}

	function toggle_permlink_mode ($func, $atts = NULL, $expand_arguments = false) {
		return $this->toggle_custom_url_func($func, $atts, true, $expand_arguments);
	}

	function encode_url ($text) {
		return urlencode(trim(dumbDown(urldecode($text))));
	}

	function debug () {
		if ($this->pref('debug')) {
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
	function preload () {
		register_callback(array(&$this, 'post_save_permlink'), $this->parent->event, gbp_save, 1);
		register_callback(array(&$this, 'post_save_permlink'), $this->parent->event, gbp_post, 1);
	}

	function main () {
		global $prefs;
		extract(gpsa(array('step', gbp_id)));

		// With have an ID, either the permlink has just been saved or the user wants to edit it
		if ($id) {
			// Newly saved or beening edited, either way we're editing a permlink
			$step = gbp_save;

			// Use the ID to grab the permlink data (components & settings)
			$permlink = $this->parent->get_permlink($id);
			$components = $this->phpArrayToJsArray('components', $permlink['components']);
			$settings = $permlink['settings'];
		} else {
			// Creating a new ID and permlink.
			$step = gbp_post;
			$id = uniqid('');

			// Set the default set of components depending on whether there is parent permlink
			$components = $this->phpArrayToJsArray('components', array(
				array('type' => 'section', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
				array('type' => 'category', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => '', 'category' => '1'),
				array('type' => 'title', 'prefix' => '', 'suffix' => '', 'regex' => '', 'text' => ''),
			));

			$settings = array(
				'pl_name' => 'Untitled', 'pl_precedence' => '0',
				'con_section' => '', 'con_category' => '',
				'des_section' => '', 'des_category' => '', 'des_page' => '',
				'des_permlink' => '', 'des_feed' => '', 'des_location' => '',
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
	var c_vals = new Array('type', 'custom', 'category', 'name', 'prefix', 'suffix', 'regex', 'text');

	window.onload = function() {
		component_refresh_all();
		component_switch(component(_current));
	}

	function component_add () {
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

	function component_refresh (element) {
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
		while (element.hasChildNodes()) { element.removeChild(element.firstChild); }

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

	function component_refresh_all () {
		// Remove all child nodes
		while (permlink_div().hasChildNodes()) { permlink_div().removeChild(permlink_div().firstChild); }

		for (var i = 0; i < components.length; i++) {
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

	function component_remove () {
		if (components.length > 1) {
			components.splice(_current, 1);

			if (_current >= components.length)
				_current = components.length - 1;

			component_refresh_all();
		}
	}

	function component_switch (element) {
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

	function component_update (element) {
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
				display_fixes();
			break;
			case 'category' :
				form('{$components_form}').category.parentNode.style['display'] = '';
				display_fixes();
			break;
			default :
				display_fixes();
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

	function display_fixes () {
		if ('{$show_prefix}')
			form('{$components_form}').prefix.parentNode.style['display'] = '';
		if ('{$show_suffix}')
			form('{$components_form}').suffix.parentNode.style['display'] = '';
	}

	function component_left () {
		if (components.length > 1 && _current > 0) {
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

	function component_right () {
		if (_current < components.length - 1) {
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

	function save (form) {
		var c = ''; var is_permlink = false; var has_page_or_search = false;
		for (var i = 0; i < components.length; i++) {
			if (components[i]['type'] == 'title' || components[i]['type'] == 'id')
				is_permlink = true;
			if (components[i]['type'] == 'page' || components[i]['type'] == 'feed' || components[i]['type'] == 'search')
				has_page_feed_search = true;
			c = c + jsArrayToPhpArray(components[i]) + '{$separator}';
		}

		if (is_permlink && has_page_or_search)
			alert("Your permanent link can't contain either a 'page', 'feed' or a 'search' component with 'title' or 'id' components.");

		else if (is_permlink && (form.pl_name.value == '' || form.pl_name.value == 'Untitled')) {
			document.getElementById('settings').style['display'] = '';
			form.pl_name.style['border'] = '3px solid rgb(221, 0, 0)';
			form.pl_precedence.style['border'] = '';
			alert('Please enter a name for this permanent link rule.');
		} else if (form.pl_precedence.value == '') {
			document.getElementById('settings').style['display'] = '';
			form.pl_precedence.style['border'] = '3px solid rgb(221, 0, 0)';
			form.pl_name.style['border'] = '';
			alert('Please enter a precedence value for this permanent link rule.');
		} else {
			form.components.value = c;
			if (permlink_div().textContent)
				form.pl_preview.value = permlink_div().textContent;
			else if (permlink_div().innerText)
				form.pl_preview.value = permlink_div().innerText;
			return true;
		}

		return false;
	}

	function jsArrayToPhpArray (array) {
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

	function permlink_div () {
		// Return the permlink rule element
		return document.getElementById('{$components_div}');
	}

	function form (name) {
		if (!name)
			name = '{$components_form}'
		// Return the form element with name
		return document.forms.namedItem(name);
	}

	function component (index) {
		// Return component with index
		return permlink_div().childNodes[index];
	}

	// ]]>
	</script>
HTML;

		// --- Rule --- //

		$out[] = hed('Permanent link rule', 2);
		$out[] = '<div id="'.$components_div.'" style="background-color: rgb(230, 230, 230); width: auto; height: 1.5em; margin: 10px 0; padding: 5px;"></div>';
		$out[] = graf
			(
			$this->fInput('button', 'component_add', 'Add component', array('click' => 'component_add();')).n.
			$this->fInput('button', 'component_remove', 'Remove component', array('click' => 'component_remove();')).n.
			$this->fInput('button', 'component_left', 'Move left', array('click' => 'component_left();')).n.
			$this->fInput('button', 'component_right', 'Move right', array('click' => 'component_right();'))
			);

		// --- Component form --- //

		$out[] = '<form action="index.php" name="'.$components_form.'" onsubmit="return false;">';

		// --- Component type --- //

		$component_types = array (
			'section' => 'Section', 'category' => 'Category',
			'title' => 'Title', 'id' => 'ID',
			'date' => 'Date (yyyy/mm/dd)', 'year' => 'Year',
			'month' => 'Month', 'day' => 'Day',
			'author' => 'Author (Real name)', 'login' => 'Author (Login)',
			'custom' => 'Custom Field', 'page' => 'Page Number',
			'feed' => 'Feed', 'search' => 'Search request',
			'text' => 'Plain Text', 'regex' => 'Regular Expression'
		);
		$out[] = graf($this->fSelect('type', $component_types, '', 1, 'Component type', ' onchange="component_update();"'));

		// --- Component data --- //

		// Grab the custom field titles
		$custom_fields = array();
		for ($i = 1; $i <= 10; $i++) {
			if ($v = $prefs["custom_{$i}_set"])
				$custom_fields[$i] = $v;
		}

		$out[] = graf (
			$this->fSelect('custom', $custom_fields, '', 0, 'Custom', ' onchange="component_update(this);"').n.
			$this->fSelect('category', array('1' => 'Category 1', '2' => 'Category 2'), '', 0, 'Primary Category', ' onchange="component_update(this);"').n.
			$this->fInput('text', 'name', '', array('keyup' => 'component_update(this);'), 'Name').n.
			$this->fInput('text', 'prefix', '', array('keyup' => 'component_update(this);'), 'Prefix').n.
			$this->fInput('text', 'regex', '', array('keyup' => 'component_update(this);'), 'Regular Expression').n.
			$this->fInput('text', 'suffix', '', array('keyup' => 'component_update(this);'), 'Suffix').n.
			$this->fInput('text', 'text', '', array('keyup' => 'component_update(this);'), 'Text')
		);
		$hr = '<hr style="border: 0; height: 1px; background-color: rgb(200, 200, 200); color: rgb(200, 200, 200); margin-bottom: 10px;" />';
		$out[] = $hr;
		$out[] = '</form>';

		// --- Settings form --- //

		$out[] = '<form action="index.php" method="post" name="'.$settings_form.'" onsubmit="return save(this);">';

		// --- Settings --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'settings\'); return false;">Settings</a>', 2);
		$out[] = '<div id="settings">';
		$out[] = graf($this->fInput('text', 'pl_name', $pl_name, NULL, 'Name'));
		$out[] = graf($this->fInput('text', 'pl_precedence', $pl_precedence, NULL, 'Precedence'));
		$out[] = '</div>';
		$out[] = $hr;

		// --- Conditions --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'conditions\'); return false;">Conditions</a>', 2);
		$out[] = '<div id="conditions" style="display:none">';
		$out[] = graf(strong('Only use this permanent link if the following conditions apply...'));

		// Generate a sections array (name=>title)
		$sections = array();
		$rs = safe_rows('name, title', 'txp_section', "name != 'default' order by name");
		foreach ($rs as $sec) {
			$sections[$sec['name']] = $sec['title'];
		}

		// Generate a categories array (name=>title)
		$categories = array();
		$rs = safe_rows('name, title', 'txp_category', "type = 'article' and name != 'root' order by name");
		foreach ($rs as $sec) {
			$categories[$sec['name']] = $sec['title'];
		}

		$out[] = graf (
			$this->fSelect('con_section', $sections, $con_section, 1, 'Within section').n.
			$this->fSelect('con_category', $categories, $con_category, 1, 'Within category')
		);
		$out[] = '</div>';
		$out[] = $hr;

		// --- Destination --- //

		$out[] = hed('<a href="#" onclick="toggleDisplay(\'destination\'); return false;">Destination</a>', 2);
		$out[] = '<div id="destination" style="display:none">';
		$out[] = graf(strong('Forward this permanent link to...'));
		$out[] = graf (
			$this->fSelect('des_section', $sections, $des_section, 1, 'Section').n.
			$this->fSelect('des_category', $categories, $des_category, 1, 'Category')
		);
		$out[] = graf($this->fSelect('des_page', safe_column('name', 'txp_page', "1"), @$des_page, 1, 'Page'));
		$out[] = graf($this->fBoxes('des_feed', array('rss', 'atom', ''), $des_feed, NULL, array('RSS feed', 'Atom feed', 'Neither')));
		$out[] = graf(strong('Redirect this permanent link to...'));
		// Generate a permlinks array
		$permlinks = $this->parent->get_all_permlinks(1);
		foreach ($permlinks as $key => $pl) {
			$permlinks[$key] = $pl['settings']['pl_name'];
		}
		unset($permlinks[$id]);
		$out[] = graf($this->fSelect('des_permlink', $permlinks, @$des_permlink, 1, 'Permanent link'));
		$out[] = graf($this->fInput('text', 'des_location', $des_location, NULL, 'HTTP location'));
		$out[] = '</div>';
		$out[] = $hr;

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

	function fLabel ($label, $contents = '', $label_right = false) {
		// <label> the contents with the name $lable
		$contents = ($label_right)
		? $contents.$label
		: $label.($contents ? ': '.$contents : '');
		return tag($contents, 'label');
	}

	function fBoxes ($name = '', $value = '', $checked_value = '', $on = array(), $label = '') {
		$out = array();
		if (is_array($value)) {
			$i = 0;
			foreach ($value as $val) {
				$o = '<input type="radio" name="'.$name.'" value="'.$val.'"';
				$o .= ($checked_value == $val) ? ' checked="checked"' : '';
				if (is_array($on)) foreach($on as $k => $v)
					$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
				$o .= ' />';
				$out[] = $this->fLabel($label[$i++], $o, true);
			}
		} else {
			$o = '<input type="checkbox" name="'.$name.'" value="'.$value.'"';
			$o .= ($checked_value == $value) ? ' checked="checked"' : '';
			if (is_array($on)) foreach($on as $k => $v)
				$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
			$o .= ' />';
			$out[] = $this->fLabel($label, $o, true);
		}

		return join('', $out);
	}

	function fInput ($type, $name = '', $value = '', $on = array(), $label = '') {
		if ($type == 'radio' || $type == 'checkbox')
			return $this->fBoxes($name, $value, $on, $label);

		$o = '<input type="'.$type.'" name="'.$name.'" value="'.$value.'"';
		if (is_array($on)) foreach($on as $k => $v)
			$o .= ($on) ? ' on'.$k.'="'.$v.'"' : '';
		$o .= ' />';
		return ($label) ? $this->fLabel($label, $o) : $o;
	}

	function fSelect ($name = '', $array = '', $value = '', $blank_first = '', $label = '', $on_submit = '') {
		$o = selectInput($name, $array, $value, $blank_first, $on_submit);
		return ($label ? $this->fLabel($label, $o) : $o);
	}

	function post_save_permlink () {
		// The function posts or saves a permanent link to txp_prefs

		extract(gpsa(array('step', gbp_id)));

		// Grab the user defined settings from the form POST data
		$settings = gpsa(array(
			'pl_name', 'pl_precedence', 'pl_preview',
			'con_section', 'con_category',
			'des_section', 'des_category', 'des_page',
			'des_permlink', 'des_feed', 'des_location',
		));

		// Remove spaces from the permanent link preview
		$settings['pl_preview'] = preg_replace('%\s+/\s*%', '/', $settings['pl_preview']);

		// Explode the separated string of serialize components - this was made by JavaScript.
		$serialize_components = explode(gbp_separator, rtrim(gps('components'), gbp_separator));

		// Unserialize the components
		$components = array();
		foreach ($serialize_components as $c)
			$components[] = unserialize(stripslashes($c));

		// Complete the permanent link array - this is exactly what needs to be stored in the db
		$permlink = array('settings' => $settings, 'components' => $components);

		// Save it
		$this->set_preference($id, $permlink, 'gbp_serialized');

		$this->parent->message = messenger('', $settings['pl_name'], 'saved');
	}

	function phpArrayToJsArray ($name, $array) {
		// From PHP.net
		if (is_array($array)) {
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
	function preload () {
		register_callback(array(&$this, $this->parent->event.'_multi_edit'), $this->parent->event, $this->parent->event.'_multi_edit', 1);
		register_callback(array(&$this, $this->parent->event.'_change_pageby'), $this->parent->event, $this->parent->event.'_change_pageby', 1);
	}

	function main () {
		extract(gpsa(array('page', 'sort', 'dir', 'crit', 'search_method')));

		$event = $this->parent->event;

		$permlinks = $this->parent->get_all_permlinks();
		$total = count($permlinks);

		if ($total < 1) {
			echo graf('You haven\'t created any custom permanent links rules yet.', ' style="text-align: center;"').
				 graf('<a href="'.$this->url(array(gbp_tab => 'build'), true).'">Click here</a> to add one.', ' style="text-align: center;"');
			return;
		}

		$limit = max($this->pref('list_pageby'), 15);

		list($page, $offset, $numPages) = $this->pager($total, $limit, $page);

		if (empty($sort))
			$sort = 'pl_precedence';

		if (empty($dir))
			$dir = 'desc';

		$dir = ($dir == 'desc') ? 'desc' : 'asc';

		// Sort the permlinks via the selected column and then their names.
		foreach ($permlinks as $id => $permlink) {
			$sort_keys[$id] = $permlink['settings'][$sort];
			$name[$id] = $permlink['settings']['pl_name'];
		}
		array_multisort($sort_keys, (($dir == 'desc') ? SORT_DESC : SORT_ASC), $name, SORT_ASC, $permlinks);

		$switch_dir = ($dir == 'desc') ? 'asc' : 'desc';

		$permlinks = array_slice($permlinks, $offset, $limit);

		if (count($permlinks)) {
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

			foreach ($permlinks as $id => $permlink) {
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
					td($pl_precedence.'&nbsp;', 50).

					td(
						fInput('checkbox', 'selected[]', $id)
					)
				);
			}

			echo n.n.tr(
				tda(
					select_buttons().
					$this->permlinks_multiedit_form($page, $sort, $dir, $crit, $search_method)
				,' colspan="4" style="text-align: right; border: none;"')
			).

			n.endTable().
			n.'</form>'.

			n.$this->nav_form($event, $page, $numPages, $sort, $dir, $crit, $search_method).

			n.pageby_form($event, $this->pref('list_pageby'));
		}
	}

	function pager ($total, $limit, $page) {
		if (function_exists('pager'))
			return pager($total, $limit, $page);

		// This is taken from txplib_misc.php r1588 it is required for 4.0.3 compatibitly
		$num_pages = ceil($total / $limit);
		$page = $page ? (int) $page : 1;
		$page = min(max($page, 1), $num_pages);
		$offset = max(($page - 1) * $limit, 0);
		return array($page, $offset, $num_pages);
	}

	function nav_form ($event, $page, $numPages, $sort, $dir, $crit, $method) {
		if (function_exists('nav_form'))
			return nav_form($event, $page, $numPages, $sort, $dir, $crit, $method);

		// This is basically stolen from the 4.0.3 version of includes/txp_list.php
		// - list_nav_form() for 4.0.3 compatibitly
		$nav[] = ($page > 1)
			? PrevNextLink($event, $page-1, gTxt('prev'), 'prev', $sort, $dir) : '';
		$nav[] = sp.small($page. '/'.$numPages).sp;
		$nav[] = ($page != $numPages)
			? PrevNextLink($event, $page+1, gTxt('next'), 'next', $sort, $dir) : '';
		return ($nav)
			? graf(join('', $nav), ' align="center"') : '';
	}

	function permlinks_multiedit_form ($page, $sort, $dir, $crit, $search_method) {
		$methods = array(
			'delete' => gTxt('delete'),
		);

		return event_multiedit_form($this->parent->event, $methods, $page, $sort, $dir, $crit, $search_method);
	}

	function permlinks_change_pageby () {
		$this->set_preference('list_pageby', gps('qty'));
	}

	function permlinks_multi_edit () {
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

global $gbp_pl;
$gbp_pl = new PermanentLinks('Permanent Links', 'permlinks', 'admin');
if (@txpinterface == 'public') {
	register_callback(array(&$gbp_pl, '_feed_entry'), 'rss_entry');
	register_callback(array(&$gbp_pl, '_feed_entry'), 'atom_entry');
	register_callback(array(&$gbp_pl, '_textpattern'), 'textpattern');
	register_callback(array(&$gbp_pl, '_textpattern_end'), 'textpattern_end');

	function gbp_if_regex ($atts, $thing) {
		global $pretext;
		extract(lAtts(array(
			'name' => '',
			'val'  => '',
		),$atts));
		$match = (@$pretext["permlink_regex_{$name}"] == $val);
		return parse(EvalElse($thing, $match));
	}

	function gbp_if_text ($atts, $thing) {
		global $pretext;
		extract(lAtts(array(
			'name' => '',
			'val'  => '',
		),$atts));

		$match = false;
		if (!empty($name)) {
			if (empty($val))
				$match = (isset($pretext["permlink_text_{$name}"]));
			else
				$match = (@$pretext["permlink_text_{$name}"] == $val);
		}
		return parse(EvalElse($thing, $match));
	}

	function gbp_use_pagelink ($atts, $thing = '') {
		global $gbp_pl;
		extract(lAtts(array(
			'rule' => '',
		),$atts));
		return $gbp_pl->_textpattern_end_callback(parse($thing), $rule);
	}

	function gbp_disable_permlinks ($atts, $thing = '') {
		global $gbp_pl;
		return $gbp_pl->toggle_permlink_mode('parse', $thing);
	}
}

# --- END PLUGIN CODE ---

?>
