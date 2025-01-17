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
class userSearchAPITest extends IznikAPITestCase {
    public $dbhr, $dbhm;

    private $count = 0;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
    }

    public function testSpecial() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_ADMIN);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('user', 'GET', [
            'search' => 'hellsauntie@uwclub.net'
        ]);

        $this->log("Got " . var_export($ret, TRUE));
    }

    public function testCreateDelete() {
        $u = User::get($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = User::get($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $s = new UserSearch($this->dbhr, $this->dbhm);
        $id = $s->create($this->uid, NULL, 'testsearch');
        
        $ret = $this->call('usersearch', 'GET', []);
        assertEquals(1, $ret['ret']);

        assertTrue($this->user->login('testpw'));

        $ret = $this->call('usersearch', 'GET', []);
        $this->log(var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);
        assertEquals(1, count($ret['usersearches']));
        assertEquals($id, $ret['usersearches'][0]['id']);

        $ret = $this->call('usersearch', 'GET', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['usersearch']['id']);

        $ret = $this->call('usersearch', 'DELETE', [
            'id' => $id
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('usersearch', 'GET', []);
        assertEquals(0, $ret['ret']);
        assertEquals(0, count($ret['usersearches']));

        $s->delete();

        }
}

