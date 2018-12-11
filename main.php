<?php
/*
Plugin Name: FocusWP DCS
*/

if (!defined( 'WPINC' )) die;

add_action('init', function()
{
	$single = 'DCS'; $single_l = strtolower($single);
	$plural = 'DCS'; $plural_l = strtolower($plural);
	register_post_type(strtr($single_l, ' ', '-'), [
		'labels' => [ 'name' => $plural ],
		'show_ui' => true,
		'supports' => [ 'title' /*, 'custom-fields'*/ ],
	]);
});

add_action('dcs_job', function()
{
	$admin_email = get_option('admin_email');
	$date = strtoupper(current_time('d-M-y'));

	$ch = curl_init("http://li-public.fmcsa.dot.gov/LIVIEW/PKG_register.prc_reg_detail?pd_date=$date&pv_vpath=LIVIEW"); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
	$res = curl_exec($ch); 
	curl_close($ch);

	/*
	file_put_contents("/home/derek/tmp/dcs/$date.html", $res);
	$res = file_get_contents("/home/derek/tmp/dcs/$date.html");
	 */

	$doc = DOMDocument::loadHTML($res);
	$xpath = new DOMXPath($doc);

	$cpl = $xpath->query("//a[@name='CPL']")->item(0);
	// up to first table
	do $cpl = $cpl->parentNode;
	while($cpl->nodeName != 'table');
	// over to next table
	do $cpl = $cpl->nextSibling;
	while($cpl->nodeName != 'table');

	$needles = get_posts([
		'post_type' => 'dcs',
		'nopaging' => true
	]);

	$numbers = $xpath->query(".//th[@scope='row']/text()", $cpl);
	foreach($numbers as $number) {
		foreach($needles as $needle) {
			if(preg_match("/\bMC-{$needle->post_title}\b/", $number->wholeText)) {
				wp_mail($admin_email,
					'DCS Search Result',
					"Found MC-{$needle->post_title}"
				);
			}
		}
	}

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
