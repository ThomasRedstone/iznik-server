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
class communityEventTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $g = Group::get($dbhr, $dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
        $dbhm->preExec("DELETE FROM communityevents WHERE title = 'Test event';");
    }

    public function testBasic() {
        # Create an event and check we can read it back.
        $c = new CommunityEvent($this->dbhm, $this->dbhm);
        $id = $c->create(NULL, 'Test event', 'Test location', NULL, NULL, NULL, NULL, NULL);
        assertNotNull($id);

        $c->addGroup($this->groupid);
        $start = Utils::ISODate('@' . (time()+600));
        $end = Utils::ISODate('@' . (time()+600));
        $c->addDate($start, $end);

        $atts = $c->getPublic();
        assertEquals('Test event', $atts['title']);
        assertEquals('Test location', $atts['location']);
        assertEquals(1, count($atts['groups']));
        assertEquals($this->groupid, $atts['groups'][0]['id']);
        assertEquals(1, count($atts['dates']));
        assertEquals($start, $atts['dates'][0]['start']);
        assertEquals($start, $atts['dates'][0]['end']);

        # Check that a user sees what we want them to see.
        $u = User::get($this->dbhm, $this->dbhm);
        $uid = $u->create('Test', 'User', 'Test User');

        # Not in the right group - shouldn't see.
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        assertEquals(0, count($events));

        # Right group - shouldn't see pending.
        $u->addMembership($this->groupid);
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        assertEquals(0, count($events));

        # Mark not pending - should see.
        $c->setPrivate('pending', 0);
        $ctx = NULL;
        $events = $c->listForUser($uid, FALSE, $ctx);
        assertEquals(1, count($events));
        assertEquals($id, $events[0]['id']);

        # Remove things.
        $c->removeDate($atts['dates'][0]['id']);
        $c->removeGroup($this->groupid);

        $c = new CommunityEvent($this->dbhm, $this->dbhm, $id);
        $atts = $c->getPublic();
        assertEquals(0, count($atts['groups']));
        assertEquals(0, count($atts['dates']));

        # Delete event - shouldn't see it.
        $c->addGroup($this->groupid);
        $c->delete();
        $ctx = NULL;
        $events = $c->listForUser($uid, TRUE, $ctx);
        assertEquals(0, count($events));

        }
}


