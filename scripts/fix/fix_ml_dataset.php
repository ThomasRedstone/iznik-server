<?php

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');

require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

# Get the 100 most popular items.
$popular = $dbhr->preQuery("SELECT itemid, COUNT(*) AS count, items.name AS name FROM `messages_items` INNER JOIN items ON items.id = messages_items.itemid GROUP BY itemid ORDER BY count DESC LIMIT 100;");

# Now scan all messages
$msgs = $dbhr->query("SELECT DISTINCT messages.id, messages.subject, ma.id AS attid FROM messages INNER JOIN messages_groups ON messages_groups.msgid = messages.id INNER JOIN messages_attachments ma on messages.id = ma.msgid WHERE messages.type = 'Offer' ORDER BY messages.id DESC LIMIT 100000;");

$f = fopen("/tmp/ml_dataset.csv", "w");

fputcsv($f, ['Message ID', 'Title', 'Matched popular item', 'Image link']);

foreach ($msgs as $msg) {
    # Only look at well-defined subjects.
    if (preg_match('/.*?\:(.*)\(.*\)/', $msg['subject'], $matches))
    {
        # If we have a well-formed subject line, record the item.
        $item= trim($matches[1]);

        # Check if this is probably a common item.
        foreach ($popular as $p)
        {
            if (strpos(strtolower($item), strtolower($p['name'])) !== false)
            {
                #error_log("{$item} matches {$p['name']}");
                fputcsv($f, [
                    $msg['id'],
                    $msg['subject'],
                    $p['name'],
                    'https://www.ilovefreegle.org/img_' . $msg['attid'] . '.jpg'
                ]);

                break;
            }
        }
    }
}