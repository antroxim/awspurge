<?php

/*
Plugin Name: AWS Varnish Purge
Description: Sends HTTP PURGE requests to URLs of changed posts/pages when they are modified.
Version: 1.0.0
*/

/**
 * The maximum number of paths to purge per batch step, this max will usually
 * only be necessary on the command line where execution time is endless.
 */
define('AWS_PURGE_MAX_PATHS', 20);

/**
 * The maximum amount of HTTP requests that can be done per step. In practice
 * this limited will be lowered to respect PHP's max execution time setting. It
 * will be met when that setting is zero, e.g. on the command line.
 */
define('AWS_PURGE_MAX_REQUESTS', 60);

/**
 * The number of HTTP requests executed in parallel during purging.
 */
define('AWS_PURGE_PARALLEL_REQUESTS', 6);

/**
 * The number of seconds before a purge attempt times out.
 */
define('AWS_PURGE_REQUEST_TIMEOUT', 2);

/**
 * The amount of time in seconds used to lock purge processing.
 */
define('AWS_PURGE_QUEUE_LOCK_TIMEOUT', 60);

/**
 * Define plugin directory
 */
define('AWS_PURGE_DIR', __DIR__ . '/');

/**
 * Define plugin DB version
 */
define('AWS_PURGE_DB', '1.0');


class AwsPurge
{

	// Collects URLs to purge
	protected $purgeUrls = array();

	public function __construct()
	{
		add_action('init', array(&$this, 'init'));
	}


	public function init()
	{
		foreach ($this->getRegisterEvents() as $event) {
			add_action($event, array($this, 'addPurgePostUrl'), 10, 2);
		}
		add_action('shutdown', array($this, 'setPurgeList'));
		if (!empty($this->purgeUrls)) {
			add_action('admin_enqueue_scripts', array($this, 'initPurgeScript'));
		} else {
			wp_cache_set('aws_purge_lock', 0);
		}
//		add_action('wp_ajax_awspurgeajax', array($this, 'awsPurgeAjax'));
		add_action('wp_ajax_nopriv_purgeworker', array($this, 'PurgeWorker'));

//		$url = 'http://local.radaronline.com/photos/kim-kardashian-khlo';
//		var_dump($this->purgeUrl($url));

	}

	public function awspurge_install()
	{
		if (get_option('awspurge_db_version') != AWS_PURGE_DB) {
			global $wpdb;
			// Purge links table
			$table_name = $wpdb->prefix . 'awspurge_links';
			$charset_collate = $wpdb->get_charset_collate();
			$sql = "CREATE TABLE $table_name (
			lid mediumint(9) NOT NULL AUTO_INCREMENT,
			url varchar(55) NOT NULL UNIQUE,
			status TINYINT NOT NULL,
			UNIQUE KEY lid (lid)) $charset_collate; ";

//			$table_name = $wpdb->prefix . 'awspurge_workers';
//			$sql .= "CREATE TABLE $table_name (
//			wid mediumint(9) NOT NULL AUTO_INCREMENT,
//			pid mediumint(9) NOT NULL,
//			UNIQUE KEY wid (wid)) $charset_collate;";
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
			add_option('awspurge_db_version', AWS_PURGE_DB);
		}
	}


	/**
	 * Shutdown action
	 * Sends a request to launch purge worker
	 */
	public function initPurgeScript()
	{
		if (!defined('DOING_AJAX')) {
			$this->runPurgeWorker();
		}
		wp_enqueue_script('awspurge', plugin_dir_url(__FILE__) . 'awspurge.js');
	}

	/**
	 * Shutdown action
	 */
	public function setPurgeList()
	{
		foreach ($this->purgeUrls as $url) {
			$this->addPathToQueue($url);
		}
	}

	protected function getRegisterEvents()
	{
		return array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'edit_post',
			'delete_attachment',
			'switch_theme',
		);
	}

	function awsPurgeAjax()
	{
//		if (function_exists('ignore_user_abort')) {
//			ignore_user_abort(true);
//		}
//		$count = count($this->purgeUrls);
//		$lock = wp_cache_get('aws_purge_lock');
//		if (!$lock) {
//			wp_cache_set('aws_purge_lock', 1, '', 5);
//			$this->runPurgeWorker();
//			wp_cache_replace('aws_purge_lock', 0, '', 5);
//		}
//
//		wp_send_json(array('processed' => $count));

	}

	public function runPurgeWorker()
	{

		$url = get_option('siteurl') . '/wp-admin/admin-ajax.php?action=purgeworker';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
		curl_exec($ch);
		$info = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		curl_close($ch);
		return $info;
	}


	public function PurgeWorker()
	{
		if (function_exists('ignore_user_abort')) {
			ignore_user_abort(true);
		}
		header("Connection: close");
		header("Content-Length: 0");
		flush();

		// Using no more than 80% of execution time
		$max_time = (int)ini_get('max_execution_time');
		$max_time = $max_time * 0.8;
		$start = microtime(TRUE);
		$queue = $this->loadQueue();

		// Loop trough all URLs in database
		foreach ($queue as $item) {
			$results = $this->purgeUrl($item->url);
			foreach ($results as $result) {
				if ($result->result) {
					$this->removePathFromQueue($item->lid);
//					$this->addPathToQueue($item->url, 1);

				} else {
					$this->addPathToQueue($item->url, 2);
				}
			}
			// Checking how much time we spent
			$step = microtime(TRUE);
			if ($max_time < ($step - $start)) {
				break;
			}
		}
//		$queue = $this->loadQueue();
	}


	/**
	 * Adds a path to purge queue. If status is specified, updates a status of existing queue item
	 * @param $path
	 * @param int $status 0 - unprocessed item, 1 - processed item, 2 - error
	 */
	public function addPathToQueue($path, $status = 0)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'awspurge_links';
		$data = array(
			'url' => $path,
			'status' => $status,
		);
		$wpdb->replace($table, $data);
	}

	/**
	 * Loads entire purge queue
	 * @return mixed - array of row objects
	 */
	private function loadQueue()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'awspurge_links';
		$query = "SELECT * FROM $table;";
		return $wpdb->get_results($query);
	}

	/**
	 * Removes all paths from queue.
	 * @param $lid - (optional) link ID to delete specific link
	 */
	private function removePathFromQueue($lid)
	{
		if (!$lid) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'awspurge_links';
		$wpdb->query("DELETE FROM $table WHERE lid = $lid");
	}


	/**
	 * Collects URLs to purge
	 * @param $postId
	 */
	public function addPurgePostUrl($postId)
	{
		// If this is a revision, stop.
		if (get_post_type($postId) == 'revision') {
			return;
		}
		// If this is a valid post we want to purge the post, the home page and any associated tags & cats
		// If not, purge everything on the site.

		$validPostStatus = array("publish", "trash");
		$thisPostStatus = get_post_status($postId);

		if (get_permalink($postId) == true && in_array($thisPostStatus, $validPostStatus)) {
			// Category & Tag purge based on Donnacha's work in WP Super Cache
			$categories = get_the_category($postId);
			if ($categories) {
				$category_base = get_option('category_base');
				if ($category_base == '')
					$category_base = '/category/';
				$category_base = trailingslashit($category_base);
				foreach ($categories as $cat) {
					array_push($this->purgeUrls, home_url($category_base . $cat->slug . '/'));
				}
			}
			$tags = get_the_tags($postId);
			if ($tags) {
				$tag_base = get_option('tag_base');
				if ($tag_base == '') {
					$tag_base = '/tag/';
				}
				$tag_base = trailingslashit(str_replace('..', '', $tag_base));
				foreach ($tags as $tag) {
					array_push($this->purgeUrls, home_url($tag_base . $tag->slug . '/'));
				}
			}
			// Post URL
			array_push($this->purgeUrls, get_permalink($postId));
			// Feeds
			$feeds = array(get_bloginfo('rdf_url'), get_bloginfo('rss_url'), get_bloginfo('rss2_url'), get_bloginfo('atom_url'), get_bloginfo('comments_atom_url'), get_bloginfo('comments_rss2_url'), get_post_comments_feed_link($postId));
			foreach ($feeds as $feed) {
				array_push($this->purgeUrls, $feed);
			}
			// Home URL
			array_push($this->purgeUrls, home_url());
		} else {
			array_push($this->purgeUrls, home_url('?vhp=regex'));
		}
	}

	protected function purgeUrl($url)
	{
		// Parse the URL for proxy proxies
		$p = parse_url($url);
		$s = array('ipad', 'iphone');
		if (isset($p['query']) && ($p['query'] == 'vhp=regex')) {
			$pregex = '.*';
			$varnish_x_purgemethod = 'regex';
		} else {
			$pregex = '';
			$varnish_x_purgemethod = 'default';
		}
		// Build a varniship
		global $aws_varnish_ips;
		if (empty($aws_varnish_ips)) {
			$aws_varnish_ips = array($p['host']);
		}
		if (isset($p['path'])) {
			$path = $p['path'];
		} else {
			$path = '';
		}

		$requests = array();

		foreach ($aws_varnish_ips as $varn) {

			$rqst = new stdClass();
			$rqst->scheme = $p['scheme'];
			$rqst->rtype = 'PURGE';
			$rqst->balancer = $varn;
			$rqst->domain = $p['host'];
			$rqst->path = $path;
			$rqst->uri = $rqst->scheme . '://' . $rqst->domain . $rqst->path . $pregex;
			$rqst->uribal = $rqst->scheme . '://' . $rqst->balancer . $rqst->path . $pregex;
			$rqst->headers = array(
				'Host: ' . $rqst->domain,
				'Accept-Encoding: gzip',
				'X-Purge-Method: ' . $varnish_x_purgemethod,
			);
			$requests[] = $rqst;

		}

		return $this->processRequest($requests);
	}

	private function processRequest($requests)
	{

		$single_mode = (count($requests) === 1);
		$results = array();

		// Initialize the cURL multi handler.
		if (!$single_mode) {
			static $curl_multi;
			if (is_null($curl_multi)) {
				$curl_multi = curl_multi_init();
			}
		}

		// Enter our event loop and keep on requesting until $unprocessed is empty.
		$unprocessed = count($requests);
		while ($unprocessed > 0) {

			// Group requests per sets that we can run in parallel.
			for ($i = 0; $i < AWS_PURGE_PARALLEL_REQUESTS; $i++) {
				if ($rqst = array_shift($requests)) {
					$rqst->curl = curl_init();

					// Instantiate the cURL resource and configure its runtime parameters.
					curl_setopt($rqst->curl, CURLOPT_URL, $rqst->uribal);
					curl_setopt($rqst->curl, CURLOPT_TIMEOUT, AWS_PURGE_REQUEST_TIMEOUT);
					curl_setopt($rqst->curl, CURLOPT_HTTPHEADER, $rqst->headers);
					curl_setopt($rqst->curl, CURLOPT_CUSTOMREQUEST, $rqst->rtype);
					curl_setopt($rqst->curl, CURLOPT_FAILONERROR, TRUE);
					curl_setopt($rqst->curl, CURLOPT_HEADER, FALSE);
					curl_setopt($rqst->curl, CURLOPT_NOBODY, TRUE);

					// Add our handle to the multiple cURL handle.
					if (!$single_mode) {
						curl_multi_add_handle($curl_multi, $rqst->curl);
					}

					// Add the shifted request to the results array and change the counter.
					$results[] = $rqst;
					$unprocessed--;
				}
			}

			// Execute the created handles in parallel.
			if (!$single_mode) {
				$active = NULL;
				do {
					$mrc = curl_multi_exec($curl_multi, $active);
				} while ($mrc == CURLM_CALL_MULTI_PERFORM);
				while ($active && $mrc == CURLM_OK) {
					if (curl_multi_select($curl_multi) != -1) {
						do {
							$mrc = curl_multi_exec($curl_multi, $active);
						} while ($mrc == CURLM_CALL_MULTI_PERFORM);
					}
				}
			} // In single mode there's only one request to do, use curl_exec().
			else {
				curl_exec($results[0]->curl);
				$single_info = array('result' => curl_errno($results[0]->curl));
			}

			// Iterate the set of results and fetch cURL result and resultcodes. Only
			// process those with the 'curl' property as the property will be removed.
			foreach ($results as $i => $rqst) {
				if (!isset($rqst->curl)) {
					continue;
				}
				$info = $single_mode ? $single_info : curl_multi_info_read($curl_multi);
				$results[$i]->result = ($info['result'] == CURLE_OK) ? TRUE : FALSE;
				$results[$i]->error_curl = $info['result'];
				$results[$i]->error_http = curl_getinfo($rqst->curl, CURLINFO_HTTP_CODE);

				// When the result failed but the HTTP code is 404 we turn the result
				// into a TRUE as Varnish simply couldn't find the entry as its not there.
				if ((!$results[$i]->result) && ($results[$i]->error_http == 404)) {
					$results[$i]->result = TRUE;
				}

				// Remove the handle if parallel processing occurred.
				if (!$single_mode) {
					curl_multi_remove_handle($curl_multi, $rqst->curl);
				}
				// Close the resource and delete its property.
				curl_close($rqst->curl);
				unset($rqst->curl);
			}
		}
		return $results;
	}
}

$awspurge = new AwsPurge();
register_activation_hook(__FILE__, array('AwsPurge', 'awspurge_install'));
