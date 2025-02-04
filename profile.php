<?php
// profile.php -- HotCRP profile management page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once("src/init.php");
$Qreq || initialize_request();

// check for change-email capabilities

/** @param Qrequest $qreq */
function change_email_by_capability(Contact $user, $qreq) {
    $conf = $user->conf;
    ensure_session();
    $capdata = TokenInfo::find(trim($qreq->changeemail), $conf);
    $capcontent = null;
    if (!$capdata
        || !$capdata->contactId
        || $capdata->capabilityType !== TokenInfo::CHANGEEMAIL
        || !$capdata->is_active()
        || !($capcontent = json_decode($capdata->data))
        || !is_object($capcontent)
        || !($capcontent->uemail ?? null)) {
        if (trim($qreq->changeemail) !== "1") {
            Ht::error_at("changeemail", "<0>That email change code has expired, or you didn’t enter it correctly.");
        }
        $capdata = false;
    }

    $Acct = null;
    if ($capdata && !($Acct = $conf->user_by_id($capdata->contactId))) {
        Ht::error_at("changeemail", "<0>The account associated with that email change code no longer exists.");
    }
    if ($Acct && strcasecmp($Acct->email, $capcontent->oldemail) !== 0) {
        Ht::error_at("changeemail", "<0>You have changed your email address since creating that email change code.");
        $Acct = null;
    }

    $newemail = $Acct ? $capcontent->uemail : null;
    if ($Acct && $conf->user_by_email($newemail)) {
        Conf::msg_error("The email address you requested, " . htmlspecialchars($newemail) . ", is already in use on this site. You may want to <a href=\"" . $conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.");
        return false;
    }

    $newcdbu = $newemail ? $conf->contactdb_user_by_email($newemail) : null;
    if ($newcdbu) {
        if ($newcdbu->contactdb_disabled()) { // NB do not use is_disabled()
            Conf::msg_error("changeemail", "That user is globally disabled.");
            return false;
        } else if ($qreq->go && $qreq->valid_post()) {
            $qreq->password = trim((string) $qreq->password);
            $info = $newcdbu->check_password_info($qreq->password);
            if (!$info["ok"]) {
                $qreqa = ["email" => $newemail] + $qreq->as_array();
                LoginHelper::login_error($conf, new Qrequest("POST", $qreqa), $info);
                unset($qreq->go);
            }
        }
    }

    if ($newemail
        && $qreq->go
        && $qreq->valid_post()) {
        $Acct->change_email($newemail);
        $capdata->delete();
        $conf->confirmMsg("Your email address has been changed.");
        if (!$user->has_account_here() || $user->contactId == $Acct->contactId) {
            Contact::set_main_user($Acct->activate($qreq));
        }
        if (Contact::session_user_index($capcontent->oldemail) >= 0) {
            LoginHelper::change_session_users([
                $capcontent->oldemail => -1, $newemail => 1
            ]);
        }
        $conf->redirect_hoturl("profile");
    } else {
        $conf->header("Change email", "account", ["action_bar" => false]);
        if ($Acct) {
            echo '<p class="mb-5">Complete the email change using this form.</p>';
        } else {
            echo '<p class="mb-5">Enter an email change code.</p>';
        }
        echo Ht::form($conf->hoturl("profile", "changeemail=1"), ["class" => "compact-form", "id" => "changeemailform"]),
            Ht::hidden("post", post_value());
        if ($Acct) {
            echo '<div class="f-i"><label>Old email</label>', htmlspecialchars($Acct->email), '</div>',
                '<div class="f-i"><label>New email</label>',
                Ht::entry("email", $newemail, ["autocomplete" => "username", "readonly" => true, "class" => "fullw"]),
                '</div>';
        }
        echo '<div class="', Ht::control_class("changeemail", "f-i"), '"><label for="changeemail">Change code</label>',
            Ht::feedback_html_at("changeemail"),
            Ht::entry("changeemail", $qreq->changeemail == "1" ? "" : $qreq->changeemail, ["id" => "changeemail", "class" => "fullw", "autocomplete" => "one-time-code"]),
            '</div>';
        if ($newcdbu) {
            echo '<div class="', Ht::control_class("password", "f-i"), '"><label for="password">Password for ', htmlspecialchars($newemail), '</label>',
            Ht::feedback_html_at("password"),
            Ht::password("password", "", ["autocomplete" => "password", "class" => "fullw"]),
            '</div>';
        }
        echo '<div class="popup-actions">',
            Ht::submit("go", "Change email", ["class" => "btn-primary", "value" => 1]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true]),
            '</div></form>';
        Ht::stash_script("hotcrp.focus_within(\$(\"#changeemailform\"));window.scroll(0,0)");
        $conf->footer();
        exit;
    }
}

if ($Qreq->changeemail) {
    if ($Qreq->cancel) {
        $Conf->redirect_self($Qreq);
    } else if (!$Me->is_actas_user()) {
        change_email_by_capability($Me, $Qreq);
    }
}

if (!$Me->is_signed_in()) {
    $Me->escape();
}

$newProfile = 0;
$UserStatus = new UserStatus($Me);
$UserStatus->set_user($Me);
$UserStatus->qreq = $Qreq;

if ($Qreq->u === null && ($Qreq->user || $Qreq->contact)) {
    $Qreq->u = $Qreq->user ? : $Qreq->contact;
}
if (($p = $Qreq->path_component(0)) !== null) {
    if (in_array($p, ["", "me", "self", "new", "bulk"])
        || strpos($p, "@") !== false
        || !$UserStatus->cs()->canonical_group($p)) {
        if ($Qreq->u === null) {
            $Qreq->u = urldecode($p);
        }
        if (($p = $Qreq->path_component(1)) !== null
            && $Qreq->t === null) {
            $Qreq->t = $p;
        }
    } else if ($Qreq->t === null) {
        $Qreq->t = $p;
    }
}
if ($Me->privChair && $Qreq->new) {
    $Qreq->u = "new";
}


// Load user.
$Acct = $Me;
if ($Qreq->u === "me" || $Qreq->u === "self") {
    $Qreq->u = "me";
} else if ($Me->privChair && ($Qreq->u || $Qreq->search)) {
    if ($Qreq->u === "new") {
        $Acct = new Contact($Conf);
        $newProfile = 1;
    } else if ($Qreq->u === "bulk") {
        $Acct = new Contact($Conf);
        $newProfile = 2;
    } else if (($id = cvtint($Qreq->u)) > 0) {
        $Acct = $Conf->user_by_id($id);
    } else if ($Qreq->u === "" && $Qreq->search) {
        $Conf->redirect_hoturl("users");
    } else {
        $Acct = $Conf->user_by_email($Qreq->u);
        if (!$Acct && $Qreq->search) {
            $cs = new ContactSearch(ContactSearch::F_USER, $Qreq->u, $Me);
            if ($cs->user_ids()) {
                $Acct = $Conf->user_by_id(($cs->user_ids())[0]);
                $list = new SessionList("u/all/" . urlencode($Qreq->search), $cs->user_ids(), "“" . htmlspecialchars($Qreq->u) . "”", $Conf->hoturl_raw("users", ["t" => "all"], Conf::HOTURL_SITEREL));
                $list->set_cookie($Me);
                $Qreq->u = $Acct->email;
            } else {
                Conf::msg_error("No user matches “" . htmlspecialchars($Qreq->u) . "”.");
                unset($Qreq->u);
            }
            $Conf->redirect_self($Qreq);
        }
    }
}

// Redirect if requested user isn't loaded user.
if (!$Acct
    || ($Qreq->u !== null
        && $Qreq->u !== (string) $Acct->contactId
        && ($Qreq->u !== "me" || $Acct !== $Me)
        && strcasecmp($Qreq->u, $Acct->email)
        && ($Acct->contactId || !$newProfile))
    || (isset($Qreq->profile_contactid)
        && $Qreq->profile_contactid !== (string) $Acct->contactId)) {
    if (!$Acct) {
        Conf::msg_error("Invalid user.");
    } else if (isset($Qreq->save) || isset($Qreq->savebulk)) {
        Conf::msg_error("You’re logged in as a different user now, so your changes were ignored.");
    }
    unset($Qreq->u, $Qreq->save, $Qreq->savebulk);
    $Conf->redirect_self($Qreq);
}

// Redirect if canceled.
if ($Qreq->cancel) {
    $Conf->redirect_self($Qreq);
}

$need_highlight = false;
if (($Acct->contactId != $Me->contactId || !$Me->has_account_here())
    && $Acct->has_email()
    && !$Acct->firstName && !$Acct->lastName && !$Acct->affiliation
    && !$Qreq->post) {
    $result = $Conf->qe_raw("select Paper.paperId, authorInformation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$Acct->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")");
    while (($prow = PaperInfo::fetch($result, $Me))) {
        foreach ($prow->author_list() as $au) {
            if (strcasecmp($au->email, $Acct->email) == 0
                && ($au->firstName || $au->lastName || $au->affiliation)) {
                if (!$Acct->firstName && $au->firstName) {
                    $Acct->firstName = $au->firstName;
                    $need_highlight = true;
                }
                if (!$Acct->lastName && $au->lastName) {
                    $Acct->lastName = $au->lastName;
                    $need_highlight = true;
                }
                if (!$Acct->affiliation && $au->affiliation) {
                    $Acct->affiliation = $au->affiliation;
                    $need_highlight = true;
                }
            }
        }
    }
}


/** @param UserStatus $ustatus
 * @param ?Contact $acct
 * @return ?Contact */
function save_user($cj, $ustatus, $acct) {
    // check for missing fields
    UserStatus::normalize_name($cj);
    if (!$acct && !isset($cj->email)) {
        $ustatus->error_at("email", "<0>Email address required");
        return null;
    }

    // check email
    if (!$acct || strcasecmp($cj->email, $acct->email)) {
        if ($acct && $acct->data("locked")) {
            $ustatus->error_at("email", "<0>This account is locked, so you can’t change its email address");
            return null;
        } else if (($new_acct = $ustatus->conf->user_by_email($cj->email))) {
            if (!$acct) {
                $cj->id = $new_acct->contactId;
            } else {
                $ustatus->error_at("email", "<0>Email address ‘{$cj->email}’ is already in use");
                $ustatus->msg_at("email", "<5>You may want to <a href=\"" . $ustatus->conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.", MessageSet::INFORM);
                return null;
            }
        } else if ($ustatus->conf->external_login()) {
            if ($cj->email === "") {
                $ustatus->error_at("email", "<0>Username required");
                return null;
            }
        } else if ($cj->email === "") {
            $ustatus->error_at("email", "<0>Email address required");
            return null;
        } else if (!validate_email($cj->email)) {
            $ustatus->error_at("email", "<0>Invalid email address ‘{$cj->email}’");
            return null;
        } else if ($acct && !$acct->has_account_here()) {
            $ustatus->error_at("email", "<0>Your current account is only active on other HotCRP.com sites. Due to a server limitation, you can’t change your email until activating your account on this site.");
            return null;
        }
        if ($acct && (!$ustatus->viewer->privChair || $acct === $ustatus->viewer)) {
            assert($acct->contactId > 0);
            $old_preferredEmail = $acct->preferredEmail;
            $acct->preferredEmail = $cj->email;
            $capability = new TokenInfo($ustatus->conf, TokenInfo::CHANGEEMAIL);
            $capability->set_user($acct)->set_token_pattern("hcce[20]")->set_expires_after(259200);
            $capability->data = json_encode_db(["oldemail" => $acct->email, "uemail" => $cj->email]);
            if (($token = $capability->create())) {
                $rest = ["capability_token" => $token, "sensitive" => true];
                $mailer = new HotCRPMailer($ustatus->conf, $acct, $rest);
                $prep = $mailer->prepare("@changeemail", $rest);
            } else {
                $prep = null;
            }
            if ($prep->can_send()) {
                $prep->send();
                $ustatus->conf->warnMsg("Mail has been sent to " . htmlspecialchars($cj->email) . ". Use the link it contains to confirm your email change request.");
            } else {
                Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($cj->email) . " at this time. Your email address was unchanged.");
            }
            // Save changes *except* for new email, by restoring old email.
            $cj->email = $acct->email;
            $acct->preferredEmail = $old_preferredEmail;
        }
    }

    // save account
    return $ustatus->save($cj, $acct);
}


function parseBulkFile(Contact $user, $text, $filename) {
    $conf = $user->conf;
    $text = cleannl(convert_to_utf8($text));
    $filename = $filename ? htmlspecialchars($filename) . ":" : "line ";
    $ms = new MessageSet;
    $success = $nochanges = $notified = [];

    if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
        && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
        $tarr = CsvParser::split_lines($text);
        foreach ($tarr as &$t) {
            if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%") {
                $t = CsvGenerator::quote($t);
            }
            $t .= "\n";
        }
        unset($t);
        $text = join("", $tarr);
    }

    $csv = new CsvParser($text);
    $csv->set_filename($filename);
    $csv->set_comment_chars("#%");
    if (($line = $csv->next_list())) {
        if (preg_grep('/\A(?:email|user)\z/i', $line)) {
            $csv->set_header($line);
        } else if (count($line) == 1) {
            $csv->set_header(["user"]);
            $csv->unshift($line);
        } else {
            // interpolate a likely header
            $csv->unshift($line);
            $hdr = [];
            for ($i = 0; $i < count($line); ++$i) {
                if (validate_email($line[$i])
                    && array_search("email", $hdr) === false) {
                    $hdr[] = "email";
                } else if (strpos($line[$i], " ") !== false
                           && array_search("name", $hdr) === false) {
                    $hdr[] = "name";
                } else if (preg_match('/\A(?:pc|chair|sysadmin|admin)\z/i', $line[$i])
                           && array_search("roles", $hdr) === false) {
                    $hdr[] = "roles";
                } else if (array_search("name", $hdr) !== false
                           && array_search("affiliation", $hdr) === false) {
                    $hdr[] = "affiliation";
                } else {
                    $hdr[] = "unknown" . count($hdr);
                }
            }
            $csv->set_header($hdr);
            $mi = $ms->warning_at(null, "<5>Header missing, assuming ‘<code>" . join(",", $hdr) . "</code>’");
            $mi->landmark = $csv->landmark();
        }
    }

    $ustatus = new UserStatus($user);
    $ustatus->no_deprivilege_self = true;
    $ustatus->no_nonempty_profile = true;
    $ustatus->add_csv_synonyms($csv);

    while (($line = $csv->next_row())) {
        $ustatus->set_user(new Contact($conf));
        $ustatus->clear_messages();
        $ustatus->jval = (object) ["id" => null];
        $ustatus->csvreq = $line;
        $ustatus->parse_csv_group("");
        $ustatus->notify = friendly_boolean($line["notify"]) ?? true;
        if (($saved_user = save_user($ustatus->jval, $ustatus, null))) {
            $url = $conf->hoturl("profile", "u=" . urlencode($saved_user->email));
            $x = "<a class=\"nb\" href=\"{$url}\">" . $saved_user->name_h(NAME_E) . "</a>";
            if ($ustatus->notified) {
                $notified[] = $x;
                $success[] = $x;
            } else if (!empty($ustatus->diffs)) {
                $success[] = $x;
            } else {
                $nochanges[] = $x;
            }
        }
        foreach ($ustatus->problem_list() as $mi) {
            $mi->landmark = $csv->landmark();
            $ms->append_item($mi);
        }
    }

    if (!empty($ustatus->unknown_topics)) {
        $ms->warning_at(null, $conf->_("<0>Unknown topics ignored (%#s)", array_keys($ustatus->unknown_topics)));
    }
    $mpos = 0;
    if (!empty($success)) {
        $ms->splice_item($mpos++, MessageItem::success($conf->_("<5>Saved accounts %#s", $success)));
    } else if ($ms->has_error()) {
        $ms->splice_item($mpos++, MessageItem::error($conf->_("<0>Changes not saved; please correct these errors and try again")));
    }
    if (!empty($notified)) {
        $ms->splice_item($mpos++, MessageItem::success($conf->_("<5>Activated accounts and sent mail to %#s", $notified)));
    }
    if (!empty($nochanges)) {
        $ms->splice_item($mpos++, new MessageItem(null, $conf->_("<5>No changes to accounts %#s", $nochanges), MessageSet::MARKED_NOTE));
    } else if (!$ms->has_message()) {
        $ms->splice_item($mpos++, new MessageItem(null, $conf->_("<0>No changes"), MessageSet::WARNING_NOTE));
    }
    $conf->feedback_msg($ms);
    return !$ms->has_error();
}

if (!$Qreq->valid_post()) {
    // do nothing
} else if ($Qreq->savebulk && $newProfile && $Qreq->has_file("bulk")) {
    if (($text = $Qreq->file_contents("bulk")) === false) {
        Conf::msg_error("Internal error: cannot read file.");
    } else {
        parseBulkFile($Me, $text, $Qreq->file_filename("bulk"));
    }
    $Qreq->bulkentry = "";
    $Conf->redirect_self($Qreq, ["#" => "bulk"]);
} else if ($Qreq->savebulk && $newProfile) {
    $success = true;
    if ($Qreq->bulkentry && $Qreq->bulkentry !== "Enter users one per line") {
        $success = parseBulkFile($Me, $Qreq->bulkentry, "");
    }
    if (!$success) {
        $Me->save_session("profile_bulkentry", [Conf::$now, $Qreq->bulkentry]);
    }
    $Conf->redirect_self($Qreq, ["#" => "bulk"]);
} else if (isset($Qreq->save)) {
    assert($Acct->is_empty() === !!$newProfile);
    $cj = (object) ["id" => $Acct->has_account_here() ? $Acct->contactId : "new"];
    $UserStatus->set_user($Acct);
    $UserStatus->qreq = $Qreq;
    $UserStatus->jval = $cj;
    $UserStatus->no_deprivilege_self = true;
    if ($newProfile) {
        $UserStatus->no_nonempty_profile = true;
        $UserStatus->no_nonempty_pc = true;
        $UserStatus->notify = true;
    }
    $UserStatus->request_group("");
    $saved_user = save_user($UserStatus->jval, $UserStatus, $newProfile ? null : $Acct);
    if ($UserStatus->has_error()) {
        $UserStatus->prepend_msg("<0>Your changes were not saved. Please fix the highlighted errors and try again", 2);
    }
    $Conf->feedback_msg($UserStatus);
    if (!$UserStatus->has_error()) {
        if ($UserStatus->created || $newProfile) {
            $purl = $Conf->hoturl("profile", ["u" => $saved_user->email]);
            if ($UserStatus->created) {
                $Conf->msg("Created " . Ht::link("an account for " . $saved_user->name_h(NAME_E), $purl) . ($UserStatus->notified ? " and sent confirmation email" : "") . ". You may now create another account.", "xconfirm");
            } else {
                if (!empty($UserStatus->diffs) && $UserStatus->notified) {
                    $changes = " Updated profile (" . commajoin(array_keys($UserStatus->diffs)) . ") and sent confirmation email.";
                } else if (!empty($UserStatus->diffs)) {
                    $changes = " Updated profile (" . commajoin(array_keys($UserStatus->diffs)) . ").";
                } else {
                    $changes = "";
                }
                $Conf->msg(Ht::link($saved_user->name_h(NAME_E), $purl) . " already had " . Ht::link("an account", $purl) . ".{$changes} You may now create another account.", "xconfirm");
            }
        } else {
            if (empty($UserStatus->diffs)) {
                $Conf->msg("No changes.", "xconfirm");
            } else if ($UserStatus->notified) {
                $Conf->msg("Updated profile and sent confirmation email.", "xconfirm");
            } else {
                $Conf->msg("Updated profile.", "xconfirm");
            }
            if ($Acct->contactId != $Me->contactId) {
                $Qreq->u = $Acct->email;
            }
        }
        if (isset($Qreq->redirect)) {
            $Conf->redirect();
        } else {
            $xcj = [];
            if ($newProfile) {
                foreach (["pctype", "ass", "contactTags"] as $k) {
                    if (isset($UserStatus->jval->$k))
                        $xcj[$k] = $UserStatus->jval->$k;
                }
            }
            if ($UserStatus->has_problem()) {
                $xcj["warning_fields"] = $UserStatus->problem_fields();
            }
            $Me->save_session("profile_redirect", $xcj);
            $Conf->redirect_self($Qreq);
        }
    }
} else if (isset($Qreq->merge)
           && !$newProfile
           && $Acct->contactId == $Me->contactId) {
    $Conf->redirect_hoturl("mergeaccounts");
}

if (isset($Qreq->delete) && !Dbl::has_error() && $Qreq->valid_post()) {
    if (!$Me->privChair) {
        Conf::msg_error("Only administrators can delete accounts.");
    } else if ($Acct->contactId == $Me->contactId) {
        Conf::msg_error("You aren’t allowed to delete your own account.");
    } else if ($Acct->has_account_here()) {
        $tracks = UserStatus::user_paper_info($Conf, $Acct->contactId);
        if (!empty($tracks->soleAuthor)) {
            Conf::msg_error("This account can’t be deleted since it is sole contact for " . UserStatus::render_paper_link($Conf, $tracks->soleAuthor) . ". You will be able to delete the account after deleting those papers or adding additional paper contacts.");
        } else if ($Acct->data("locked")) {
            Conf::msg_error("This account is locked and can’t be deleted.");
        } else {
            $Conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?, affiliation=?", $Acct->contactId, $Acct->firstName, $Acct->lastName, $Acct->unaccentedName, $Acct->email, $Acct->affiliation);
            foreach (array("ContactInfo",
                           "PaperComment", "PaperConflict", "PaperReview",
                           "PaperReviewPreference", "PaperReviewRefused",
                           "PaperWatch", "ReviewRating", "TopicInterest")
                     as $table) {
                $Conf->qe_raw("delete from $table where contactId=$Acct->contactId");
            }
            // delete twiddle tags
            $assigner = new AssignmentSet($Me, true);
            $assigner->parse("paper,tag\nall,{$Acct->contactId}~all#clear\n");
            $assigner->execute();
            // clear caches
            if ($Acct->isPC || $Acct->privChair) {
                $Conf->invalidate_caches(["pc" => true]);
            }
            // done
            $Conf->confirmMsg("Permanently deleted account " . htmlspecialchars($Acct->email) . ".");
            $Me->log_activity_for($Acct, "Account deleted " . htmlspecialchars($Acct->email));
            $Conf->redirect_hoturl("users", "t=all");
        }
    }
}

// canonicalize topic
$UserStatus->set_user($Acct);
if (!$newProfile
    && ($g = $UserStatus->cs()->canonical_group($Qreq->t ? : "main"))) {
    $profile_topic = $g;
} else {
    $profile_topic = "main";
}
if ($Qreq->t && $Qreq->t !== $profile_topic && $Qreq->method() === "GET") {
    $Qreq->t = $profile_topic === "main" ? null : $profile_topic;
    $Conf->redirect_self($Qreq);
}
$UserStatus->cs()->set_root($profile_topic);

// set session list
if (!$newProfile
    && ($list = SessionList::load_cookie($Me, "u"))
    && $list->set_current_id($Acct->contactId)) {
    $Conf->set_active_list($list);
}

// set title
if ($newProfile == 2) {
    $title = "Bulk update";
} else if ($newProfile) {
    $title = "New account";
} else if (strcasecmp($Me->email, $Acct->email) == 0) {
    $title = "Profile";
} else {
    $title = $Me->name_html_for($Acct) . " profile";
}
$Conf->header($title, "account", [
    "title_div" => '<hr class="c">', "body_class" => "leftmenu",
    "action_bar" => actionBar("account")
]);

$useRequest = !$Acct->has_account_here() && isset($Qreq->watchreview);
if ($UserStatus->has_error()) {
    $need_highlight = $useRequest = true;
} else if ($Me->session("freshlogin")) {
    $Me->save_session("freshlogin", null);
}

// obtain user json
$userj = $UserStatus->user_json();
if (!$useRequest
    && $Me->privChair
    && $Acct->is_empty()
    && ($Qreq->role === "chair" || $Qreq->role === "pc")) {
    $userj->roles = [$Qreq->role];
}

// set warnings about user json
if (!$newProfile && !$useRequest) {
    assert($UserStatus->user === $Acct);
    foreach ($UserStatus->cs()->members("__crosscheck", "crosscheck_function") as $gj) {
        $UserStatus->cs()->call_function($gj, $gj->crosscheck_function, $gj);
    }
}

// compute current form json
if ($useRequest) {
    $UserStatus->swap_ignore_messages(true);
} else {
    if (($cdbu = $Acct->contactdb_user())) {
        $Acct->import_prop($cdbu);
        if ($Acct->prop_changed()) {
            $Acct->save_prop();
        }
    }
}
if (($prdj = $Me->session("profile_redirect"))) {
    $Me->save_session("profile_redirect", null);
    foreach ($prdj as $k => $v) {
        if ($k === "warning_fields") {
            foreach ($v as $k) {
                $UserStatus->warning_at($k);
            }
        } else {
            $Qreq->$k = $v;
        }
    }
}

// start form
$form_params = [];
if ($newProfile) {
    $form_params["u"] = ($newProfile === 2 ? "bulk" : "new");
} else if ($Me->contactId != $Acct->contactId) {
    $form_params["u"] = $Acct->email;
}
$form_params["t"] = $Qreq->t;
if (isset($Qreq->ls)) {
    $form_params["ls"] = $Qreq->ls;
}
echo Ht::form($Conf->hoturl("=profile", $form_params), [
    "id" => "form-profile",
    "class" => "need-unload-protection",
    "data-user" => $newProfile ? null : $Acct->email
]);

// left menu
echo '<div class="leftmenu-left"><nav class="leftmenu-menu">',
    '<h1 class="leftmenu"><a href="" class="uic js-leftmenu q">Account</a></h1>',
    '<ul class="leftmenu-list">';

if ($Me->privChair) {
    foreach ([["New account", "new"], ["Bulk update", "bulk"], ["Your profile", null]] as $t) {
        if (!$t[1] && !$newProfile && $Acct->contactId == $Me->contactId) {
            continue;
        }
        $active = $t[1] && $newProfile === ($t[1] === "new" ? 1 : 2);
        echo '<li class="leftmenu-item',
            ($active ? ' active' : ' ui js-click-child'),
            ' font-italic', '">';
        if ($active) {
            echo $t[0];
        } else {
            echo Ht::link($t[0], $Conf->selfurl($Qreq, ["u" => $t[1], "t" => null]));
        }
        echo '</li>';
    }
}

if (!$newProfile) {
    $first = $Me->privChair;
    foreach ($UserStatus->cs()->members("", "title") as $gj) {
        echo '<li class="leftmenu-item',
            ($gj->name === $profile_topic ? ' active' : ' ui js-click-child'),
            ($first ? ' leftmenu-item-gap4' : ''), '">';
        if ($gj->name === $profile_topic) {
            echo $gj->title;
        } else {
            echo Ht::link($gj->title, $Conf->selfurl($Qreq, ["t" => $gj->name]));
        }
        echo '</li>';
        $first = false;
    }

    echo '</ul><div class="leftmenu-if-left if-alert mt-5">',
        Ht::submit("save", "Save changes", ["class" => "btn-primary"]),
        '</div>';
} else {
    echo '</ul>';
}

echo '</nav></div>',
    '<main id="profilecontent" class="leftmenu-content main-column">';

if ($newProfile === 2
    && $Qreq->bulkentry === null
    && ($session_bulkentry = $Me->session("profile_bulkentry"))
    && is_array($session_bulkentry)
    && $session_bulkentry[0] > Conf::$now - 5) {
    $Qreq->bulkentry = $session_bulkentry[1];
    $Me->save_session("profile_bulkentry", null);
}

if ($newProfile === 2) {
    echo '<h2 class="leftmenu">Bulk update</h2>';
} else {
    echo Ht::hidden("profile_contactid", $Acct->contactId);
    if (isset($Qreq->redirect)) {
        echo Ht::hidden("redirect", $Qreq->redirect);
    }

    echo '<div id="foldaccount" class="';
    if ($Qreq->pctype === "chair"
        || $Qreq->pctype === "pc"
        || (!isset($Qreq->pctype) && ($Acct->roles & Contact::ROLE_PC) !== 0)) {
        echo "fold1o fold2o";
    } else if ($Qreq->ass
               || (!isset($Qreq->pctype) && ($Acct->roles & Contact::ROLE_ADMIN) !== 0)) {
        echo "fold1c fold2o";
    } else {
        echo "fold1c fold2c";
    }
    echo "\">";

    echo '<h2 class="leftmenu">';
    if ($newProfile) {
        echo 'New account';
    } else {
        if ($Me->contactId !== $Acct->contactId) {
            echo $Me->reviewer_html_for($Acct), ' ';
        }
        echo htmlspecialchars($UserStatus->cs()->get($profile_topic)->title);
        if ($Acct->is_disabled()) {
            echo ' <span class="n dim">(disabled)</span>';
        }
    }
    echo '</h2>';
}

if (!$UserStatus->has_message()) {
    echo '<div class="msgs-wide">', $Conf->feedback_msg($UserStatus), "</div>\n";
}

$UserStatus->qreq = $Qreq;
$UserStatus->render_group($newProfile === 2 ? "__bulk" : $profile_topic);

if ($newProfile !== 2) {
    if (false
        && $UserStatus->is_auth_self()
        && $UserStatus->contactdb_user()) {
        echo '<div class="form-g"><div class="checki"><label><span class="checkc">',
            Ht::checkbox("saveglobal", 1, $useRequest ? !!$Qreq->saveglobal : true, ["class" => "ignore-diff"]),
            '</span>Update global profile</label></div></div>';
    }

    echo "</div>"; // foldaccount
}

echo "</main></form>";

if (!$newProfile) {
    Ht::stash_script('hotcrp.highlight_form_children("#form-profile")');
}
$Conf->footer();
