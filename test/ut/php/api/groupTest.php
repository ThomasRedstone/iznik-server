<?php
namespace Freegle\Iznik;

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}

require_once(UT_DIR . '/../../include/config.php');
require_once(UT_DIR . '/../../include/db.php');

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class groupAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test';");
        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM `groups` WHERE nameshort = 'testgroup2';");

        # Create a moderator
        $g = Group::get($this->dbhr, $this->dbhm);
        $this->group = $g;

        $this->groupid = $g->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $emailid = $this->user->addEmail('test@test.com');
        $this->user->addMembership($this->groupid, User::ROLE_MEMBER, $emailid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    protected function tearDown() {
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");

        parent::tearDown ();
    }

    public function testCreate()
    {
        # Not logged in - should fail
        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup'
        ]);
        assertEquals(1, $ret['ret']);

        # Logged in - not mod, can't create
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup2'
        ]);

        assertEquals(1, $ret['ret']);
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);

        $ret = $this->call('group', 'POST', [
            'action' => 'Create',
            'grouptype' => 'Reuse',
            'name' => 'testgroup3'
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);

        # Should be owner.
        $ret = $this->call('group', 'GET', [
            'id' => $ret['id'],
            'members' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(User::ROLE_OWNER, $ret['group']['myrole']);
        assertEquals(1, count($ret['group']['members']));
        assertEquals($this->uid, $ret['group']['members'][0]['userid']);
        assertEquals(User::ROLE_OWNER, $ret['group']['members'][0]['role']);
    }

    public function testGet() {
        # Not logged in - shouldn't see members list
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($this->groupid, $ret['group']['id']);
        assertFalse(Utils::pres('members', $ret['group']));

        # By short name
        $ret = $this->call('group', 'GET', [
            'id' => 'testgroup',
            'members' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($this->groupid, $ret['group']['id']);
        assertFalse(Utils::pres('members', $ret['group']));

        # Duff shortname
        $ret = $this->call('group', 'GET', [
            'id' => 'testinggroup',
            'members' => TRUE
        ]);
        assertEquals(2, $ret['ret']);

        # Member - shouldn't see members list
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->log(var_export($ret, true));
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('members', $ret['group']));

        # Moderator - should see members list
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'members' => TRUE
        ]);
        $this->log("Members " . var_export($ret, true));
        assertEquals(0, $ret['ret']);

        assertEquals(1, count($ret['group']['members']));
        assertEquals('test@test.com', $ret['group']['members'][0]['email']);

        }

    public function testPatch() {
        # Not logged in - shouldn't be able to set
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('members', $ret));

        # Member - shouldn't either
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('members', $ret));

        # Owner - should be able to
        $this->user->setRole(User::ROLE_OWNER, $this->groupid);
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'settings' => [
                'mapzoom' => 12
            ]
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        assertEquals(12, $ret['group']['settings']['mapzoom']);

        # Support attributes
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'lat' => 10
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        assertEquals(10, $ret['group']['lat']);

        # Valid and invalid polygon
        $polystr = 'POLYGON((59.58984375 9.102096738726456,54.66796875 -5.0909441750333855,65.7421875 -6.839169626342807,76.2890625 -4.740675384778361,74.8828125 6.4899833326706515,59.58984375 9.102096738726456))';
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => $polystr
        ]);
        assertEquals(0, $ret['ret']);

        # Check we can see it.
        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'polygon' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($polystr, $ret['group']['polygon']);

        # Invalid polygon
        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'poly' => 'POLYGON((59.58984375 9.102096738726456,54.66796875 -5.0909441750333855,65.7421875 -6.839169626342807,76.2890625 -4.740675384778361,74.8828125 6.4899833326706515,59.58984375 9.102096738726456)))'
        ]);
        assertEquals(3, $ret['ret']);

        # Profile
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $a = new Attachment($this->dbhr, $this->dbhm, NULL, Attachment::TYPE_GROUP);
        $attid = $a->create(NULL, $data);
        assertNotNull($attid);

        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'profile' => $attid,
            'tagline' => 'Test slogan'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'pending' => true
        ]);
        assertNotFalse(strpos($ret['group']['profile'], $attid));
        assertEquals('Test slogan', $ret['group']['tagline']);

        }

    public function testConfirmMod() {
        $ret = $this->call('group', 'POST', [
            'action' => 'ConfirmKey',
            'id' => $this->groupid
        ]);
        $this->log(var_export($ret, true));
        assertEquals(0, $ret['ret']);
        $key = $ret['key'];

        # And again but with support status so it goes through.
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('group', 'POST', [
            'action' => 'ConfirmKey',
            'dup' => TRUE,
            'id' => $this->groupid
        ]);
        $this->log(var_export($ret, true));
        assertEquals(100, $ret['ret']);

        }

    public function testList() {
        $ret = $this->call('groups', 'GET', [
            'grouptype' => 'Freegle'
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testShowmods() {
        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'showmods' => TRUE
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('showmods', $ret['group']));

        assertTrue($this->user->login('testpw'));
        $ret = $this->call('session', 'PATCH', [
            'settings' => [
                'showmod' => TRUE
            ]
        ]);
        assertEquals(0, $ret['ret']);
        $this->log("Settings after patch for {$this->uid} " . $this->user->getPrivate('settings'));

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'showmods' => TRUE
        ]);
        $this->log("Returned " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertTrue(array_key_exists('showmods', $ret['group']));
        assertEquals(1, count($ret['group']['showmods']));
        assertEquals($this->uid, $ret['group']['showmods'][0]['id']);

        }

    public function testAffiliation() {
        assertTrue($this->user->login('testpw'));

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);

        $confdate = Utils::ISODate('@' . time());

        $ret = $this->call('group', 'PATCH', [
            'id' => $this->groupid,
            'affiliationconfirmed' => $confdate
        ]);

        assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'affiliationconfirmedby' => TRUE
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals($this->uid, $ret['group']['affiliationconfirmedby']['id']);
        assertEquals($confdate, $ret['group']['affiliationconfirmed']);
    }

    public function testLastActive() {
        # Approve a message onto the group.
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        assertTrue($this->user->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->groupid,
            'action' => 'Approve'
        ]);
        assertEquals(0, $ret['ret']);
        $this->waitBackground();

        # Get the mods.
        $ret = $this->call('memberships', 'GET', [
            'id' => $this->groupid,
            'filter' => Group::FILTER_MODERATORS,
            'members' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['members']));
    }

    public function testSponsors()
    {
        $this->dbhm->preExec("INSERT INTO groups_sponsorship (groupid, name, startdate, enddate, contactname, contactemail, amount) VALUES (?, 'testsponsor', NOW(), NOW(), 'test', 'test', 1);", [
            $this->groupid
        ]);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid,
            'sponsors' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['group']['sponsors']));
        assertEquals('testsponsor', $ret['group']['sponsors'][0]['name']);
    }

    public function testRemoveFacebook() {
        $gf = new GroupFacebook($this->dbhr, $this->dbhm);
        $uid = $gf->add($this->groupid, 'UT', 'UT', 1);

        $this->user->setPrivate('systemrole', User::ROLE_MODERATOR);
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        assertEquals(1, count($ret['group']['facebook']));

        $ret = $this->call('group', 'POST', [
            'action' => 'RemoveFacebook',
            'groupid' => $this->groupid,
            'uid' => $uid
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('group', 'GET', [
            'id' => $this->groupid
        ]);
        assertEquals(0, count($ret['group']['facebook']));
    }
}

