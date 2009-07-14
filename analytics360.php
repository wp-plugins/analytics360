<?php
/*
Plugin Name: Analytics360
Plugin URI: http://www.mailchimp.com/wordpress_analytics_plugin/?pid=wordpress&source=website
Description: Allows you to pull Google Analytics and MailChimp data directly into your dashboard, so you can access robust analytics tools without leaving WordPress. Compliments of <a href="http://mailchimp.com/">MailChimp</a>.
Version: 1.0 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

load_plugin_textdomain('analytics360');


if (is_file(trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__))) {
	define('A360_FILE', trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__));
}
else if (is_file(trailingslashit(ABSPATH.PLUGINDIR).dirname(__FILE__).'/'.basename(__FILE__))) {
	define('A360_FILE', trailingslashit(ABSPATH.PLUGINDIR).dirname(__FILE__).'/'.basename(__FILE__));
}

define('A360_VERSION', '1.0');
define('A360_PHP_COMPATIBLE', version_compare(phpversion(), '5', '>='));
if (!A360_PHP_COMPATIBLE) {
	trigger_error('Analytics 360&deg; requires PHP 5 or greater.', E_USER_ERROR);
}


function a360_admin_init() {
	global $a360_page, $pagenow;
	$a360_page = null;
	if ($_GET['page'] == 'analytics360.php') {
		$a360_page = (
			$pagenow == 'options-general.php' ? 'settings' : (
				$pagenow == 'index.php' ? 'dashboard' : '' )
		);
	}
	
	if ($a360_page == 'dashboard') {
		header('X-UA-Compatible: IE=7');	// ask ie8 to behave like ie7 for the sake of vml
		include(trailingslashit(ABSPATH).'/wp-includes/rss.php');
	}

	if ($a360_page == 'dashboard') {
		wp_enqueue_script('jquery');
		wp_enqueue_script('a360_admin_js', trailingslashit(get_bloginfo('url')).'?a360_action=admin_js&a360_page='.$a360_page, array('jquery'));
		wp_enqueue_script('google_jsapi', 'http://www.google.com/jsapi');
	}
}
add_action('admin_init', 'a360_admin_init');


function a360_admin_head() {
	global $a360_page, $a360_api_key, $a360_ga_token;
	if (!empty($a360_page)) {
		echo '
			<style> v\:* { behavior: url(#default#VML); } </style>
			<xml:namespace ns="urn:schemas-microsoft-com:vml" prefix="v" >
		';
		echo '
			<link rel="stylesheet" type="text/css" href="'.trailingslashit(get_bloginfo('url')).'?a360_action=admin_css" media="screen" charset="utf-8" />
			<!--[if IE]>
				<link rel="stylesheet" href="'.trailingslashit(get_bloginfo('url')).'?a360_action=admin_css_ie" type="text/css" media="screen" charset="utf-8" />
			<![endif]-->
		';
		if ($a360_page == 'dashboard' && !empty($a360_ga_token)) {
			echo '
				<script type="text/javascript">
					if (typeof google !== \'undefined\') {
						google.load("gdata", "1.x");
						google.load("visualization", "1.x", {"packages": ["areachart", "table", "piechart", "imagesparkline", "geomap", "columnchart"]});
					}
				</script>
			';
		}
	}

}
add_action('admin_head', 'a360_admin_head');


$a360_version = '1.0';
$a360_has_key = false;
$a360_api_key = get_option('a360_api_key');

$a360_ga_token = get_option('a360_ga_token');
$a360_ga_profile_id = get_option('a360_ga_profile_id');
if ($a360_api_key && !empty($a360_api_key)) {
	$a360_has_key = true;
}

if (!$a360_has_key) {
	add_action('after_plugin_row', 'a360_warn_no_key_plugin_page');
}
function a360_warn_no_key_plugin_page($plugin_file) {
	if (strpos($plugin_file, 'analytics360.php')) {
		print('
			<tr>
				<td colspan="5" class="plugin-update">
				<strong>Note</strong>: Analytics360 requires account authentication to work. <a href="options-general.php?page=analytics360.php">Go here to set everything up</a>, then start analyticalizing!.
				</td>
			</tr>
		');
	}
}

// returns false only when we're not using our own MCAPI, 
// and the existing version is < 2.1.
function a360_MCAPI_is_compatible() {
	if (class_exists('MCAPI')) {
		$api = new MCAPI(null, null);
		return version_compare($api->version, '1.2', '>=');
	}
	return true;
}

function a360_request_handler() {
	if (!empty($_GET['a360_action'])) {
		switch ($_GET['a360_action']) {

			case 'admin_js':
				a360_admin_js();
			break;
			case 'admin_css_ie':
				header('Content-type: text/css');
				require('css/a360-ie.css');
				die();
			break;
			case 'admin_css':
				header('Content-type: text/css');
				require('css/datePicker.css');
				require('css/a360.css');
				die();
			break;

			case 'capture_ga_token':
				if (!current_user_can('manage_options')) {
					wp_die(__('You are not allowed to do that.', 'analytics360'));
				}
				$args = array();
				parse_str($_SERVER['QUERY_STRING'], $args);
				$token = NULL;
				if (isset($args['token'])) {
					$ch = curl_init('https://www.google.com/accounts/AuthSubSessionToken');
					curl_setopt($ch, CURLOPT_HEADER, true);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array(
						'Authorization: AuthSub token="'.$args['token'].'"'
					));
					$result = curl_exec($ch);
					$matches = array();
					$found = preg_match('/Token=(.*)/', $result, $matches);
					if ($found) {
						$token = $matches[1];
						$result = update_option('a360_ga_token', $token);
					}
				}
				if (!$token) {
					$q = http_build_query(array(
						'a360_error' => 'Authentication with Google did not succeed. Please try again.'
					));
				}
				else {
					delete_option('a360_ga_profile_id');
					$q = http_build_query(array(
						'updated' => true
					));
				}
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&'.$q);
			break;
			case 'get_wp_posts':
				add_filter('posts_where', create_function(
					'$where', 
					'return $where." AND post_date >= \''.$_GET['start_date'].'\' AND post_date < \''.$_GET['end_date'].'\'";'
				));
				$results = query_posts('post_status=publish&posts_per_page=999');
				
				header('Content-type: text/javascript');
				die(cf_json_encode(array(
					'success' => true,
					'data' => $results,
					'cached' => false
				)));
			break;
			case 'get_mc_data':
				global $a360_api_key;
				if (!class_exists('MCAPI')) {
					include_once(ABSPATH.PLUGINDIR.'/analytics360/php/MCAPI.class.php');
				}
				$api = new MCAPI($a360_api_key);
				switch ($_GET['data_type']) {
					case 'campaigns':
						$results = $api->campaigns(array(
							'sendtime_start' => $_GET['start_date'],
							'end_start' => $_GET['end_date']
						));
						if ($results) {
							die(cf_json_encode(array(
								'success' => true,
								'data' => $results,
								'cached' => false
							)));
						}
						else {
							die(cf_json_encode(array(
								'success' => false,
								'error' => $api->errorMessage
							)));
						}
					break;
					case 'list_growth':
						$results = $api->listGrowthHistory($_GET['list_id']);
						if ($results) {
							die(cf_json_encode(array(
								'success' => true,
								'data' => $results,
								'cached' => false
							)));
						}
						else {
							die(cf_json_encode(array(
								'success' => false,
								'error' => $api->errorMessage
							)));
						}
					break;
				}
			break;
			case 'get_ga_data':
				global $a360_ga_token, $a360_ga_profile_id;
				
				$parameters = array(
					'start-date' => $_GET['start_date'],
					'end-date' => $_GET['end_date'],
					'sort' => 'ga:date',
					'ids' => 'ga:'.$a360_ga_profile_id
				);
				
				// split up top referrals by filtering on each medium in turn
				if ($_GET['data_type'] == 'top_referrals') {
					$handles = array(
						'referral' => null,
						'organic' => null,
						'email' => null,
						'cpc' => null,
						'*' => null
					);
					$parameters['dimensions'] = 'ga:medium,ga:source';
					$parameters['metrics'] = 'ga:visits,ga:timeOnSite,ga:pageviews';
					$parameters['sort'] = '-ga:visits';
					
					foreach ($handles as $filter => $handle) {
						$p = ($filter == '*' ? array('max-results' => 200) : array('filters' => 'ga:medium=='.$filter, 'max-results' => 200));
						$handles[$filter] = $handle = curl_init('https://www.google.com/analytics/feeds/data?'.http_build_query(array_merge(
							$parameters,
							$p
						)));
						curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($handle, CURLOPT_TIMEOUT, 10);
						curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
						curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, true);
						curl_setopt($handle, CURLOPT_HTTPHEADER, array(
							'Authorization: AuthSub token="'.$a360_ga_token.'"'
						));
					}

					$mh = curl_multi_init();
					foreach ($handles as $handle) {
						curl_multi_add_handle($mh, $handle);
					}

					$running = null;
					do {
						curl_multi_exec($mh, $running);
					} while ($running > 0);
					
					$all_results = array();
					foreach ($handles as $filter => $handle) {
						$http_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
						if (substr($http_code, 0, 1) == '2') {
							$all_results[$filter] = a360_reportObjectMapper(curl_multi_getcontent($handle));
						}
						else {
							$all_results[$filter] = curl_multi_getcontent($handle);
						}
						curl_multi_remove_handle($mh, $handle);
					}
					curl_multi_close($mh);

					header('Content-type: text/javascript');
					die(cf_json_encode(array(
						'success' => true,
						'data' => $all_results,
						'cached' => false
					)));

				}
				else {
					switch ($_GET['data_type']) {
						case 'visits':
							$parameters['dimensions'] = 'ga:date,ga:medium';
							$parameters['metrics'] = 'ga:visits,ga:bounces,ga:entrances,ga:pageviews,ga:newVisits,ga:timeOnSite';
							//$parameters['filters'] = 'ga:medium==referral,ga:medium==organic,ga:medium==email,ga:medium==cpc';
							//$parameters['sort'] = '-ga:visits';
						break;
						case 'geo':
							$parameters['dimensions'] = 'ga:country';
							$parameters['metrics'] = 'ga:visits';
							$parameters['sort'] = '-ga:visits';
						break;
						case 'top_referrals':
							$parameters['dimensions'] = 'ga:medium,ga:source';
							$parameters['metrics'] = 'ga:visits,ga:timeOnSite,ga:pageviews';
							$parameters['sort'] = '-ga:visits';
							$parameters['filters'] = 'ga:medium==referral,ga:medium==organic,ga:medium==email,ga:medium==cpc';
						break;
						case 'referral_media':
							$parameters['dimensions'] = 'ga:medium';
							$parameters['metrics'] = 'ga:visits';
							$parameters['sort'] = '-ga:visits';
						break;
						case 'top_content':
							$parameters['dimensions'] = 'ga:pagePath';
							$parameters['metrics'] = 'ga:pageviews,ga:uniquePageviews,ga:timeOnPage,ga:exits';
							$parameters['sort'] = '-ga:pageviews';
						break;
						case 'keywords':
							$parameters['dimensions'] = 'ga:keyword';
							$parameters['metrics'] = 'ga:pageviews,ga:uniquePageviews,ga:timeOnPage,ga:exits';
							$parameters['sort'] = '-ga:pageviews';
							$parameters['filters'] = 'ga:source=='.$_GET['source_name'];
						break;
						case 'referral_paths':
							$parameters['dimensions'] = 'ga:source,ga:referralPath';
							$parameters['metrics'] = 'ga:pageviews,ga:uniquePageviews,ga:timeOnPage,ga:exits';
							$parameters['sort'] = '-ga:pageviews';
							$parameters['filters'] = 'ga:source=='.$_GET['source_name'];
						break;
						case 'email_referrals':
							$parameters['dimensions'] = 'ga:campaign';
							$parameters['metrics'] = 'ga:pageviews,ga:uniquePageviews,ga:timeOnPage,ga:exits';
							$parameters['sort'] = '-ga:pageviews';
							$parameters['filters'] = 'ga:medium==email';
						break;
						default:
						break;
					}

					$ch = curl_init('https://www.google.com/analytics/feeds/data?'.http_build_query($parameters));
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
					curl_setopt($ch, CURLOPT_TIMEOUT, 10);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array(
						'Authorization: AuthSub token="'.$a360_ga_token.'"'
					));

					$result = curl_exec($ch);
				}


				if (!$result) {
					header('Content-type: text/javascript');
					die(cf_json_encode(array(
						'success' => false,
						'error' => curl_error($ch)
					)));
				}

				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if (substr($http_code, 0, 1) == '2') {
					
					$result = a360_reportObjectMapper($result);

					header('Content-type: text/javascript');
					die(cf_json_encode(array(
						'success' => true,
						'data' => $result,
						'cached' => false
					)));
				}
				else {
					header('Content-type: text/javascript');
					die(cf_json_encode(array(
						'success' => false,
						'error' => $result
					)));
				}
			break;
		}
	}
	if (!empty($_POST['a360_action']) && current_user_can('manage_options')) {
		switch ($_POST['a360_action']) {
			case 'update_mc_api_key':
				if (isset($_POST['a360_username']) && isset($_POST['a360_password'])) {
					$key_result = a360_fetch_API_key($_POST['a360_username'], $_POST['a360_password']);
					if ($key_result['success']) {
						delete_option('a360_chimp_chatter_url');
						update_option('a360_api_key', $key_result['api_key']);
						$q = http_build_query(array('updated' => 'true'));
					}
					else {
						$q = http_build_query(array('a360_error' => $key_result['error']));
					}
				}
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&'.$q);
				die();
			break;
			case 'clear_mc_api_key':
				delete_option('a360_api_key');
				delete_option('a360_chimp_chatter_url');
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&'.http_build_query(array('updated' => 'true')));
			break;
			case 'revoke_ga_token':
				global $a360_ga_token;
				$ch = curl_init('https://www.google.com/accounts/AuthSubRevokeToken');
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					'Authorization: AuthSub token="'.$a360_ga_token.'"'
				));
				$result = curl_exec($ch);
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if ($http_code == 200) {
					delete_option('a360_ga_token');
					delete_option('a360_ga_profile_id');
					wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&update=true');
				}
				else {
					wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&'.http_build_query(array(
						'a360_error' => 'Could not revoke token!'
					)));
				}
			break;
			case 'set_ga_profile_id':
				$result = update_option('a360_ga_profile_id', $_POST['profile_id']);
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page='.basename(__FILE__).'&updated=true');
			break;
		}
	}
}
add_action('init', 'a360_request_handler');


function a360_admin_js() {
	global $a360_api_key, $a360_has_key;
	header('Content-type: text/javascript');
	require('js/date-coolite.js');
	require('js/date.js');
	require('js/jquery.datePicker.js');
	require('js/jquery.datePickerMultiMonth.js');
	require('js/a360.js');
	print('
		(function() {
			a360.pageName = "'.$_GET['a360_page'].'";
			a360.mcAPIKey = "'.($a360_has_key ? $a360_api_key : '').'";
		})();
	');
	die();
}




function a360_admin_menu() {
	if (current_user_can('manage_options')) {
		add_options_page(
			__('Settings', 'analytics360'),
			__('Analytics360°', 'analytics360'),
			10,
			basename(__FILE__),
			'a360_settings_form'
		);
		add_dashboard_page(
			__('Dashboard', 'analytics360'),
			__('Analytics360°', 'analytics360'),
			10,
			basename(__FILE__),
			'a360_dashboard'
		);
	}
}
add_action('admin_menu', 'a360_admin_menu');

function a360_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'analytics360').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'a360_plugin_action_links', 10, 2);

function a360_settings_form() {
	global $a360_api_key, $a360_has_key, $a360_version, $a360_ga_token;

	$notification = (
		isset($_GET['a360_error']) ? 
			'<span class="error" style="padding:3px;"><strong>Error</strong>: '.stripslashes($_GET['a360_error']).'</span>' : 
			''
	);
	include('php/header.php');
	include('php/settings.php');
	include('php/footer.php');
}

function a360_dashboard() {
	global $a360_api_key, $a360_ga_token, $a360_has_key;
	$notification = (
		isset($_GET['a360_error']) ? 
			'<span class="error" style="padding:3px;"><strong>Error</strong>: '.stripslashes($_GET['a360_error']).'</span>' : 
			''
	);
	
	$a360_list_options = array();
	
	if (!empty($a360_api_key)) {
		if (!class_exists('MCAPI')) {
			include_once(ABSPATH.PLUGINDIR.'/analytics360/php/MCAPI.class.php');
		}
		$api = new MCAPI($a360_api_key);
		if (empty($api->errorCode)) {
			$lists = $api->lists();
			if (is_array($lists)) {
				foreach ($lists as $list) {
					$a360_list_options[] = '<option value="'.$list['id'].'">'.$list['name'].'</option>';
				}
			}
			else {
				$a360_list_options[] = '<option value="">Error: '.$api->errorMessage.'</option>';
			}
		}
		else {
			$a360_list_options[] = '<option value="">API Key Error: '.$api->errorMessage.'</option>';
		}
	}

	include('php/header.php');
	include('php/dashboard.php');
	include('php/footer.php');
}

function a360_render_chimp_chatter() {
	$rss = a360_get_chimp_chatter(10);
	echo '<ul id="chatter-messages">';
	foreach ((array)$rss->items as $item) {
		printf(
			'<li class="'.$item['category'].'"><a href="%1$s" title="%2$s">%3$s</a></li>',
			clean_url($item['link']),
			attribute_escape(strip_tags($item['description'])),
			$item['title']
		);
	}
	echo '</ul>';
}

function a360_get_chimp_chatter($num_items = -1) {
	$url = a360_get_chimp_chatter_url();
	if ($url) {
		if ($rss = fetch_rss($url)) {	// intentional assignment
			
			if ($num_items !== -1) {
				$rss->items = array_slice($rss->items, 0, $num_items);
			}
			return $rss;
		}
	}
	return false;
}

function a360_get_chimp_chatter_url() {
	if ($url = get_option('a360_chimp_chatter_url')) {	// intentional assignment
		return $url;
	}
	global $a360_api_key;
	if (!empty($a360_api_key)) {
		if (!class_exists('MCAPI')) {
			include_once(ABSPATH.PLUGINDIR.'/analytics360/php/MCAPI.class.php');
		}
		$api = new MCAPI($a360_api_key);
		if (!empty($api->errorCode)) {
			return null;
		}
		$results = $api->getAffiliateInfo();
		if (!empty($api->errorCode)) {
			return null;
		}
		$url = 'http://us1.admin.mailchimp.com/chatter/feed?u='.$results['user_id'];
		update_option('a360_chimp_chatter_url', $url);
		return $url;
	}
}

function a360_fetch_API_key($username, $password) {
	if (!class_exists('MCAPI')) {
		include_once(ABSPATH.PLUGINDIR.'/analytics360/php/MCAPI.class.php');
	}
	$api = new MCAPI($username, $password, true);
	if ($api->errorCode) {
		return array(
			'success' => false,
			'error' => $api->errorMessage
		);
	}
	return array(
		'success' => true,
		'api_key' => $api->api_key
	);
}



/**
 * Adapted from: 
 * 
 * GAPI - Google Analytics PHP Interface
 * http://code.google.com/p/gapi-google-analytics-php-interface/
 * @copyright Stig Manning 2009
 * @author Stig Manning <stig@sdm.co.nz>
 * @version 1.3
 */
function a360_reportObjectMapper($xml_string) {
	$xml = simplexml_load_string($xml_string);

	$results = null;
	$results = array();
	
	$report_root_parameters = array();
	$report_aggregate_metrics = array();
	
	//Load root parameters
	
	$report_root_parameters['updated'] = strval($xml->updated);
	$report_root_parameters['generator'] = strval($xml->generator);
	$report_root_parameters['generatorVersion'] = strval($xml->generator->attributes());
	
	$open_search_results = $xml->children('http://a9.com/-/spec/opensearchrss/1.0/');
	
	foreach($open_search_results as $key => $open_search_result) {
		$report_root_parameters[$key] = intval($open_search_result);
	}
	
	$google_results = $xml->children('http://schemas.google.com/analytics/2009');

	foreach($google_results->dataSource->property as $property_attributes) {
		$attr = $property_attributes->attributes();
		$report_root_parameters[str_replace('ga:','',$attr->name)] = strval($attr->value);
	}
	
	$report_root_parameters['startDate'] = strval($google_results->startDate);
	$report_root_parameters['endDate'] = strval($google_results->endDate);
	
	//Load result aggregate metrics
	
	foreach($google_results->aggregates->metric as $aggregate_metric) {
		$attr = $aggregate_metric->attributes();
		$metric_value = strval($attr->value);
		$name = $attr->name;
		//Check for float, or value with scientific notation
		if(preg_match('/^(\d+\.\d+)|(\d+E\d+)|(\d+.\d+E\d+)$/',$metric_value)) {
			$report_aggregate_metrics[str_replace('ga:','',$name)] = floatval($metric_value);
		}
		else {
			$report_aggregate_metrics[str_replace('ga:','',$name)] = intval($metric_value);
		}
	}
	
	//Load result entries
	
	foreach($xml->entry as $entry) {
		$metrics = array();
		$children = $entry->children('http://schemas.google.com/analytics/2009');
		foreach($children->metric as $metric) {
			$attr = $metric->attributes(); 
			$metric_value = strval($attr->value);
			$name = $attr->name;
			
			//Check for float, or value with scientific notation
			if(preg_match('/^(\d+\.\d+)|(\d+E\d+)|(\d+.\d+E\d+)$/',$metric_value)) {
				$metrics[str_replace('ga:','',$name)] = floatval($metric_value);
			}
			else {
				$metrics[str_replace('ga:','',$name)] = intval($metric_value);
			}
		}
		
		$dimensions = array();
		$children = $entry->children('http://schemas.google.com/analytics/2009');
		foreach($children->dimension as $dimension) {
			$attr = $dimension->attributes();
			$dimensions[str_replace('ga:','',$attr->name)] = strval($attr->value);
		}
		
		$results[] = array('metrics' => $metrics, 'dimensions' => $dimensions);
	}
		
	return $results;
}

if (!function_exists('get_snoopy')) {
	function get_snoopy() {
		include_once(ABSPATH.'/wp-includes/class-snoopy.php');
		return new Snoopy;
	}
}

/**
 * JSON ENCODE for PHP < 5.2.0
 * Checks if json_encode is not available and defines json_encode
 * to use php_json_encode in its stead
 * Works on iteratable objects as well - stdClass is iteratable, so all WP objects are gonna be iteratable
 */ 
if(!function_exists('cf_json_encode')) {
	function cf_json_encode($data) {
		if(function_exists('json_encode')) { return json_encode($data); }
		else { return cfjson_encode($data); }
	}
	
	function cfjson_encode_string($str) {
		if(is_bool($str)) { 
			return $str ? 'true' : 'false'; 
		}
	
		return str_replace(
			array(
				'"'
				, '/'
				, "\n"
				, "\r"
			)
			, array(
				'\"'
				, '\/'
				, '\n'
				, '\r'
			)
			, $str
		);
	}

	function cfjson_encode($arr) {
		$json_str = '';
		if (is_array($arr)) {
			$pure_array = true;
			$array_length = count($arr);
			for ( $i = 0; $i < $array_length ; $i++) {
				if (!isset($arr[$i])) {
					$pure_array = false;
					break;
				}
			}
			if ($pure_array) {
				$json_str = '[';
				$temp = array();
				for ($i=0; $i < $array_length; $i++) {
					$temp[] = sprintf("%s", cfjson_encode($arr[$i]));
				}
				$json_str .= implode(',', $temp);
				$json_str .="]";
			}
			else {
				$json_str = '{';
				$temp = array();
				foreach ($arr as $key => $value) {
					$temp[] = sprintf("\"%s\":%s", $key, cfjson_encode($value));
				}
				$json_str .= implode(',', $temp);
				$json_str .= '}';
			}
		}
		else if (is_object($arr)) {
			$json_str = '{';
			$temp = array();
			foreach ($arr as $k => $v) {
				$temp[] = '"'.$k.'":'.cfjson_encode($v);
			}
			$json_str .= implode(',', $temp);
			$json_str .= '}';
		}
		else if (is_string($arr)) {
			$json_str = '"'. cfjson_encode_string($arr) . '"';
		}
		else if (is_numeric($arr)) {
			$json_str = $arr;
		}
		else if (is_bool($arr)) {
			$json_str = $arr ? 'true' : 'false';
		}
		else {
			$json_str = '"'. cfjson_encode_string($arr) . '"';
		}
		return $json_str;
	}
}

?>