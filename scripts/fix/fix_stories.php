<?php

# Fake user site.
# TODO Messy.
$_SERVER['HTTP_HOST'] = "www.ilovefreegle.org";

require_once dirname(__FILE__) . '/../../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
require_once(IZNIK_BASE . '/mailtemplates/story.php');
require_once(IZNIK_BASE . '/include/user/User.php');
global $dbhr, $dbhm;

$lockh = Utils::lockScript(basename(__FILE__));

$users = $dbhr->preQuery("SELECT userid FROM polls_users WHERE pollid = 3 AND response LIKE '%All%';");
list ($transport, $mailer) = Mail::getMailer();

$count = 0;

foreach ($users as $user) {
    $u = new User($dbhr, $dbhm, $user['userid']);
    $html = story($u->getName(), $u->getEmailPreferred());
    error_log("..." . $u->getEmailPreferred());
    $count++;

    $message = \Swift_Message::newInstance()
        ->setSubject("Tell us your Freegle story!")
        ->setFrom([NOREPLY_ADDR => SITE_NAME])
        ->setReturnPath($u->getBounce())
        ->setTo([ $u->getEmailPreferred() => $u->getName() ])
        ->setBody("We'd love to hear your Freegle story.  Tell us at https://" . USER_SITE . "/stories");

    # Add HTML in base-64 as default quoted-printable encoding leads to problems on
    # Outlook.
    $htmlPart = \Swift_MimePart::newInstance();
    $htmlPart->setCharset('utf-8');
    $htmlPart->setEncoder(new \Swift_Mime_ContentEncoder_Base64ContentEncoder);
    $htmlPart->setContentType('text/html');
    $htmlPart->setBody($html);
    $message->attach($htmlPart);

    $mailer->send($message);
}

error_log("Sent $count");

Utils::unlockScript($lockh);