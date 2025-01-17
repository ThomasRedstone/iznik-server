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
class chatMessagesAPITest extends IznikAPITestCase
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

        $dbhm->preExec("DELETE FROM chat_rooms WHERE name = 'test';");

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        self::assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertEquals($this->user->getId(), $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        self::assertNotNull($this->uid2);
        $this->user2 = User::get($this->dbhr, $this->dbhm, $this->uid2);
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid3 = $u->create(NULL, NULL, 'Test User');
        self::assertNotNull($this->uid3);
        $this->user3 = User::get($this->dbhr, $this->dbhm, $this->uid3);
        assertGreaterThan(0, $this->user3->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = Group::get($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);

        # Recipient must be a member of at least one group
        $this->user2->addMembership($this->groupid);

        $c = new ChatRoom($this->dbhr, $this->dbhm);
        $this->cid = $c->createGroupChat('test', $this->groupid);
    }

    public function testGroupGet()
    {
        # Logged out - no rooms
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatmessages', $ret));

        $m = new ChatMessage($this->dbhr, $this->dbhm);;
        list ($mid, $banned) = $m->create($this->cid, $this->uid, 'Test');
        $this->log("Created chat message $mid");

        # Just because it exists, doesn't mean we should be able to see it.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatmessages', $ret));

        assertTrue($this->user->login('testpw'));

        # Still not, even logged in.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        assertEquals(2, $ret['ret']);
        assertFalse(Utils::pres('chatmessages', $ret));

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));

        # Now we're talking.
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        $this->log("Now we're talking " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatmessages']));
        assertEquals($mid, $ret['chatmessages'][0]['id']);
        assertEquals($this->cid, $ret['chatmessages'][0]['chatid']);
        assertEquals('Test', $ret['chatmessages'][0]['message']);

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($mid, $ret['chatmessage']['id']);

        }

    public function testGroupPut()
    {
        # Logged out - no rooms
        $this->log("Logged out");
        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $this->cid, 'message' => 'Test' ]);
        assertEquals(1, $ret['ret']);
        assertFalse(Utils::pres('chatmessages', $ret));

        assertEquals(1, $this->user->addMembership($this->groupid, User::ROLE_MODERATOR));
        assertTrue($this->user->login('testpw'));

        # Now we're talking.  Make sure we're on the roster.
        $this->log("Logged in");
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $this->cid,
            'lastmsgseen' => 1
        ]);

        $this->log("Post test");
        $ret = $this->call('chatmessages', 'POST', [ 'roomid' => $this->cid, 'message' => 'Test2' ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid = $ret['id'];

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($mid, $ret['chatmessage']['id']);

        # Test search
        $ret = $this->call('chatrooms', 'GET', [
            'search' => 'zzzz',
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['chatrooms']));

        $ret = $this->call('chatrooms', 'GET', [
            'search' => 'ES',
            'chattypes' => [ ChatRoom::TYPE_MOD2MOD ]
        ]);
        assertEquals(0, $ret['ret']);

        # Two rooms - one we've created, and the automatic mod chat.
        assertEquals(2, count($ret['chatrooms']));
        assertTrue($this->cid == $ret['chatrooms'][0]['id'] || $this->cid == $ret['chatrooms'][1]['id']);

        }

    public function testConversation() {
        assertTrue($this->user->login('testpw'));

        # We want to use a referenced message which is promised, to test suppressing of email notifications.
        $u = new User($this->dbhr, $this->dbhm);
        $uid2 = $u->create(NULL, NULL, 'Test User');

        $this->user2->addEmail('test@test.com');
        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $this->user2->addMembership($this->groupid);
        $this->user2->setMembershipAtt($this->groupid, 'ourPostingStatus', Group::POSTING_DEFAULT);
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $r = new MailRouter($this->dbhr, $this->dbhm);
        $refmsgid = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        self::assertNotNull($refmsgid);
        $rc = $r->route();
        assertEquals(MailRouter::APPROVED, $rc);

        # Promise to someone else.
        $m = new Message($this->dbhr, $this->dbhm, $refmsgid);
        $m->promise($uid2);

        # Create a chat to the second user with a referenced message from the second user.
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);

        $ret = $this->call('chatrooms', 'GET', []);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($this->cid, $ret['chatrooms'][0]['id']);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'refmsgid' => $refmsgid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        # Should be able to set the replyexpected flag
        $ret = $this->call('chatmessages', 'PATCH', [
            'roomid' => $this->cid,
            'id' => $mid1,
            'replyexpected' => TRUE
        ]);
        assertEquals(0, $ret['ret']);

        # Second user should now show that they are expected to reply.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $r->updateExpected();
        $info = $this->user->getInfo(0);
        assertEquals(0, $info['expectedreply']);
        $info = $this->user2->getInfo(0);
        assertEquals(1, $info['expectedreply']);

        # Duplicate
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'refmsgid' => $refmsgid,
            'dup' => TRUE
        ]);
        $this->log("Dup create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        assertEquals($mid1, $ret['id']);

        # Check that the email was suppressed.
        $this->log("Check for suppress of $mid1 to {$this->uid2}");
        $ret = $this->call('chatrooms', 'POST', [
            'id' => $this->cid
        ]);

        $this->log(var_export($ret, TRUE));
        foreach ($ret['roster'] as $rost) {
            if ($rost['user']['id'] == $this->uid2) {
                self::assertEquals($mid1, $rost['lastmsgemailed']);
            }
        }

        # Now log in as the other user.
        assertTrue($this->user2->login('testpw'));

        # Should be able to see the room
        $ret = $this->call('chatrooms', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatrooms']));
        assertEquals($this->cid, $ret['chatrooms'][0]['id']);

        # If we create a chat to the first user, should get the same chat
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals($this->cid, $ret['id']);

        # Check no reply expected shows for sender.
        $this->waitBackground();
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        $r->updateExpected();
        $replies = $this->user->getExpectedReplies([ $this->user->getId() ], ChatRoom::ACTIVELIM, -1);
        assertEquals(0, $replies[0]['count']);

        # Reply expected should show for recipient.
        $replies = $this->user->getExpectedReplies([ $this->uid2 ], ChatRoom::ACTIVELIM, -1);
        assertEquals(1, $replies[0]['count']);
        $replies = $this->user->listExpectedReplies($this->uid2, ChatRoom::ACTIVELIM, -1);
        assertEquals(1, count($replies));
        assertEquals($this->cid, $replies[0]['id']);

        # Should see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        $this->log("Get message" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($mid1, $ret['chatmessage']['id']);
        assertEquals(1, $ret['chatmessage']['replyexpected']);

        # Should be able to post
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test',
            'dup' => 1
        ]);
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);

        # We have now replied.
        $r->updateExpected();
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($mid1, $ret['chatmessage']['id']);
        assertEquals(1, $ret['chatmessage']['replyreceived']);

        # Now log in as a third user
        assertTrue($this->user3->login('testpw'));

        # Shouldn't see the chat
        $ret = $this->call('chatmessages', 'GET', [ 'roomid' => $this->cid ]);
        assertEquals(2, $ret['ret']);
        assertFalse(Utils::pres('chatmessages', $ret));

        # Shouldn't see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        assertEquals(2, $ret['ret']);
    }

    public function testImage() {
        assertTrue($this->user->login('testpw'));

        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);

        # Create a chat to the second user with a referenced image
        $data = file_get_contents(IZNIK_BASE . '/test/ut/php/images/chair.jpg');
        file_put_contents("/tmp/pan.jpg", $data);

        $ret = $this->call('image', 'POST', [
            'photo' => [
                'tmp_name' => '/tmp/pan.jpg'
            ],
            'chatmessage' => 1,
            'imgtype' => Attachment::TYPE_CHAT_MESSAGE
        ]);

        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $iid = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'imageid' => $iid
        ]);
        $this->log("Create image " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        # Now log in as the other user.
        assertTrue($this->user2->login('testpw'));

        # Should see the messages
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid,
            'id' => $mid1
        ]);
        error_log("Get message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals($mid1, $ret['chatmessage']['id']);
        assertEquals($iid, $ret['chatmessage']['image']['id']);
        assertEquals($mid1, $ret['chatmessage']['image']['chatmsgid']);
    }

    public function testLink() {
        $m = new ChatMessage($this->dbhr, $this->dbhm);;

        assertEquals(ChatMessage::REVIEW_SPAM, $m->checkReview("Hi ↵↵repetitionbowie.com/sportscapping.php?meant=mus2x216xkrn0mpb↵↵↵↵↵Thank you!", FALSE, NULL));
    }

    public function testReview() {
        $this->dbhm->preExec("DELETE FROM spam_whitelist_links WHERE domain LIKE 'spam.wherever';");
        assertTrue($this->user->login('testpw'));

        # Make the originating user be on the group so we can test groupfrom.
        $this->user->addMembership($this->groupid);

        # Add some mods on the recipient's group, so they can be notified.
        $u = new User($this->dbhr, $this->dbhm);
        $modid = $u->create('Test', 'User', 'Test User');
        $u = new User($this->dbhr, $this->dbhm, $modid);
        $u->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://spam.wherever and email test@test.com',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://ham.wherever'
        ]);
        $this->log("Create message with link" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test without link'
        ]);
        $this->log("Create message with no link " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid3 = $ret['id'];

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid1);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_SPAM, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_LAST, $cm->getPrivate('reportreason'));

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid3);
        self::assertEquals(1, $cm->getPrivate('reviewrequired'));
        self::assertEquals(ChatMessage::REVIEW_LAST, $cm->getPrivate('reportreason'));

        # Now log in as the other user.
        assertTrue($this->user2->login('testpw'));

        # Shouldn't see chat as no messages not held for review.
        $ret = $this->call('chatrooms', 'GET', []);
        $this->log("Shouldn't see rooms " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals(0, count($ret['chatrooms']));

        # Shouldn't see messages as held for review.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid
        ]);
        $this->log("Get message" . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['chatmessages']));

        # Now log in as a third user.
        assertTrue($this->user3->login('testpw'));
        $this->user3->addMembership($this->groupid, User::ROLE_MODERATOR);

        $this->user2->removeMembership($this->groupid);

        # We're a mod, but not on any of the groups that these users are on (because they're not on any).  So we
        # shouldn't see this chat message to review.
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['work']['chatreview']);

        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Shouldn't see this as the recipient is still not on a group we mod.
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['work']['chatreview']);

        $this->user2->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Should see this now.
        $this->log("Check can see for mod on {$this->groupid}");
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(3, $ret['work']['chatreview']);

        # Test the 'other' variant.
        $this->user2->setGroupSettings($this->groupid, [ 'active' => 0 ]);
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['work']['chatreviewother']);

        # Get the messages for review.
        $ret = $this->call('chatmessages', 'GET', []);
        assertEquals(0, $ret['ret']);
        $this->log("Messages for review " . var_export($ret, TRUE));
        assertEquals(3, count($ret['chatmessages']));
        assertEquals($mid1, $ret['chatmessages'][0]['id']);
        assertEquals(ChatMessage::TYPE_REPORTEDUSER, $ret['chatmessages'][0]['type']);
        assertEquals(Spam::REASON_LINK, $ret['chatmessages'][0]['reviewreason']);
        assertEquals($mid2, $ret['chatmessages'][1]['id']);
        assertEquals(ChatMessage::REVIEW_LAST, $ret['chatmessages'][1]['reviewreason']);
        assertEquals($mid3, $ret['chatmessages'][2]['id']);
        assertEquals(ChatMessage::REVIEW_LAST, $ret['chatmessages'][2]['reviewreason']);
        assertEquals($this->groupid, $ret['chatmessages'][0]['group']['id']);
        assertEquals($this->groupid, $ret['chatmessages'][0]['groupfrom']['id']);

        # Should be able to redact.
        $ret2 = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Redact'
        ]);
        assertEquals(0, $ret2['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        #$this->log("After hold " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals('Test with link http://spam.wherever and email (email removed)', $ret['chatmessages'][0]['message']);

        # Test hold/unhold.
        $this->log("Hold");
        assertFalse(Utils::pres('held', $ret['chatmessages'][0]));
        $ret = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Hold'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('chatmessages', 'GET', []);
        #$this->log("After hold " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        self::assertEquals($this->user3->getId(), $ret['chatmessages'][0]['held']['id']);

        $ret = $this->call('chatmessages', 'POST', [
            'id' => $mid1,
            'action' => 'Release'
        ]);
        assertEquals(0, $ret['ret']);
        $ret = $this->call('chatmessages', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertFalse(Utils::pres('held', $ret['chatmessages'][0]));

        # Approve the first
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Approve',
            'id' => $mid1
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(2, count($ret['chatmessages']));
        assertEquals($mid2, $ret['chatmessages'][0]['id']);

        # Reject the second
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Reject',
            'id' => $mid2
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['chatmessages']));

        # Approve the third
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Approve',
            'id' => $mid3
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('chatmessages', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['chatmessages']));

        # Now log in as the recipient.  Should see the approved ones.
        assertTrue($this->user2->login('testpw'));

        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $this->cid
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals(2, count($ret['chatmessages']));
        assertEquals($mid1, $ret['chatmessages'][0]['id']);
        assertEquals($mid3, $ret['chatmessages'][1]['id']);
    }

    public function testReviewDup() {
        $this->dbhm->preExec("DELETE FROM spam_whitelist_links WHERE domain LIKE 'spam.wherever';");
        assertTrue($this->user->login('testpw'));

        # Make the originating user be on the group so we can test groupfrom.
        $this->user->addMembership($this->groupid);

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);
        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);

        # Create a chat to the third user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid3
        ]);
        assertEquals(0, $ret['ret']);
        $this->cid2 = $ret['id'];
        assertNotNull($this->cid2);

        # Create the same spam on each.
        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://spam.wherever ',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid2,
            'message' => 'Test with link http://spam.wherever ',
            'refchatid' => $this->cid2
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        # Now log in as a mod.
        assertTrue($this->user3->login('testpw'));
        $this->user3->addMembership($this->groupid, User::ROLE_MODERATOR);

        # Should see this now.
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(2, $ret['work']['chatreview']);

        # Reject the first
        $ret = $this->call('chatmessages', 'POST', [
            'action' => 'Reject',
            'id' => $mid1
        ]);
        assertEquals(0, $ret['ret']);

        # Should have deleted the dup.
        $ret = $this->call('session', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, $ret['work']['chatreview']);
    }

    public function testReviewUnmod() {
        $this->dbhm->preExec("DELETE FROM spam_whitelist_links WHERE domain LIKE 'spam.wherever';");
        $this->user->setPrivate('chatmodstatus', User::CHAT_MODSTATUS_UNMODERATED);
        assertTrue($this->user->login('testpw'));

        # Create a chat to the second user
        $ret = $this->call('chatrooms', 'PUT', [
            'userid' => $this->uid2
        ]);

        assertEquals(0, $ret['ret']);
        $this->cid = $ret['id'];
        assertNotNull($this->cid);

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://spam.wherever ',
            'refchatid' => $this->cid
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid1 = $ret['id'];

        $ret = $this->call('chatmessages', 'POST', [
            'roomid' => $this->cid,
            'message' => 'Test with link http://ham.wherever '
        ]);
        $this->log("Create message " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertNotNull($ret['id']);
        $mid2 = $ret['id'];

        $cm = new ChatMessage($this->dbhr, $this->dbhm, $mid2);
        self::assertEquals(0, $cm->getPrivate('reviewrequired'));

        }

    public function testContext() {
        # Set up a conversation with lots of messages.
        $r = new ChatRoom($this->dbhr, $this->dbhm);
        list ($rid, $blocked) = $r->createConversation($this->uid, $this->uid2);

        for ($i = 0; $i < 10; $i++) {
            $cm = new ChatMessage($this->dbhr, $this->dbhm);
            list ($cid, $banned) = $cm->create($rid, $this->uid, "Test message $i");
            $this->log("Created chat message $cid in $rid");
        }

        assertTrue($this->user->login('testpw'));

        # Get all.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(10, count($ret['chatmessages']));

        # Get first lot.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid,
            'limit' => 5
        ]);

        assertEquals(0, $ret['ret']);
        assertEquals(5, count($ret['chatmessages']));

        for ($i = 5; $i < 10; $i++) {
            assertEquals("Test message $i", $ret['chatmessages'][$i - 5]['message']);
        }

        $ctx = $ret['context'];

        # Get second lot.
        $ret = $this->call('chatmessages', 'GET', [
            'roomid' => $rid,
            'limit' => 5,
            'context' => $ctx
        ]);

        $this->log("Got second " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(5, count($ret['chatmessages']));

        for ($i = 0; $i < 5; $i++) {
            assertEquals("Test message $i", $ret['chatmessages'][$i]['message']);
        }
    }
//
//    public function testEH2()
//    {
//        $u = new User($this->dbhr, $this->dbhm);
//        $this->dbhr->errorLog = TRUE;
//        $this->dbhm->errorLog = TRUE;
//
//        $uid = $u->findByEmail('sheilamentor@gmail.com');
//        $u = new User($this->dbhr, $this->dbhm, $uid);
//        $_SESSION['id'] = $uid;
//
//        $ret = $this->call('chatmessages', 'GET', [ 'limit' => 10, 'modtools' => TRUE ]);
//
//        assertEquals(0, $ret['ret']);
//        $this->log("Took {$ret['duration']} DB {$ret['dbwaittime']}");
//    }
}

