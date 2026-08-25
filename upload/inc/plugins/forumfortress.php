<?php
/**
 * Forum Fortress for MyBB 1.8.
 *
 * Copyright (c) 2026 Forum Fortress.
 * Distributed under the Forum Fortress Plugin License, Version 1.0.
 */

if(!defined('IN_MYBB'))
{
	die('This file cannot be accessed directly.');
}

define('FORUMFORTRESS_MYBB_VERSION', '1.2');

global $plugins;
if(isset($plugins))
{
	$plugins->add_hook('global_start', 'forumfortress_background_bootstrap');
	$plugins->add_hook('datahandler_user_validate', 'forumfortress_validate_user');
	$plugins->add_hook('datahandler_user_insert_end', 'forumfortress_report_registration');
	$plugins->add_hook('datahandler_post_validate_post', 'forumfortress_validate_post');
	$plugins->add_hook('datahandler_post_validate_thread', 'forumfortress_validate_thread');
	$plugins->add_hook('datahandler_post_insert_post_end', 'forumfortress_report_post');
	$plugins->add_hook('datahandler_post_insert_thread_end', 'forumfortress_report_post');
	$plugins->add_hook('usercp_do_editsig_start', 'forumfortress_validate_signature');
	$plugins->add_hook('contact_do_start', 'forumfortress_validate_contact');
	if(defined('IN_ADMINCP'))
	{
		$plugins->add_hook('admin_config_menu', 'forumfortress_admin_menu');
		$plugins->add_hook('admin_config_plugins_begin', 'forumfortress_admin_page');
	}
}

function forumfortress_info()
{
	return array(
		'name' => 'Forum Fortress',
		'description' => 'Real-time spam protection for registrations, threads, replies, and profile edits. <a href="index.php?module=config-plugins&amp;action=forumfortress">Open Forum Fortress</a>.',
		'website' => 'https://forumfortress.com',
		'author' => 'Forum Fortress',
		'authorsite' => 'https://forumfortress.com',
		'version' => FORUMFORTRESS_MYBB_VERSION,
		'compatibility' => '18*',
		'codename' => 'forumfortress'
	);
}

/**
 * Add a first-class Configuration sidebar entry. The dashboard runs through
 * MyBB's Plugins module, retaining its established administrator access check.
 */
function forumfortress_admin_menu($sub_menu)
{
	$sub_menu['105'] = array(
		'id' => 'forumfortress',
		'title' => 'Forum Fortress',
		// The ACP sidebar escapes links when it renders them, so keep the query
		// separator raw here to avoid routing to an `amp;action` parameter.
		'link' => 'index.php?module=config-plugins&action=forumfortress',
	);
	return $sub_menu;
}

function forumfortress_install()
{
	global $db;
	$group = array(
		'name' => 'forumfortress',
		'title' => 'Forum Fortress',
		'description' => 'Forum Fortress protection, connection, and moderation settings.',
		'disporder' => 1,
		'isdefault' => 0,
	);
	$query = $db->simple_select('settinggroups', 'gid', "name='forumfortress'");
	$gid = (int)$db->fetch_field($query, 'gid');
	if(!$gid)
	{
		$gid = (int)$db->insert_query('settinggroups', array_map(array($db, 'escape_string'), $group));
	}

	$legacy_region = ForumFortressMyBB::api_region();
	// The old free-form URL is no longer administrator-configurable. Keep its
	// recognized regional intent, then remove the generated MyBB setting row.
	$db->delete_query('settings', "name='forumfortress_api_base_url'");
	$settings = array(
		'forumfortress_enabled' => array('Enabled', 'Enable Forum Fortress checks and background bootstrap.', 'yesno', '1'),
		'forumfortress_api_region' => array('API region', 'Lock check traffic to a region, or use the recommended global network.', "select\nglobal=Global - Recommended\nuk=United Kingdom only\neu=European Union only\nus=United States only", $legacy_region),
		'forumfortress_allow_global_fallback' => array('Allow global emergency fallback', 'After a regional retry fails, permit the global network. Processing may occur outside the selected region.', 'yesno', '0'),
		'forumfortress_control_base_url' => array('Control base URL', 'Forum Fortress control-plane base URL used for bootstrap and portal operations.', 'text', 'https://control.ffapi.net'),
		'forumfortress_timeout' => array('Request timeout', 'Maximum API request time in seconds (1–30).', 'numeric', '5'),
		'forumfortress_fail_open' => array('Fail open', 'Allow an action when Forum Fortress is unavailable. Disable only when your forum can tolerate API outages blocking registrations and content.', 'yesno', '1'),
		'forumfortress_api_key' => array('API key', 'Stored automatically after anonymous bootstrap, or paste a live key from Forum Fortress.', 'text', ''),
		'forumfortress_site_id' => array('Site ID', 'Stored automatically after bootstrap.', 'text', ''),
		'forumfortress_endpoint_state' => array('Endpoint state', 'Internal diagnostics state. Do not edit manually.', 'textarea', '{}'),
	);
	$order = 0;
	foreach($settings as $name => $setting)
	{
		++$order;
		$data = array(
			'name' => $db->escape_string($name), 'title' => $db->escape_string($setting[0]),
			'description' => $db->escape_string($setting[1]), 'optionscode' => $db->escape_string($setting[2]),
			'value' => $db->escape_string($setting[3]), 'disporder' => $order, 'gid' => $gid,
		);
		$query = $db->simple_select('settings', 'sid', "name='{$data['name']}'");
		$sid = (int)$db->fetch_field($query, 'sid');
		if($sid)
		{
			unset($data['value']);
			$db->update_query('settings', $data, "sid='{$sid}'");
		}
		else
		{
			$db->insert_query('settings', $data);
		}
	}

	if(!$db->table_exists('forumfortress_events'))
	{
		$collation = $db->build_create_table_collation();
		$db->write_query("CREATE TABLE ".TABLE_PREFIX."forumfortress_events (
			eid int unsigned NOT NULL auto_increment,
			dateline int unsigned NOT NULL default 0,
			endpoint varchar(40) NOT NULL default '',
			decision varchar(20) NOT NULL default '',
			username varchar(120) NOT NULL default '',
			remote_id varchar(40) NOT NULL default '',
			reason varchar(255) NOT NULL default '',
			PRIMARY KEY (eid), KEY dateline (dateline)
		) ENGINE=MyISAM{$collation}");
	}
	rebuild_settings();
}

function forumfortress_is_installed()
{
	global $db;
	return $db->table_exists('forumfortress_events');
}

function forumfortress_activate()
{
	// Reconcile generated settings when an existing installation activates a
	// newer plugin release, without touching its identity or audit table.
	forumfortress_install();
}

function forumfortress_uninstall()
{
	global $db;
	$api_key = ForumFortressMyBB::setting('forumfortress_api_key');
	$site_id = ForumFortressMyBB::setting('forumfortress_site_id');
	if(trim($api_key) !== '' && trim($site_id) !== '')
	{
		try
		{
			// Remote cleanup is best effort: uninstall must remain possible when the
			// service is unavailable or the site was already removed.
			ForumFortressMyBB::deprovision($api_key, $site_id, ForumFortressMyBB::domain());
		}
		catch(Exception $e) {}
	}
	$db->delete_query('settings', "name LIKE 'forumfortress\\_%'");
	$db->delete_query('settinggroups', "name='forumfortress'");
	if($db->table_exists('forumfortress_events'))
	{
		$db->drop_table('forumfortress_events');
	}
	rebuild_settings();
}

function forumfortress_background_bootstrap()
{
	if(defined('IN_ADMINCP') || !ForumFortressMyBB::enabled() || !ForumFortressMyBB::background_bootstrap_allowed())
	{
		return;
	}
	try
	{
		ForumFortressMyBB::bootstrap_if_needed();
	}
	catch(Exception $e)
	{
		// Protection hooks apply the configured fail-open policy; a page request must never fail here.
	}
}

function forumfortress_validate_user($handler)
{
	global $mybb, $lang;
	if(!ForumFortressMyBB::enabled() || !is_object($handler) || !isset($handler->data) || !is_array($handler->data) || defined('IN_ADMINCP'))
	{
		return;
	}
	if(isset($handler->method) && $handler->method === 'update' && ForumFortressMyBB::has_profile_changes($handler->data))
	{
		$lang->userdata_forumfortress_blocked = 'Forum Fortress rejected this profile update.';
		$merged_user = array_merge(isset($mybb->user) && is_array($mybb->user) ? $mybb->user : array(), $handler->data);
		$payload = ForumFortressMyBB::profile_payload($merged_user);
		$endpoint = isset($handler->data['signature']) ? 'signature_edit' : 'profile_edit';
		if($endpoint === 'signature_edit' && !empty($GLOBALS['forumfortress_signature_checked'])) return;
		$response = ForumFortressMyBB::check($endpoint, $payload);
		$decision = ForumFortressMyBB::decision($response);
		ForumFortressMyBB::event($endpoint, $decision, $payload, $response);
		if(ForumFortressMyBB::reject($response)) $handler->set_error('forumfortress_blocked');
		return;
	}
	if(!isset($handler->method) || $handler->method !== 'insert' || empty($handler->data['registration'])) return;
	$lang->userdata_forumfortress_blocked = 'Forum Fortress rejected this registration.';
	$payload = ForumFortressMyBB::user_payload($handler->data);
	$response = ForumFortressMyBB::check('register', $payload);
	$decision = ForumFortressMyBB::decision($response);
	ForumFortressMyBB::event('register', $decision, $payload, $response);
	if(ForumFortressMyBB::reject($response))
	{
		$handler->set_error('forumfortress_blocked');
	}
}

function forumfortress_validate_signature()
{
	global $mybb;
	if(!ForumFortressMyBB::enabled() || defined('IN_ADMINCP') || !is_object($mybb)) return;
	$action = isset($mybb->input) && is_array($mybb->input) && isset($mybb->input['action']) ? $mybb->input['action'] : '';
	if($action !== 'do_editsig' || (isset($mybb->request_method) && $mybb->request_method !== 'post')) return;
	if(!empty($GLOBALS['forumfortress_signature_checked'])) return;
	$GLOBALS['forumfortress_signature_checked'] = true;
	$signature = method_exists($mybb, 'get_input') ? $mybb->get_input('signature') : (isset($mybb->input['signature']) ? $mybb->input['signature'] : '');
	$user = isset($mybb->user) && is_array($mybb->user) ? $mybb->user : array();
	$user['signature'] = $signature;
	$payload = ForumFortressMyBB::profile_payload($user);
	$response = ForumFortressMyBB::check('signature_edit', $payload);
	$decision = ForumFortressMyBB::decision($response);
	ForumFortressMyBB::event('signature_edit', $decision, $payload, $response);
	if(ForumFortressMyBB::reject($response) && function_exists('error')) error('Forum Fortress rejected this signature.');
}

function forumfortress_report_registration($handler)
{
	if(!ForumFortressMyBB::enabled() || !is_object($handler) || !isset($handler->data) || !is_array($handler->data) || defined('IN_ADMINCP'))
	{
		return;
	}
	$payload = ForumFortressMyBB::user_payload($handler->data);
	$uid = isset($handler->uid) ? $handler->uid : (isset($handler->data['uid']) ? $handler->data['uid'] : '');
	$account = $payload;
	unset($account['email']);
	$report = $payload;
	unset($report['email']);
	$report['payload'] = array('remote_user_id' => (string)$uid, 'account' => $account);
	ForumFortressMyBB::report('register', $report);
}

function forumfortress_validate_thread($handler)
{
	forumfortress_validate_post($handler, 'thread');
}

function forumfortress_validate_post($handler, $forced_type='')
{
	global $mybb, $lang;
	if(!ForumFortressMyBB::enabled() || !is_object($handler) || !isset($handler->data) || !is_array($handler->data) || defined('IN_ADMINCP'))
	{
		return;
	}
	if(!isset($handler->data['message']) && !isset($handler->data['subject'])) return;
	if(!empty($handler->data['savedraft']) || (isset($handler->data['visible']) && (string)$handler->data['visible'] === '-2')) return;
	$lang->postdata_forumfortress_blocked = 'Forum Fortress rejected this content.';
	$method = isset($handler->method) ? $handler->method : 'insert';
	$is_topic = $forced_type === 'thread' || (isset($handler->action) && $handler->action === 'thread');
	if($method === 'update' && !empty($handler->first_post)) $is_topic = true;
	$endpoint = $is_topic ? 'topic' : 'reply';
	if($method === 'update')
	{
		$endpoint .= '_edit';
	}
	$payload = ForumFortressMyBB::post_payload($handler->data);
	// CheckRequest has no separate subject field. Include a topic subject in the
	// scanned content so subject-only edits cannot bypass content protection.
	if($is_topic && isset($payload['subject']) && trim($payload['subject']) !== '')
	{
		$payload['content'] = trim($payload['subject'])."\n".$payload['content'];
		$payload['links'] = ForumFortressMyBB::links($payload['content']);
	}
	$response = ForumFortressMyBB::check($endpoint, $payload);
	$decision = ForumFortressMyBB::decision($response);
	ForumFortressMyBB::event($endpoint, $decision, $payload, $response);
	if(ForumFortressMyBB::reject($response))
	{
		$handler->set_error('forumfortress_blocked');
		return;
	}
}

function forumfortress_report_post($handler)
{
	if(!ForumFortressMyBB::enabled() || !is_object($handler) || defined('IN_ADMINCP') || !ForumFortressMyBB::reportable_post($handler))
	{
		return;
	}
	$post = ForumFortressMyBB::post_payload($handler->data);
	$payload = array(
		'forum_id' => $post['forum_id'],
		'username' => $post['username'],
		'email_domain' => $post['email_domain'],
		'ip' => $post['ip'],
		'links' => $post['links'],
		'content_hash' => sha1($post['content']),
		'payload' => array(
			'content' => $post['content'],
			'subject' => isset($post['subject']) ? $post['subject'] : '',
			'content_type' => (isset($handler->action) && $handler->action === 'thread') ? 'thread' : 'post',
			'post_id' => (string)(isset($handler->pid) ? $handler->pid : (isset($post['content_id']) ? $post['content_id'] : '')),
			'thread_id' => (string)(isset($handler->tid) ? $handler->tid : (isset($post['thread_id']) ? $post['thread_id'] : '')),
		),
	);
	ForumFortressMyBB::report('moderation', $payload);
}

function forumfortress_validate_contact()
{
	global $mybb;
	if(!ForumFortressMyBB::enabled()) return;
	$user = isset($mybb->user) && is_array($mybb->user) ? $mybb->user : array();
	$input = isset($mybb->input) && is_array($mybb->input) ? $mybb->input : array();
	$email = isset($input['email']) ? $input['email'] : (isset($user['email']) ? $user['email'] : '');
	$message = isset($input['message']) ? (string)$input['message'] : '';
	$subject = isset($input['subject']) ? (string)$input['subject'] : '';
	$payload = array('ip' => ForumFortressMyBB::ip(), 'username' => (string)(isset($user['username']) ? $user['username'] : ''), 'email' => (string)$email, 'email_domain' => ForumFortressMyBB::email_domain($email), 'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 'content' => trim($subject)."\n".$message, 'links' => ForumFortressMyBB::links($message));
	$response = ForumFortressMyBB::check('contact_page', $payload);
	$decision = ForumFortressMyBB::decision($response);
	ForumFortressMyBB::event('contact_page', $decision, $payload, $response);
	if(ForumFortressMyBB::reject($response)) error('Forum Fortress rejected this message.');
}

function forumfortress_admin_page()
{
	global $mybb, $page, $db;
	if((isset($mybb->input['action']) ? $mybb->input['action'] : '') !== 'forumfortress')
	{
		return;
	}
	// Select the dedicated sidebar entry, rather than the generic Plugins item.
	$page->active_action = 'forumfortress';
	// ACP controls and transient action results must never be restored from browser history/cache.
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
	$result = null;
	$operation = '';
	if($mybb->request_method === 'post')
	{
		verify_post_check($mybb->get_input('my_post_key'));
		$operation = $mybb->get_input('ff_operation');
		try
		{
			$hasIdentity = trim(ForumFortressMyBB::setting('forumfortress_api_key')) !== '' && trim(ForumFortressMyBB::setting('forumfortress_site_id')) !== '';
			if(in_array($operation, array('portal', 'attack_on', 'attack_off'), true) && !$hasIdentity)
			{
				$result = array('error' => 'Bootstrap or enter an API key and site ID in Settings before using this control.');
			}
			elseif($operation === 'bootstrap') $result = ForumFortressMyBB::bootstrap_if_needed(true);
			elseif($operation === 'test') $result = ForumFortressMyBB::connection_test();
			elseif($operation === 'attack_on') $result = ForumFortressMyBB::attack_mode(true);
			elseif($operation === 'attack_off') $result = ForumFortressMyBB::attack_mode(false);
			elseif($operation === 'portal')
			{
				$portal = ForumFortressMyBB::portal_launch();
				if(!empty($portal['portal_url']) && ForumFortressMyBB::safe_portal_url($portal['portal_url']))
				{
					header('Location: '.$portal['portal_url']); exit;
				}
				$result = $portal;
			}
		}
		catch(Exception $e) { $result = array('error' => $e->getMessage()); }
	}
	$status = ForumFortressMyBB::site_status();
	$status = is_array($status) ? $status : array();
	$state = ForumFortressMyBB::state();
	$hasIdentity = trim(ForumFortressMyBB::setting('forumfortress_api_key')) !== '' && trim(ForumFortressMyBB::setting('forumfortress_site_id')) !== '';
	$attackActive = !empty($status['attack_mode_active']);
	$page->add_breadcrumb_item('Forum Fortress', 'index.php?module=config-plugins&amp;action=forumfortress');
	$page->output_header('Forum Fortress');
	echo '<style>
.ffDashboard{--ff-green:#087443;--ff-green-bright:#159458;--ff-green-soft:rgba(8,116,67,.1);--ff-amber:#b86313;--ff-border:rgba(127,127,127,.22);display:grid;gap:14px}.ffDashboard *{box-sizing:border-box}.ffCard{overflow:hidden;margin:0!important;border:1px solid var(--ff-border);border-radius:10px;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.05)}.ffHero{border-top:3px solid var(--ff-green)}.ffHeroHeader{display:flex;align-items:center;gap:13px;padding:18px;background:linear-gradient(135deg,var(--ff-green-soft),transparent 62%)}.ffMark{display:grid;flex:0 0 46px;width:46px;height:50px;place-content:center;gap:4px;clip-path:polygon(50% 0,94% 16%,88% 67%,70% 88%,50% 100%,30% 88%,12% 67%,6% 16%);background:linear-gradient(145deg,var(--ff-green-bright),#034f2e);filter:drop-shadow(0 2px 2px rgba(0,0,0,.18))}.ffMark i{display:block;width:25px;height:4px;border-radius:3px;background:#f4f0e5}.ffMark i:nth-child(2){width:20px}.ffMark i:nth-child(3){width:15px}.ffHeroCopy{display:grid;flex:1;gap:2px}.ffHeroCopy strong{font-size:17px}.ffHeroCopy span,.ffSectionHeader span{color:#6c7480}.ffPill{padding:5px 10px;border-radius:999px;background:rgba(108,116,128,.12);color:#6c7480;font-size:12px;font-weight:700}.ffPill.is-connected{background:var(--ff-green-soft);color:var(--ff-green-bright)}.ffActionGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;padding:0 18px 18px}.ffActionGrid form,.ffActionGrid a{display:flex;width:100%;margin:0!important}.ffButton{display:flex!important;align-items:center;justify-content:center;width:100%!important;min-height:42px;box-sizing:border-box!important;border-radius:6px!important;font-weight:600!important;padding:9px 12px!important;cursor:pointer!important;text-align:center!important;text-decoration:none!important}.ffButton--primary{background:var(--ff-green)!important;border-color:var(--ff-green)!important;color:#fff!important}.ffButton--primary:hover{background:var(--ff-green-bright)!important}.ffButton--attack{color:var(--ff-amber)!important;border-color:rgba(184,99,19,.45)!important;background:#fff!important}.ffButton--quiet{background:#fff!important;border:1px solid #cbd3d8!important;color:#263740!important}.ffButton[disabled]{opacity:.5;cursor:not-allowed!important}.ffNotice{display:flex;align-items:flex-start;gap:9px;padding:11px 13px;border:1px solid transparent;border-radius:8px;line-height:1.45}.ffNotice--success{border-color:rgba(21,148,88,.28);background:rgba(21,148,88,.09);color:#087443}.ffNotice--error{border-color:rgba(197,48,48,.28);background:rgba(197,48,48,.09);color:#a42424}.ffNotice span{flex:1}.ffSectionHeader{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;border-bottom:1px solid var(--ff-border)}.ffSectionHeader>div{display:grid;gap:2px}.ffSectionHeader strong,.ffCard h2{font-size:14px;color:#27313d}.ffSectionHeader a{color:var(--ff-green);font-size:12px;font-weight:600}.ffStatusGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.ffMetric{display:grid;gap:4px;min-width:0;padding:13px 16px;border-bottom:1px solid var(--ff-border)}.ffMetric:nth-child(odd){border-right:1px solid var(--ff-border)}.ffMetric:nth-last-child(-n+2){border-bottom:0}.ffMetric span{color:#6c7480;font-size:12px}.ffMetric strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:14px}.ffMetric.is-good strong{color:var(--ff-green-bright)}.ffMaintenance summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;cursor:pointer;list-style:none}.ffMaintenance summary::-webkit-details-marker{display:none}.ffMaintenance summary span{display:grid;gap:2px}.ffMaintenance summary small{color:#6c7480}.ffMaintenance[open] summary{border-bottom:1px solid var(--ff-border)}.ffMaintenanceBody{padding:14px 16px 16px}.ffMaintenanceBody p{margin:0 0 12px;color:#6c7480}.ffTable{width:calc(100% - 32px);margin:0 16px 16px;border-collapse:collapse}.ffTable td{padding:9px 8px;border-top:1px solid #e8ebed}.ffTable td:first-child{font-weight:700;width:35%;color:#3b4a53}.ffEmpty{color:#69767e;text-align:center;padding:18px!important}.ffPill--ok{background:#d9f2e3;color:#076236}.ffPill--warn{background:#fff0c5;color:#7a5300}.ffPill--off{background:#edf0f2;color:#56626b}@media(max-width:600px){.ffHeroHeader{align-items:flex-start}.ffPill{margin-left:auto}.ffActionGrid,.ffStatusGrid{grid-template-columns:1fr}.ffMetric:nth-child(odd){border-right:0}.ffMetric:nth-last-child(2){border-bottom:1px solid var(--ff-border)}}
</style>';
	echo '<div class="ffDashboard"><section class="ffCard ffHero"><div class="ffHeroHeader"><div class="ffMark" aria-hidden="true"><i></i><i></i><i></i></div><div class="ffHeroCopy"><strong>Forum Fortress</strong><span>Protection status, moderator controls, and account access.</span></div><span class="ffPill'.($hasIdentity ? ' is-connected' : '').'">'.($hasIdentity ? 'Connected' : 'Configured').'</span></div><div class="ffActionGrid">';
	forumfortress_admin_action('portal', 'Portal login', 'ffButton--primary', !$hasIdentity);
	forumfortress_admin_action('bootstrap', 'Bootstrap site', 'ffButton--primary', false);
	forumfortress_admin_action('test', 'Connection test', 'ffButton--quiet', false);
	forumfortress_admin_action($attackActive ? 'attack_off' : 'attack_on', $attackActive ? 'End attack mode' : 'Enable attack mode', $attackActive ? 'ffButton--quiet' : 'ffButton--attack', !$hasIdentity);
	echo '</div><div class="ffVersion" style="padding:0 18px 15px;color:#6c7480;font-size:12px;">MyBB plugin '.htmlspecialchars_uni(FORUMFORTRESS_MYBB_VERSION).'</div></section>';
	if(!$hasIdentity)
	{
		echo '<div class="ffNotice ffNotice--error"><span><strong>Protection is not connected yet.</strong> Use <strong>Bootstrap site</strong> to request an identity. If this forum was bootstrapped earlier, enter a fresh API key and site ID in Settings after opening a plugin re-registration window in the Forum Fortress portal.</span></div>';
	}
	if($result !== null) forumfortress_admin_result($operation, $result);
	$metrics = array(
		'Protection' => ForumFortressMyBB::enabled() ? ($hasIdentity ? 'Active' : 'Configured') : 'Disabled',
		'Plan' => isset($status['plan']) ? $status['plan'] : 'Refresh to view',
		'Domain' => ForumFortressMyBB::domain(),
		'Preferred endpoint' => isset($state['preferred_endpoint']) ? $state['preferred_endpoint'] : 'Automatic selection',
		'Last responded endpoint' => isset($state['last_responded_endpoint']) ? $state['last_responded_endpoint'] : 'Refresh to view',
		'Attack mode' => isset($status['attack_mode_active']) ? (!empty($status['attack_mode_active']) ? 'Active' : 'Inactive') : 'Unknown',
	);
	echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>Site status</strong><span>Current protection and service details.</span></div><a href="index.php?module=config-plugins&amp;action=forumfortress">Refresh</a></div><div class="ffStatusGrid">';
	foreach($metrics as $label => $value) echo '<div class="ffMetric'.($label === 'Protection' && $hasIdentity ? ' is-good' : '').'"><span>'.htmlspecialchars_uni($label).'</span><strong>'.htmlspecialchars_uni((string)$value).'</strong></div>';
	echo '</div></section><details class="ffCard ffMaintenance"><summary><span><strong>Maintenance and configuration</strong><small>Settings, keys, endpoints, and policy controls</small></span><span aria-hidden="true">⌄</span></summary><div class="ffMaintenanceBody"><p>Open the native MyBB settings page to manage Forum Fortress configuration.</p><a class="ffButton ffButton--quiet" href="index.php?module=config-settings&amp;action=change&amp;gid='.forumfortress_settings_gid().'">Open Settings</a><table class="ffTable"><tr><td>API region</td><td>'.htmlspecialchars_uni(strtoupper(ForumFortressMyBB::api_region())).'</td></tr><tr><td>API endpoint</td><td>'.htmlspecialchars_uni(ForumFortressMyBB::api_base_url()).'</td></tr><tr><td>Stored API key</td><td>'.htmlspecialchars_uni(ForumFortressMyBB::mask(ForumFortressMyBB::setting('forumfortress_api_key'))).'</td></tr><tr><td>Stored site ID</td><td>'.htmlspecialchars_uni(ForumFortressMyBB::setting('forumfortress_site_id') ?: 'Not set').'</td></tr><tr><td>Registration required</td><td>'.(isset($status['registration_required']) ? (!empty($status['registration_required']) ? 'Yes' : 'No') : 'Unknown').'</td></tr><tr><td>Dataset version</td><td>'.htmlspecialchars_uni((string)(isset($status['dataset_version']) ? $status['dataset_version'] : 'Unknown')).'</td></tr></table></div></details>';
	$query = $db->simple_select('forumfortress_events', '*', '', array('order_by' => 'eid', 'order_dir' => 'DESC', 'limit' => 20));
	echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>Recent protection decisions</strong><span>Latest events recorded by this forum.</span></div></div><table class="ffTable"><tr><td>Time</td><td>Endpoint</td><td>Decision</td><td>Subject</td><td>Reason</td></tr>';
	$eventCount = 0;
	while($row = $db->fetch_array($query)) { ++$eventCount; $decisionClass = $row['decision'] === 'allow' ? 'ffPill--ok' : ($row['decision'] === 'block' ? 'ffPill--warn' : 'ffPill--off'); echo '<tr><td>'.htmlspecialchars_uni(my_date('Y-m-d H:i:s', $row['dateline'])).'</td><td>'.htmlspecialchars_uni($row['endpoint']).'</td><td><span class="ffPill '.$decisionClass.'">'.htmlspecialchars_uni($row['decision']).'</span></td><td>'.htmlspecialchars_uni($row['username']).'</td><td>'.htmlspecialchars_uni($row['reason']).'</td></tr>'; }
	if(!$eventCount) echo '<tr><td colspan="5" class="ffEmpty">No decisions recorded yet. Protection events will appear here as traffic reaches your forum.</td></tr>';
	echo '</table></section></div>';
	$page->output_footer(); exit;
}

function forumfortress_admin_action($operation, $label, $variant, $disabled)
{
	global $mybb;
	echo '<form method="post" action="index.php?module=config-plugins&amp;action=forumfortress"><input type="hidden" name="my_post_key" value="'.htmlspecialchars_uni($mybb->post_code).'"><input type="hidden" name="ff_operation" value="'.htmlspecialchars_uni($operation).'"><input type="submit" class="ffButton '.htmlspecialchars_uni($variant).'" value="'.htmlspecialchars_uni($label).'"'.($disabled ? ' disabled="disabled"' : '').'></form>';
}

function forumfortress_admin_result($operation, $result)
{
	if(!is_array($result))
	{
		echo '<div class="ffNotice ffNotice--error"><span>No response was returned. Check the configured endpoint and try again.</span></div>';
		return;
	}
	if(!empty($result['error']))
	{
		echo '<div class="ffNotice ffNotice--error"><span><strong>Action needs attention.</strong> '.htmlspecialchars_uni((string)$result['error']).'</span></div>';
		return;
	}
	if($operation === 'test')
	{
		echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>Connection test</strong><span>Latest endpoint and capability checks.</span></div></div><div class="ffStatusGrid">';
		foreach(array('bootstrap' => 'Bootstrap', 'health' => 'Health', 'capabilities' => 'Capabilities', 'site_status' => 'Site status') as $key => $label)
		{
			$value = (string)(isset($result[$key]) ? $result[$key] : 'unknown');
			echo '<div><small>'.htmlspecialchars_uni($label).'</small><strong>'.htmlspecialchars_uni(str_replace('_', ' ', $value)).'</strong></div>';
		}
		echo '</div></section>';
		return;
	}
	$labels = array('bootstrap' => 'Bootstrap completed.', 'attack_on' => 'Attack mode enabled.', 'attack_off' => 'Attack mode ended.', 'portal' => 'Portal request completed.');
	echo '<div class="ffNotice ffNotice--success"><span><strong>'.htmlspecialchars_uni(isset($labels[$operation]) ? $labels[$operation] : 'Action completed.').'</strong> Forum Fortress returned a successful response.</span></div>';
}

function forumfortress_settings_gid()
{
	global $db;
	$query = $db->simple_select('settinggroups', 'gid', "name='forumfortress'");
	return (int)$db->fetch_field($query, 'gid');
}

class ForumFortressMyBB
{
	protected static $request_meta = array();
	protected static $bootstrap_in_progress = false;
	protected static $bootstrap_attempted = false;
	protected static $authenticated_portal_url = NULL;

	public static function setting($name, $default='') { global $mybb; return isset($mybb->settings[$name]) ? (string)$mybb->settings[$name] : $default; }
	public static function enabled() { return self::setting('forumfortress_enabled', '1') === '1'; }
	public static function fail_open() { return self::setting('forumfortress_fail_open', '1') === '1'; }
	public static function timeout($cap=30) { return max(1, min((int)$cap, min(30, (int)self::setting('forumfortress_timeout', '5')))); }
	public static function api_region()
	{
		$region = strtolower(trim(self::setting('forumfortress_api_region', '')));
		if(in_array($region, array('global', 'uk', 'eu', 'us'), true)) return $region;
		$legacy = strtolower(rtrim(self::setting('forumfortress_api_base_url', ''), '/'));
		$map = array('https://api-uk.ffapi.net' => 'uk', 'https://api-eu.ffapi.net' => 'eu', 'https://api-us.ffapi.net' => 'us');
		return isset($map[$legacy]) ? $map[$legacy] : 'global';
	}
	public static function api_base_url()
	{
		$legacy = self::setting('forumfortress_api_base_url', '');
		$legacy_host = strtolower((string)@parse_url($legacy, PHP_URL_HOST));
		if(self::setting('forumfortress_api_region', '') === '' && in_array($legacy_host, array('localhost', '127.0.0.1', '::1'), true)) return rtrim($legacy, '/');
		$map = array('global' => 'https://api.ffapi.net', 'uk' => 'https://api-uk.ffapi.net', 'eu' => 'https://api-eu.ffapi.net', 'us' => 'https://api-us.ffapi.net');
		return $map[self::api_region()];
	}
	public static function allow_global_emergency_fallback() { return self::setting('forumfortress_allow_global_fallback', '0') === '1'; }
	public static function background_bootstrap_allowed()
	{
		if(trim(self::setting('forumfortress_api_key')) !== '' && trim(self::setting('forumfortress_site_id')) !== '') return false;
		$script = defined('THIS_SCRIPT') ? THIS_SCRIPT : (isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '');
		return in_array($script, array('index.php', 'portal.php', 'forumdisplay.php', 'showthread.php', 'newthread.php', 'newreply.php', 'usercp.php', 'contact.php'), true);
	}
	public static function domain()
	{
		$url = self::setting('bburl'); $host = parse_url($url, PHP_URL_HOST);
		return $host ? strtolower($host) : (isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\\d+$/', '', strtolower($_SERVER['HTTP_HOST'])) : 'localhost');
	}
	public static function mask($key) { $key = trim($key); return $key === '' ? 'Not set' : (strlen($key) <= 10 ? str_repeat('*', strlen($key)) : substr($key, 0, 6).str_repeat('*', strlen($key)-10).substr($key, -4)); }
	public static function state()
	{
		$state = json_decode(self::setting('forumfortress_endpoint_state', '{}'), true);
		return is_array($state) ? $state : array();
	}
	protected static function save_state($state) { self::save_setting('forumfortress_endpoint_state', json_encode($state)); }
	protected static function save_setting($name, $value)
	{
		global $db, $mybb; $value = (string)$value;
		$db->update_query('settings', array('value' => $db->escape_string($value)), "name='".$db->escape_string($name)."'");
		$mybb->settings[$name] = $value;
	}
	protected static function rebuild_settings_cache() { if(function_exists('rebuild_settings')) rebuild_settings(); }
	public static function valid_base_url($url)
	{
		$url = trim((string)$url); if($url === '') return false;
		$parts = @parse_url($url); if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) return false;
		$scheme = strtolower($parts['scheme']); $host = strtolower($parts['host']);
		if($scheme === 'https') return true;
		return $scheme === 'http' && in_array($host, array('localhost', '127.0.0.1', '::1'), true);
	}
	public static function safe_portal_url($url)
	{
		$parts = @parse_url((string)$url); if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['fragment'])) return false;
		$scheme = strtolower((string)(isset($parts['scheme']) ? $parts['scheme'] : ''));
		if($scheme !== 'https' && !self::valid_base_url((string)$url)) return false;
		$host = strtolower(trim((string)(isset($parts['host']) ? $parts['host'] : '')));
		$path = '/' . ltrim((string)(isset($parts['path']) ? $parts['path'] : ''), '/');
		$query = array(); parse_str((string)(isset($parts['query']) ? $parts['query'] : ''), $query);
		if(
			rtrim($path, '/') === '/access'
			&& isset($query['token']) && is_string($query['token']) && trim($query['token']) !== ''
		)
		{
			if(
				$scheme === 'https'
				&& ((int)(isset($parts['port']) ? $parts['port'] : 443)) === 443
				&& self::matches_expected_portal_host($host, self::candidate_portal_hosts())
			)
			{
				return true;
			}
			if(self::$authenticated_portal_url !== NULL && hash_equals((string)self::$authenticated_portal_url, (string)$url))
			{
				return true;
			}
		}
		if($scheme !== 'http') return false;
		foreach (array(self::setting('forumfortress_control_base_url'), self::setting('forumfortress_api_base_url'), self::setting('bburl')) as $configured)
		{
			$configured_parts = @parse_url((string)$configured);
			if(!is_array($configured_parts) || !self::valid_base_url((string)$configured)) continue;
			$configured_host = strtolower(trim((string)(isset($configured_parts['host']) ? $configured_parts['host'] : '')));
			$configured_scheme = strtolower(trim((string)(isset($configured_parts['scheme']) ? $configured_parts['scheme'] : '')));
			$configured_port = isset($configured_parts['port']) ? (int)$configured_parts['port'] : (($configured_scheme === 'https') ? 443 : 80);
			if(
				$host === $configured_host
				&& $scheme === $configured_scheme
				&& ((int)(isset($parts['port']) ? $parts['port'] : 0)) === $configured_port
			)
			{
				return true;
			}
		}
		return false;
	}

	protected static function candidate_portal_hosts()
	{
		$hosts = array();
		foreach(array(self::setting('forumfortress_api_base_url'), self::setting('forumfortress_control_base_url'), self::setting('bburl')) as $candidate)
		{
			$host = self::derive_portal_host((string)$candidate);
			if($host !== '')
			{
				$hosts[] = $host;
			}
		}
		return array_values(array_unique($hosts));
	}

	protected static function derive_portal_host($candidate)
	{
		$parts = @parse_url((string)$candidate);
		if(!is_array($parts) || empty($parts['host'])) return '';
		$host = strtolower(trim((string)$parts['host']));
		if(strpos($host, 'api.') === 0)
		{
			return 'portal.' . substr($host, 4);
		}
		if(strpos($host, 'control.') === 0)
		{
			return 'portal.' . substr($host, 8);
		}
		return $host;
	}

	protected static function matches_expected_portal_host($host, $expected)
	{
		$host = strtolower(trim((string)$host));
		foreach((array)$expected as $candidate)
		{
			if($host === strtolower(trim((string)$candidate)))
			{
				return true;
			}
		}
		return false;
	}
	protected static function endpoint_candidates($control=false)
	{
		$candidates = array(); $state = self::state(); $key = trim(self::setting('forumfortress_api_key'));
		$is_offline_key = strpos($key, 'ff_ob_') === 0 || (isset($state['key_type']) && $state['key_type'] === 'offline_bootstrap');
		if(!$control && $is_offline_key)
		{
			// Offline bootstrap tokens are node-scoped. Never send one to the
			// configured API hostname or control plane.
			if(!empty($state['preferred_endpoint']) && self::valid_base_url($state['preferred_endpoint'])) return array(rtrim($state['preferred_endpoint'], '/'));
			return array();
		}
		if(!$control && self::api_region() !== 'global')
		{
			$candidates[] = self::api_base_url();
			if(self::allow_global_emergency_fallback()) $candidates[] = 'https://api.ffapi.net';
		}
		else
		{
			if(!$control && !empty($state['preferred_endpoint'])) $candidates[] = $state['preferred_endpoint'];
			$candidates[] = self::setting($control ? 'forumfortress_control_base_url' : 'forumfortress_api_base_url');
		}
		if(!$control && self::api_region() === 'global') $candidates[] = self::setting('forumfortress_control_base_url');
		$out = array(); foreach($candidates as $candidate) { if(self::valid_base_url($candidate)) { $candidate = rtrim($candidate, '/'); if(!in_array($candidate, $out, true)) $out[] = $candidate; } }
		return $out;
	}
	protected static function bootstrap_endpoint_candidates()
	{
		$state = self::state(); $key = trim(self::setting('forumfortress_api_key')); $candidates = array();
		if(strpos($key, 'ff_ob_') === 0 || (isset($state['key_type']) && $state['key_type'] === 'offline_bootstrap'))
		{
			if(!empty($state['preferred_endpoint'])) $candidates[] = $state['preferred_endpoint'];
			if(isset($state['fallback_bootstrap_endpoints']) && is_array($state['fallback_bootstrap_endpoints'])) $candidates = array_merge($candidates, $state['fallback_bootstrap_endpoints']);
		}
		else
		{
			if(self::api_region() !== 'global')
			{
				$candidates[] = self::api_base_url();
				if(self::allow_global_emergency_fallback()) $candidates[] = 'https://api.ffapi.net';
				$candidates = array_merge($candidates, self::endpoint_candidates(true));
			}
			else
			{
				$candidates = self::endpoint_candidates(true);
				$candidates[] = self::api_base_url();
			}
		}
		$out = array();
		foreach($candidates as $candidate) if(self::valid_base_url($candidate)) { $candidate = rtrim($candidate, '/'); if(!in_array($candidate, $out, true)) $out[] = $candidate; }
		return $out;
	}
	protected static function should_failover()
	{
		$status = isset(self::$request_meta['status']) ? (int)self::$request_meta['status'] : 0;
		return $status === 0 || $status === 408 || $status === 429 || $status >= 500 || ($status >= 200 && $status < 300 && empty(self::$request_meta['valid_json']));
	}
	public static function bootstrap_if_needed($force=false)
	{
		if(!self::enabled()) return null;
		$key = trim(self::setting('forumfortress_api_key'));
		$site = trim(self::setting('forumfortress_site_id'));
		if(!$force && $key !== '' && $site !== '') return null;
		if(self::$bootstrap_in_progress || (!$force && self::$bootstrap_attempted)) return null;
		$state = self::state(); $now = time();
		if(!$force && !empty($state['bootstrap_lock_until']) && (int)$state['bootstrap_lock_until'] > $now) return null;
		if(!$force && !empty($state['last_bootstrap_failure_at']) && (int)$state['last_bootstrap_failure_at'] > $now - 300) return null;
		self::$bootstrap_in_progress = true; self::$bootstrap_attempted = true;
		$state['bootstrap_lock_until'] = $now + 10; $state['last_bootstrap_attempt_at'] = $now; self::save_state($state); self::rebuild_settings_cache();
		$payload = array('domain' => self::domain(), 'platform' => 'mybb', 'platform_version' => defined('MYBB_VERSION') ? MYBB_VERSION : '1.8', 'plugin_version' => FORUMFORTRESS_MYBB_VERSION, 'api_key' => $key !== '' ? $key : null);
		$bases = self::bootstrap_endpoint_candidates();
		$started = microtime(true); $response = null; $responded_base = '';
		foreach($bases as $base)
		{
			if(microtime(true) - $started >= 3) break;
			$response = self::raw_request('POST', $base, '/v1/site/bootstrap', $payload, false, 1);
			if(is_array($response) && !empty($response['api_key'])) { $responded_base = $base; break; }
			$status = isset(self::$request_meta['status']) ? (int)self::$request_meta['status'] : 0;
			if(!self::should_failover() && !in_array($status, array(404, 405), true)) break;
		}
		self::$bootstrap_in_progress = false;
		$state = self::state(); unset($state['bootstrap_lock_until']);
		if(is_array($response) && !empty($response['api_key']))
		{
			self::persist_identity($response, $responded_base); return $response;
		}
		$state['last_bootstrap_failure_at'] = time(); $state['last_bootstrap_error'] = 'Bootstrap endpoint unavailable or returned an invalid response.'; self::save_state($state); self::rebuild_settings_cache();
		return null;
	}
	protected static function persist_identity($response, $base)
	{
		$returned_key = isset($response['api_key']) ? trim((string)$response['api_key']) : '';
		if($returned_key !== '') self::save_setting('forumfortress_api_key', $returned_key);
		if(!empty($response['site_id'])) self::save_setting('forumfortress_site_id', $response['site_id']);
		$state = self::state(); $preferred = isset($response['preferred_endpoint']) ? $response['preferred_endpoint'] : $base; $preferred = self::valid_base_url($preferred) ? rtrim($preferred, '/') : $base; $state['preferred_endpoint'] = $preferred; $state['last_responded_endpoint'] = $base; $state['last_bootstrap_at'] = time(); $state['key_type'] = isset($response['key_type']) ? (string)$response['key_type'] : ($returned_key !== '' ? (strpos($returned_key, 'ff_ob_') === 0 ? 'offline_bootstrap' : 'normal') : (isset($state['key_type']) ? $state['key_type'] : 'normal')); if(isset($response['fallback_bootstrap_endpoints']) && is_array($response['fallback_bootstrap_endpoints'])) $state['fallback_bootstrap_endpoints'] = $response['fallback_bootstrap_endpoints']; unset($state['last_bootstrap_failure_at'], $state['last_bootstrap_error'], $state['bootstrap_lock_until']); self::save_state($state); self::rebuild_settings_cache();
	}
	public static function check($endpoint, $payload)
	{
		if(!self::enabled()) return null;
		$allowed = array('register', 'login', 'topic', 'reply', 'profile', 'signature', 'contact_page', 'post_edit', 'reply_edit', 'topic_edit', 'signature_edit', 'profile_edit');
		if(!in_array($endpoint, $allowed, true)) return null;
		self::bootstrap_if_needed();
		return self::request('POST', '/v1/check/'.$endpoint, $payload, false, 5);
	}
	public static function report($endpoint, $payload)
	{
		if(!self::enabled() || trim(self::setting('forumfortress_api_key')) === '' || !in_array($endpoint, array('moderation', 'register', 'ham'), true)) return null;
		return self::request('POST', '/v1/report/'.$endpoint, $payload, false, 1);
	}
	public static function request($method, $path, $payload=array(), $control=false, $timeout=null)
	{
		$defaults = array('domain' => self::domain());
		if(strtoupper($method) !== 'GET') $defaults = array_merge($defaults, array('api_key' => self::setting('forumfortress_api_key'), 'site_id' => self::setting('forumfortress_site_id'), 'platform' => 'mybb', 'plugin_version' => FORUMFORTRESS_MYBB_VERSION));
		$payload = array_merge($defaults, $payload);
		$bases = $control ? self::endpoint_candidates(true) : self::endpoint_candidates(false);
		foreach($bases as $base_index => $base)
		{
			$response = self::raw_request($method, $base, $path, $payload, true, $timeout);
			if(!is_array($response) && !$control && self::api_region() !== 'global' && $base_index === 0 && self::should_failover())
			{
				$response = self::raw_request($method, $base, $path, $payload, true, $timeout);
			}
			if(is_array($response))
			{
				if($path === '/v1/site/status' || $path === '/v1/site/bootstrap' || (strpos($path, '/v1/check/') === 0 && (isset($response['api_key']) || isset($response['site_id'])))) self::persist_identity($response, $base);
				return $response;
			}
			if(!self::should_failover()) break;
		}
		return null;
	}
	protected static function raw_request($method, $base, $path, $payload, $authenticate, $timeout=null)
	{
		$base = self::valid_base_url($base) ? rtrim($base, '/') : '';
		if($base === '') { self::$request_meta = array('status' => 0, 'error' => 'invalid_url'); return null; }
		$url = $base.$path; $key = isset($payload['api_key']) ? trim((string)$payload['api_key']) : trim(self::setting('forumfortress_api_key'));
		$query = $payload;
		if(isset($query['api_key'])) unset($query['api_key']);
		if($method === 'GET' && $query) $url .= '?'.http_build_query($query, '', '&');
		$body = $method === 'GET' ? null : json_encode($payload);
		$headers = array('Accept: application/json', 'Content-Type: application/json', 'User-Agent: ForumFortress-MyBB/'.FORUMFORTRESS_MYBB_VERSION);
		if($authenticate && $method === 'GET' && $key !== '') $headers[] = 'X-FF-Key: '.$key;
		$raw = false;
		$status = 0; $limit = $timeout === null ? self::timeout(5) : max(1, min(5, (int)$timeout));
		if(function_exists('curl_init'))
		{
			$ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, $limit); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(1, $limit)); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			if($method !== 'GET') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
			$raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
		}
		else
		{
			$context = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ? $body : '', 'timeout' => $limit, 'ignore_errors' => true, 'follow_location' => 0)));
			$raw = @file_get_contents($url, false, $context);
			if(isset($http_response_header[0]) && preg_match('/\s([0-9]{3})\s/', $http_response_header[0], $match)) $status = (int)$match[1];
		}
		$data = is_string($raw) ? json_decode($raw, true) : null;
		self::$request_meta = array('status' => $status, 'valid_json' => is_array($data));
		if($status < 200 || $status >= 300 || !is_array($data)) return null;
		return $data;
	}
	public static function decision($response)
	{
		if(!is_array($response)) return 'unavailable';
		if(!isset($response['decision'])) return 'unavailable';
		$decision = strtolower(trim((string)$response['decision']));
		return in_array($decision, array('allow', 'block'), true) ? $decision : 'unavailable';
	}
	public static function reject($response) { $decision = self::decision($response); return $decision === 'block' || ($decision !== 'allow' && !self::fail_open()); }
	public static function user_payload($user)
	{
		$email = isset($user['email']) ? $user['email'] : '';
		$payload = array('ip' => self::ip(), 'username' => (string)(isset($user['username']) ? $user['username'] : ''), 'email' => (string)$email, 'email_domain' => self::email_domain($email), 'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 'links' => array(), 'account_age_seconds' => self::account_age($user), 'post_count' => isset($user['postnum']) ? (int)$user['postnum'] : 0);
		if(isset($user['profile_fields']) && is_array($user['profile_fields'])) $payload['profile_fields'] = self::string_map($user['profile_fields']);
		return $payload;
	}
	public static function profile_payload($user)
	{
		$payload = self::user_payload($user); $parts = array();
		foreach(array('website', 'usertitle', 'icq', 'skype', 'google', 'awayreason', 'birthday') as $key) if(isset($user[$key]) && (string)$user[$key] !== '') $parts[] = $key.': '.(is_array($user[$key]) ? json_encode($user[$key]) : (string)$user[$key]);
		if(isset($payload['profile_fields'])) $parts[] = json_encode($payload['profile_fields']);
		$signature = isset($user['signature']) ? (string)$user['signature'] : '';
		$payload['signature_text'] = $signature; $payload['content'] = $signature !== '' ? $signature : implode("\n", $parts); if(!$payload['content']) $payload['content'] = implode("\n", $parts);
		$payload['links'] = self::links($payload['content']);
		return $payload;
	}
	public static function has_profile_changes($data)
	{
		if(!is_array($data)) return false;
		foreach(array('signature', 'website', 'usertitle', 'icq', 'skype', 'google', 'birthday', 'birthdayprivacy', 'away', 'awayreason', 'profile_fields') as $key) if(array_key_exists($key, $data)) return true;
		return false;
	}
	protected static function string_map($values)
	{
		$out = array(); foreach($values as $key => $value) $out[(string)$key] = is_array($value) ? implode(', ', self::string_map($value)) : (string)$value; return $out;
	}
	protected static function account_age($user)
	{
		$regdate = isset($user['regdate']) ? (int)$user['regdate'] : 0; return $regdate > 0 ? max(0, time() - $regdate) : null;
	}
	public static function post_payload($post)
	{
		global $mybb; $user = is_object($mybb) && is_array($mybb->user) ? $mybb->user : array(); $content = isset($post['message']) ? (string)$post['message'] : ''; $username = isset($post['username']) ? $post['username'] : (isset($user['username']) ? $user['username'] : ''); $email = isset($user['email']) ? $user['email'] : '';
		return array('ip' => self::ip(), 'username' => (string)$username, 'email' => (string)$email, 'email_domain' => self::email_domain($email), 'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 'content' => $content, 'links' => self::links($content), 'forum_id' => isset($post['fid']) ? (int)$post['fid'] : 0, 'content_id' => isset($post['pid']) ? (string)$post['pid'] : '', 'thread_id' => isset($post['tid']) ? (string)$post['tid'] : '', 'subject' => isset($post['subject']) ? (string)$post['subject'] : '', 'account_age_seconds' => self::account_age($user), 'post_count' => isset($user['postnum']) ? (int)$user['postnum'] : 0);
	}
	public static function email_domain($email) { $at = strrpos((string)$email, '@'); return $at === false ? '' : strtolower(substr($email, $at + 1)); }
	public static function ip() { if(function_exists('get_ip')) return (string)get_ip(); return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : ''; }
	public static function links($content) { preg_match_all('~https?://[^\\s<>"\']+~i', (string)$content, $matches); return array_values(array_unique(isset($matches[0]) ? $matches[0] : array())); }
	public static function reportable_post($handler) { if(!is_object($handler) || !isset($handler->data) || !is_array($handler->data)) return false; $data = $handler->data; if(!empty($data['savedraft']) || (isset($data['visible']) && (string)$data['visible'] === '-2') || !empty($data['private']) || !empty($data['is_private']) || !empty($data['pm'])) return false; return true; }
	public static function event($endpoint, $decision, $payload, $response)
	{
		global $db; if(!$db->table_exists('forumfortress_events')) return;
		$codes = is_array($response) && isset($response['reason_codes']) ? (array)$response['reason_codes'] : array(); $reason = count($codes) ? implode(', ', array_slice($codes, 0, 4)) : 'API unavailable'; $username = isset($payload['username']) ? $payload['username'] : ''; $remote = isset($payload['content_id']) ? $payload['content_id'] : (isset($payload['remote_user_id']) ? $payload['remote_user_id'] : '');
		$db->insert_query('forumfortress_events', array('dateline' => time(), 'endpoint' => $db->escape_string($endpoint), 'decision' => $db->escape_string($decision), 'username' => $db->escape_string((string)$username), 'remote_id' => $db->escape_string((string)$remote), 'reason' => $db->escape_string(substr($reason, 0, 255))));
	}
	// These public diagnostics deliberately run without a site key so the ACP can diagnose first-time bootstrap.
	public static function health() { return self::raw_request('GET', self::api_base_url(), '/health', array(), false, 2); }
	public static function capabilities() { return self::raw_request('GET', self::setting('forumfortress_control_base_url'), '/v1/capabilities', array(), false, 2); }
	public static function site_status() { return trim(self::setting('forumfortress_api_key')) === '' ? array() : self::request('GET', '/v1/site/status', array()); }
	public static function connection_test() { $boot = self::bootstrap_if_needed(); return array('bootstrap' => $boot ? 'ok' : (self::setting('forumfortress_api_key') ? 'already_configured' : 'no_response'), 'health' => self::health() ? 'ok' : 'no_response', 'capabilities' => self::capabilities() ? 'ok' : 'no_response', 'site_status' => self::site_status() ? 'ok' : 'no_response', 'state' => self::state()); }
	public static function deprovision($api_key, $site_id, $domain)
	{
		$payload = array('api_key' => (string)$api_key, 'site_id' => (string)$site_id, 'domain' => (string)$domain, 'platform' => 'mybb', 'plugin_version' => FORUMFORTRESS_MYBB_VERSION, 'reason' => 'plugin_uninstall');
		$bases = self::endpoint_candidates(true); foreach($bases as $base) { $response = self::raw_request('POST', $base, '/v1/site/deprovision', $payload, true, 2); if(is_array($response) && isset($response['status']) && $response['status'] === 'ok') return $response; }
		return null;
	}
	public static function attack_mode($enable)
	{
		$response = self::request('POST', $enable ? '/v1/site/attack-mode' : '/v1/site/attack-mode/end', array(), true);
		$active = null;
		if(is_array($response) && array_key_exists('attack_mode_active', $response)) $active = (bool)$response['attack_mode_active'];
		elseif(is_array($response) && array_key_exists('enabled', $response)) $active = (bool)$response['enabled'];
		elseif(is_array($response) && array_key_exists('attack_mode', $response) && !is_array($response['attack_mode'])) $active = (bool)$response['attack_mode'];
		elseif(is_array($response) && isset($response['attack_mode']) && is_array($response['attack_mode']) && array_key_exists('enabled', $response['attack_mode'])) $active = (bool)$response['attack_mode']['enabled'];
		if($active === null || $active !== (bool)$enable)
		{
			throw new RuntimeException($enable ? 'Forum Fortress did not confirm that attack mode is active.' : 'Forum Fortress did not confirm that attack mode has ended.');
		}
		$response['attack_mode_active'] = $active;
		return $response;
	}
	public static function portal_launch()
	{
		if(!self::enabled()) return null;
		self::$authenticated_portal_url = NULL;
		$response = self::request('POST', '/v1/site/portal', array(), true);
		$portal_url = is_array($response) ? trim((string)(isset($response['portal_url']) ? $response['portal_url'] : '')) : '';
		if(self::is_portal_url_bearer_shape_safe($portal_url))
		{
			self::$authenticated_portal_url = $portal_url;
		}
		return $response;
	}

	protected static function is_portal_url_bearer_shape_safe(string $value)
	{
		if(trim($value) === '' || filter_var($value, FILTER_VALIDATE_URL) === FALSE) return false;
		$parts = @parse_url($value);
		if(
			!is_array($parts)
			|| !in_array(strtolower((string)(isset($parts['scheme']) ? $parts['scheme'] : '')), array('http', 'https'), true)
			|| trim((string)(isset($parts['host']) ? $parts['host'] : '')) === ''
			|| !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['fragment'])
		)
		{
			return false;
		}
		$path = '/' . ltrim((string)(isset($parts['path']) ? $parts['path'] : ''), '/');
		if(rtrim($path, '/') !== '/access') return false;
		$query = array(); parse_str((string)(isset($parts['query']) ? $parts['query'] : ''), $query);
		return isset($query['token']) && is_string($query['token']) && trim($query['token']) !== '';
	}
}
