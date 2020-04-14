<?php
function socialactions() {
    global $dbhr, $dbhm;

    $ctx = presdef('context', $_REQUEST, NULL);
    $id = presdef('id', $_REQUEST, NULL);
    $id = $id ? intval($id) : $id;
    $uid = intval(presdef('uid', $_REQUEST, NULL));
    $msgid = intval(presdef('msgid', $_REQUEST, NULL));

    $ret = [ 'ret' => 100, 'status' => 'Unknown verb' ];

    switch ($_REQUEST['type']) {
        case 'GET':
            $f = new GroupFacebook($dbhr, $dbhm);
            $actions = $f->listSocialActions($ctx);
            $ret = [
                'ret' => 0,
                'status' => 'Success',
                'socialactions' => $actions,
                'context' => $ctx
            ];
           break;

        case 'POST':
            $f = new GroupFacebook($dbhr, $dbhm, $uid);
            $action = presdef('action', $_REQUEST, GroupFacebook::ACTION_DO);

            switch ($action) {
                case GroupFacebook::ACTION_DO:
                    $f->performSocialAction($id);
                    break;
                case GroupFacebook::ACTION_HIDE:
                    $f->hideSocialAction($id);
                    break;
            }

            $ret = [
                'ret' => 0,
                'status' => 'Success'
            ];
            break;
    }

    return ($ret);
}