<?php
// Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress.
// SPDX-License-Identifier: GPL-2.0-or-later
$l['setting_forumfortress_repeat_ban'] = 'Ban repeat spammers for seven days';
$l['setting_forumfortress_repeat_ban_desc'] = 'Off by default. Two distinct confirmed spam BLOCKs within 24 hours can ban a member who joined within seven days and has at most ten posts. Administrators, moderators, custom groups and established members are protected. Reversal and expiry use native MyBB bans. The Forum Fortress native task must be installed and enabled.';
$l['setting_forumfortress_repeat_cleanup'] = 'Also clean eligible public replies';
$l['setting_forumfortress_repeat_cleanup_desc'] = 'Off by default. Requires three distinct cleanup-eligible blocks in 24 hours and five or fewer posts. After a confirmed ban, soft-delete up to five public replies. Topic starters, private messages, profiles and signatures are excluded. Review or restore cleaned replies using MyBB Deleted Posts.';
$l['forumfortress_repeat_unavailable'] = 'Unavailable: an enabled Forum Fortress native task, MariaDB transaction-status support, InnoDB evidence tables and a native banned group are required.';
$l['setting_forumfortress_api_key_desc'] = 'Stored automatically after bootstrap. Paste a replacement key, or leave blank to preserve the stored key. The current value is never shown.';
