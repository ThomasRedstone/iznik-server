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
class statsTest extends IznikTestCase {
    private $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->dbhm->exec("DELETE FROM `groups` WHERE nameshort = 'testgroup';");
        $this->dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
    }

    public function testBasic() {
        # Create a group with one message and one member.
        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup', Group::GROUP_REUSE);

        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        assertNotNull($this->uid);
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        $this->user->addMembership($gid);

        $msg = $this->unique(file_get_contents(IZNIK_BASE . '/test/ut/php/msgs/basic'));
        $msg = str_ireplace('FreeglePlayground', 'testgroup', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::EMAIL, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals($gid, $m->getGroups()[0]);
        $this->log("Created message $id on $gid");
        $m->approve($gid);

        # Need to be a mod to see all.
        $u = User::get($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $u = User::get($this->dbhr, $this->dbhm, $uid);
        $u->setPrivate('systemrole', User::SYSTEMROLE_MODERATOR);
        assertGreaterThan(0, $u->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($u->login('testpw'));

        # Now generate stats for today
        $s = new Stats($this->dbhr, $this->dbhm, $gid);
        $date = date ("Y-m-d", strtotime("today"));
        $this->log("Generate for $date");
        $s->generate($date);

        $stats = $s->get($date);
        assertEquals(1, $stats['ApprovedMessageCount']);
        assertEquals(1, $stats['ApprovedMemberCount']);

        assertEquals([ 'FDv2' => 1 ], $stats['PostMethodBreakdown']);
        assertEquals([ 'Other' => 1 ], $stats['MessageBreakdown']);

        $multistats = $s->getMulti($date, [ $gid ], "30 days ago", "tomorrow");
        assertEquals([
            [
                'date' => $date,
                'count' => 1
            ]
        ], $multistats['ApprovedMessageCount']);
        assertEquals([
            [
                'date' => $date,
                'count' => 1
            ]
        ], $multistats['ApprovedMemberCount']);
        assertEquals([
            [
                'date' => $date,
                'count' => 0
            ]
        ], $multistats['SpamMemberCount']);
        assertEquals([
            [
                'date' => $date,
                'count' => 0
            ]
        ], $multistats['SpamMessageCount']);

        # Now yesterday - shouldn't be any
        $s = new Stats($this->dbhr, $this->dbhm, $gid);
        $date = date ("Y-m-d", strtotime("yesterday"));
        $stats = $s->get($date);
        assertEquals(0, $stats['ApprovedMessageCount']);
        assertEquals(0, $stats['ApprovedMemberCount']);
        assertEquals([], $stats['PostMethodBreakdown']);
        assertEquals([], $stats['MessageBreakdown']);
     }

    public function testHeatmap() {
        $l = new Location($this->dbhr, $this->dbhm);
        $areaid = $l->create(NULL, 'Tuvalu Central', 'Polygon', 'POLYGON((179.21 8.53, 179.21 8.54, 179.22 8.54, 179.22 8.53, 179.21 8.53, 179.21 8.53))');
        assertNotNull($areaid);
        $pcid = $l->create(NULL, 'TV13', 'Postcode', 'POLYGON((179.2 8.5, 179.3 8.5, 179.3 8.6, 179.2 8.6, 179.2 8.5))');
        $fullpcid = $l->create(NULL, 'TV13 1HH', 'Postcode', 'POINT(179.2167 8.53333)');
        $locid = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');

        $m = new Message($this->dbhr, $this->dbhm);
        $id = $m->createDraft();
        $m = new Message($this->dbhr, $this->dbhm, $id);

        $m->setPrivate('locationid', $fullpcid);
        $m->setPrivate('type', Message::TYPE_OFFER);
        $m->setPrivate('textbody', 'Test');

        $i = new Item($this->dbhr, $this->dbhm);
        $iid = $i->create('test item');
        $m->addItem($iid);

        $g = Group::get($this->dbhr, $this->dbhm);
        $gid = $g->create('testgroup1', Group::GROUP_REUSE);
        $g->setSettings([ 'includearea' => FALSE ]);

        $m->constructSubject($gid);
        self::assertEquals(strtolower('OFFER: test item (TV13)'), strtolower($m->getSubject()));

        $u = new User($this->dbhr, $this->dbhm);
        $uid = $u->create(NULL, NULL, 'Test User');
        $this->dbhm->preExec("UPDATE users SET lastaccess = NOW(), lastlocation = ? WHERE id = ?", [
            $fullpcid,
            $uid
        ]);

        $s = new Stats($this->dbhr, $this->dbhm);

        $this->waitBackground();

        $map = $s->getHeatmap(Stats::HEATMAP_FLOW, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));

        $map = $s->getHeatmap(Stats::HEATMAP_MESSAGES, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));
        assertGreaterThan(0, count($map));

        $map = $s->getHeatmap(Stats::HEATMAP_USERS, 'TV13 1HH');
        $this->log("Heatmap " . var_export($map, TRUE));
        assertGreaterThan(0, count($map));
    }
}

