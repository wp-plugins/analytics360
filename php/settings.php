
<p>
	This is version <span class="a360-version-num"><?php echo $a360_version;?></span>.
	<a href="http://wordpress.org/extend/plugins/analytics360/faq/">FAQ</a> | <a href="http://www.mailchimp.com/support/contact">Feedback</a>
</p>
<p>Just <strong>two quick steps</strong> before you can view your Google Analytics and MailChimp stats in WordPress &hellip;</p>

<ol class="a360-connection-steps">
	<li>
		<ul class="a360-tabs">
			<li id="a360-create-account-tab">I need to create an account</li>
			<li id="a360-have-account-tab" class="a360-selected">I have an account</li>
		</ul>
		<h3 id="a360-connect-to-mailchimp-head" class="a360-subhead<?php echo ($a360_has_key ? ' complete' : ''); ?>">
			1) Connect to MailChimp
		</h3>
		<ul class="a360-tab-contents">
			<li id="a360-have-account-content">

				<form id="a360_mc_login_form" name="a360-settings-form" action="<?php bloginfo('wpurl');?>/wp-admin/options-general.php" method="post">
					<input type="hidden" name="a360_action" value="update_mc_api_key" />
					<fieldset class="options">
						<p class="a360-want-key"<?php echo ($a360_has_key ? ' style="display:none;"' : '');?>>
							Enter your MailChimp username and password to generate an API key. This key will power Analytics360°.
						</p>
						<p class="a360-has-key"<?php echo (!$a360_has_key ? ' style="display:none;"' : '');?>>
							Your API key powers Analytics360°.
						</p>
						<div class="option a360-has-key"<?php echo (!$a360_has_key ? ' style="display:none;"' : '');?>>
							<label for="a360_api_key">API Key</label>
							<input disabled="disabled" size="32" value="<?php echo $a360_api_key;?>" id="a360_api_key" name="a360_api_key" />
							<div class="clear"></div>
						</div>
						<div class="option a360-want-key"<?php echo ($a360_has_key ? ' style="display:none;"' : '');?>'>
							<label for="a360_username">Username</label>
							<input value="" id="a360_username" name="a360_username" />
							<div class="clear"></div>
						</div>
						<div class="option a360-want-key"<?php echo ($a360_has_key ? ' style="display:none;"' : '');?>>
							<label for="a360_password">Password</label>
							<input type="password" value="" id="a360_password" name="a360_password" />
							<div class="clear"></div>
						</div>
					</fieldset>
					
					<p class="submit a360-want-key" <?php echo ($a360_has_key ? ' style="display:none;"' : '');?>>
						<input type="submit" name="submit" value="<?php echo __('Submit', 'a360');?>" id="a360-submit-mc-userpass"/>
					</p>
				</form>
				
				<form id="a360-clear-mc-api-key" action="" method="post" class="a360-has-key" <?php echo (!$a360_has_key ? ' style="display:none;"' : '');?>>
					<input type="hidden" name="a360_action" value="clear_mc_api_key" />
					<p>
						<a id="generate-new-link" href="javascript:;">Connect to a different account</a>, 
						or just <input type="submit" value="Forget This API Key" class="button" />
					</p>
				</form>

				<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery('#generate-new-link').click(function() {
							jQuery('.a360-want-key').show();
							jQuery('.a360-has-key').hide();
							jQuery('#a360_api_key').val('');
						});
					});
				</script>

			</li>
			<li id="a360-create-account-content" style="display:none;">
				<iframe frameborder="0" style="width:950px; height:450px; margin:0 auto;" src="http://www.mailchimp.com/signup/wpa_signup/"></iframe>
			</li>
		</ul>
	</li>
	<li>
<?php
		if (empty($a360_ga_token)) {
			$authenticate_url = 'https://www.google.com/accounts/AuthSubRequest?'.http_build_query(array(
				'next' => trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?a360_action=capture_ga_token',
				'scope' => 'https://www.google.com/analytics/feeds/',
				'secure' => 0,
				'session' => 1
			));
		}
		else {
			$url = 'https://www.google.com/analytics/feeds/accounts/default';
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: AuthSub token=".$a360_ga_token));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$result = curl_exec($ch);
			$connection_error = '';
			if ($result === false) {
				$connection_error = curl_error($ch);
				curl_close($ch);
			}
			else {
				$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				$ga_auth_error = '';
				if($http_code != 200) {
					$ga_auth_error = $result;
				}
				else {
					$xml = simplexml_load_string($result);
					$profiles = array();
					foreach($xml->entry as $entry) {
						$properties = array();
						$children = $entry->children('http://schemas.google.com/analytics/2009');
						foreach($children->property as $property) {
							$attr = $property->attributes();
							$properties[str_replace('ga:','',$attr->name)] = strval($attr->value);
						}
						$properties['title'] = strval($entry->title);
						$properties['updated'] = strval($entry->updated);
						$profiles[$properties['profileId']] = $properties;
					}
					if (count($profiles)) {
						global $a360_ga_profile_id;
						if (empty($a360_ga_profile_id)) {
							$a360_ga_profile_id = $properties['profileId'];	// just use the last one
							update_option('a360_ga_profile_id', $a360_ga_profile_id);
						}
						if (count($profiles) > 1) {
							$profile_options = array();
							foreach ($profiles as $id => $profile) {
								$profile_options[] = '<option value="'.$id.'"'.($a360_ga_profile_id == $id ? 'selected="selected"' : '').'>'.$profile['title'].'</option>';
							}
						}
					}
					else {
					}
				}
			}
		}

?>
		<h3 id="a360-connect-to-google-head" class="a360-subhead<?php echo (!empty($a360_ga_token) ? ' complete' : '') ?>">
			2) Connect to Google Analytics
		</h3>

<?php if (empty($a360_ga_token)) : ?>

		<strong>Authenticate with Google.</strong><br/>
		Follow this link to be taken to Google's authentication page. After logging in there, you will be returned to Analytics360°.<br/>
		<a href="<?php echo $authenticate_url; ?>">Begin Authentication</a>

<?php else : ?>

	<?php if (!empty($ga_auth_error)) : ?>

		<strong>Hmm. Something's wrong with your Google authentication! <span style="color:red;"><?php echo $ga_auth_error;?></span>.</strong>

	<?php elseif (!empty($connection_error)) : ?>
	
		<strong>Darn! You should have access to an account, but we couldn't connect to google! The error was: <span style="color:red;"><?php echo $connection_error; ?></span></strong>
		
	<?php else : ?>

		<strong>Yippee! We can do some Google analytics tracking!</strong>
		
		<?php if (count($profiles)) : ?>
		
			<p>
				You have <?php echo count($profiles); ?> profiles in your account. 
				Currently you're tracking 
				<strong>
					<a href="https://www.google.com/analytics/reporting/?id=<?php echo $a360_ga_profile_id; ?>"><?php echo $profiles[$a360_ga_profile_id]['title']; ?></a>
				</strong>
				<?php echo (count($profiles) > 1 ? ', but you can change that if you\'d like.' :'.'); ?>
			</p>

			<?php if (count($profiles) > 1) : ?>
					<form action="" method="post">
						<input type="hidden" name="a360_action" value="set_ga_profile_id" />
						<label for="a360-profile-id-select">From now on track:</label>
						<select id="a360-profile-id-select" name="profile_id">
							<?php echo implode("\n", $profile_options); ?>
						</select>
						<input type="submit" class="button" value="This one!" />
					</form>
			<?php endif; ?>

		<?php else :  /* if (count($profiles)) */ ?>

			<p>
				You do not have any profiles associated with your Google Analytics account. Probably better
				<a href="https://www.google.com/analytics">head over there</a> and set one up!
			</p>

		<?php endif; /* if (count($profiles)) */ ?>

	<?php endif; /* if (empty($ga_auth_error)) */ ?>
	
	<form action="" method="post">
		<input type="hidden" name="a360_action" value="revoke_ga_token" />
		<a id="a360-revoke-ga-auth-link" href="javascript:;">Want to revoke access to this analytics account?</a>
		<div id="a360-revoke-ga-auth-container" style="display:none;">
			<label for="a360-revoke-ga-auth">Press this button to revoke Analytics360° access to your Google Analytics account: </label>
			<input id="a360-revoke-ga-auth" class="button" type="submit" value="Revoke!"/>
		</div>
	</form>
	
	
<?php endif; /* if (!empty($a360_ga_token)) */ ?>
	</li>
</ol>
<script type="text/javascript">
	jQuery(document).ready(function() {
		jQuery('.a360-tabs li').click(function() {
			var id = jQuery(this).attr('id');
			jQuery('.a360-tab-contents li').hide('fast');
			jQuery('#' + id.substring(0, id.indexOf('-tab')) + '-content').show('fast');
			jQuery(this).addClass('a360-selected').siblings().removeClass('a360-selected');
			return false;
		});
		jQuery('#a360-revoke-ga-auth-link').click(function() {
			jQuery('#a360-revoke-ga-auth-container').slideDown();
			return false;
		})
	});
</script>