<?php
/*
Plugin Name: Limit login
Plugin URI: http://страница_автора_плагина
Description: ограничивает вход
Version: Номер версии плагина, например: 1.0
Author: noname
Author URI: http://страница_автора_плагина
*/

add_option('limit_retries','','','no');
add_option('limit_retries_valid','','','no');


function define_ip(){
	$user_ip = $_SERVER['REMOTE_ADDR'];
	$user_ip = preg_replace( '/[^0-9a-fA-F:., ]/', '', $user_ip );
	return $user_ip;
}

add_filter('wp_login_failed','define_retries');
function define_retries(){
	$user_ip = define_ip();
	$limit_retries = get_option('limit_retries');
	if (empty($limit_retries)){
		$retries = 1;
		$limit_retries = array(
			"$user_ip" => $retries
		);	
	}
	else {
		foreach ($limit_retries as $key => $value) {
			if ($key == $user_ip){
				$value++;
				$limit_retries["$user_ip"] = $value;
			}
		}
	}
	update_option( 'limit_retries', $limit_retries );

	$option_lockout = get_option('option_lockout');
	$limit_retries_valid = get_option('limit_retries_valid');
	$current_count_lockouts = $limit_retries_valid["$user_ip"]['count_lockouts'];
	$count_lockouts = $option_lockout['count_lockouts'];
	$increase_lockout = $option_lockout['increase_lockout'];
	$time_lockout = $option_lockout['time_lockout'];
	if ($limit_retries["$user_ip"] == $option_lockout['count_retries']){
		$currenttime = current_time('d m Y H i');
		list( $year, $month, $day, $hour, $minute, $second ) = preg_split( '([^0-9])', $currenttime );
		if($current_count_lockouts >= $count_lockouts){
			$hour = $hour + $increase_lockout;
		}
		else{
			$minute = $minute + $time_lockout;
		}
		$end_lockout = $year .' '. $month .' '. $day .' '. $hour .' '. $minute .' '. $second;
		$limit_retries_valid = get_option('limit_retries_valid');
		if(empty($limit_retries_valid)){
			$limit_retries_valid = array();
		}
		if(!isset($limit_retries_valid["$user_ip"]['count_lockouts'])){
			$count_lockouts = 1;
		}
		else {
			$count_lockouts = $limit_retries_valid["$user_ip"]['count_lockouts'];
			$count_lockouts++;
		}

		$lockout = array(
			"$user_ip" => array(
				'end_lockout' => $end_lockout,
				'count_lockouts' => $count_lockouts
			)
		);
		$limit_retries_valid = array_merge($limit_retries_valid, $lockout);
		update_option( 'limit_retries_valid', $limit_retries_valid );
	}
}

function login_limit_message(){
    $user_ip = define_ip();
    $option_lockout = get_option('option_lockout');
	$limit_retries_valid = get_option('limit_retries_valid');
	$current_count_lockouts = $limit_retries_valid["$user_ip"]['count_lockouts'];
	$count_lockouts = $option_lockout['count_lockouts'];
	$end_lockout = $limit_retries_valid["$user_ip"]['end_lockout'];
	list( $year, $month, $day, $hour, $minute, $second ) = preg_split( '([^0-9])', $end_lockout );
	$end_hour = $hour;
	$end_minute = $minute;
	$currenttime = current_time('d m Y H i');
	list( $year, $month, $day, $hour, $minute, $second ) = preg_split( '([^0-9])', $currenttime );
	if($current_count_lockouts>$count_lockouts){
		$hour = $end_hour - $hour;
		if( $hour > 0 ){
			$time_lockout = $hour . ' hour';
			return $time_lockout;
		}
		else{
			$minute = $end_minute - $minute;
			$time_lockout = $minute . ' minute';
			return $time_lockout;
		}
	}	
	else{
		$minute = $end_minute - $minute;
		$time_lockout = $minute . ' minute';
		return $time_lockout;
	}
}

add_filter('login_errors','login_error_message');
function login_error_message($error){
    $pos = strpos($error, 'incorrect');
    $option_lockout = get_option('option_lockout');
    $user_ip = define_ip();
    $limit_retries = get_option('limit_retries');
    if (is_int($pos)) {
    	$retries = $option_lockout['count_retries'] - $limit_retries["$user_ip"];
    	if($retries != 0){
        	$error .= $retries. " attempt remaining";
        }
        else{
        	$error .= sprintf(
						__( '<strong>ERROR</strong>: Too many failed login attempts. Please try again in ' . login_limit_message($time_lockout))
						
					) .
					' <a href="' . wp_lostpassword_url() . '">' .
					__( 'Lost your password?' ) .
					'</a>'
					;
        }
    }
    return $error;
}

add_filter('wp_authenticate_user','lockout');
function lockout($user){

	$user_ip = define_ip();
	$option_lockout = get_option('option_lockout');
	$limit_retries_valid = get_option('limit_retries_valid');
	if(isset($limit_retries_valid["$user_ip"])){
		if(isset($limit_retries_valid["$user_ip"]['end_lockout'])){
			$current_time = current_time('d m Y H i');
			$end_lockout = $limit_retries_valid["$user_ip"]['end_lockout'];
			if ($current_time == $end_lockout || $current_time > $end_lockout){
				unset($limit_retries_valid["$user_ip"]['end_lockout']);
				update_option( 'limit_retries_valid', $limit_retries_valid );
				$limit_retries = get_option('limit_retries');
				$limit_retries["$user_ip"] = 0;
				update_option( 'limit_retries', $limit_retries );
				return $user;
			}		
			else{
					$user =  new WP_Error( 'incorrect_password',
						sprintf(
							__( '<strong>ERROR</strong>: Too many failed login attempts. Please try again in ' .login_limit_message($time_lockout))
						) .
						' <a href="' . wp_lostpassword_url() . '">' .
						__( 'Lost your password?' ) .
						'</a>'
						);
						return $user;
			}

		}
		return $user;
	}
}

add_action('admin_menu', 'add_limit_page');
function add_limit_page(){
	add_options_page( 'Limit Login', 'Limit Login', 'manage_options', 'limit_slug', 'limit_options_page_output' );
}

function limit_options_page_output(){
	?>
	<div class="wrap">
		<h2><?php echo get_admin_page_title() ?></h2>

		<form action="options.php" method="POST">
			<?php
				settings_fields( 'limi_option_group' );     

				do_settings_sections( 'limit_page' ); 

				submit_button();
			?>
		</form>
	</div>
	<?php
}

add_action('admin_init', 'limit_settings');
function limit_settings(){
	
	register_setting( 'limi_option_group', 'option_lockout', 'limit_sanitize_callback' );

	add_settings_section( 'section_id', 'Options', '', 'limit_page' ); 

	add_settings_field('primer_field1', 'Lockout', 'fill_limit_field1', 'limit_page', 'section_id' );
}

function fill_limit_field1(){
	$default = array ( 'count_retries' => 3, 'time_lockout' => 1, 'count_lockouts' => 5, 'increase_lockout' => 1 );
	$val = get_option('option_lockout', $default);
	?>
	<input type="text" name="option_lockout[count_retries]" value="<?php echo esc_attr( $val['count_retries'] ) ?>" /><label> allowed retries</label></br>
	<input type="text" name="option_lockout[time_lockout]" value="<?php echo esc_attr( $val['time_lockout'] ) ?>" /><label> minutes lockout</label></br>
	<input type="text" name="option_lockout[count_lockouts]" value="<?php echo esc_attr( $val['count_lockouts'] ) ?>" /><label> lockouts increase lockout time to</label>
	 <input type="text" name="option_lockout[increase_lockout]" value="<?php echo esc_attr( $val['increase_lockout'] ) ?>" />hours 
	<?php
}

function limit_sanitize_callback( $options ){ 

	foreach( $options as $name => & $val ){
		$val = strip_tags( $val );
		$val = preg_replace( '/[^,.0-9]/', '', $val );
	}

	return $options;
}

function limit_settings_link($links) { 
  $settings_link = '<a href="options-general.php?page=limit_slug">Settings</a>'; 
  array_unshift($links, $settings_link); 
  return $links; 
}

$plugin = plugin_basename(__FILE__); 
add_filter("plugin_action_links_$plugin", 'limit_settings_link' );
?>