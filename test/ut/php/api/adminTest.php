<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/group/Alerts.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class adminAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM admins WHERE subject LIKE 'UT %';");

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_UT);
        $g->setPrivate('onyahoo', 0);
        $this->groupid2 = $g->create('testgroup2', Group::GROUP_UT);
        $g->setPrivate('onyahoo', 0);
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->dbhm->preExec("DELETE FROM admins WHERE subject LIKE 'UT %';");
    }

    public function testBasic()
    {
        $admindata = [
            'groupid' => $this->groupid,
            'subject' => 'UT Admin',
            'text' => 'Please ignore this - generated by Iznik UT'
        ];

        # Can't create logged out.
        $ret = $this->call('admin', 'POST', $admindata);
        assertEquals(1, $ret['ret']);

        # Or logged in as non-mod
        assertTrue($this->user->login('testpw'));
        $this->user->addMembership($this->groupid);
        $admindata['dup'] = 1;
        $ret = $this->call('admin', 'POST', $admindata);
        assertEquals(2, $ret['ret']);

        # Can create as mod
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $admindata['dup']++;
        $ret = $this->call('admin', 'POST', $admindata);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Should now be able to get it.
        $ret = $this->call('admin', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['admin']['id']);

        foreach ($admindata as $key => $val) {
            if ($key != 'dup') {
                assertEquals($val, $ret['admin'][$key]);
            }
        }

        assertEquals(1, $ret['admin']['pending']);
        assertFalse(pres('heldby', $ret['admin']));

        # And also get it via list.
        $ret = $this->call('admin', 'GET', [ 'groupid' => $this->groupid ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['admins'][0]['id']);

        # Hold it
        $ret = $this->call('admin', 'POST', [
            'id' => $id,
            'action' => 'Hold'
        ]);

        $ret = $this->call('admin', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['admin']['id']);
        assertNotNull('heldby', $ret['admin']['heldby']);

        # Release it
        $ret = $this->call('admin', 'POST', [
            'id' => $id,
            'action' => 'Release'
        ]);

        $ret = $this->call('admin', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['admin']['id']);
        assertFalse(pres('heldby', $ret['admin']));

        # Now send - none to find, as we don't have an email on our domain.
        $a = new Admin($this->dbhr, $this->dbhm);
        assertEquals(0, $a->process($id, TRUE));
        $this->dbhm->preExec("UPDATE admins SET complete = NULL WHERE id = $id");

        # Send again with an email present - still none as pending.
        $this->user->addEmail('test@blackhole.io', 1, TRUE);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $eid = $this->user->addEmail($email, 0, FALSE);
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR, $eid);
        assertEquals(0, $a->process($id, TRUE));

        # Check it shows as pending.
        $ret = $this->call('admin', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['admins'][0]['id']);

        # And in work.
        $ret = $this->call('session', 'GET', [
            'components' => [
                'work'
            ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['work']['pendingadmins']);

        # Now approve it and send
        $ret = $this->call('admin', 'PATCH', [ 'id' => $id, 'pending' => 0 ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $a->process($id, TRUE));

        # Fake error for coverage
        $this->user->addEmail('testblackhole.io', 1, TRUE);
        $this->dbhm->preExec("UPDATE admins SET complete = NULL WHERE id = $id");
        assertEquals(0, $a->process($id, TRUE));

        $ret = $this->call('admin', 'DELETE', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
    }

    public function testSuggested() {
        $admindata = [
            'subject' => 'UT Admin',
            'text' => 'Please ignore this - generated by Iznik UT',
            'dup' => 1
        ];

        $this->user->addEmail('test1@test.com');
        $this->user2->addEmail('test2@test.com');

        # Can't create suggested admin as just mod
        assertTrue($this->user->login('testpw'));
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->user->addMembership($this->groupid2, User::ROLE_MEMBER);
        $ret = $this->call('admin', 'POST', $admindata);
        assertEquals(2, $ret['ret']);

        # But can as support
        $this->user2->addMembership($this->groupid2, User::ROLE_MODERATOR);
        $this->user2->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        assertTrue($this->user2->login('testpw'));

        $admindata['dup']++;
        $ret = $this->call('admin', 'POST', $admindata);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # Copying happens in batch so do it directly.
        $a = new Admin($this->dbhr, $this->dbhm, $id);
        $pergroup1 = $a->copyForGroup($this->groupid);
        assertNotNull($pergroup1);
        $pergroup2 = $a->copyForGroup($this->groupid2);
        assertNotNull($pergroup2);

        # Now approve it on first group and send
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('admin', 'PATCH', [ 'id' => $pergroup1, 'pending' => 0 ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $a->process($pergroup1, TRUE));

        # Now approve it on the second group and send - should go to user2 but not user.
        assertTrue($this->user2->login('testpw'));
        $ret = $this->call('admin', 'PATCH', [ 'id' => $pergroup2, 'pending' => 0 ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $a->process($pergroup2, TRUE));
    }
}
