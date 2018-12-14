<?php
/*
Plugin Name: FocusWP DCS Docket Number Searcher
Version: 3
*/

if (!defined( 'WPINC' )) die;

add_filter('woocommerce_order_data_store_cpt_get_orders_query',
	function($query, $query_vars)
	{
		if(isset($query_vars['has_docket_number']) &&
			$query_vars['has_docket_number'])
		{
			$query['meta_query'][] = array(
				'key' => 'docket_number',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	},
	10, 2 );

function create_update_tables()
{
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	$table_name = $wpdb->prefix . 'fwp_mc_instance';
	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta( $sql );

	$table_name = $wpdb->prefix . 'fwp_mc_value';
	$sql = "CREATE TABLE $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		instance_id bigint(20) NOT NULL,
		value varchar(32) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta( $sql );
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

function more_junk($needle)
{
	global $wpdb;

	$table_i = $wpdb->prefix . 'fwp_mc_instance';
	$table_v = $wpdb->prefix . 'fwp_mc_value';

	/*
	$pq = "SELECT $table_i.time
		FROM $table_v
		JOIN $table_i ON $table_i.id = $table_v.instance_id
		WHERE $table_v.value RLIKE '%sMC-%s%s'";
	$q = sprintf($pq, '\\\\b', $needle, '\\\\b');
	*/
	$pq = "SELECT $table_i.time
		FROM $table_v
		JOIN $table_i ON $table_i.id = $table_v.instance_id
		WHERE $table_v.value RLIKE '%s%s%s'";
	$q = sprintf($pq, '\\\\b', $needle, '\\\\b');

	return $wpdb->get_col($q);
}

add_action('dcs_job', function()
{
	$admin_email = get_option('dcs_notification_email');

	create_update_tables();

	$instance_id = insert_instance();

	$date = strtoupper(current_time('d-M-y'));
	$subject_prefix = "DCS $date:";

	$orders = wc_get_orders([
		'limit' => -1,
		'type' => 'shop_order',
		'status' => [
			'processing',
			'completed',
		],
		'has_docket_number' => true,
	]);
	$mc_numbers = [];
	foreach($orders as $order)
	{
		$docket_type = $order->get_meta('docket_type', true);
		$docket_number = $order->get_meta('docket_number', true);
		/*
		$mc_number = preg_replace('/[^0-9]/', '', $mc_number);
		if($mc_number != '') $mc_numbers[] = $mc_number;
		*/
		$mc_numbers[] = sprintf("%s-%s", $docket_type, $docket_number);
	}
goto foo;
	wp_mail($admin_email,
		"$subject_prefix Docket Number Search List",
		var_export($mc_numbers, true));

	$url = "http://li-public.fmcsa.dot.gov/LIVIEW/PKG_register.prc_reg_detail?pd_date=$date&pv_vpath=LIVIEW"; 
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

	$numbers = $xpath->query(".//th[@scope='row']/text()", $cpl);
	foreach($numbers as $number)
		insert_value($instance_id, $number->wholeText);
foo:

	$found_count = 0;
	foreach($mc_numbers as $needle)
	{
		$r = more_junk($needle);
		if($r)
		{
			$found_count++;
			wp_mail($admin_email,
				"$subject_prefix Search Result for $needle",
				"Found $needle\n" . var_export($r, true)
			);
		}
	}

	wp_mail($admin_email,
		"$subject_prefix Search Result Count",
		"Found $found_count target docket numbers."
	);

});

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
	register_setting(
		'dcs_option_group',
		'dcs_notification_email',
		[
			'type' => 'string',
		]
	);

	add_settings_section(
		'dcs_settings_section',
		'',
		function() { },
		'dcs_settings'
	);

	add_settings_field(
		'dcs_notification_email',
		'Notification Email Address',
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
});
