<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/misc/Location.php';
require_once IZNIK_BASE . '/include/message/MessageCollection.php';
require_once IZNIK_BASE . '/include/message/WorryWords.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class messageAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM messages_drafts WHERE (SELECT fromuser FROM messages WHERE id = msgid) IS NULL;");
        $dbhm->preExec("DELETE FROM worrywords WHERE keyword LIKE 'UTtest%';");

        $this->group = Group::get($this->dbhr, $this->dbhm);
        $this->gid = $this->group->create('testgroup', Group::GROUP_FREEGLE);
        $this->group = Group::get($this->dbhr, $this->dbhm, $this->gid);
        $this->group->setPrivate('onhere', 1);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail('test@test.com');
        $u->addEmail('sender@example.net');
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $u->addMembership($this->gid);
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $this->user = $u;
    }

    public function testLoggedOut() {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        $this->log("Message returned when logged out " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);
        assertFalse(array_key_exists('fromuser', $ret['message']));

        }

    public function testApproved()
    {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        $a->setPrivate('sourceheader', Message::PLATFORM);

        # Should be able to see this message even logged out.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);
        assertFalse(array_key_exists('fromuser', $ret['message']));

        # When logged in should be able to see message history.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Approved'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);
        assertFalse(array_key_exists('emails', $ret['message']['fromuser']));

        # Test we can get the message history.
        $ret = $this->call('messages', 'GET', [
            'id' => $id,
            'collection' => 'Approved',
            'summary' => FALSE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['messages'][0]['id']);
        assertEquals(1, count($ret['messages'][0]['fromuser']['messagehistory']));

        $atts = $a->getPublic();
        assertEquals(1, count($atts['fromuser']['messagehistory']));
        assertEquals($id, $atts['fromuser']['messagehistory'][0]['id']);
        assertEquals('Other', $atts['fromuser']['messagehistory'][0]['type']);
        assertEquals('Basic test', $atts['fromuser']['messagehistory'][0]['subject']);

        $a->delete();
        $this->group->delete();

        }

    public function testBadColl()
    {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to see a bad collection
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'BadColl'
        ]);
        assertEquals(101, $ret['ret']);

        }

    public function testPending()
    {
        # Create a group with a message on it
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);

        # Shouldn't be able to see pending logged out
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'collection' => 'Pending'
        ]);
        assertEquals(1, $ret['ret']);

        # Now join - shouldn't be able to see a pending message as user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to mod - should be able to see it.
        $u->setRole(User::ROLE_MODERATOR, $this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);

        $a->delete();

        }

    public function testSpam()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($id, $a->getID());
        assertTrue(array_key_exists('subject', $a->getPublic()));

        # Shouldn't be able to see spam logged out
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(1, $ret['ret']);

        # Now join - shouldn't be able to see a spam message as user
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to see it.
        $u->setRole(User::ROLE_OWNER, $this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);

        # Delete it - as a user should fail
        $u->setRole(User::ROLE_MEMBER, $this->gid);
        $ret = $this->call('message', 'DELETE', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Approved'
        ]);
        assertEquals(2, $ret['ret']);

        $u->setRole(User::ROLE_OWNER, $this->gid);
        $ret = $this->call('message', 'DELETE', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone
        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(3, $ret['ret']);

        }

    public function testSpamToApproved()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($id, $a->getID());
        assertTrue(array_key_exists('subject', $a->getPublic()));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_OWNER);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);

        # Mark as not spam.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam'
        ]);
        assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone from spam into approved
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->log("Should be in approved " . var_export($ret['message']['groups'], TRUE));
        assertEquals('Approved', $ret['message']['groups'][0]['collection']);

        # Now send it again - should stay in approved.
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id2 = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        #assertEquals(MailRouter::INCOMING_SPAM, $rc);
        self::assertEquals($id, $id2);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $this->log("Should still be in approved " . var_export($ret['message']['groups'], TRUE));
        assertEquals('Approved', $ret['message']['groups'][0]['collection']);

        }

    public function testSpamNoLongerMember()
    {
        # Create a group with a message on it
        $msg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $this->log("Created spam message $id");
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $a = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($id, $a->getID());
        assertTrue(array_key_exists('subject', $a->getPublic()));

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid, User::ROLE_OWNER);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['message']['id']);

        # Remove member from group.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $fromuid = $m->getFromuser();
        $fromu = new User($this->dbhr, $this->dbhm, $fromuid);
        $fromu->removeMembership($this->gid);

        # Mark as not spam.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam'
        ]);
        assertEquals(0, $ret['ret']);

        # Try again to see it - should be gone from spam into approved
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(3, $ret['ret']);

        }

    public function testApprove()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);

        # Send from a user at our domain, so that we can cover the reply going back to them
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $u->addEmail('from@test.com');
        $u->addEmail($email);

        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $this->dbhm->preExec("UPDATE messages_groups SET yahooapprove = 'test@test.com', yahoopendingid = 1 WHERE msgid = $id;");

        # Suppress mails.
        $m = $this->getMockBuilder('Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();
        $m->method('mailer')->willReturn(false);

        # Shouldn't be able to approve logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to approve as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'dup' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to approve it.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Specific');
        $c->setPrivate('ccrejectaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'duplicate' => 1,
            'subject' => 'Test',
            'body' => 'Test',
            'stdmsgid' => $sid
        ]);
        assertEquals(0, $ret['ret']);

        # Sleep for background logging
        $this->waitBackground();

        # Get the logs - should reference the stdmsg.
        $ret = $this->call('user', 'GET', [
            'id' => $uid,
            'logs' => TRUE
        ]);

        $log = $this->findLog('Message', 'Approved', $ret['user']['logs']);
        assertEquals($sid, $log['stdmsgid']);

        $s->delete();
        $c->delete();

        # Message should now exist but approved.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $groups = $m->getGroups();
        assertEquals($this->gid, $groups[0]);
        $p = $m->getPublic();
        $this->log("After approval " . var_export($p, TRUE));
        assertEquals('Approved', $p['groups'][0]['collection']);
        assertEquals($uid, $p['groups'][0]['approvedby']['id']);

        # Should be gone
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Approve',
            'duplicate' => 2
        ]);
        assertEquals(3, $ret['ret']);

        }

    public function testReject()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Subject: Basic test', 'Subject: OFFER: thing (place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $this->dbhm->preExec("UPDATE messages_groups SET yahooreject = 'test@test.com', yahoopendingid = 1 WHERE msgid = $id;");

        # Suppress mails.
        $m = $this->getMockBuilder('Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();
        $m->method('mailer')->willReturn(false);

        assertEquals(Message::TYPE_OFFER, $m->getType());
        $senduser = $m->getFromUser();
        error_log("Send user $senduser");

        # Set to platform for testing message visibility.
        $m->setPrivate('sourceheader', Message::PLATFORM);

        # Shouldn't be able to reject logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to reject as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'dup' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to reject it.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Me');
        $c->setPrivate('fromname', 'Groupname Moderator');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'stdmsgid' => $sid,
            'action' => 'Reject',
            'subject' => 'Test reject',
            'body' => 'Test body',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $s->delete();
        $c->delete();

        # User should have modmails.
        $this->waitBackground();
        $u->updateModMails($senduser);
        $ret = $this->call('user', 'GET', [
            'id' => $senduser,
            'logs' => TRUE,
            'modmailsonly' => TRUE
        ]);
        assertEquals(Log::TYPE_MESSAGE, $ret['user']['logs'][0]['type']);
        assertEquals(Log::SUBTYPE_REJECTED, $ret['user']['logs'][0]['subtype']);

        assertEquals(1, $ret['user']['modmails']);

        # The message should exist as rejected.  Should be able to see logged out
        $this->log("Can see logged out");
        $_SESSION['id'] = NULL;
        $ret = $this->call('message', 'GET', [
            'id' => $m->getId()
        ]);
        assertEquals(0, $ret['ret']);

        # Now log in as the sender.
        $uid = $m->getFromuser();
        $this->log("Found sender as $uid");
        $u = User::get($this->dbhm, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $this->log("Message $id should now be rejected");
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::REJECTED,
            'groupid' => $this->gid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['messages']));
        assertEquals($id, $ret['messages'][0]['id']);
        $this->log("Indeed it is");

        # We should have a chat message.
        $ret = $this->call('chatrooms', 'GET', [
            'chattypes' => [ ChatRoom::TYPE_USER2MOD ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        $chatid = $ret['chatrooms'][0]['id'];

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $chatid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatmessages']));
        $chatmsgid = $ret['chatmessages'][0]['id'];

        # And it should be flagged as mailed.
        $r = new ChatRoom($this->dbhr, $this->dbhm, $chatid);
        $roster = $r->getRoster();
        $found = FALSE;

        foreach ($roster as $rost) {
            if ($rost['userid'] == $uid) {
                $found = TRUE;
                assertEquals($chatmsgid, $rost['lastmsgemailed']);
            }
        }
        assertEquals(TRUE, $found);

        # Try to convert it back to a draft.
        $this->log("Back to draft");
//        $this->dbhm->errorLog = TRUE;
//        $this->dbhr->errorLog = TRUE;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'RejectToDraft'
        ]);
        assertEquals(0, $ret['ret']);

        # Check it's a draft.  Have to be logged in to see that.
        $this->log("Check draft");
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::DRAFT
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['messages']));
        assertEquals($id, $ret['messages'][0]['id']);

        # Coverage of rollback case.
        $this->log("Rollback");
        $m2 = new Message($this->dbhr, $this->dbhm);
        assertFalse($m2->backToDraft());

        # Should be gone from the messages we can see as a mod
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'duplicate' => 2
        ]);
        assertEquals(3, $ret['ret']);
    }

    public function testReply()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        # Suppress mails.
        $m = $this->getMockBuilder('Message')
            ->setConstructorArgs(array($this->dbhr, $this->dbhm, $id))
            ->setMethods(array('mailer'))
            ->getMock();
        $m->method('mailer')->willReturn(false);

        assertEquals(Message::TYPE_OTHER, $m->getType());

        # Shouldn't be able to mail logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to mail as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply',
            'dup' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to reply.  Suppress the mail.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $c = new ModConfig($this->dbhr, $this->dbhm);
        $cid = $c->create('Test');
        $c->setPrivate('ccrejectto', 'Specifc');
        $c->setPrivate('ccrejectaddr', 'test@test.com');

        $s = new StdMessage($this->dbhr, $this->dbhm);
        $sid = $s->create('Test', $cid);
        $s->setPrivate('action', 'Leave Approved Message');
        $s = new StdMessage($this->dbhr, $this->dbhm, $sid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reply',
            'stdmsgid' => $sid,
            'subject' => 'Test reply',
            'body' => 'Test body',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $s->delete();
        $c->delete();
    }

    public function testDelete()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $this->dbhm->preExec("UPDATE messages_groups SET yahooreject = 'test@test.com' WHERE msgid = $id;");

        # Shouldn't be able to delete logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to delete as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete',
            'dup' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to delete it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);

        # Should be gone
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Reject',
            'duplicate' => 2
        ]);
        assertEquals(3, $ret['ret']);

        # Route and delete approved.
        $this->log("Route and delete approved");
        $msg = $this->unique($msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Delete'
        ]);
        assertEquals(0, $ret['ret']);

        }

    public function testNotSpam()
    {
        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/spam');
        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $origmsg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        # Shouldn't be able to do this logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to do this as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam',
            'dup' => 2
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to do this it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'NotSpam',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);

        # Message should now be in pending.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $msgs = $ret['messages'];
        assertEquals(1, count($msgs));
        assertEquals($id, $msgs[0]['id']);

        # Spam should be empty.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Spam'
        ]);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);

        # Pending should be empty.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        # Again as admin
        $u->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);

        $msg = str_ireplace('To: FreeglePlayground <freegleplayground@yahoogroups.com>', 'To: "testgroup@yahoogroups.com" <testgroup@yahoogroups.com>', $origmsg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from1@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::INCOMING_SPAM, $rc);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Spam'
        ]);
        assertEquals(0, $ret['ret']);

        # Pending should be empty.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => 'Pending'
        ]);
        $msgs = $ret['messages'];
        assertEquals(0, count($msgs));

        }

    public function testHold()
    {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $this->dbhm->preExec("UPDATE messages_groups SET yahooreject = 'test@test.com', yahoopendingid = 1 WHERE msgid = $id;");

        # Shouldn't be able to hold logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold'
        ]);
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to hold as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold',
            'dup' => 1
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to hold it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Hold',
            'duplicate' => 1
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid
        ]);
        assertEquals($uid, $ret['message']['heldby']['id']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'groupid' => $this->gid,
            'action' => 'Release',
            'duplicate' => 2
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
            'groupid' => $this->gid
        ]);
        assertFalse(pres('heldby', $ret['message']));

        }

    public function testEditAsMod()
    {
        # Create an attachment
        $cwd = getcwd();
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        file_put_contents("/tmp/chair.jpg", $data);
        chdir($cwd);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg',
                'type' => 'image/jpeg'
            ],
            'identify' => FALSE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $attid = $ret['id'];

        $this->group->setPrivate('onyahoo', 1);
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)',0);

        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertFalse($m->isEdited());
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        # Shouldn't be able to edit logged out
        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit',
            'attachments' => []
        ]);

        $this->log(var_export($ret, true));
        assertEquals(2, $ret['ret']);

        # Now join - shouldn't be able to edit as a member
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit'
        ]);
        assertEquals(2, $ret['ret']);

        # Promote to owner - should be able to edit it.
        $u->setRole(User::ROLE_OWNER, $this->gid);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'subject' => 'Test edit long'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);

        assertEquals('Test edit long', $ret['message']['subject']);
        assertEquals('Test edit long', $ret['message']['suggestedsubject']);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertTrue($m->isEdited());

        # Now edit a platform subject.
        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'msgtype' => 'Offer',
            'item' => 'Test item',
            'location' => 'TV1 1AA'
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals('OFFER: Test item (TV1)', $ret['message']['subject']);
        assertEquals($ret['message']['subject'], $ret['message']['suggestedsubject']);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'msgtype' => 'Offer',
            'item' => 'Test item',
            'location' => 'TV1 1BB'
        ]);
        assertEquals(2, $ret['ret']);

        # Attachments
        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'textbody' => 'Test edit',
            'attachments' => [ $attid ]
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals('Test edit', $ret['message']['textbody']);
        $this->log("After text edit " . var_export($ret, TRUE));

        # Check edit history
        assertEquals('Test edit long', $ret['message']['edits'][1]['oldsubject']);
        assertEquals('OFFER: Test item (TV1)', $ret['message']['edits'][1]['newsubject']);
        assertEquals(Message::TYPE_OTHER, $ret['message']['edits'][1]['oldtype']);
        assertEquals(Message::TYPE_OFFER, $ret['message']['edits'][1]['newtype']);
        assertEquals('Hey.', $ret['message']['edits'][0]['oldtext']);
        assertEquals('Test edit', $ret['message']['edits'][0]['newtext']);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'groupid' => $this->gid,
            'htmlbody' => 'Test edit',
            'FOP' => 0
        ]);
        assertEquals(0, $ret['ret']);
        $this->log("After HTML edit " . var_export($ret, TRUE));

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals('Test edit', $ret['message']['htmlbody']);
        self::assertEquals(0, $ret['message']['FOP']);

    }

    public function testEditAsMember()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $modid = $u->create('Test','User', 'Test User');
        $mod = User::get($this->dbhr, $this->dbhm, $modid);
        assertGreaterThan(0, $mod->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $mod->addMembership($this->gid, User::ROLE_MODERATOR);

        $this->log("Created member $memberid and mod $modid");

        # Submit a message from the member, who will be moderated as new members are.
        assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        assertEquals(0, $ret['ret']);

        # Test the canedit flag
        assertTrue($member->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        assertEquals(TRUE, $ret['message']['canedit']);
        assertEquals(MessageCollection::PENDING, $ret['message']['groups'][0]['collection']);

        # Disable edits for moderated members.
        $settings = json_decode($this->group->getPrivate('settings'), TRUE);
        $settings['allowedits'] = [
            'moderated' => FALSE,
            'group' => TRUE
        ] ;
        $this->group->setPrivate('settings', json_encode($settings));

        # Shouldn't be allowed to edit now.
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        assertEquals(FALSE, $ret['message']['canedit']);

        # Should be allowed to edit as mod
        assertTrue($mod->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);
        assertEquals(TRUE, $ret['message']['canedit']);

        # Now approve
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'groupid' => $this->gid,
            'action' => 'Approve'
        ]);
        assertEquals(0, $ret['ret']);

        # Now log in as the member and edit the message.
        assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        assertEquals(0, $ret['ret']);

        # Again but with no actual change
        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        assertEquals(0, $ret['ret']);

        # Now back as the mod and check the edit history.
        assertTrue($mod->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        # Check edit history.  Edit should show as needing approval.
        assertEquals(1, count($ret['message']['edits']));
        assertEquals('Text body', $ret['message']['edits'][0]['oldtext']);
        assertEquals('Another text body', $ret['message']['edits'][0]['newtext']);
        assertEquals(TRUE, $ret['message']['edits'][0]['reviewrequired']);
        assertEquals('OFFER: a thing (TV1)', $ret['message']['edits'][0]['oldsubject']);
        assertEquals('OFFER: Edited (TV1)', $ret['message']['edits'][0]['newsubject']);

        # This message should also show up in edits.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::EDITS
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['messages']));
        assertEquals($mid, $ret['messages'][0]['id']);
        assertEquals($this->gid, $ret['messages'][0]['groups'][0]['groupid']);

        # Will have a collection of approved, because it is.
        assertEquals(MessageCollection::APPROVED, $ret['messages'][0]['groups'][0]['collection']);

        # And will show the edit.
        assertEquals(1, count($ret['messages'][0]['edits']));
        $editid = $ret['messages'][0]['edits'][0]['id'];

        # And also in the counts.
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals($this->gid, $ret['groups'][0]['id']);
        assertEquals(1, $ret['groups'][0]['work']['editreview']);
        assertEquals(1, $ret['work']['editreview']);

        # Now approve the edit.
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'ApproveEdits',
            'editid' => $editid
        ]);
        assertEquals(0, $ret['ret']);

        # No longer showing for review.
        $ret = $this->call('messages', 'GET', [
            'groupid' => $this->gid,
            'collection' => MessageCollection::EDITS
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['messages']));

        # Now revert it.
        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'RevertEdits',
            'editid' => $editid
        ]);
        assertEquals(0, $ret['ret']);

        # Should be back as it was.
        $ret = $this->call('message', 'GET', [
            'id' => $mid
        ]);

        assertEquals('OFFER: a thing (TV1)', $ret['message']['subject']);
        assertEquals('Text body', $ret['message']['textbody']);
    }

    public function testEditAsMemberPending()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        $this->log("Created member $memberid");

        # Submit a message from the member, who will be moderated as new members are.
        assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        assertEquals(0, $ret['ret']);

        # Now log in as the member and edit the message in Pending.
        assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PATCH', [
            'id' => $mid,
            'groupid' => $this->gid,
            'item' => 'Edited',
            'textbody' => 'Another text body'
        ]);
        assertEquals(0, $ret['ret']);
    }

    public function testDraft()
    {
        # Can create drafts when not logged in.
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        file_put_contents("/tmp/chair.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg',
                'type' => 'image/jpeg'
            ],
            'identify' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $attid = $ret['id'];

        $locid = $this->dbhr->preQuery("SELECT id FROM locations ORDER BY id LIMIT 1;")[0]['id'];

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'attachments' => [ $attid ]
        ]);
        $this->log("Draft PUT " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];

        # And again to exercise codepath
        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'groupid' => $this->gid,
            'attachments' => [ $attid ]
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['id']);

        # Delete the draft to test the case where the draft is completed from another session.
        $this->dbhm->preExec("DELETE FROM messages_drafts WHERE msgid = ?;", [ $id ]);

        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'textbody' => 'Text body',
            'locationid' => $locid,
            'groupid' => $this->gid,
            'attachments' => [ $attid ]
        ]);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotEquals($id, $ret['id']);
        $id = $ret['id'];

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        assertEquals('Offer', $msg['type']);
        assertEquals('a thing', $msg['subject']);
        assertEquals('Text body', $msg['textbody']);
        assertEquals($attid, $msg['attachments'][0]['id']);

        # Now create a new attachment and update the draft.
        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/chair.jpg',
                'type' => 'image/jpeg',
                'dedup' => 1
            ],
            'identify' => TRUE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $attid2 = $ret['id'];

        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Wanted',
            'item' => 'a thing2',
            'locationid' => $locid,
            'textbody' => 'Text body2',
            'attachments' => [ $attid2 ]
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['id']);

        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        $msg = $ret['message'];
        assertEquals('Wanted', $msg['type']);
        assertEquals('a thing2', $msg['subject']);
        assertEquals('Text body2', $msg['textbody']);
        assertEquals($attid2, $msg['attachments'][0]['id']);

        $this->log("Get back in draft");
        $ret = $this->call('messages', 'GET', [
            'collection' => 'Draft'
        ]);
        $this->log("Messages " . var_export($ret, TRUE));
        $found = FALSE;
        foreach ($ret['messages'] as $message) {
            $this->log("Compare {$message['id']} to $id");
            if ($message['id'] == $id) {
                $found = TRUE;
            }
        }
        assertTrue($found);

        # Now remove the attachment
        $ret = $this->call('message', 'PUT', [
            'id' => $id,
            'collection' => 'Draft',
            'messagetype' => 'Wanted',
            'locationid' => $locid,
            'item' => 'a thing2',
            'textbody' => 'Text body2'
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['id']);

        $ret = $this->call('messages', 'GET', [
            'collection' => 'Draft'
        ]);
        $this->log("Messages " . var_export($ret, TRUE));
        assertEquals($id, $ret['messages'][0]['id']);
        assertEquals(0, count($ret['messages'][0]['attachments']));

        }

    public function testSubmit()
    {
        assertTrue(TRUE);

        if (!getenv('STANDALONE')) {
            # Set a fake IP for coverage reasons; choose the BBC.  No license fee required.
            $_SERVER['REMOTE_ADDR'] = '212.58.244.22';

            $email = 'test-' . rand() . '@blackhole.io';

            # This is similar to the actions on the client
            # - find a location close to a lat/lng
            # - upload a picture
            # - create a draft with a location
            # - find the closest group to that location
            # - submit it
            $this->group->setPrivate('onyahoo', 1);
            $this->group->setPrivate('lat', 8.5);
            $this->group->setPrivate('lng', 179.3);
            $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
            $this->group->setPrivate('publish', 1);

            $l = new Location($this->dbhr, $this->dbhm);
            $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
            file_put_contents("/tmp/chair.jpg", $data);

            $ret = $this->call('image', 'POST', [
                'photo' => [
                    'tmp_name' => '/tmp/chair.jpg',
                    'type' => 'image/jpeg'
                ],
                'identify' => TRUE
            ]);

            #$this->log("Create attachment " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertNotNull($ret['id']);
            $attid = $ret['id'];

            # Submit to the playground group explicitly.
            $g = Group::get($this->dbhr, $this->dbhm);
            $gid = $g->findByShortName('FreeglePlayground');

            $ret = $this->call('message', 'PUT', [
                'collection' => 'Draft',
                'locationid' => $locid,
                'messagetype' => 'Offer',
                'item' => 'a thing',
                'groupid' => $gid,
                'textbody' => 'Text body',
                'attachments' => [ $attid ]
            ]);
            assertEquals(0, $ret['ret']);
            $id = $ret['id'];
            $this->log("Created draft $id");

            # This will get sent; will get queued, as we don't have a membership for the group
            $ret = $this->call('message', 'POST', [
                'id' => $id,
                'action' => 'JoinAndPost',
                'email' => $email,
                'ignoregroupoverride' => true
            ]);

            $this->log("Message #$id should be queued " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertEquals('Queued for group membership', $ret['status']);
            $applied = $ret['appliedemail'];

            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($email);

            # This assumes the playground group is set to auto-approve and moderate new messages.
            #
            # Now when that approval gets notified to us, it should trigger submission of the
            # messages from that user.
            $count = 0;
            $found = FALSE;

            do {
                $this->log("...waiting for pending message from $applied #$uid, try $count");
                sleep(1);
                $msgs = $this->dbhr->preQuery("SELECT * FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND groupid = ? AND messages_groups.collection = ? AND fromuser = ?;",
                    [ $gid, MessageCollection::PENDING, $uid ]);
                foreach ($msgs as $msg) {
                    $this->log("Reached pending " . var_export($msg, TRUE));
                    $found = TRUE;
                }
                $count++;
            } while ($count < IznikTestCase::YAHOO_PATIENCE && !$found);

            assertTrue($found, "Yahoo slow?  Failed to reach pending messages");

            $m = new Message($this->dbhr, $this->dbhm, $id);
            $m->delete("UT delete");

            # And again, now that the user exists.  Set an invalid from IP which will
            # fail to resolve.
            $_SERVER['REMOTE_ADDR'] = '1.1.1.1';

            $ret = $this->call('message', 'PUT', [
                'collection' => 'Draft',
                'locationid' => $locid,
                'groupid' => $this->groupid,
                'messagetype' => 'Offer',
                'item' => 'a thing',
                'textbody' => 'Text body',
                'attachments' => [ $attid ]
            ]);
            assertEquals(0, $ret['ret']);
            $id = $ret['id'];
            $this->log("Created draft $id");

            # This will get queued, as we don't have a membership for the group
            $ret = $this->call('message', 'POST', [
                'id' => $id,
                'action' => 'JoinAndPost',
                'email' => $email,
                'ignoregroupoverride' => true
            ]);

            $this->log("Message #$id should be queued 2 " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertEquals('Queued for group membership', $ret['status']);

            $count = 0;
            $found = FALSE;

            do {
                $this->log("...waiting for pending message from $applied #$uid, try $count");
                sleep(1);
                $msgs = $this->dbhr->preQuery("SELECT * FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND groupid = ? AND messages_groups.collection = ? AND fromuser = ?;",
                    [ $gid, MessageCollection::PENDING, $uid ]);
                foreach ($msgs as $msg) {
                    $this->log("Reached pending " . var_export($msg, TRUE));
                    $found = TRUE;
                    $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);
                    $m->delete('UT');
                }
                $count++;
            } while ($count < IznikTestCase::YAHOO_PATIENCE && !$found);

            assertTrue($found, "Yahoo slow?  Failed to reach pending messages");

            $m = new Message($this->dbhr, $this->dbhm, $id);
            $m->delete("UT delete");

            # And once again, now that the user exists and will be a member of the group.
            $ret = $this->call('message', 'PUT', [
                'collection' => 'Draft',
                'locationid' => $locid,
                'groupid' => $gid,
                'messagetype' => 'Offer',
                'item' => 'a thing',
                'textbody' => 'Text body',
                'attachments' => [ $attid ]
            ]);
            assertEquals(0, $ret['ret']);
            $id = $ret['id'];
            $this->log("Created draft $id");

            $ret = $this->call('message', 'POST', [
                'id' => $id,
                'action' => 'JoinAndPost',
                'email' => $email,
                'ignoregroupoverride' => true
            ]);

            assertEquals(0, $ret['ret']);
            assertEquals('Success', $ret['status']);

            $count = 0;
            $found = FALSE;

            do {
                $this->log("...waiting for pending message from $applied #$uid, try $count");
                sleep(1);
                $msgs = $this->dbhr->preQuery("SELECT * FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND groupid = ? AND messages_groups.collection = ? AND fromuser = ?;",
                    [ $gid, MessageCollection::PENDING, $uid ]);
                foreach ($msgs as $msg) {
                    $this->log("Reached pending " . var_export($msg, TRUE));
                    $found = TRUE;
                    $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);
                    $m->delete('UT');
                }
                $count++;
            } while ($count < IznikTestCase::YAHOO_PATIENCE && !$found);

            assertTrue($found, "Yahoo slow?  Failed to reach pending messages");

            # Now approve the message and wait for it to reach the group.
            $m = new Message($this->dbhr, $this->dbhm, $id);
            $m->approve($gid, NULL, NULL, NULL);

            do {
                $this->log("...waiting for approved message from $applied #$uid, try $count");
                sleep(1);
                $msgs = $this->dbhr->preQuery("SELECT * FROM messages_groups INNER JOIN messages ON messages_groups.msgid = messages.id AND groupid = ? AND messages_groups.collection = ? AND fromuser = ?;",
                    [ $gid, MessageCollection::APPROVED, $uid ]);
                foreach ($msgs as $msg) {
                    $this->log("Reached approved" . var_export($msg, TRUE));
                    $found = TRUE;
                    $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);

                    # Check that the attachment is present.
                    $atts = $m->getAttachments();
                    assertEquals(1, count($atts));

                    $m->delete('UT');
                }
                $count++;
            } while ($count < IznikTestCase::YAHOO_PATIENCE && !$found);

            assertTrue($found, "Yahoo slow?  Failed to reach pending messages");
        }

        }

    public function testSubmit2()
    {
        assertTrue(TRUE);

        if (!getenv('STANDALONE')) {
            $email = 'test-' . rand() . '@blackhole.io';

            # This is similar to the actions on the client
            # - find a location close to a lat/lng
            # - upload a picture
            # - create a draft with a location
            # - find the closest group to that location
            # - submit it
            $this->group->setPrivate('lat', 8.5);
            $this->group->setPrivate('lng', 179.3);
            $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');

            $l = new Location($this->dbhr, $this->dbhm);
            $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

            $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
            file_put_contents("/tmp/chair.jpg", $data);

            $ret = $this->call('image', 'POST', [
                'photo' => [
                    'tmp_name' => '/tmp/chair.jpg',
                    'type' => 'image/jpeg'
                ],
                'identify' => TRUE
            ]);

            #$this->log("Create attachment " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertNotNull($ret['id']);
            $attid = $ret['id'];

            # Find a location
            $ret = $this->call('message', 'PUT', [
                'collection' => 'Draft',
                'locationid' => $locid,
                'messagetype' => 'Offer',
                'item' => 'a thing',
                'groupid' => $this->gid,
                'textbody' => 'Text body',
                'attachments' => [ $attid ]
            ]);
            assertEquals(0, $ret['ret']);
            $id = $ret['id'];
            $this->log("Created draft $id");

            # This will get sent; will get queued, as we don't have a membership for the group
            $ret = $this->call('message', 'POST', [
                'id' => $id,
                'action' => 'JoinAndPost',
                'email' => $email,
                'ignoregroupoverride' => true
            ]);

            $this->log("Message #$id should be queued " . var_export($ret, TRUE));
            assertEquals(0, $ret['ret']);
            assertEquals('Queued for group membership', $ret['status']);
            $applied = $ret['appliedemail'];

            # Now to get coverage, invoke the submission arm in here, rather than on the separate mail server.  This
            # assumes we run tests faster than Yahoo responds.
            $u = User::get($this->dbhr, $this->dbhm);
            $uid = $u->findByEmail($email);
            $u = User::get($this->dbhr, $this->dbhm, $uid);
            $this->log("User id $uid");
//        $eid = $u->addEmail($applied);
//        $this->log("Added email $eid");
            $emails = $u->getEmails();
            $this->log("Email " . var_export($emails, TRUE));
            $gemail = NULL;
            foreach ($emails as $anemail) {
                if ($anemail['email'] != $email) {
                    $gemail = $anemail['id'];
                }
            }
            $u->addMembership($this->gid, User::ROLE_MEMBER, $gemail);

            $rc = $u->submitYahooQueued($this->gid);
            assertEquals(1, $rc);
        }
    }

    public function testSubmitNative() {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be pending " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

        # Now approve the message and wait for it to reach the group.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        $m->approve($this->gid, NULL, NULL, NULL);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        assertEquals(0, count($msgs));

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::APPROVED);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

    }

    public function testSubmitNativeApprove()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        #
        # Set the group to approve members
        $this->group->setSettings([
            'approvemembers' => TRUE
        ]);

        $this->log("Set private for {$this->gid} to " . $this->group->getPrivate('onyahoo'));

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be held for membership" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::QUEUED_USER);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

        # Now approve the membership, which should trigger the message to move to pending.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail($email);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->approve($this->gid,NULL, NULL, NULL);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

        }

    public function testSubmitNativeApproveReject()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        #
        # Set the group to approve members
        $this->group->setSettings([
            'approvemembers' => TRUE
        ]);

        $this->log("Set private for {$this->gid} to " . $this->group->getPrivate('onyahoo'));

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be held for membership" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::QUEUED_USER);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);

        # Now reject the membership, which should trigger the message to move to rejected.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail($email);
        $u = new User($this->dbhr, $this->dbhm, $uid);
        $u->reject($this->gid,NULL, NULL, NULL);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        $gs = $m->getGroups(TRUE, FALSE);
        $this->log("Groups " . var_export($gs, TRUE));
        self::assertEquals(1, count($gs));
        self::assertEquals($this->gid, $gs[0]['groupid']);
        self::assertEquals(MessageCollection::REJECTED, $gs[0]['collection']);

        }

    public function testSubmitBanned()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - upload a picture
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        # ...but ban them first.
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->findByEmail($email);

        if (!$uid) {
            $uid = $u->create("Test", "User", "Test User");
            $u->addEmail($email);
        }

        $u = new User($this->dbhr, $this->dbhm, $uid);
        $this->log("Ban $uid from {$this->gid}");
        $u->removeMembership($this->gid, TRUE);
        assertFalse($u->addMembership($this->gid));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should not be pending " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(0, count($msgs));

        }

    public function testCrosspost() {
        # At the moment a crosspost results in two separate messages - see comment in Message::save().
        $this->group = Group::get($this->dbhr, $this->dbhm);
        $group1= $this->group->create('testgroup1', Group::GROUP_REUSE);
        $group2 = $this->group->create('testgroup2', Group::GROUP_REUSE);

        $this->user->addMembership($group1);
        $this->user->addMembership($group2);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup1', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id1 = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $msg = str_ireplace('testgroup1', 'testgroup2', $msg);
        $id2 = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $m1 = new Message($this->dbhr, $this->dbhm, $id1);
        $m2 = new Message($this->dbhr, $this->dbhm, $id2);
        assertNotEquals($m1->getMessageID(), $m2->getMessageID());
        $m1->delete("UT delete");
        $m2->delete("UT delete");

        }
    
    public function testPromise() {
        $u = $this->user;

        $uid2 = $u->create(NULL, NULL, 'Test User');
        $uid3 = $u->create(NULL, NULL, 'Test User');

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: A thing (A place)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Shouldn't be able to promise logged out
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $this->uid,
            'action' => 'Promise'
        ]);
        assertEquals(2, $ret['ret']);

        # Promise it to the other user.
        $u = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Promise'
        ]);
        assertEquals(0, $ret['ret']);
        
        # Promise should show
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        $this->log("Got message " . var_export($ret, TRUE));
        assertEquals(1, count($ret['message']['promises']));
        assertEquals($uid2, $ret['message']['promises'][0]['userid']);

        # Can't promise to multiple users
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Promise'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['message']['promises']));
        assertEquals($uid3, $ret['message']['promises'][0]['userid']);
        
        # Renege on one of them.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid2,
            'action' => 'Renege'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('message', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['message']['promises']));
        assertEquals($uid3, $ret['message']['promises'][0]['userid']);

        $ret = $this->call('user', 'GET', [
            'id' => $uid2,
            'info' => TRUE
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(1, $ret['user']['info']['reneged']);

        # Check we can't promise on someone else's message.
        $u = User::get($this->dbhr, $this->dbhm, $uid3);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Promise'
        ]);
        assertEquals(2, $ret['ret']);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'userid' => $uid3,
            'action' => 'Renege'
        ]);
        assertEquals(2, $ret['ret']);

        }

    public function testMark()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::TYPE_OFFER, $m->getType());

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_TAKEN
        ]);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'happiness' => User::FINE,
            'userid' => $uid
        ]);
        assertEquals(0, $ret['ret']);

        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(Message::TYPE_WANTED, $m->getType());

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_RECEIVED,
            'happiness' => User::FINE,
            'userid' => $uid
        ]);
        assertEquals(0, $ret['ret']);

        # Now withdraw it
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_WITHDRAWN,
            'happiness' => User::FINE,
            'comment' => "It was fine",
            'userid' => $uid
        ]);
        assertEquals(0, $ret['ret']);

        # Now get the happiness back.
        $u->setRole(User::ROLE_MODERATOR, $this->gid);
        assertTrue($u->login('testpw'));
        $ret = $this->call('memberships', 'GET', [
            'collection' => 'Happiness',
            'groupid' => $this->gid
        ]);
        $this->log("Happiness " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals(2, count($ret['members']));

        $m->delete("UT delete");

    }

    public function testMarkAsMod()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Create a member on the group and check we can can't mark.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $u->addMembership($this->gid, User::ROLE_MEMBER);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN
        ]);
        assertEquals(2, $ret['ret']);

        $u->addMembership($this->gid, User::ROLE_MODERATOR);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Outcome',
            'outcome' => Message::OUTCOME_TAKEN,
            'dup' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);
        assertEquals(1, count($ret['message']['outcomes']));
    }

    public function testExpired()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        # Force it to expire.  First ensure that it's not expired just because it's old.
        $expired = date("Y-m-d H:i:s", strtotime("midnight 91 days ago"));
        $m->setPrivate('arrival', $expired);

        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);

        assertEquals(0, count($ret['message']['outcomes']));

        # Now expire it on the group by.
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = '$expired' WHERE msgid = $id;");

        # Should now have expired.
        $ret = $this->call('message', 'GET', [
            'id' => $id,
        ]);

        self::assertEquals(Message::OUTCOME_EXPIRED, $ret['message']['outcomes'][0]['outcome']);

        }

    public function testIntendedTaken()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'OFFER: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_TAKEN
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        assertEquals(Message::OUTCOME_TAKEN, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedReceived()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_RECEIVED
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        assertEquals(Message::OUTCOME_RECEIVED, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedWithdrawn()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_WITHDRAWN
        ]);

        # Too soon.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals(0, $m->processIntendedOutcomes($id));

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        assertEquals(Message::OUTCOME_WITHDRAWN, $atts['outcomes'][0]['outcome']);

        $m->delete("UT delete");

        }

    public function testIntendedRepost()
    {
        $email = 'test-' . rand() . '@blackhole.io';

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u->addEmail($email);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        $origmsg = file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic');
        $msg = $this->unique($origmsg);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_replace('Basic test', 'WANTED: a thing (A Place)', $msg);
        $msg = str_replace('test@test.com', $email, $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, $email, 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_REPOST
        ]);

        $groups = $m->getGroups(FALSE, FALSE);
        $arrival = strtotime($groups[0]['arrival']);

        # Too soon.
        $m = new Message($this->dbhm, $this->dbhm, $id);
        assertEquals(0, $m->processIntendedOutcomes($id));
        sleep(5);

        # Now make it look older.
        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        $this->dbhm->preExec("UPDATE messages_groups SET arrival = DATE_SUB(arrival, INTERVAL 20 DAY) WHERE msgid = ?;", [ $id ]);
        assertEquals(1, $this->dbhm->rowsAffected());
        assertEquals(1, $m->processIntendedOutcomes($id));
        $atts = $m->getPublic();
        assertEquals(0, count($atts['outcomes']));

        # The arrival time should have been bumped.
        $groups = $m->getGroups(FALSE, FALSE);
        $arrival2 = strtotime($groups[0]['arrival']);
        assertGreaterThan($arrival, $arrival2);

        # Now try again; shouldn't repost as recently done.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'OutcomeIntended',
            'outcome' => Message::OUTCOME_REPOST,
            'dup' => 2
        ]);

        self::assertEquals(0, $ret['ret']);

        $this->dbhm->preExec("UPDATE messages_outcomes_intended SET timestamp = DATE_SUB(timestamp, INTERVAL 3 HOUR) WHERE msgid = ?;", [ $id ]);
        assertEquals(1, $this->dbhm->rowsAffected());
        assertEquals(0, $m->processIntendedOutcomes($id));

        $m->delete("UT delete");

        }

    public function testChatSource()
    {
        # Create a group we're on
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->addMembership($this->gid);

        # Put a message on the group.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/offer'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'test@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Create a chat reply by email.
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/replytext'));
        $msg = str_replace('Re: Basic test', 'Re: OFFER: a test item (location)', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $replyid = $r->received(Message::EMAIL, 'test2@test.com', 'test@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::TO_USER, $rc);

        # Try logged out
        $this->log("Logged out");
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        assertEquals(2, $ret['ret']);

        # Try to get not as a mod.
        $this->log("Logged in");
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        assertEquals(2, $ret['ret']);

        # Try as mod
        $this->log("As mod");
        $u->addMembership($this->gid, User::ROLE_MODERATOR);
        $ret = $this->call('message', 'GET', [
            'id' => $replyid,
            'collection' => 'Chat'
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($replyid, $ret['message']['id']);
    }

    public function testNativeSpam() {
        $email = 'test-' . rand() . '@blackhole.io';

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->log("Set private for {$this->gid} to " . $this->group->getPrivate('onyahoo'));

        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body with viagra'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id should be spam " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::SPAM);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        $this->log("Got pending messages " . var_export($msgs, TRUE));
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);
    }

    public function testNativeWorry() {
        $email = 'test-' . rand() . '@blackhole.io';

        $this->dbhm->preExec("INSERT INTO worrywords (keyword, type) VALUES (?, ?);", [
            'UTtest1',
            WorryWords::TYPE_REPORTABLE
        ]);

        # This is similar to the actions on the client
        # - find a location close to a lat/lng
        # - create a draft with a location
        # - find the closest group to that location
        # - submit it
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);
        $this->group->setPrivate('poly', 'POLYGON((179.1 8.3, 179.2 8.3, 179.2 8.4, 179.1 8.4, 179.1 8.3))');
        $this->group->setPrivate('publish', 1);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Find a location
        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body with uttest2'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent as for native groups we can do so immediate.
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        $this->log("Message #$id is worrying " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        $c = new MessageCollection($this->dbhr, $this->dbhm, MessageCollection::PENDING);
        $ctx = NULL;
        list ($groups, $msgs) = $c->get($ctx, 10, [ $this->gid ]);
        assertEquals(1, count($msgs));
        self::assertEquals($id, $msgs[0]['id']);
        self::assertTrue(array_key_exists('worry', $msgs[0]));
    }

    public function testLikes() {
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_replace('Basic test', 'OFFER: Test item (location)', $msg);

        $m = new Message($this->dbhr, $this->dbhm);
        $m->parse(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $id = $m->save();

        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Love'
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(1, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Unlove'
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Laugh'
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(1, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'Unlaugh'
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(0, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View'
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(1, $m->getLikes(Message::LIKE_VIEW));

        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 1
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(1, $m->getLikes(Message::LIKE_VIEW));

        # Can pass View logged out and get back success.
        $_SESSION['id'] = NULL;
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 3
        ]);

        assertEquals(0, $ret['ret']);
        assertTrue($u->login('testpw'));

        # Check this shows up in our list of viewed messages.
        $ret = $this->call('messages', 'GET', [
            'collection' => MessageCollection::VIEWED,
            'fromuser' => $u->getId()
        ]);

        assertEquals(1, count($ret['messages']));
        assertEquals($id, $ret['messages'][0]['id']);

        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'View',
            'dup' => 2
        ]);

        assertEquals(0, $ret['ret']);

        assertEquals(0, $m->getLikes(Message::LIKE_LOVE));
        assertEquals(0, $m->getLikes(Message::LIKE_LAUGH));
        assertEquals(2, $m->getLikes(Message::LIKE_VIEW));
    }

    public function testBigSwitch()
    {
        assertTrue(TRUE);

        $this->group->setPrivate('overridemoderation', Group::OVERRIDE_MODERATION_ALL);

        $email = 'test-' . rand() . '@blackhole.io';
        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');
        $u->addEmail($email);
        $u->addMembership($this->gid);

        # Take off moderation.
        $u->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_DEFAULT);

        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'Tuvalu Postcode', 'Postcode', 'POINT(179.2167 8.53333)',0);

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        $this->log("Created draft $id");

        # This will get sent; will get queued, as we don't have a membership for the group
        $ret = $this->call('message', 'POST', [
            'id' => $id,
            'action' => 'JoinAndPost',
            'email' => $email,
            'ignoregroupoverride' => true
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals('Success', $ret['status']);

        # Should be pending because of the big switch.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertTrue($m->isPending($this->gid));
    }

    public function testCantPost()
    {
        $l = new Location($this->dbhr, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)',0);

        # Create member and mod.
        $u = User::get($this->dbhr, $this->dbhm);

        $memberid = $u->create('Test','User', 'Test User');
        $member = User::get($this->dbhr, $this->dbhm, $memberid);
        assertGreaterThan(0, $member->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        $member->addMembership($this->gid, User::ROLE_MEMBER);
        $email = 'ut-' . rand() . '@' . USER_DOMAIN;
        $member->addEmail($email);

        # Forbid us from posting.
        $member->setMembershipAtt($this->gid, 'ourPostingStatus', Group::POSTING_PROHIBITED);

        # Submit a message from the member - should fail
        assertTrue($member->login('testpw'));

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => $email
        ]);

        assertNotEquals(0, $ret['ret']);
    }

    public function testMergeDuringSubmit() {
        $this->group = Group::get($this->dbhm, $this->dbhm);
        $this->gid = $this->group->create('testgroup1', Group::GROUP_REUSE);

        $u = User::get($this->dbhm, $this->dbhm);
        $id1 = $u->create(NULL, NULL, 'Test User');
        $u1 = User::get($this->dbhm, $this->dbhm, $id1);
        assertGreaterThan(0, $u1->addEmail('test1@test.com'));

        $l = new Location($this->dbhm, $this->dbhm);
        $locid = $l->create(NULL, 'TV1 1AA', 'Postcode', 'POINT(179.2167 8.53333)',0);

        $ret = $this->call('message', 'PUT', [
            'collection' => 'Draft',
            'locationid' => $locid,
            'messagetype' => 'Offer',
            'item' => 'a thing',
            'groupid' => $this->gid,
            'textbody' => 'Text body'
        ]);

        assertEquals(0, $ret['ret']);
        $mid = $ret['id'];

        $ret = $this->call('message', 'POST', [
            'id' => $mid,
            'action' => 'JoinAndPost',
            'ignoregroupoverride' => true,
            'email' => 'test2@test.com'
        ]);

        $msgs = $this->dbhr->preQuery("SELECT * FROM messages WHERE id = $mid;");

        $m = new Message($this->dbhm, $this->dbhm, $mid);
        $id2 = $m->getFromuser();
        assertNotNull($id2);
        assertNotEquals($id1, $id2);

        assertEquals(0, $ret['ret']);

        $u->merge($id1, $id2, "UT Test");

        $m = new Message($this->dbhm, $this->dbhm, $mid);
        $id3 = $m->getFromuser();
        assertNotNull($id3);
    }

    public function testPartner() {
        $key = randstr(64);
        $id = $this->dbhm->preExec("INSERT INTO partners_keys (`partner`, `key`, `domain`) VALUES ('UT', ?, ?);", [$key, 'test.com']);
        assertNotNull($id);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $ret = $this->call('message', 'PATCH', [
            'id' => $id,
            'subject' => 'Test edit',
            'partner' => $key
        ]);
        assertEquals(0, $ret['ret']);
    }
}