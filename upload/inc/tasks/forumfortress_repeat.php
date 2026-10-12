<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress.
// SPDX-License-Identifier: GPL-2.0-or-later
if(!defined('IN_MYBB')) die('This file cannot be accessed directly.');
function task_forumfortress_repeat($task)
{
	require_once MYBB_ROOT.'inc/plugins/forumfortress.php';
	if(!class_exists('ForumFortressMyBBRepeat') || !ForumFortressMyBBRepeat::available()) return;
	$count = ForumFortressMyBBRepeat::run($task);
	add_task_log($task, 'Forum Fortress applied '.$count.' native seven-day bans. Failed or ambiguous attempts require native moderator review.');
}
