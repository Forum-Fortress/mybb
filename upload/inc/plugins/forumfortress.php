<?php
/**
 * Forum Fortress for MyBB 1.8.
 *
 * Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if(!defined('IN_MYBB'))
{
	die('This file cannot be accessed directly.');
}

define('FORUMFORTRESS_MYBB_VERSION', '1.2.2');

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
		$plugins->add_hook('admin_config_settings_begin', 'forumfortress_admin_settings');
		$plugins->add_hook('admin_formcontainer_output_row', 'forumfortress_admin_setting_row');
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
	$db->delete_query('settings', "name='forumfortress_control_base_url'");
	$settings = array(
		'forumfortress_enabled' => array('Enabled', 'Enable Forum Fortress checks and background bootstrap.', 'yesno', '1'),
		'forumfortress_repeat_ban' => array('Ban repeat spammers for seven days', 'Off by default. Two distinct server-certified spam blocks within 24 hours can ban a new, low-trust member. Requires the installed Forum Fortress native task. Administrators, moderators and established members are protected.', 'yesno', '0'),
		'forumfortress_api_region' => array('API region', 'Lock check traffic to a region, or use the recommended global network.', "select\nglobal=Global - Recommended\nuk=United Kingdom only\neu=European Union only\nus=United States only", $legacy_region),
		'forumfortress_allow_global_fallback' => array('Allow global emergency fallback', 'After a regional retry fails, permit the global network. Processing may occur outside the selected region.', 'yesno', '0'),
		'forumfortress_timeout' => array('Request timeout', 'Maximum API request time in seconds (1–30).', 'numeric', '5'),
		'forumfortress_fail_open' => array('Fail open', 'Allow an action when Forum Fortress is unavailable. Disable only when your forum can tolerate API outages blocking registrations and content.', 'yesno', '1'),
		'forumfortress_api_key' => array('API key', 'Stored automatically after anonymous bootstrap. With the plugin active, paste a replacement key. Blank submissions preserve the stored key.', "php\n".'<input type=\"password\" name=\"upsetting[forumfortress_api_key_replacement]\" id=\"setting_forumfortress_api_key\" value=\"\" autocomplete=\"new-password\" />', ''),
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
		if($name === 'forumfortress_api_key') $data['isdefault'] = 1; // Secure input metadata is maintained by the plugin.
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
	ForumFortressMyBBIdentity::install();
	ForumFortressMyBBRepeat::install();
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

function forumfortress_deactivate()
{
	global $db;
	// A subsequent re-enable starts with fresh evidence, retaining action receipts.
	if($db->table_exists('forumfortress_repeat_state')) $db->write_query('UPDATE '.TABLE_PREFIX.'forumfortress_repeat_state SET pending=0', 1);
	if($db->table_exists('forumfortress_repeat_events')) $db->write_query('UPDATE '.TABLE_PREFIX.'forumfortress_repeat_events SET consumed=1', 1);
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
	ForumFortressMyBBRepeat::uninstall();
	ForumFortressMyBBIdentity::uninstall();
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
		ForumFortressMyBB::background_maintenance();
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
		$actor = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
		$owner = isset($handler->data['uid']) ? (int)$handler->data['uid'] : 0;
		ForumFortressMyBBRepeat::record($owner, $actor, $response, isset($payload['content']) ? $payload['content'] : '');
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
	$actor = isset($user['uid']) ? (int)$user['uid'] : 0;
	ForumFortressMyBBRepeat::record($actor, $actor, $response, $signature);
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
	$actor = isset($mybb->user['uid']) ? (int)$mybb->user['uid'] : 0;
	$owner = isset($handler->data['uid']) ? (int)$handler->data['uid'] : 0;
	$pid = $method === 'update' && isset($handler->data['pid']) ? (int)$handler->data['pid'] : 0;
	if($method !== 'update' || $pid > 0) ForumFortressMyBBRepeat::record($owner, $actor, $response, isset($handler->data['message']) ? (string)$handler->data['message'] : '', $pid);
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
				forumfortress_admin_action('portal', 'Open Forum Fortress', 'ffButton--primary', !$hasIdentity);
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
		'GeoDNS route' => isset($state['preferred_endpoint']) ? $state['preferred_endpoint'] : 'https://api.ffapi.net',
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
	echo '</table></section>';
	$attempts = ForumFortressMyBBRepeat::recent();
	echo '<section class="ffCard"><div class="ffSectionHeader"><div><strong>Repeat-spammer bans</strong><span>Review or lift bans through native MyBB moderation. Failed or unconfirmed attempts require review and are never retried automatically.</span></div><a href="index.php?module=user-banning">Native bans</a></div><table class="ffTable">';
	foreach($attempts as $attempt)
	{
		$label = $attempt['acted_at'] ? 'Applied' : ($attempt['failed'] ? 'Failed; review required' : 'Unconfirmed; review required');
		echo '<tr><td><a href="index.php?module=user-users&amp;action=edit&amp;uid='.(int)$attempt['uid'].'">Member '.(int)$attempt['uid'].'</a></td><td>'.htmlspecialchars_uni(my_date('Y-m-d H:i:s', $attempt['attempted_at'])).'</td><td>'.htmlspecialchars_uni($label).'</td></tr>';
	}
	if(!$attempts) echo '<tr><td class="ffEmpty">No automatic ban attempts recorded.</td></tr>';
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
		foreach(array('bootstrap' => 'Bootstrap', 'heartbeat' => 'Authenticated heartbeat', 'capabilities' => 'Capabilities', 'site_status' => 'Site status') as $key => $label)
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
	const CONTROL_BASE_URL = 'https://api.ffapi.net';
	const STANDARD_HEARTBEAT_INTERVAL_SECONDS = 3600;
	const PRO_HEARTBEAT_INTERVAL_SECONDS = 600;
	protected static $request_meta = array();
	protected static $bootstrap_in_progress = false;
	protected static $bootstrap_attempted = false;
	protected static $authenticated_portal_url = NULL;

	public static function setting($name, $default='') { global $mybb; if($name === 'forumfortress_api_key' && ForumFortressMyBBIdentity::ready()) return ForumFortressMyBBIdentity::value(); return isset($mybb->settings[$name]) ? (string)$mybb->settings[$name] : $default; }
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
		// The live lifecycle harness deliberately routes CLI traffic to its
		// localhost fixture. The test seam must not depend on whether a fresh
		// install has already written the default api_region setting.
		$region = strtolower(trim(self::setting('forumfortress_api_region', '')));
		if(PHP_SAPI === 'cli' && in_array($region, array('', 'global'), true) && defined('FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL') && hash_equals(rtrim((string)FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL, '/'), rtrim($legacy, '/'))) return rtrim($legacy, '/');
		$map = array('global' => 'https://api.ffapi.net', 'uk' => 'https://api-uk.ffapi.net', 'eu' => 'https://api-eu.ffapi.net', 'us' => 'https://api-us.ffapi.net');
		return $map[self::api_region()];
	}
	public static function allow_global_emergency_fallback() { return self::setting('forumfortress_allow_global_fallback', '0') === '1'; }
	public static function control_base_url()
	{
		// Deterministic localhost integration tests run in CLI only; web and cron
		// traffic always use the fixed public plugin service.
		if(PHP_SAPI === 'cli' && defined('FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL'))
		{
			$test = rtrim((string)FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL, '/');
			$host = strtolower((string)@parse_url($test, PHP_URL_HOST));
			if(in_array($host, array('localhost', '127.0.0.1', '::1'), true)) return $test;
		}
		return self::CONTROL_BASE_URL;
	}
	public static function background_bootstrap_allowed()
	{
		$script = defined('THIS_SCRIPT') ? THIS_SCRIPT : (isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '');
		return in_array($script, array('index.php', 'portal.php', 'forumdisplay.php', 'showthread.php', 'newthread.php', 'newreply.php', 'usercp.php', 'contact.php'), true);
	}
	public static function background_maintenance()
	{
		if(!self::enabled()) return;
		$key = trim(self::setting('forumfortress_api_key'));
		$site = trim(self::setting('forumfortress_site_id'));
		if($key === '' || $site === '')
		{
			self::bootstrap_if_needed();
			$key = trim(self::setting('forumfortress_api_key'));
			$site = trim(self::setting('forumfortress_site_id'));
			if($key === '' || $site === '') return;
		}
		$state = self::state(); $now = time();
		$last_success = isset($state['last_site_ping_at']) ? (int)$state['last_site_ping_at'] : 0;
		$last_attempt = isset($state['last_site_ping_attempt_at']) ? (int)$state['last_site_ping_attempt_at'] : 0;
		$plan = isset($state['plan_name']) ? strtolower(trim((string)$state['plan_name'])) : '';
		$interval = in_array($plan, array('pro', 'multimod'), true) ? self::PRO_HEARTBEAT_INTERVAL_SECONDS : self::STANDARD_HEARTBEAT_INTERVAL_SECONDS;
		if(max($last_success, $last_attempt) > $now - $interval) return;
		$state['last_site_ping_attempt_at'] = $now; self::save_state($state); self::rebuild_settings_cache();
		self::site_ping(2);
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
		if($name === 'forumfortress_api_key' && ForumFortressMyBBIdentity::ready()) { ForumFortressMyBBIdentity::save($value); $mybb->settings[$name] = ''; return; }
		$db->update_query('settings', array('value' => $db->escape_string($value)), "name='".$db->escape_string($name)."'");
		$mybb->settings[$name] = $value;
	}
	protected static function rebuild_settings_cache() { if(function_exists('rebuild_settings')) rebuild_settings(); }
	public static function valid_base_url($url)
	{
		$url = trim((string)$url); if($url === '') return false;
		$parts = @parse_url($url); if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) return false;
		if(strtolower($parts['scheme']) === 'https') return true;
		return PHP_SAPI === 'cli' && defined('FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL') && hash_equals(rtrim((string)FORUMFORTRESS_MYBB_TEST_CONTROL_BASE_URL, '/'), rtrim($url, '/'));
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
		foreach (array(self::control_base_url(), self::setting('forumfortress_api_base_url'), self::setting('bburl')) as $configured)
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
		foreach(array(self::setting('forumfortress_api_base_url'), self::control_base_url(), self::setting('bburl')) as $candidate)
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
		if($host === 'fortress.ffapi.net') return 'portal.ffapi.net';
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
		if($is_offline_key)
		{
			// Offline bootstrap tokens are node-scoped. Never send one to the
			// configured API hostname or control plane.
			if(!empty($state['preferred_endpoint']) && self::valid_base_url($state['preferred_endpoint'])) return array(rtrim($state['preferred_endpoint'], '/'));
			return array();
		}
		$primary = self::api_base_url();
		$host = strtolower((string)@parse_url($primary, PHP_URL_HOST));
		if(in_array($host, array('localhost', '127.0.0.1', '::1'), true))
		{
			$candidates[] = $primary;
		}
		elseif(self::api_region() === 'global') $candidates = array('https://api.ffapi.net', self::control_base_url());
		elseif(self::allow_global_emergency_fallback()) $candidates = array($primary, 'https://api.ffapi.net', self::control_base_url());
		else $candidates = array($primary);
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
			$candidates = self::endpoint_candidates(false);
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
		$state = self::state(); $state['key_type'] = isset($response['key_type']) ? (string)$response['key_type'] : ($returned_key !== '' ? (strpos($returned_key, 'ff_ob_') === 0 ? 'offline_bootstrap' : 'normal') : (isset($state['key_type']) ? $state['key_type'] : 'normal')); $preferred = $state['key_type'] === 'offline_bootstrap' && isset($response['preferred_endpoint']) ? $response['preferred_endpoint'] : self::api_base_url(); $state['preferred_endpoint'] = self::valid_base_url($preferred) ? rtrim($preferred, '/') : self::api_base_url(); $state['last_responded_endpoint'] = $base; $state['last_bootstrap_at'] = time(); if(isset($response['plan'])) $state['plan_name'] = strtolower(trim((string)$response['plan'])); if(isset($response['fallback_bootstrap_endpoints']) && is_array($response['fallback_bootstrap_endpoints'])) $state['fallback_bootstrap_endpoints'] = $response['fallback_bootstrap_endpoints']; unset($state['last_bootstrap_failure_at'], $state['last_bootstrap_error'], $state['bootstrap_lock_until']); self::save_state($state); self::rebuild_settings_cache();
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
	public static function request($method, $path, $payload=array(), $control=false, $timeout=null, $allow_rebootstrap=true)
	{
		$defaults = array('domain' => self::domain());
		if(strtoupper($method) !== 'GET') $defaults = array_merge($defaults, array('api_key' => self::setting('forumfortress_api_key'), 'site_id' => self::setting('forumfortress_site_id'), 'platform' => 'mybb', 'plugin_version' => FORUMFORTRESS_MYBB_VERSION));
		$payload = array_merge($defaults, $payload);
		$bases = $control ? self::endpoint_candidates(true) : self::endpoint_candidates(false);
		foreach($bases as $base_index => $base)
		{
			$response = self::raw_request($method, $base, $path, $payload, true, $timeout);
			if(is_array($response))
			{
				if($path === '/v1/site/status' || $path === '/v1/site/bootstrap' || (strpos($path, '/v1/check/') === 0 && (isset($response['api_key']) || isset($response['site_id'])))) self::persist_identity($response, $base);
				return $response;
			}
			if($allow_rebootstrap && self::should_rebootstrap($path))
			{
				$previous_key = self::setting('forumfortress_api_key');
				$previous_site = self::setting('forumfortress_site_id');
				self::prepare_identity_recovery();
				$bootstrap = self::bootstrap_if_needed(true);
				if(is_array($bootstrap) && trim(self::setting('forumfortress_api_key')) !== '')
				{
					$payload['api_key'] = self::setting('forumfortress_api_key');
					$payload['site_id'] = self::setting('forumfortress_site_id');
					$payload['domain'] = self::domain();
					return self::request($method, $path, $payload, $control, $timeout, false);
				}
				// A regional edge can briefly reject a valid key while propagation
				// catches up. Do not strand a synced/registered forum by erasing its
				// last known identity when the cautious control-plane recovery gate
				// correctly refuses to mint a replacement.
				self::save_setting('forumfortress_api_key', $previous_key);
				self::save_setting('forumfortress_site_id', $previous_site);
				self::rebuild_settings_cache();
				return null;
			}
			if(!self::should_failover()) break;
		}
		return null;
	}
	protected static function response_error_code()
	{
		$data = isset(self::$request_meta['data']) && is_array(self::$request_meta['data']) ? self::$request_meta['data'] : array();
		$detail = isset($data['detail']) ? $data['detail'] : null;
		if(isset($data['error'])) return strtolower(trim((string)$data['error']));
		if(is_array($detail) && isset($detail['error'])) return strtolower(trim((string)$detail['error']));
		return is_string($detail) ? strtolower(trim($detail)) : '';
	}
	protected static function should_rebootstrap($path)
	{
		if($path === '/v1/site/bootstrap' || trim(self::setting('forumfortress_api_key')) === '') return false;
		$status = isset(self::$request_meta['status']) ? (int)self::$request_meta['status'] : 0;
		$code = self::response_error_code();
		if($status === 409 && $code === 'stale_site') return true;
		return $status === 401 && in_array($code, array('invalid_key', 'invalid_api_key', 'unknown_site', 'invalid_key_format', 'site_not_found'), true);
	}
	protected static function prepare_identity_recovery()
	{
		$code = self::response_error_code();
		if($code === 'stale_site')
		{
			self::save_setting('forumfortress_site_id', '');
		}
		else
		{
			self::save_setting('forumfortress_api_key', '');
			self::save_setting('forumfortress_site_id', '');
		}
		$state = self::state(); unset($state['last_bootstrap_failure_at'], $state['last_bootstrap_error']); self::save_state($state); self::rebuild_settings_cache();
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
		self::$request_meta = array('status' => $status, 'valid_json' => is_array($data), 'data' => is_array($data) ? $data : array());
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
	public static function health()
	{
		return self::site_ping(2);
	}
	public static function capabilities() { return self::request('GET', '/v1/capabilities', array(), true, 2); }
	public static function site_status() { return trim(self::setting('forumfortress_api_key')) === '' ? array() : self::request('GET', '/v1/site/status', array()); }
	public static function site_ping($timeout=2)
	{
		if(trim(self::setting('forumfortress_api_key')) === '' || trim(self::setting('forumfortress_site_id')) === '') return null;
		$response = self::request('POST', '/v1/site/ping', array(), true, $timeout);
		if(is_array($response))
		{
			$state = self::state(); $state['last_site_ping_at'] = time(); unset($state['last_site_ping_attempt_at']); self::save_state($state); self::rebuild_settings_cache();
			self::persist_identity($response, self::control_base_url());
		}
		return $response;
	}
	public static function connection_test() { $boot = self::bootstrap_if_needed(); return array('bootstrap' => $boot ? 'ok' : (self::setting('forumfortress_api_key') ? 'already_configured' : 'no_response'), 'heartbeat' => self::site_ping(2) ? 'ok' : 'no_response', 'capabilities' => self::capabilities() ? 'ok' : 'no_response', 'site_status' => self::site_status() ? 'ok' : 'no_response', 'state' => self::state()); }
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
			|| strtolower((string)(isset($parts['scheme']) ? $parts['scheme'] : '')) !== 'https'
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

/** Keep native ACP secret inputs write-only, including the metadata editor. */
function forumfortress_admin_settings()
{
	global $mybb, $db, $lang;
	if(isset($lang) && method_exists($lang, 'load')) $lang->load('forumfortress', false, true);
	if($mybb->request_method !== 'post') return;
	if(isset($mybb->input['upsetting']['forumfortress_api_key_replacement']))
	{
		$value = trim((string)$mybb->input['upsetting']['forumfortress_api_key_replacement']);
		if($value !== '')
		{
			if(ForumFortressMyBBIdentity::ready()) ForumFortressMyBBIdentity::save($value);
			else $mybb->input['upsetting']['forumfortress_api_key'] = $value;
		}
		unset($mybb->input['upsetting']['forumfortress_api_key_replacement']);
	}
	if(isset($mybb->input['upsetting']['forumfortress_api_key']) && trim((string)$mybb->input['upsetting']['forumfortress_api_key']) === '') unset($mybb->input['upsetting']['forumfortress_api_key']);
	if(isset($mybb->input['upsetting']['forumfortress_api_key']) && ForumFortressMyBBIdentity::ready()) { ForumFortressMyBBIdentity::save((string)$mybb->input['upsetting']['forumfortress_api_key']); unset($mybb->input['upsetting']['forumfortress_api_key']); }
	if(isset($mybb->input['action'], $mybb->input['sid'], $mybb->input['value']) && $mybb->input['action'] === 'edit' && trim((string)$mybb->input['value']) === '')
	{
		$q = $db->simple_select('settings', 'value', "name='forumfortress_api_key' AND sid=".(int)$mybb->input['sid']);
		$value = $db->fetch_field($q, 'value');
		if($value !== false) $mybb->input['value'] = $value;
	}
	if(isset($mybb->input['action'], $mybb->input['sid'], $mybb->input['value']) && $mybb->input['action'] === 'edit' && trim((string)$mybb->input['value']) !== '' && ForumFortressMyBBIdentity::ready())
	{
		$q = $db->simple_select('settings', 'name', "name='forumfortress_api_key' AND sid=".(int)$mybb->input['sid']);
		if($db->fetch_field($q, 'name')) { ForumFortressMyBBIdentity::save((string)$mybb->input['value']); $mybb->input['value'] = ''; }
	}
}

function forumfortress_admin_setting_row($row)
{
	global $setting, $form, $mybb, $lang;
	if(!isset($row['content']) || !is_string($row['content'])) return $row;
	$regular = strpos($row['content'], 'name="upsetting[forumfortress_api_key]"') !== false;
	$metadata = isset($setting['name'], $mybb->input['action']) && $setting['name'] === 'forumfortress_api_key' && $mybb->input['action'] === 'edit' && strpos($row['content'], 'name="value"') !== false;
	if($regular || $metadata)
	{
		$name = $regular ? 'upsetting[forumfortress_api_key]' : 'value';
		$row['content'] = $form->generate_password_box($name, '', array('id' => $regular ? 'setting_forumfortress_api_key' : 'value', 'autocomplete' => 'new-password'));
	}
	if(strpos($row['content'], 'name="upsetting[forumfortress_repeat_ban]"') !== false && !ForumFortressMyBBRepeat::available())
	{
		$row['content'] = htmlspecialchars_uni(isset($lang->forumfortress_repeat_unavailable) ? $lang->forumfortress_repeat_unavailable : 'Unavailable: install and enable the Forum Fortress native task on a supported MySQL database.');
	}
	return $row;
}

/** Local evidence and separate native task; the content checker never bans. */
class ForumFortressMyBBRepeat
{
	const WINDOW = 86400;
	const DURATION = 604800;
	private static $staged = array();
	private static $shutdown = false;

	private static function mysql()
	{
		global $db;
		return isset($db->type) && in_array($db->type, array('mysql', 'mysqli', 'pdomysql'), true);
	}
	private static function table($name) { return '`'.str_replace('`', '``', TABLE_PREFIX.$name).'`'; }
	private static function q($sql)
	{
		global $db;
		$r = $db->write_query($sql, 1);
		if($r === false) throw new RuntimeException('Optional repeat-spammer database operation failed.');
		return $r;
	}
	private static function row($sql) { global $db; return $db->fetch_array(self::q($sql)); }

	public static function install()
	{
		global $db, $cache;
		if(!self::mysql()) return;
		$collation = $db->build_create_table_collation();
		try
		{
		if(!$db->table_exists('forumfortress_repeat_state')) $db->write_query('CREATE TABLE '.self::table('forumfortress_repeat_state')." (
			uid int unsigned NOT NULL PRIMARY KEY, pending tinyint NOT NULL DEFAULT 0,
			attempted_at int unsigned NOT NULL DEFAULT 0, acted_at int unsigned NOT NULL DEFAULT 0,
			failed tinyint NOT NULL DEFAULT 0, last_seen_at int unsigned NOT NULL,
			KEY pending (pending)
		) ENGINE=InnoDB{$collation}", 1);
		if(!$db->table_exists('forumfortress_repeat_events')) $db->write_query('CREATE TABLE '.self::table('forumfortress_repeat_events')." (
			eid int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, uid int unsigned NOT NULL,
			body_hash char(64) NOT NULL, reference_hash char(64) NOT NULL,
			observed_at int unsigned NOT NULL, consumed tinyint NOT NULL DEFAULT 0,
			UNIQUE KEY body (uid, body_hash), UNIQUE KEY reference_id (uid, reference_hash), KEY observed (observed_at)
		) ENGINE=InnoDB{$collation}", 1);
		}
		catch(Exception $ignored) { return; } // Optional schema must not break an Off upgrade.
		if(!defined('MYBB_ROOT') || !is_file(MYBB_ROOT.'inc/tasks/forumfortress_repeat.php') || !$db->table_exists('tasks')) return;
		require_once MYBB_ROOT.'inc/functions_task.php';
		$q = $db->simple_select('tasks', 'tid', "file='forumfortress_repeat'");
		if(!$db->fetch_field($q, 'tid'))
		{
			$task = array('title' => 'Forum Fortress repeat-spammer bans', 'description' => 'Process local opt-in seven-day native bans.', 'file' => 'forumfortress_repeat', 'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'enabled' => 1, 'logging' => 1, 'lastrun' => 0, 'locked' => 0);
			$task['nextrun'] = fetch_next_run($task);
			$db->insert_query('tasks', $task);
			$cache->update_tasks();
		}
	}
	public static function uninstall()
	{
		global $db, $cache;
		foreach(array('forumfortress_repeat_events', 'forumfortress_repeat_state') as $table) if($db->table_exists($table)) $db->drop_table($table);
		if($db->table_exists('tasks'))
		{
			$q = $db->simple_select('tasks', 'tid', "file='forumfortress_repeat'");
			while($task = $db->fetch_array($q)) if($db->table_exists('tasklog')) $db->delete_query('tasklog', 'tid='.(int)$task['tid']);
			$db->delete_query('tasks', "file='forumfortress_repeat'");
			$cache->update_tasks();
		}
		// Native bans, original groups, accounts and moderator reversals remain.
	}
	public static function available()
	{
		global $db;
		if(!self::mysql() || !defined('MYBB_ROOT') || !is_file(MYBB_ROOT.'inc/tasks/forumfortress_repeat.php') || !function_exists('add_shutdown') || !function_exists('ban_date2timestamp')) return false;
		try
		{
			// The transaction boundary must be observable. Unsupported servers
			// leave this optional feature unavailable rather than risking a commit
			// of another extension's work.
			if(!self::row('SELECT @@in_transaction AS active,@@autocommit AS automatic')) return false;
			foreach(array('forumfortress_repeat_state', 'forumfortress_repeat_events') as $name)
			{
				$t = self::row('SHOW TABLE STATUS WHERE Name=\''.$db->escape_string(TABLE_PREFIX.$name).'\'');
				if(!$t || strcasecmp($t['Engine'], 'InnoDB') !== 0) return false;
			}
			$task = self::row('SELECT enabled FROM '.self::table('tasks')." WHERE file='forumfortress_repeat' LIMIT 1");
			$group = self::row('SELECT gid FROM '.self::table('usergroups').' WHERE isbannedgroup=1 AND cancp=0 AND canmodcp=0 AND issupermod=0 LIMIT 1');
			return $task && (int)$task['enabled'] === 1 && $group;
		}
		catch(Exception $e) { return false; }
	}
	public static function reference($response)
	{
		if(!is_array($response) || !isset($response['escalation_eligible'], $response['decision']) || $response['escalation_eligible'] !== true || !is_string($response['decision']) || strtolower(trim($response['decision'])) !== 'block') return null;
		if(isset($response['decision_reference']) && is_string($response['decision_reference']) && trim($response['decision_reference']) !== '' && strlen($response['decision_reference']) <= 256) return hash('sha256', 'reference:'.trim($response['decision_reference']));
		$id = isset($response['decision_id']) ? $response['decision_id'] : null;
		if((is_int($id) || is_string($id)) && ctype_digit((string)$id) && strlen((string)$id) <= 32 && ltrim((string)$id, '0') !== '') return hash('sha256', 'decision:'.ltrim((string)$id, '0'));
		return null;
	}
	public static function record($uid, $actor, $response, $body, $pid=0)
	{
		$reference = self::reference($response);
		if(!ForumFortressMyBB::enabled() || ForumFortressMyBB::setting('forumfortress_repeat_ban', '0') !== '1' || (int)$uid <= 1 || (int)$uid !== (int)$actor || !$reference || trim((string)$body) === '' || count(self::$staged) >= 16 || !function_exists('add_shutdown')) return;
		self::$staged[] = array((int)$uid, $reference, hash('sha256', trim((string)$body)), (int)$pid);
		if(!self::$shutdown) { add_shutdown(array(__CLASS__, 'flush')); self::$shutdown = true; }
	}
	private static function enabled()
	{
		global $db;
		$q = self::q('SELECT name,value FROM '.self::table('settings')." WHERE name IN ('forumfortress_enabled','forumfortress_repeat_ban')");
		$settings = array(); while($row = $db->fetch_array($q)) $settings[$row['name']] = $row['value'];
		$cache = self::row('SELECT cache FROM '.self::table('datacache')." WHERE title='plugins'");
		$plugins = $cache && function_exists('native_unserialize') ? native_unserialize($cache['cache']) : null;
		return is_array($plugins) && !empty($plugins['active']['forumfortress']) && isset($settings['forumfortress_enabled'], $settings['forumfortress_repeat_ban']) && $settings['forumfortress_enabled'] === '1' && $settings['forumfortress_repeat_ban'] === '1';
	}
	private static function eligible($user)
	{
		if(!$user || (int)$user['uid'] <= 1 || (int)$user['usergroup'] !== 2 || trim($user['additionalgroups']) !== '' || !in_array((int)$user['displaygroup'], array(0,2), true) || (int)$user['postnum'] > 10 || (int)$user['regdate'] <= time()-self::DURATION || (int)$user['regdate'] > time() || !empty($user['moderateposts']) || !empty($user['suspendposting']) || !empty($user['suspendsignature']) || is_super_admin($user['uid'])) return false;
		$group = self::row('SELECT * FROM '.self::table('usergroups').' WHERE gid=2');
		if(!$group) return false;
		foreach(array('cancp','canmodcp','issupermod','canwarnusers','isbannedgroup') as $power) if(!empty($group[$power])) return false;
		$uid = (int)$user['uid'];
		if(self::row('SELECT uid FROM '.self::table('banned').' WHERE uid='.$uid.' LIMIT 1')) return false;
		if(self::row('SELECT uid FROM '.self::table('adminoptions').' WHERE uid='.$uid.' LIMIT 1')) return false;
		if(self::row('SELECT mid FROM '.self::table('moderators').' WHERE (isgroup=0 AND id='.$uid.') OR (isgroup=1 AND id=2) LIMIT 1')) return false;
		return true;
	}
	public static function flush()
	{
		global $db;
		$staged = self::$staged; self::$staged = array();
		if(!$staged) return;
		try
		{
			if(!self::available() || !self::enabled()) return;
			$transaction = self::row('SELECT @@in_transaction AS active');
			if(!$transaction || $transaction['active']) return; // Never commit another extension's transaction.
			foreach($staged as $event)
			{
				list($uid, $reference, $body, $pid) = $event;
				self::q('START TRANSACTION');
				$now = time(); $state = self::table('forumfortress_repeat_state'); $events = self::table('forumfortress_repeat_events');
				// Take an exclusive lock even on the duplicate path; INSERT IGNORE
				// takes shared locks that can deadlock two subsequent FOR UPDATEs.
				self::q("INSERT INTO $state (uid,last_seen_at) VALUES ($uid,$now) ON DUPLICATE KEY UPDATE uid=VALUES(uid)");
				$row = self::row("SELECT * FROM $state WHERE uid=$uid FOR UPDATE");
				$user = self::row('SELECT * FROM '.self::table('users').' WHERE uid='.$uid);
				$owner = $pid ? self::row('SELECT uid FROM '.self::table('posts').' WHERE pid='.$pid) : null;
				if(!$row || $row['attempted_at'] || !self::eligible($user) || !self::enabled() || ($pid && (!$owner || (int)$owner['uid'] !== $uid))) { self::q('ROLLBACK'); continue; }
				self::q("UPDATE $events SET consumed=1 WHERE uid=$uid AND observed_at<=".($now-self::WINDOW));
				$count = self::row("SELECT COUNT(*) AS total,SUM(consumed=0) AS recent FROM $events WHERE uid=$uid");
				if((int)$count['total'] >= 128) { self::q('COMMIT'); continue; }
				if((int)$count['recent'] < 2) self::q("INSERT IGNORE INTO $events (uid,body_hash,reference_hash,observed_at) VALUES ($uid,'$body','$reference',$now)");
				$count = self::row("SELECT COUNT(*) AS recent FROM $events WHERE uid=$uid AND consumed=0");
				$pending = (int)$count['recent'] >= 2 ? 1 : 0;
				self::q("UPDATE $state SET pending=$pending,last_seen_at=$now WHERE uid=$uid");
				self::q('COMMIT');
			}
		}
		catch(Exception $e) { $db->write_query('ROLLBACK', 1); error_log('Forum Fortress repeat-spammer evidence could not be saved.'); }
	}
	public static function run($task)
	{
		global $db, $cache;
		if(!is_array($task) || !isset($task['tid'], $task['file']) || $task['file'] !== 'forumfortress_repeat') throw new RuntimeException('Repeat enforcement requires a separate native task.');
		$native = self::row('SELECT file,locked FROM '.self::table('tasks').' WHERE tid='.(int)$task['tid']);
		if(!$native || $native['file'] !== 'forumfortress_repeat' || !$native['locked']) throw new RuntimeException('Repeat enforcement requires a separate native task.');
		$transaction = self::row('SELECT @@in_transaction AS active,@@autocommit AS automatic');
		if(!$transaction || $transaction['active'] || !$transaction['automatic']) throw new RuntimeException('Repeat enforcement requires a separate native task.');
		$q = self::q('SELECT uid FROM '.self::table('forumfortress_repeat_state').' WHERE pending=1 ORDER BY uid LIMIT 50');
		$ids = array(); while($row = $db->fetch_array($q)) $ids[] = (int)$row['uid'];
		$applied = 0;
		foreach($ids as $uid) if(self::apply($uid)) ++$applied;
		self::prune();
		if($applied) { $cache->update_moderators(); $cache->update_awaitingactivation(); }
		return $applied;
	}
	public static function recent()
	{
		global $db;
		$rows = array();
		if(!$db->table_exists('forumfortress_repeat_state')) return $rows;
		try
		{
			$q = self::q('SELECT uid,attempted_at,acted_at,failed FROM '.self::table('forumfortress_repeat_state').' WHERE attempted_at>0 ORDER BY attempted_at DESC LIMIT 15');
			while($row = $db->fetch_array($q)) $rows[] = $row;
		}
		catch(Exception $ignored) {}
		return $rows;
	}
	private static function apply($uid)
	{
		global $db;
		$claimed = false; $applied = false; $user = null;
		$state = self::table('forumfortress_repeat_state'); $events = self::table('forumfortress_repeat_events');
		try
		{
			// MyBB 1.8's native users/banned tables may be MyISAM. Lock native
			// account, staff, permission and settings tables across the whole action.
			// COMMIT retains MySQL table locks until explicit UNLOCK TABLES.
			self::q('SET autocommit=0');
			$locks = array();
			foreach(array('users','banned','forumfortress_repeat_state','forumfortress_repeat_events') as $name) $locks[] = self::table($name).' WRITE';
			foreach(array('usergroups','moderators','adminoptions','settings','tasks','datacache') as $name) $locks[] = self::table($name).' READ';
			self::q('LOCK TABLES '.implode(',', $locks));
			$user = self::row('SELECT * FROM '.self::table('users').' WHERE uid='.$uid);
			$work = self::row("SELECT * FROM $state WHERE uid=$uid");
			$count = self::row("SELECT COUNT(*) AS recent FROM $events WHERE uid=$uid AND consumed=0 AND observed_at>".(time()-self::WINDOW));
			$task = self::row('SELECT enabled FROM '.self::table('tasks')." WHERE file='forumfortress_repeat' LIMIT 1");
			if($work && $work['pending'] && !$work['attempted_at'])
			{
				if(!self::enabled() || !$task || !$task['enabled'] || !is_file(MYBB_ROOT.'inc/tasks/forumfortress_repeat.php') || !self::eligible($user) || (int)$count['recent'] < 2)
				{
					self::q("UPDATE $state SET pending=0 WHERE uid=$uid");
					self::q("UPDATE $events SET consumed=1 WHERE uid=$uid");
					self::q('COMMIT');
				}
				else
				{
					$group = self::row('SELECT gid FROM '.self::table('usergroups').' WHERE isbannedgroup=1 AND cancp=0 AND canmodcp=0 AND issupermod=0 ORDER BY gid LIMIT 1');
					if(!$group) throw new RuntimeException('No native banned group.');
					$now = time(); $until = ban_date2timestamp('7-0-0', $now);
					if(abs($until-$now-self::DURATION) > 60) throw new RuntimeException('Native seven-day duration unavailable.');
					// Durable BEFORE native writes: MyISAM cannot roll back on a crash.
					// A failed/ambiguous attempt requires moderator review, never a
					// blind retry that could undo an intervening native reversal.
					self::q("UPDATE $state SET pending=0,attempted_at=$now WHERE uid=$uid");
					self::q("UPDATE $events SET consumed=1 WHERE uid=$uid");
					self::q('COMMIT'); $claimed = true;
					self::nativeBan($user, (int)$group['gid'], $now, $until);
					self::q("UPDATE $state SET acted_at=$now WHERE uid=$uid");
					self::q('COMMIT'); $applied = true;
				}
			}
		}
		catch(Exception $e)
		{
			$db->write_query('ROLLBACK', 1);
			if($claimed && $user)
			{
				// Compensate a caught partial native write while native tables are
				// still exclusively locked. Retain the pre-action receipt either way.
				try
				{
					$db->update_query('users', array('usergroup'=>(int)$user['usergroup'],'additionalgroups'=>$db->escape_string($user['additionalgroups']),'displaygroup'=>(int)$user['displaygroup']), 'uid='.$uid);
					$db->delete_query('banned', 'uid='.$uid);
					self::q("UPDATE $state SET failed=1 WHERE uid=$uid"); self::q('COMMIT');
				}
				catch(Exception $ignored) {}
			}
			error_log('Forum Fortress repeat-spammer task failed for member '.$uid.($claimed ? '; native moderation review required.' : '; pending work retained.'));
		}
		$db->write_query('UNLOCK TABLES', 1); $db->write_query('SET autocommit=1', 1);
		return $applied;
	}
	private static function nativeBan($user, $gid, $now, $until)
	{
		global $db;
		// MyBB has no public ban service. Use the native Banning module's
		// record/group format through its DB API; ACP lift and usercleanup own
		// reversal/expiry. Existing accounts, posts and subscriptions are retained.
		$db->insert_query('banned', array('uid'=>(int)$user['uid'],'gid'=>$gid,'oldgroup'=>(int)$user['usergroup'],'oldadditionalgroups'=>$db->escape_string($user['additionalgroups']),'olddisplaygroup'=>(int)$user['displaygroup'],'admin'=>0,'dateline'=>$now,'bantime'=>'7-0-0','lifted'=>$until,'reason'=>'Forum Fortress: two confirmed spam blocks within 24 hours.'));
		$db->update_query('users', array('usergroup'=>$gid,'displaygroup'=>0,'additionalgroups'=>''), 'uid='.(int)$user['uid']);
		$fresh = self::row('SELECT usergroup FROM '.self::table('users').' WHERE uid='.(int)$user['uid']);
		$ban = self::row('SELECT lifted FROM '.self::table('banned').' WHERE uid='.(int)$user['uid']);
		if(!$fresh || (int)$fresh['usergroup'] !== $gid || !$ban || (int)$ban['lifted'] !== $until) throw new RuntimeException('Native ban was not confirmed.');
	}
	private static function prune()
	{
		$events = self::table('forumfortress_repeat_events'); $state = self::table('forumfortress_repeat_state');
		self::q("DELETE FROM $events WHERE observed_at<=".(time()-self::DURATION).' LIMIT 500');
		self::q("DELETE FROM $state WHERE pending=0 AND attempted_at=0 AND last_seen_at<=".(time()-self::DURATION).' LIMIT 500');
		self::q("DELETE FROM $state WHERE uid NOT IN (SELECT uid FROM ".self::table('users').') LIMIT 500');
	}
}

/** The native setting metadata must never contain the current plaintext key. */
class ForumFortressMyBBIdentity
{
	private static $present = null;
	private static $value = null;
	public static function ready()
	{
		global $db;
		if(self::$present === null)
		{
			self::$present = false;
			if(defined('MYBB_ROOT') && isset($db->type) && in_array($db->type, array('mysql','mysqli','pdomysql'), true) && $db->table_exists('forumfortress_identity'))
			{
				$q = $db->simple_select('forumfortress_identity', 'name', "name='api_key'");
				self::$present = (bool)$db->fetch_field($q, 'name');
			}
		}
		return self::$present;
	}
	public static function value()
	{
		global $db;
		if(self::$value === null)
		{
			$q = $db->simple_select('forumfortress_identity', 'secret', "name='api_key'");
			self::$value = (string)$db->fetch_field($q, 'secret');
		}
		return self::$value;
	}
	public static function save($value)
	{
		global $db, $mybb;
		$db->update_query('forumfortress_identity', array('secret'=>$db->escape_string((string)$value)), "name='api_key'");
		self::$value = (string)$value;
		$db->update_query('settings', array('value'=>''), "name='forumfortress_api_key'");
		$mybb->settings['forumfortress_api_key'] = '';
	}
	public static function install()
	{
		global $db, $mybb;
		if(!defined('MYBB_ROOT') || !isset($db->type) || !in_array($db->type, array('mysql','mysqli','pdomysql'), true)) return;
		try
		{
			if(!$db->table_exists('forumfortress_identity') && $db->write_query('CREATE TABLE '.TABLE_PREFIX.'forumfortress_identity (name varchar(24) NOT NULL PRIMARY KEY,secret text NOT NULL) ENGINE=InnoDB'.$db->build_create_table_collation(), 1) === false) return;
		}
		catch(Exception $ignored) { return; }
		$q = $db->simple_select('forumfortress_identity', 'name', "name='api_key'");
		if(!$db->fetch_field($q, 'name'))
		{
			$q = $db->simple_select('settings', 'value', "name='forumfortress_api_key'");
			$old = (string)$db->fetch_field($q, 'value');
			$db->insert_query('forumfortress_identity', array('name'=>'api_key','secret'=>$db->escape_string($old)));
			// Confirm the durable copy before clearing legacy native metadata.
			$q = $db->simple_select('forumfortress_identity', 'secret', "name='api_key'");
			if((string)$db->fetch_field($q, 'secret') !== $old) throw new RuntimeException('Stored key migration could not be confirmed.');
		}
		$db->update_query('settings', array('value'=>''), "name='forumfortress_api_key'");
		$mybb->settings['forumfortress_api_key'] = ''; self::$present = true; self::$value = null;
	}
	public static function uninstall()
	{
		global $db;
		if($db->table_exists('forumfortress_identity')) $db->drop_table('forumfortress_identity');
		self::$present = false; self::$value = null;
	}
}
