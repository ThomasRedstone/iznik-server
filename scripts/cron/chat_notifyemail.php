<?php
# Notify by email of unread chats

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/chat/ChatRoom.php');
global $dbhr, $dbhm;

$lockh = lockScript(basename(__FILE__));

$c = new ChatRoom($dbhr, $dbhm);

$count = $c->notifyByEmail(593252, ChatRoom::TYPE_USER2MOD, TRUE);
error_log("Sent $count for User2Mod");

#$count = $c->notifyByEmail(NULL, ChatRoom::TYPE_USER2USER, TRUE);
#error_log("Sent $count to User2User");

unlockScript($lockh);