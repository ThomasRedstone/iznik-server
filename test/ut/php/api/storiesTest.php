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
class storiesAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users_stories WHERE headline LIKE 'Test%';");
    }

    public function testBasic() {
        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $u->addMembership($this->groupid);

        # Create logged out - should fail
        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test',
            'story' => 'Test'
        ]);
        assertEquals(1, $ret['ret']);

        # Create logged in - should work
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('stories', 'PUT', [
            'headline' => 'Test',
            'story' => 'Test2'
        ]);
        assertEquals(0, $ret['ret']);

        $id = $ret['id'];
        assertNotNull($id);

        # Get with id - should work
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['story']['id']);
        self::assertEquals('Test', $ret['story']['headline']);
        self::assertEquals('Test2', $ret['story']['story']);

        # Edit
        $ret = $this->call('stories', 'PATCH', [
            'id' => $id,
            'headline' => 'Test2',
            'story' => 'Test2',
            'public' => 0,
            'newsletter' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        self::assertEquals('Test2', $ret['story']['headline']);
        self::assertEquals('Test2', $ret['story']['story']);

        # List stories - should be none as we're not a mod.
        $ret = $this->call('stories', 'GET', [ 'groupid' => $this->groupid ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['stories']));

        # Get logged out - should fail, not public
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        assertEquals(2, $ret['ret']);

        # Make us a mod
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('stories', 'GET', [
            'reviewed' => 0
        ]);
        $this->log("Get as mod " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['stories']));
        self::assertEquals($id, $ret['stories'][0]['id']);

        $ret = $this->call('stories', 'PATCH', [
            'id' => $id,
            'reviewed' => 1,
            'public' => 1
        ]);
        assertEquals(0, $ret['ret']);

        # Get logged out - should work.
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['story']['id']);
        assertEquals(0, $ret['story']['likes']);
        assertFalse($ret['story']['liked']);

        # List for this group - should work.
        $ret = $this->call('stories', 'GET', [ 'groupid' => $this->groupid ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['stories'][0]['id']);

        # List for newsletter - should not be there as not yet reviewed for it.
        $ret = $this->call('stories', 'GET', [ 'reviewnewsletter' => TRUE ]);
        assertEquals(0, $ret['ret']);
        $found = FALSE;
        foreach ($ret['stories'] as $story) {
            if ($story['id'] == $id) {
                $found = TRUE;
            }
        }
        assertFalse($found);

        # Flag as reviewed for inclusion in the newsletter.
        $s = new Story($this->dbhr, $this->dbhm, $id);
        $s->setPrivate('reviewed', 1);
        $s->setPrivate('public', 1);
        $s->setPrivate('newsletterreviewed', 1);
        $s->setPrivate('mailedtomembers', 0);

        # Should now show up for mods to like their favourites.
        $ret = $this->call('stories', 'GET', [ 'reviewnewsletter' => TRUE ]);
        assertEquals(0, $ret['ret']);
        $found = FALSE;
        foreach ($ret['stories'] as $story) {
            if ($story['id'] == $id) {
                $found = TRUE;
            }
        }
        assertTrue($found);

        # Like it.
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('stories', 'POST', [
            'id' => $id,
            'action' => Story::LIKE
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['story']['likes']);
        assertTrue($ret['story']['liked']);

        $ret = $this->call('stories', 'POST', [
            'id' => $id,
            'action' => Story::UNLIKE
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('stories', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['story']['likes']);
        assertFalse($ret['story']['liked']);

        # Delete logged out - should fail
        $_SESSION['id'] = NULL;
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(2, $ret['ret']);

        assertTrue($this->user->login('testpw'));

        # Delete - should work
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        # Delete - fail
        $ret = $this->call('stories', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(2, $ret['ret']);

        }

    function testAsk() {
        # Create the sending user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->log("Created user $uid");
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addEmail('test@test.com'));
        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        assertEquals(1, $u->addMembership($this->groupid));
        $u->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        # Send a message.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Subject: Basic test', 'Subject: [Group-tag] Offer: thing (place)', $msg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $origid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        assertNotNull($origid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Shouldn't yet appear.
        $s = new Story($this->dbhr, $this->dbhm);
        self::assertEquals(0, $s->askForStories('2017-01-01', $uid, 0, 2, NULL));

        # Now mark the message as complete
        $this->log("Mark $origid as TAKEN");
        $m = new Message($this->dbhr, $this->dbhm, $origid);
        $m->mark(Message::OUTCOME_TAKEN, "Thanks", User::HAPPY, $uid);

        # Now should ask.
        self::assertEquals(1, $s->askForStories('2017-01-01', $uid, 0, 0, NULL));

        # But not a second time
        self::assertEquals(0, $s->askForStories('2017-01-01', $uid, 0, 0, NULL));
    }
}

