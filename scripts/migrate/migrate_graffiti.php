<?php

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');

require_once(IZNIK_BASE . '/include/group/Group.php');

$dsn = "mysql:host={$dbconfig['host']};dbname=republisher;charset=utf8";

$dbhd = new LoggedPDO($dsn, $dbconfig['user'], $dbconfig['pass']);

$g = Group::get($dbhr, $dbhm);

$sql = "SELECT * FROM `groups` WHERE grouppublish = 1;";
$fgroups = $dbhd->query($sql);

foreach ($fgroups as $fgroup) {
    error_log("FD group {$fgroup['groupname']}");
    $gid = $g->findByShortName($fgroup['groupname']);

    $name = $fgroup['facebookpage'];
    if ($name) {
        $p = strrpos($name, '/');
        if ($p && $fgroup['graffititoken']) {
            $t = new GroupFacebook($dbhr, $dbhm, $gid);
            $name = substr($name, $p + 1);
            error_log("...$name");
            $t->set($fgroup['graffititoken']);
        }
    }
}

