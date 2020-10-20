<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

error_log("Start at " . date("Y-m-d H:i:s"));

$groups = $dbhr->preQuery("SELECT groups.id, groups.nameshort FROM groups INNER JOIN groups_twitter ON groups.id = groups_twitter.groupid WHERE type = ? AND publish = 1 AND valid = 1 AND onhere = 1 ORDER BY LOWER(nameshort) ASC;", [
    Group::GROUP_FREEGLE
]);

foreach ($groups as $group) {
    $g = Group::get($dbhr, $dbhm, $group['id']);

    # Don't send for closed groups.
    if (!$g->getSetting('closed')) {
        $t = new Twitter($dbhr, $dbhm, $group['id']);
        $count = $t->tweetMessages();
        error_log("{$group['nameshort']} $count");
    }
}

error_log("Finish at " . date("Y-m-d H:i:s"));

Utils::unlockScript($lockh);