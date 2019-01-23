<?php
/*
Plugin Name: FocusWP DCS Docket Number Searcher
Version: 10
*/

if (!defined( 'WPINC' )) die;

add_filter('woocommerce_order_data_store_cpt_get_orders_query',
	function($query, $query_vars)
	{
		if(isset($query_vars['has_docket_number']) &&
			$query_vars['has_docket_number'])
		{
			$query['meta_query'][] = [
				'key' => 'docket_type',
				'compare' => 'EXISTS',
			];
			$query['meta_query'][] = [
				'key' => 'docket_number',
				'compare' => 'EXISTS',
			];
			/*
			$query['meta_query'][] = [
				'key' => 'docket_published',
				'compare' => 'NOT EXISTS',
			];
			 */
		}

		return $query;
	},
	10, 2);

function create_update_tables()
{
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	$tbl_i = $wpdb->prefix . 'fwp_mc_instance';
	$sql = "CREATE TABLE $tbl_i (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta( $sql );

	$tbl_v = $wpdb->prefix . 'fwp_mc_value';
	$sql = "CREATE TABLE $tbl_v (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		instance_id bigint(20) NOT NULL,
		value varchar(32) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta( $sql );

	cleanup();
}

function cleanup()
{
	global $wpdb;
	$tbl_i = $wpdb->prefix . 'fwp_mc_instance';
	$tbl_v = $wpdb->prefix . 'fwp_mc_value';

	$retention = get_option('dcs_retention_days');
	if($retention)
	{
		$tz = new DateTimeZone(get_option('timezone_string'));
		$t = new DateTime('00:00', $tz);
		$t->sub(new DateInterval("P{$retention}D"));

		$sql = "DELETE FROM $tbl_v
			WHERE instance_id IN (
				SELECT id FROM $tbl_i
				WHERE time < %s
			)";
		$pq = $wpdb->prepare($sql, $t->format('Y-m-d H:i:s'));
		$wpdb->query($pq);

		$sql = "DELETE FROM $tbl_i WHERE time < %s";
		$pq = $wpdb->prepare($sql, $t->format('Y-m-d H:i:s'));
		$wpdb->query($pq);
	}
}

function insert_instance()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'fwp_mc_instance';
	$wpdb->insert( $table_name, [ 'time' => current_time( 'mysql' ) ] );
	return $wpdb->insert_id;
}

function insert_value($instance_id, $value)
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'fwp_mc_value';
	$wpdb->insert( $table_name, [
		'instance_id' => $instance_id,
		'value' => $value
	] );
}

function find_stuff($docket_type, $docket_number)
{
	global $wpdb;

	$table_i = $wpdb->prefix . 'fwp_mc_instance';
	$table_v = $wpdb->prefix . 'fwp_mc_value';

	$pq = "SELECT $table_i.time
		FROM $table_v
		JOIN $table_i ON $table_i.id = $table_v.instance_id
		WHERE $table_v.value RLIKE '%s%s-0*%s%s'
		ORDER BY $table_i.time
		";
	/*
	$q = sprintf($pq,
		'\\\\b',
		$docket_type,
		$docket_number,
		'\\\\b');
	 */
	$q = sprintf($pq,
		'[[:<:]]',
		$docket_type,
		$docket_number,
		'[[:>:]]');

	return $wpdb->get_col($q);
}

add_action('dcs_job', function(){
	fetch_and_search();
});

function fetch_and_search($fetch = true)
{
	$summary_email = get_option('dcs_notification_email');
	$match_email = get_option('dcs_match_email');

	create_update_tables();

	$date = strtoupper(current_time('d-M-y'));
	$subject_prefix = "DCS $date:";

	$wc_orders = wc_get_orders([
		'limit' => -1,
		'type' => 'shop_order',
		'status' => [ 'processing', 'completed', ],
		'has_docket_number' => true,
	]);

	$dockets = [];

	foreach($wc_orders as $order)
	{
		$docket_type = $order->get_meta('docket_type', true);
		$docket_number = $order->get_meta('docket_number', true);
		$docket_number = trim($docket_number);
		$docket_number = ltrim($docket_number, '0');
		$docket = sprintf("%s%s", $docket_type, $docket_number);
		$docket_published = $order->get_meta('docket_published', true);

		$ela = false;
		foreach($order->get_items() as $item) {
			$name = $item->get_name();
			if($name == 'Expedited Letter of Authority')
				$ela = true;
		}

		$dockets[$docket]['type'] = $docket_type;
		$dockets[$docket]['number'] = $docket_number;
		$dockets[$docket]['orders'][] = [
			'id' => $order->ID,
			'published' => $docket_published ? true : false,
			'ela' => $ela,
		];
	}

	if($fetch)
	{
		$url = "https://li-public.fmcsa.dot.gov/LIVIEW/PKG_register.prc_reg_detail?pd_date=$date&pv_vpath=LIVIEW";

		$ch = curl_init($url); 
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
		$res = curl_exec($ch); 
		curl_close($ch);

		$doc = DOMDocument::loadHTML($res);
		$xpath = new DOMXPath($doc);

		$cpl = $xpath->query("//a[@name='CPL']")->item(0);
		// up to first table
		do $cpl = $cpl->parentNode;
		while($cpl->nodeName != 'table');
		// over to next table
		do $cpl = $cpl->nextSibling;
		while($cpl->nodeName != 'table');

		$published_numbers = $xpath->query(".//th[@scope='row']/text()", $cpl);
		$instance_id = insert_instance();
		foreach($published_numbers as $number)
			insert_value($instance_id, $number->wholeText);
	}

	$needles = [];
	$found_count = 0;
	foreach($dockets as $docket)
	{
		// if a published ela is present, continue
		if(docket_order_exists($docket['orders'], true, true))
			continue;

		// if an unpublished ela is present, look for stuff
		if($id = docket_order_exists($docket['orders'], true, false))
		{
			$needles[] = sprintf("%s-%s",
				$docket['type'],
				$docket['number']);
			$found_count += process_docket(
				$docket['type'],
				$docket['number'],
				$id,
				true,
				$match_email
			);
			continue;
		}

		// if a published non-ela is present, continue
		if(docket_order_exists($docket['orders'], false, true))
			continue;

		// if an unpublished non-ela is present, look for stuff
		if($id = docket_order_exists($docket['orders'], false, false))
		{
			$needles[] = sprintf("%s-%s",
				$docket['type'],
				$docket['number']);
			$found_count += process_docket(
				$docket['type'],
				$docket['number'],
				$id,
				false,
				$match_email
			);
			continue;
		}
	}

	$body = "Found $found_count target docket numbers.\n\n";
	$body .= "Search Criteria:\n";
	foreach($needles as $needle)
	{
		$body .= $needle;
	}
	wp_mail($summary_email,
		"$subject_prefix Summary",
		$body
	);
}

function process_docket($type, $number, $id, $ela, $match_email)
{
	if(find_stuff($type, $number))
	{
		update_post_meta(
			$id,
			'docket_published',
			true
		);
		$subject = sprintf(
			"New Authority Granted for %s-%s%s",
			$type,
			$number,
			$ela ? ' (Expedited)' : ''
		);
		/*
		$body = sprintf(
			"Expedited letter available\n%s-%s\n%s",
			$needle['type'],
			$needle['number'],
			$r[0]
		);
		 */
		wp_mail($match_email, $subject, $subject);
		return 1;
	}
	return 0;
}

function docket_order_exists($orders, $ela, $published)
{
	foreach($orders as $order)
	{
		if($order['ela'] == $ela && $order['published'] == $published)
			return $order['id'];
	}
	return false;
}

function render_dcs_admin_page()
{
	echo "<div class='wrap'>";

	echo "<form method='post' action='options.php'>";
	settings_fields('dcs_option_group');
	do_settings_sections('dcs_settings');
	submit_button();

	$next = wp_next_scheduled('dcs_job');
	$ref = admin_url('admin-post.php');
	if($next)
	{
		$nextf = strftime('%FT%T', $next);
		$tm = $next - time();
		echo "<p>";
		echo "Next run in $tm seconds ($nextf)<br>";
		echo "<a href='$ref?action=dcs_unschedsched' class='button'>Run Now</a> ";
		echo "<a href='$ref?action=dcs_unsched' class='button'>Disable</a><br>";
		echo "</p>";
	}
	else
	{
		echo "<p>";
		echo "Job is unscheduled<br>";
		echo "<a href='$ref?action=dcs_sched' class='button'>Enable</a><br>";
		echo "</p>";
	}
	echo "</div>";
}

add_action('admin_menu', function()
{
	add_management_page(
		'DCS',
		'DCS',
		'manage_options',
		'dcs',
		'render_dcs_admin_page'
	);
});

add_action('admin_post_dcs_sched', function()
{
	wp_schedule_event(time(), 'daily', 'dcs_job');
	if(wp_get_referer())
		wp_safe_redirect(wp_get_referer());
});

add_action('admin_post_dcs_unsched', function()
{
	$next = wp_next_scheduled('dcs_job');
	if($next)
		wp_unschedule_event($next, 'dcs_job');
	if(wp_get_referer())
		wp_safe_redirect(wp_get_referer());
});

add_action('admin_post_dcs_unschedsched', function()
{
	$next = wp_next_scheduled('dcs_job');
	if($next)
		wp_unschedule_event($next, 'dcs_job');
	wp_schedule_event(time(), 'daily', 'dcs_job');
	if(wp_get_referer())
		wp_safe_redirect(wp_get_referer());
});

add_action('admin_init', function()
{
	add_settings_section(
		'dcs_settings_section',
		'',
		function() { },
		'dcs_settings'
	);

	register_setting(
		'dcs_option_group',
		'dcs_retention_days',
		[
			'type' => 'integer',
		]
	);
	add_settings_field(
		'dcs_retention_days',
		'Data Retention (Days)',
		function() {
			$key = 'dcs_retention_days';
			$value = get_option($key);
			printf("<input name='%s' type='number' value='%s'>",
				$key,
				$value
			);
		},
		'dcs_settings',
		'dcs_settings_section'
	);

	register_setting(
		'dcs_option_group',
		'dcs_notification_email',
		[
			'type' => 'string',
		]
	);
	add_settings_field(
		'dcs_notification_email',
		'Summary Email Addresses',
		function() {
			$key = 'dcs_notification_email';
			$value = get_option($key);
			$style = 'width: 100%;';
			printf("<input name='%s' value='%s' style='%s'>",
				$key,
				$value,
				$style
			);
		},
		'dcs_settings',
		'dcs_settings_section'
	);

	register_setting(
		'dcs_option_group',
		'dcs_match_email',
		[
			'type' => 'string',
		]
	);
	add_settings_field(
		'dcs_match_email',
		'Match Email Addresses',
		function() {
			$key = 'dcs_match_email';
			$value = get_option($key);
			$style = 'width: 100%;';
			printf("<input name='%s' value='%s' style='%s'>",
				$key,
				$value,
				$style
			);
		},
		'dcs_settings',
		'dcs_settings_section'
	);
});

add_action('tools_page_dcs', function()
{
	if(isset($_GET['s']))
		fetch_and_search(false);
});
