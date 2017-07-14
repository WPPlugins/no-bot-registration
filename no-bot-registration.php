<?php
/*
Plugin Name: No-Bot Registration
Plugin URI: https://ajdg.solutions/products/no-bot-registration/?pk_campaign=nobot&pk_kwd=plugin-url
Author: Arnan de Gans
Author URI: http://www.arnan.me/?pk_campaign=nobot&pk_kwd=author-url
Description: Prevent people from registering by blacklisting emails and present people with a security question when registering or posting a comment.
Version: 1.2.1
*/

/* ------------------------------------------------------------------------------------
*  COPYRIGHT NOTICE
*  Copyright 2014-2017 Arnan de Gans. All Rights Reserved.

*  COPYRIGHT NOTICES AND ALL THE COMMENTS SHOULD REMAIN INTACT.
*  By using this code you agree to indemnify Arnan de Gans from any
*  liability that might arise from it's use.

*  This software borrows some methods from and is inspired by:
*  - Banhammer (Mika Epstein)
*  - WP No Bot Question (digicompitech).
------------------------------------------------------------------------------------ */

register_activation_hook(__FILE__, 'ajdg_nobot_activate');
register_uninstall_hook(__FILE__, 'ajdg_nobot_remove');

add_action('init', 'ajdg_nobot_init');
add_action('admin_menu', 'ajdg_nobot_adminmenu');
add_action("admin_print_styles", 'ajdg_nobot_dashboard_styles');
add_action('admin_notices', 'ajdg_nobot_notifications_dashboard');
add_filter('plugin_action_links_' . plugin_basename( __FILE__ ), 'ajdg_nobot_action_links');

// Protect comments
add_action('comment_form_after_fields', 'ajdg_nobot_comment_field');
add_action('comment_form_logged_in_after', 'ajdg_nobot_comment_field');
add_filter('preprocess_comment', 'ajdg_nobot_filter');

// Protect the registration form (Including custom registration in theme)
add_action('register_form', 'ajdg_nobot_registration_field');
add_action('user_registration_email', 'ajdg_nobot_filter');
add_action('register_post', 'ajdg_nobot_blacklist', 10, 3);

if(in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	// Protect WooCommerce My-Account page
	add_action('woocommerce_register_form', 'ajdg_nobot_woocommerce_field');

	// Protect WooCommerce Registration on checkout
	add_action('woocommerce_after_checkout_registration_form', 'ajdg_nobot_woocommerce_field');
	add_action('woocommerce_register_post', 'ajdg_nobot_filter', 10 ,3);
	add_action('woocommerce_register_post', 'ajdg_nobot_blacklist', 10, 3);
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_activate
 Purpose: 	Activation/setup script
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_activate() {
	add_option('ajdg_nobot_protect', array('registration' => 1, 'comment' => 1, 'woocommerce' => 1));
	add_option('ajdg_nobot_questions', array('What is the sum of 2 and 7?'));
	add_option('ajdg_nobot_answers', array(array('nine','Nine','9')));
	add_option('ajdg_nobot_blacklist_message', 'Your email has been banned from registration! Try using another email address or contact support for a solution.');
	add_option('ajdg_nobot_security_message', 'Please fill in the correct answer to the security question!');
	add_option('ajdg_nobot_hide_review', current_time('timestamp'));

	$blacklist = explode("\n", get_option('blacklist_keys')); // wp core option
	$blacklist = array_merge($blacklist, array('hotmail', 'yahoo', '.cn', '.info', '.biz'));
	sort($blacklist);
	$blacklist = implode("\n", array_unique($blacklist));
	update_option('blacklist_keys', $blacklist);
	unset($blacklist);
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_remove
 Purpose: 	uninstall script
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_remove() {
	delete_option('ajdg_nobot_protect');
	delete_option('ajdg_nobot_questions');
	delete_option('ajdg_nobot_answers');
	delete_option('ajdg_nobot_blacklist_message');
	delete_option('ajdg_nobot_security_message');
	delete_option('ajdg_nobot_hide_review');
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_init
 Purpose: 	Initialize
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_init() {
	load_plugin_textdomain('ajdg-nobot', false, basename(dirname(__FILE__)) . '/language');
	wp_enqueue_script('jquery', false, false, false, true);
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_adminmenu
 Purpose: 	Set up dashboard menu
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_adminmenu() {
	add_submenu_page('options-general.php', 'No-Bot Registration &rarr; Settings', 'No-Bot Registration', 'moderate_comments', 'ajdg-nobot-settings', 'ajdg_nobot_admin');
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_action_links
 Purpose:	Plugin page link
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_action_links($links) {
	$custom_actions = array();
	$custom_actions['nobot'] = sprintf('<a href="%s" target="_blank">%s</a>', 'https://ajdg.solutions/forums/?pk_campaign=nobot&pk_kwd=support', 'Support');

	return array_merge($custom_actions, $links);
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_dashboard_styles
 Purpose: 	Add security field to comment form
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_dashboard_styles() {
	wp_enqueue_style('ajdg-nobot-admin-stylesheet', plugins_url('library/dashboard.css', __FILE__));
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_comment_field
 Purpose: 	Add security field to comment form
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_comment_field() {
	ajdg_nobot_field('comment');
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_registration_field
 Purpose: 	Add security field to registration form
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_registration_field() {
	ajdg_nobot_field('registration');
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_woocommerce_field
 Purpose: 	Add security field to WooCommerce Checkout
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_woocommerce_field() {
	ajdg_nobot_field('woocommerce');
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_field
 Purpose: 	Format the security field and put a random question in there
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_field($context = 'comment') {
	$protect = get_option('ajdg_nobot_protect');

	if(current_user_can('editor') 
		OR current_user_can('administrator') 
		OR ($context == 'registration' AND !$protect['registration']) 
		OR ($context == 'comment' AND !$protect['comment']) 
		OR ($context == 'woocommerce' AND !$protect['woocommerce'])
	) return;
	?>
	<p class="comment-form-ajdg_nobot">
		<?php
		$questions = get_option('ajdg_nobot_questions');
		$answers = get_option('ajdg_nobot_answers');
		$selected_id = rand(0, count($questions)-1);
		?>
		<label for="ajdg_nobot_answer"><?php echo htmlspecialchars($questions[$selected_id]); ?> (Required)</label>
		<input id="ajdg_nobot_answer" name="ajdg_nobot_answer" type="text" value="" size="30" <?php if($context == 'registration') { ?> tabindex="25" <?php }; ?>/>
		<input type="hidden" name="ajdg_nobot_id" value="<?php echo $selected_id; ?>" />
		<input type="hidden" name="ajdg_nobot_hash" value="<?php echo ajdg_nobot_security_hash($selected_id, $questions[$selected_id], $answers[$selected_id]); ?>" />
	</p>
<?php
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_filter
 Purpose: 	Check the given answer and respond accordingly
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_filter($user_login, $user_email = '', $errors = '') {

	$protect = get_option('ajdg_nobot_protect');
	$security_message = get_option('ajdg_nobot_security_message');

	if(current_user_can('editor') 
		OR current_user_can('administrator') 
		OR (!is_array($user_login) AND (!$protect['registration'] OR !$protect['woocommerce'])) 
		OR (is_array($user_login) AND (!$protect['comment'] OR ($user_login['comment_type'] == 'pingback' OR $user_login['comment_type'] == 'trackback')))
	) return $user_login;

	if(!array_key_exists('ajdg_nobot_answer', $_POST) OR !array_key_exists('ajdg_nobot_id', $_POST) OR trim($_POST['ajdg_nobot_answer']) == '') {
		if(!is_array($user_login)) {
			$errors->add('nobot_answer_empty', $security_message);
		} else {
			wp_die("<p class=\"error\">$security_message</p>");
		}
	}

	$question_id = intval($_POST['ajdg_nobot_id']);
	$questions_all = get_option('ajdg_nobot_questions');
	$answers_all = get_option('ajdg_nobot_answers');

	// Hash verification to make sure the bot isn't picking on one answer.
	// This does not mean that they got the question right.
	if(trim($_POST['ajdg_nobot_hash']) != ajdg_nobot_security_hash($question_id, $questions_all[$question_id], $answers_all[$question_id])) {
		if(!is_array($user_login)) {
			$errors->add('nobot_answer_hash', $security_message);
		} else {
			wp_die("<p class=\"error\">$security_message</p>");
		}
	}

	// Verify the answer.
	if($question_id < count($answers_all)) {
		$answers = $answers_all[$question_id];
		foreach($answers as $answer) {
			if(trim($_POST['ajdg_nobot_answer']) == $answer) return $user_login;
		}
	}

	// As a last resort - Just fail
	if(!is_array($user_login)) {
		$errors->add('nobot_answer_fail', $security_message);
	} else {
		wp_die("<p class=\"error\">$security_message</p>");
	}
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_blacklist
 Purpose: 	Check for banned emails on registration
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_blacklist($user_login, $user_email, $errors) {
    $blacklist = get_option('blacklist_keys'); // wp core option
    $blacklist_message = get_option('ajdg_nobot_blacklist_message');

    $blacklist_array = explode("\n", $blacklist);
    $blacklist_size = sizeof($blacklist_array);

    // Go through blacklist
    for($i = 0; $i < $blacklist_size; $i++) {
        $blacklist_current = trim($blacklist_array[$i]);
        if(stripos($user_email, $blacklist_current) !== false) {
			$errors->add('invalid_email', $blacklist_message);

            return;
        }
    }
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_security_hash
 Purpose: 	Generate security hash used in question verification
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_security_hash($id, $question, $answer) {
	/*
	 * Hash format: SHA256( Question ID + Question Title + serialize( Question Answers ) )
	 */
	$hash_string = strval($id).strval($question).serialize($answer);
	return hash('sha256', $hash_string);
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_template
 Purpose: 	Settings questions listing
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_template($id_, $question, $answers) {
	$id = intval($id_);
?>
	<tr valign="top" class="ajdg_nobot_row_<?php echo $id; ?>">
		<th scope="row"><?php _e('Question:', 'ajdg-nobot'); ?></th>
		<td>
			<input type="input" name="ajdg_nobot_question_<?php echo $id; ?>" size="70" value="<?php echo htmlspecialchars($question); ?>" placeholder="Type here to add a new question" /> <a href="javascript:void(0)" onclick="ajdg_nobot_delete_entire_question(&quot;<?php echo $id ?>&quot;)"><?php _e('Delete Question', 'ajdg-nobot'); ?></a>
		</td>
	</tr>
	<tr valign="top" class="ajdg_nobot_row_<?php echo $id; ?>">
		<th scope="row"><?php _e('Possible Answers:', 'ajdg-nobot'); ?></th>
		<td>
			<?php
			$i = 0;
			foreach($answers as $value) {
				echo "<span id=\"ajdg_nobot_line_{$id}_$i\">";
				printf('<input type="input" id="ajdg_nobot_answer_%1$d_%2$d" name="ajdg_nobot_answers_%1$d[]" size="70" value="%3$s" />', $id, $i, htmlspecialchars($value));
				echo " <a href=\"javascript:void(0)\" onclick=\"ajdg_nobot_delete(&quot;$id&quot;, &quot;$i&quot;)\">Delete</a>";
				echo "<br /></span>\n";
				$i++;
			}
			echo "<script id=\"ajdg_nobot_placeholder_$id\">ct[$id] = $i;</script>";
			?>
			<button onclick="return ajdg_nobot_add_newitem(<?php echo $id; ?>)" class="button-secondary"><?php _e('Add New', 'ajdg-nobot'); ?></button>
		</td>
	</tr>
<?php
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_admin
 Purpose: 	Admin screen and save settings
 Since:		1.0
-------------------------------------------------------------*/
function ajdg_nobot_admin() {
	if(!current_user_can('moderate_comments')) return;

	if(isset($_POST['submit'])) {
		$questions = $answers = $protect = array();

		$protect['registration'] = (isset($_POST['ajdg_nobot_registration'])) ? 1 : 0;
		$protect['comment'] = (isset($_POST['ajdg_nobot_comment'])) ? 1 : 0;
		$protect['woocommerce'] = (isset($_POST['ajdg_nobot_woocommerce'])) ? 1 : 0;

		foreach($_POST as $key => $value) {
			if(strpos($key, 'ajdg_nobot_question_') === 0) {
				// value starts with ajdg_nobot_question_ (form field name)
				$q_id = str_replace('ajdg_nobot_question_', '', $key);
				if(trim(strval($value)) != '') { // if not empty
					$question_slashed = trim(strval($value));
					// WordPress seems to add quotes by default:
					$questions[] = stripslashes($question_slashed);
					$answers_slashed = array_filter($_POST['ajdg_nobot_answers_' . $q_id]);
					foreach($answers_slashed as $key => $value) {
						$answers_slashed[$key] = stripslashes($value);
					}
					$answers[] = $answers_slashed;
				}
			}
		}

		update_option('ajdg_nobot_protect', $protect);
		update_option('ajdg_nobot_questions', $questions);
		update_option('ajdg_nobot_answers', $answers);

		if(isset($_POST['ajdg_nobot_security_message'])) {
			update_option('ajdg_nobot_security_message', sanitize_text_field($_POST['ajdg_nobot_security_message']));
		}

		if(isset($_POST['ajdg_nobot_blacklist_message'])) {
			update_option('ajdg_nobot_blacklist_message', sanitize_text_field($_POST['ajdg_nobot_blacklist_message']));
		}

		if(isset($_POST['ajdg_nobot_blacklist'])) {
			$blacklist_new_keys = strip_tags(htmlspecialchars($_POST['ajdg_nobot_blacklist'], ENT_QUOTES));
			$blacklist_array = explode("\n", $blacklist_new_keys);
			sort($blacklist_array);
			update_option('blacklist_keys', implode("\n", array_unique($blacklist_array)));
		}
		
		add_settings_error('ajdg_nobot', 'ajdg_nobot_updated', 'Settings updated.', 'updated');
	}

	$ajdg_nobot_protect = get_option('ajdg_nobot_protect');
	$ajdg_nobot_questions = get_option('ajdg_nobot_questions');
	$ajdg_nobot_answers = get_option('ajdg_nobot_answers');
    $ajdg_nobot_blacklist = get_option('blacklist_keys'); // WP Core
    $ajdg_nobot_blacklist_message = get_option('ajdg_nobot_blacklist_message');
    $ajdg_nobot_security_message = get_option('ajdg_nobot_security_message');
	?>
	<div class="wrap">
		<h2><?php _e('No-Bot Registration settings', 'ajdg-nobot'); ?></h2>
		<?php settings_errors(); ?>

		<div id="dashboard-widgets-wrap">
			<div id="dashboard-widgets" class="metabox-holder">
	
				<div id="postbox-container-1" class="postbox-container" style="width:50%;">
					<div id="normal-sortables" class="meta-box-sortables ui-sortable">
						
						<h3><?php _e('No-Bot Registration by Arnan de Gans', 'ajdg-nobot'); ?></h3>
						<div class="postbox-ajdg">
							<div class="inside">
						    	<p><?php _e('Set up a unintrusive security question into our registration and login pages that is just as effective as a regular captcha, but much more user friendly. On top of that, you can ban email addresses from being used for accounts.', 'ajdg-nobot'); ?></p>

								<p><strong><?php _e('Support No-Bot Registration', 'ajdg-nobot'); ?></strong></p>
								<p><?php _e('Consider writing a review or making a donation if you like the plugin or if you find the plugin useful. Thanks for your support!', 'ajdg-nobot'); ?><br />
								<center><a class="button-primary" href="https://paypal.me/arnandegans/5usd" target="_blank">Donate $5 via Paypal</a> <a class="button" target="_blank" href="https://wordpress.org/support/plugin/ajdg-no-bot/reviews/?rate=5#new-post">Write review on WordPress.org</a></center><br />
								<script>(function(d, s, id) {
								  var js, fjs = d.getElementsByTagName(s)[0];
								  if (d.getElementById(id)) return;
								  js = d.createElement(s); js.id = id;
								  js.src = "https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v2.5";
								  fjs.parentNode.insertBefore(js, fjs);
								}(document, 'script', 'facebook-jssdk'));</script>
								<p><center><div class="fb-page" 
									data-href="https://www.facebook.com/Arnandegans" 
									data-width="490" 
									data-adapt-container-width="true" 
									data-hide-cover="false" 
									data-show-facepile="false">
								</div></center></p>
							</div>
						</div>
					</div>
				</div>
	
				<div id="postbox-container-3" class="postbox-container" style="width:50%;">
					<div id="side-sortables" class="meta-box-sortables ui-sortable">
								
						<h3><?php _e('Arnan de Gans News & Updates', 'ajdg-nobot'); ?></h3>
						<div class="postbox-ajdg">
							<div class="inside">
								<?php wp_widget_rss_output(array(
									'url' => array('http://ajdg.solutions/feed/'), 
									'items' => 3, 
									'show_summary' => 1, 
									'show_author' => 0, 
									'show_date' => 1)
								); ?>
							</div>
						</div>
		
					</div>	
				</div>
			</div>
		</div>

		<h3><?php _e('Registration protection', 'ajdg-nobot'); ?></h3>

		<form method="post" name="ajdg_nobot_form">
		<?php settings_fields('ajdg_nobot_question'); ?>

		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Protect registration page?', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<input type="checkbox" name="ajdg_nobot_registration" value="1" <?php if($ajdg_nobot_protect['registration']) echo 'checked="checked"' ?> /> <em><?php _e('Add a security question to your registration form (Including custom ones in your theme).', 'ajdg-nobot'); ?></em>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Protect comment form?', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<input type="checkbox" name="ajdg_nobot_comment" value="1" <?php if($ajdg_nobot_protect['comment']) echo 'checked="checked"' ?> /> <em><?php _e('Add a security question to your comment form.', 'ajdg-nobot'); ?></em>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Protect WooCommerce?', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<input type="checkbox" name="ajdg_nobot_woocommerce" value="1" <?php if($ajdg_nobot_protect['woocommerce']) echo 'checked="checked"' ?> /> <em><?php _e('Add a security question to your WooCommerce registration and checkout page (If you allow people to register accounts from there, has no effect if WooCommerce is not installed).', 'ajdg-nobot'); ?></em>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Failure message:', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<textarea name='ajdg_nobot_security_message' cols='80' rows='2'><?php echo stripslashes($ajdg_nobot_security_message); ?></textarea>
						<p><em><?php _e('Displayed to those who fail the security question. Keep it short and simple.', 'ajdg-nobot'); ?></em></p>
					</fieldset>
				</td>
			</tr>
			<script type="text/javascript">
			var ct = Array();
			function ajdg_nobot_delete(id, x) {
				jQuery("#ajdg_nobot_line_" + id + "_" + x).remove();
			}
		
			function ajdg_nobot_delete_entire_question(id) {
				jQuery("tr.ajdg_nobot_row_" + id).remove();
			}
		
			function ajdg_nobot_add_newitem(id) {
				jQuery("#ajdg_nobot_placeholder_" + id).before("<span id=\"ajdg_nobot_line_" + id + "_" + ct[id] + "\"><input type=\"input\" id=\"ajdg_nobot_answer_" + id + "_" + ct + "\" name=\"ajdg_nobot_answers_" + id + "[]\" size=\"70\" value=\"\" placeholder=\"Enter a new answer here\" /> <a href=\"javascript:void(0)\" onclick=\"ajdg_nobot_delete(&quot;" + id + "&quot;, &quot;" + ct[id] + "&quot;)\">Delete</a><br /></span>");
				ct[id]++;
				return false;
			}
			</script>
			<?php
			$i = 0;
			foreach($ajdg_nobot_questions as $question) {
				ajdg_nobot_template($i, $question, $ajdg_nobot_answers[$i]);
				$i++;
			}
			ajdg_nobot_template($i, '', Array());
			?>
		</table>
		
		<h3><?php _e('Blacklisted e-mail domains', 'ajdg-nobot'); ?></h3>
		<p><em><?php _e('If you get many fake accounts or paid robots registering you can blacklist their email addresses or domains to prevent them from adding multiple accounts.', 'ajdg-nobot'); ?></em></p>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Blacklist message:', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<textarea name='ajdg_nobot_blacklist_message' cols='80' rows='2'><?php echo stripslashes($ajdg_nobot_blacklist_message); ?></textarea>
						<p><em><?php _e('This message is shown to users who are not allowed to register on your site. Keep it short and simple.', 'ajdg-nobot'); ?></em></p>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e('Blacklisted emails:', 'ajdg-nobot'); ?></th>
				<td>
					<fieldset>
						<textarea name='ajdg_nobot_blacklist' cols='80' rows='10'><?php echo stripslashes($ajdg_nobot_blacklist); ?></textarea>
						<p><em><?php _e('You can add: full emails (someone@hotmail.com), domains (hotmail.com) or simply a keyword (hotmail).', 'ajdg-nobot'); ?><br /><?php _e('One item per line! Add as many items as you need.', 'ajdg-nobot'); ?><br />
						<strong><?php _e('Caution:', 'ajdg-nobot'); ?></strong> <?php _e('This is a powerful filter matching partial words. So banning "mail" will also block Gmail users!', 'ajdg-nobot'); ?></em></p>
					</fieldset>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
		</form>
	</div>
<?php
}

/*-------------------------------------------------------------
 Name:      ajdg_nobot_notifications_dashboard
 Since:		1.1
-------------------------------------------------------------*/
function ajdg_nobot_notifications_dashboard() {
	if(isset($_GET['hide'])) {
		if($_GET['hide'] == 1) update_option('ajdg_nobot_hide_review', 1);
	}

	$review_banner = get_option('ajdg_nobot_hide_review');
	if($review_banner != 1 AND $review_banner < (current_time('timestamp') - 2419200)) {
		echo '<div class="updated" style="padding: 0; margin: 0;">';
		echo '	<div class="ajdg_nobot_notification">';
		echo '		<div class="button_div"><a class="button" target="_blank" href="https://wordpress.org/support/plugin/no-bot-registration/reviews/?rate=5#postform">Rate Plugin</a></div>';
		echo '		<div class="text">If you like <strong>No-Bot Registration</strong> let the world know that you do. Thanks for your support!<br /><span>If you have questions, suggestions or something else that doesn\'t belong in a review, please <a href="https://ajdg.solutions/forums/forum/no-bot-registration/" target="_blank">get in touch</a>!</span></div>';
		echo '		<a class="close_notification" href="options-general.php?page=ajdg-nobot-settings&hide=1"><img title="Close" src="'.plugins_url('/images/icon-close.png', __FILE__).'" alt=""/></a>';
		echo '		<div class="icon"><img title="Logo" src="'.plugins_url('/images/ajdg-logo.png', __FILE__).'" alt=""/></div>';
		echo '	</div>';
		echo '</div>';
	}
}
?>