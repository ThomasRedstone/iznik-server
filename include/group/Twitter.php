<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Entity.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/group/CommunityEvent.php');

use Abraham\TwitterOAuth\TwitterOAuth;

class Twitter {
    var $publicatts = ['name', 'token', 'secret', 'authdate', 'valid', 'msgid', 'eventid'];
    
    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $groupid)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;
        $this->groupid = $groupid;

        foreach ($this->publicatts as $att) {
            $this->$att = NULL;
        }

        $groups = $this->dbhr->preQuery("SELECT * FROM groups_twitter WHERE groupid = ?;", [ $groupid ]);
        foreach ($groups as $group) {
            foreach ($this->publicatts as $att) {
                $this->$att = $group[$att];
            }
        }
    }

    public function getPublic() {
        $ret = [];
        foreach ($this->publicatts as $att) {
            $ret[$att] = $this->$att;
        }
        
        return($ret);
    }
    
    public function set($name, $token, $secret) {
        $this->dbhm->preExec("INSERT INTO groups_twitter (groupid, name, token, secret, authdate, valid) VALUES (?,?,?,?,NOW(),1) ON DUPLICATE KEY UPDATE name = ?, token = ?, secret = ?, authdate = NOW(), valid = 1;",
            [
                $this->groupid,
                $name, $token, $secret,
                $name, $token, $secret
            ]);

        $this->name = $name;
        $this->token = $token;
        $this->secret = $secret;
    }

    public function tweet($status, $media) {
        $tw = new TwitterOAuth(TWITTER_CONSUMER_KEY, TWITTER_CONSUMER_SECRET, $this->token, $this->secret);
        $tw->setTimeouts(120, 120);
        $content = $tw->get("account/verify_credentials");
        $ret = NULL;
        $rc = FALSE;
        $valid = TRUE;

        if ($content) {
            if ($media) {
                # The API uploads from file, unfortunately.
                $fname = tempnam('/tmp', 'twitter_');
                file_put_contents($fname, $media);

                try {
                    $ret = $tw->upload('media/upload', array('media' => $fname));
                    $ret = json_decode(json_encode($ret), TRUE);

                    if (!pres('errors', $ret)) {
                        $ret = $tw->post('statuses/update', [
                            'status' => $status,
                            'media_ids' => implode(',', [$ret['media_id_string']])
                        ]);
                    }
                } catch (Exception $e) {}

                unlink($fname);
            } else {
                $ret = $tw->post('statuses/update', [
                    'status' => $status
                ]);
            }

            $ret = json_decode(json_encode($ret), TRUE);

            if (pres('errors', $ret)) {
                # Something failed.
                #error_log("Tweet failed " . var_export($ret, TRUE));
                $this->dbhm->preExec("UPDATE groups_twitter SET lasterror = ?, lasterrortime = NOW() WHERE groupid = ?;", [ var_export($ret['errors'], TRUE), $this->groupid ]);

                if ($ret['errors'][0]['code'] == 220) {
                    # This indicates invalid credentials.
                    $valid = FALSE;
                }
            } else {
                $rc = TRUE;
            }
        }

        if (!$valid) {
            $this->dbhm->preExec("UPDATE groups_twitter SET valid = 0 WHERE groupid = ?;", [ $this->groupid ]);
            #error_log("Twitter link not valid for {$this->groupid}");
        }

        return($rc);
    }

    public function tweetEvents() {
        # We want to tweet:
        # - any events since the last one, with a max of the 24 hours ago to avoid flooding things
        # - which start after now and within the next 96 hours
        $addedsince = date("Y-m-d", strtotime("24 hours ago"));
        $startafter = date("Y-m-d");
        $startbefore = date("Y-m-d", strtotime("+96 hours"));
        $eventid = $this->eventid ? $this->eventid : 0;
        $sql = "SELECT DISTINCT communityevents_groups.eventid, communityevents_dates.start FROM communityevents_groups INNER JOIN groups ON groups.id = communityevents_groups.groupid INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents_groups.eventid WHERE communityevents_groups.groupid = ? AND ((communityevents_groups.arrival >= ? AND communityevents_dates.eventid > ?) OR communityevents_dates.start <= ?) AND communityevents_dates.start >= ? ORDER BY communityevents_dates.start ASC;";

        $events = $this->dbhr->preQuery($sql, [
            $this->groupid,
            $addedsince,
            $eventid,
            $startbefore,
            $startafter
        ]);
        $eventid = NULL;
        $worked = 0;

        foreach ($events as $event) {
            $e = new CommunityEvent($this->dbhr, $this->dbhm, $event['eventid']);

            if (!$e->getPrivate('deleted')) {
                # We tweet the title, first date later than now, and a link.
                $atts = $e->getPublic();

                # Get a string representation of the date in UK time.
                $tz1 = new DateTimeZone('UTC');
                $tz2 = new DateTimeZone('Europe/London');
                $datetime = new DateTime($event['start'], $tz1);
                $datetime->setTimezone($tz2);
                $datestr = $datetime->format('D jS F g:i a');

                $status = $atts['title'];
                $status = substr($status, 0, 80);
                $status .= " on $datestr";

                $link = "https://directv2.ilovefreegle.org/events/{$this->groupid}?t=". time();

                $status .= " $link";
                $rc = $this->tweet($status, NULL);
                error_log($status);

                if ($rc) {
                    $worked++;
                }

                # Whether the tweet works or not, we might as well assume it does - tweets are ephemeral so there's no
                # point getting too het up if they don't work.
                $eventid = max($eventid, $event['eventid']);
            }
        }

        if ($eventid) {
            $this->dbhm->preExec("UPDATE groups_twitter SET eventid = ? WHERE groupid = ?;", [ $eventid, $this->groupid ]);
        }

        return($worked);
    }

    public function tweetMessages() {
        # We want to tweet any messages since the last one, with a max of the 24 hours ago to avoid flooding things.
        $mysqltime = date ("Y-m-d", strtotime("24 hours ago"));
        $msgid = $this->msgid ? $this->msgid : 0;
        $sql = "SELECT messages_groups.msgid, groups.legacyid, messages_groups.yahooapprovedid FROM messages_groups INNER JOIN groups ON groups.id = messages_groups.groupid WHERE messages_groups.groupid = ? AND messages_groups.arrival >= ? AND msgid > ? AND messages_groups.yahooapprovedid IS NOT NULL ORDER BY messages_groups.msgid ASC;";

        $msgs = $this->dbhr->preQuery($sql, [ $this->groupid, $mysqltime, $msgid ]);
        $msgid = NULL;
        $worked = 0;

        foreach ($msgs as $msg) {
            $m = new Message($this->dbhr, $this->dbhm, $msg['msgid']);
            $atts = $m->getAttachments();
            $media = count($atts) > 0 ? $atts[0]->getData() : NULL;

            # We tweet the subject and a link.
            $status = $m->getSubject();
            $status = substr($status, 0, 80);

            $link = "https://directv2.ilovefreegle.org/mygroups/{$msg['legacyid']}/message/{$msg['yahooapprovedid']}";

            $status .= " $link";
            $rc = $this->tweet($status, $media);
            
            if ($rc) {
                $worked++;
            }

            # Whether the tweet works or not, we might as well assume it does - tweets are ephemeral so there's no
            # point getting too het up if they don't work.
            $msgid = $msg['msgid'];
        }

        if ($msgid) {
            $this->dbhm->preExec("UPDATE groups_twitter SET msgid = ? WHERE groupid = ?;", [ $msgid, $this->groupid ]);
        }
        
        return($worked);
    }
}
