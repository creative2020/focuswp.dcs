<?php
/*
Plugin Name: FocusWP DCS MC Searcher
*/

if (!defined( 'WPINC' )) die;

add_filter('woocommerce_order_data_store_cpt_get_orders_query',
	function($query, $query_vars)
	{
		if(isset($query_vars['has_mc']) && $query_vars['has_mc'])
		{
			$query['meta_query'][] = array(
				'key' => 'billing_mc_number',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	},
	10, 2 );

add_action('dcs_job', function()
{
	//$admin_email = get_option('admin_email');
	$admin_email = 'support@2020creative.com';
	$date = strtoupper(current_time('d-M-y'));
	$subject_prefix = "DCS $date:";

	$orders = wc_get_orders([
		'limit' => -1,
		'type' => 'shop_order',
		'status' => [
			'processing',
			'completed',
		],
		'has_mc' => true,
	]);
	$mc_numbers = [];
	foreach($orders as $order)
	{
		$mc_number = $order->get_meta('billing_mc_number', true);
		$mc_number = preg_replace('/[^0-9]/', '', $mc_number);
		if($mc_number != '') $mc_numbers[] = $mc_number;
	}
	wp_mail($admin_email,
		"$subject_prefix MC Number Search List",
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
	$found_count = 0;
	foreach($numbers as $number)
	{
		foreach($mc_numbers as $needle)
		{
			if(preg_match("/\bMC-$needle\b/", $number->wholeText))
			{
				$found_count++;
				wp_mail($admin_email,
					"$subject_prefix Search Result for MC-$needle",
					"Found MC-$needle"
				);
			}
		}
	}

	wp_mail($admin_email,
		"$subject_prefix Search Result Count",
		"Found $found_count target MC numbers at $url."
	);

});

function render_dcs_admin_page()
{
	echo "<div class='wrap'>";
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
	wp_schedule_event(time(), 'twicedaily', 'dcs_job');
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
	wp_schedule_event(time(), 'twicedaily', 'dcs_job');
	if(wp_get_referer())
		wp_safe_redirect(wp_get_referer());
});
