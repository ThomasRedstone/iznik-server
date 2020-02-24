<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/user/User.php');

class Schedule extends Entity
{
    /** @var  $dbhm LoggedPDO */
    var $publicatts = array('id', 'created', 'schedule', 'userid');
    var $settableatts = array('created', 'schedule');
    protected $schedule = NULL;
    protected $allowpast = FALSE;

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $userid = NULL, $allowpast = false)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->allowpast = $allowpast;
        $this->fetch($dbhr, $dbhm, NULL, 'users_schedules', 'schedule', $this->publicatts);

        if ($userid) {
            $schedules = $this->dbhr->preQuery("SELECT id FROM users_schedules WHERE userid = ?;", [
                $userid
            ]);

            foreach ($schedules as $schedule) {
                $this->fetch($dbhr, $dbhm, $schedule['id'], 'users_schedules', 'schedule', $this->publicatts);
            }
        }
    }

    private function filterPast($schedule) {
        $sched = $schedule;

        if (!$this->allowpast && $schedule) {
            error_log("Filter");
            $sched = [];
            $today = strtotime('midnight today');

            foreach ($schedule as $s) {
                if (strtotime($s['date']) >= $today) {
                    $sched[] = $s;
                } else {
                    error_log("Filter out {$s['date']}");
                }
            }
        }

        return $sched;
    }

    public function create($userid, $schedule) {
        $id = NULL;

        $rc = $this->dbhm->preExec("REPLACE INTO users_schedules (userid, schedule) VALUES (?, ?);", [
            $userid,
            json_encode($this->filterPast($schedule))
        ]);

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            if ($id) {
                $this->fetch($this->dbhm, $this->dbhm, $id, 'users_schedules', 'schedule', $this->publicatts);
            }
        }

        return($id);
    }

    public function getPublic()
    {
        $ret = parent::getPublic();
        $ret['schedule'] = json_decode($ret['schedule'], TRUE);
        $ret['created'] = pres('created', $ret) ? ISODate($ret['created']) : NULL;
        $ret['textversion'] = $this->getSummary();

        return($ret);
    }

    public function setSchedule($schedule) {
        $this->setPrivate('schedule', json_encode($this->filterPast($schedule)));
    }

    public function match($user1, $user2) {
        $schedules = $this->dbhr->preQuery("SELECT * FROM users_schedules WHERE userid = ? OR userid = ?;", [
            $user1,
            $user2
        ]);

        $matches = [];

        if (count($schedules) == 2) {
            $schedule1 = json_decode($schedules[0]['schedule'], TRUE);
            $schedule2 = json_decode($schedules[1]['schedule'], TRUE);

            if ($schedule1 && $schedule2) {
                foreach ($schedule1 as $slot1) {
                    foreach ($schedule2 as $slot2) {
                        #error_log("Compare {$slot1['date']} {$slot1['hour']} av {$slot1['available']} to {$slot2['date']} {$slot2['hour']} av {$slot2['available']} ");
                        $key = $slot1['date'] . $slot1['hour'];

                        if ($slot1['available'] && $slot2['available'] &&
                            $slot1['date'] == $slot2['date'] &&
                            $slot1['hour'] == $slot2['hour'] &&
                            !array_key_exists($key, $matches)) {
                            $matches[$key] = $slot1;
                            #error_log("Matches {$slot1['date']} {$slot1['hour']} av {$slot1['available']} to {$slot2['date']} {$slot2['hour']} av {$slot2['available']} ");
                        }
                    }
                }
            }
        }

        ksort($matches);

        return(array_values($matches));
    }

    public function getSummary() {
        # Get human readable version of a schedule.
        $schedule = $this->filterPast(json_decode($this->schedule['schedule'], TRUE));

        $slots = [];

        if ($schedule) {
            foreach ($schedule as $s) {
                if ($s['available']) {
                    $t = strtotime($s['date']);
                    $str = date("l", $t) . " ";

                    switch($s['hour']) {
                        case 0: $str .= 'morning'; break;
                        case 1: $str .= 'afternoon'; break;
                        case 2: $str .= 'evening'; break;
                    }

                    $slots[$t] = $str;
                }
            }

            ksort($slots);
        }

        $str = '';

        foreach ($slots as $slot) {
            if ($str != '') {
                $str .= ', ';
            }

            $str .= $slot;
        }

        return($str);
    }
}