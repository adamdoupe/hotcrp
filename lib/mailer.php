<?php
// mailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MailPreparation {
    /** @var Conf */
    public $conf;
    /** @var string */
    public $subject = "";
    /** @var string */
    public $body = "";
    /** @var string */
    public $preparation_owner = "";
    /** @var list<string> */
    public $to = [];
    /** @var list<int> */
    public $contactIds = [];
    /** @var bool */
    private $_valid_recipient = true;
    /** @var bool */
    public $sensitive = false;
    public $headers = [];
    /** @var list<MessageItem> */
    public $errors = [];
    /** @var bool */
    public $unique_preparation = false;
    public $reset_capability;

    /** @param Conf $conf
     * @param Contact|Author $recipient */
    function __construct($conf, $recipient) {
        $this->conf = $conf;
        if ($recipient) {
            $email = ($recipient->preferredEmail ?? null) ? : $recipient->email;
            $this->to[] = Text::name($recipient->firstName, $recipient->lastName, $email, NAME_MAILQUOTE|NAME_E);
            $this->_valid_recipient = self::valid_email($email);
            if ($recipient->contactId) {
                $this->contactIds[] = $recipient->contactId;
            }
        }
    }
    /** @param string $email */
    static function valid_email($email) {
        return ($at = strpos($email, "@")) !== false
            && ((($ch = $email[$at + 1]) !== "_" && $ch !== "e" && $ch !== "E")
                || !preg_match('/\G(?:_.*|example\.(?:com|net|org))\z/i', $email, $m, 0, $at + 1));
    }
    /** @param MailPreparation $p
     * @return bool */
    function can_merge($p) {
        return $this->subject === $p->subject
            && $this->body === $p->body
            && ($this->headers["cc"] ?? null) == ($p->headers["cc"] ?? null)
            && ($this->headers["reply-to"] ?? null) == ($p->headers["reply-to"] ?? null)
            && $this->preparation_owner === $p->preparation_owner
            && !$this->unique_preparation
            && !$p->unique_preparation
            && empty($this->errors)
            && empty($p->errors);
    }
    /** @param MailPreparation $p */
    function merge($p) {
        foreach ($p->to as $dest) {
            if (!in_array($dest, $this->to))
                $this->to[] = $dest;
        }
        foreach ($p->contactIds as $cid) {
            if (!in_array($cid, $this->contactIds))
                $this->contactIds[] = $cid;
        }
        $this->_valid_recipient = $this->_valid_recipient && $p->_valid_recipient;
    }
    /** @return bool */
    function can_send_external() {
        return $this->conf->opt("sendEmail")
            && empty($this->errors)
            && $this->_valid_recipient;
    }
    /** @return bool */
    function can_send() {
        return $this->can_send_external()
            || (empty($this->errors) && !$this->sensitive)
            || $this->conf->opt("debugShowSensitiveEmail");
    }
    function send() {
        if ($this->conf->call_hooks("send_mail", null, $this) === false) {
            return false;
        }

        $headers = $this->headers;
        $sent = false;

        // create valid To: header
        $to = $this->to;
        if (is_array($to)) {
            $to = join(", ", $to);
        }
        $eol = $this->conf->opt("postfixEOL") ?? "\r\n";
        $to = (new MimeText($eol))->encode_email_header("To: ", $to);
        $headers["to"] = $to . $eol;
        $headers["content-transfer-encoding"] = "Content-Transfer-Encoding: quoted-printable" . $eol;
        // XXX following assumes body is text
        $qpe_body = quoted_printable_encode(preg_replace('/\r?\n/', "\r\n", $this->body));

        // set sendmail parameters
        $extra = $this->conf->opt("sendmailParam");
        if (($sender = $this->conf->opt("emailSender")) !== null) {
            @ini_set("sendmail_from", $sender);
            if ($extra === null) {
                $extra = "-f" . escapeshellarg($sender);
            }
        }

        if ($this->can_send_external()
            && ($this->conf->opt("internalMailer") ?? strncasecmp(PHP_OS, "WIN", 3) != 0)
            && ($sendmail = ini_get("sendmail_path"))) {
            $htext = join("", $headers);
            $f = popen($extra ? "$sendmail $extra" : $sendmail, "wb");
            fwrite($f, $htext . $eol . $qpe_body);
            $status = pclose($f);
            if (pcntl_wifexitedwith($status, 0)) {
                $sent = true;
            } else {
                $this->conf->set_opt("internalMailer", false);
                error_log("Mail " . $headers["to"] . " failed to send, falling back (status $status)");
            }
        }

        if (!$sent && $this->can_send_external()) {
            if (strpos($to, $eol) === false) {
                unset($headers["to"]);
                $to = substr($to, 4); // skip "To: "
            } else {
                $to = "";
            }
            unset($headers["subject"]);
            $htext = substr(join("", $headers), 0, -2);
            $sent = mail($to, $this->subject, $qpe_body, $htext, $extra);
        } else if (!$sent
                   && !$this->conf->opt("sendEmail")
                   && !Contact::is_anonymous_email($to)) {
            unset($headers["mime-version"], $headers["content-type"], $headers["content-transfer-encoding"]);
            $text = join("", $headers) . $eol . $this->body;
            if (PHP_SAPI != "cli") {
                $this->conf->infoMsg("<pre>" . htmlspecialchars($text) . "</pre>");
            } else if (!$this->conf->opt("disablePrintEmail")) {
                fwrite(STDERR, "========================================\n" . str_replace("\r\n", "\n", $text) .  "========================================\n");
            }
        }

        return $sent;
    }
}

class Mailer {
    const CONTEXT_BODY = 0;
    const CONTEXT_HEADER = 1;
    const CONTEXT_EMAIL = 2;

    const CENSOR_NONE = 0;
    const CENSOR_DISPLAY = 1;
    const CENSOR_ALL = 2;

    public static $email_fields = ["to" => "To", "cc" => "Cc", "bcc" => "Bcc", "reply-to" => "Reply-To"];
    public static $template_fields = ["to", "cc", "bcc", "reply-to", "subject", "body"];

    /** @var Conf */
    public $conf;
    /** @var ?Contact */
    public $recipient;
    /** @var string */
    protected $eol;

    /** @var int */
    protected $width;
    /** @var bool */
    protected $flowed = false;
    /** @var int */
    protected $censor;
    /** @var ?string */
    protected $reason;
    /** @var bool */
    protected $adminupdate = false;
    /** @var ?string */
    protected $notes;
    public $capability_token;
    /** @var bool */
    protected $sensitive;

    /** @var ?MailPreparation */
    protected $preparation;
    /** @var int */
    protected $context = 0;

    /** @var array<string,true> */
    private $_unexpanded = [];
    protected $_errors_reported = [];
    /** @var ?MessageSet */
    private $_ms;

    /** @param ?Contact $recipient */
    function __construct(Conf $conf, $recipient = null, $settings = []) {
        $this->conf = $conf;
        $this->eol = $conf->opt("postfixEOL") ?? "\r\n";
        $this->reset($recipient, $settings);
    }

    /** @param ?Contact $recipient */
    function reset($recipient = null, $settings = []) {
        $this->recipient = $recipient;
        $this->width = $settings["width"] ?? 72;
        if ($this->width <= 0) {
            $this->width = 10000000;
        }
        $this->flowed = !!$this->conf->opt("mailFormatFlowed");
        $this->censor = $settings["censor"] ?? self::CENSOR_NONE;
        $this->reason = $settings["reason"] ?? null;
        $this->adminupdate = $settings["adminupdate"] ?? false;
        $this->notes = $settings["notes"] ?? null;
        $this->capability_token = $settings["capability_token"] ?? null;
        $this->sensitive = $settings["sensitive"] ?? false;
    }


    /** @param Author|Contact $contact
     * @param string $out
     * @return string */
    function expand_user($contact, $out) {
        $r = Author::make_keyed($contact);
        if (is_object($contact)
            && ($contact->preferredEmail ?? "") != "") {
            $r->email = $contact->preferredEmail;
        }

        // maybe infer username
        if ($r->firstName === ""
            && $r->lastName === ""
            && $r->email !== "") {
            $this->infer_user_name($r, $contact);
        }

        $flags = $this->context === self::CONTEXT_EMAIL ? NAME_MAILQUOTE : 0;
        $email = $r->email;
        if ($r->email !== "") {
            $email = $r->email;
        } else {
            $email = "none";
            $flags |= NAME_B;
        }

        if ($out === "EMAIL") {
            return $flags & NAME_B ? "<$email>" : $email;
        } else if ($out === "CONTACT") {
            return Text::name($r->firstName, $r->lastName, $email, $flags | NAME_E);
        } else if ($out === "NAME") {
            if ($this->context !== self::CONTEXT_EMAIL) {
                $flags |= NAME_P;
            }
            return Text::name($r->firstName, $r->lastName, $email, $flags);
        } else if ($out === "FIRST") {
            return Text::name($r->firstName, "", "", $flags);
        } else if ($out === "LAST") {
            return Text::name("", $r->lastName, "", $flags);
        } else {
            return "";
        }
    }

    /** @param Author $r
     * @param Contact|Author $contact */
    function infer_user_name($r, $contact) {
    }


    static function kw_null() {
        return false;
    }

    function kw_opt($args, $isbool) {
        $yes = $this->expandvar($args, true);
        if ($yes && !$isbool) {
            return $this->expandvar($args, false);
        } else {
            return $yes;
        }
    }

    static function kw_urlenc($args, $isbool, $m) {
        $hasinner = $m->expandvar($args, true);
        if ($hasinner && !$isbool) {
            return urlencode($m->expandvar($args, false));
        } else {
            return $hasinner;
        }
    }

    static function kw_ims_expand($args, $isbool, $mx) {
        preg_match('/\A\s*(.*?)\s*(?:|,\s*(\d+)\s*)\z/', $args, $m);
        $t = $mx->conf->_c("mail", $m[1]);
        if ($m[2] && strlen($t) < (int) $m[2]) {
            $t = str_repeat(" ", (int) $m[2] - strlen($t)) . $t;
        }
        return $t;
    }

    function kw_confnames($args, $isbool, $uf) {
        if ($uf->name === "CONFNAME") {
            return $this->conf->full_name();
        } else if ($uf->name == "CONFSHORTNAME") {
            return $this->conf->short_name;
        } else {
            return $this->conf->long_name;
        }
    }

    function kw_siteuser($args, $isbool, $uf) {
        return $this->expand_user($this->conf->site_contact(), $uf->userx);
    }

    static function kw_signature($args, $isbool, $m) {
        return $m->conf->opt("emailSignature") ? : "- " . $m->conf->short_name . " Submissions";
    }

    static function kw_url($args, $isbool, $m) {
        if (!$args) {
            return $m->conf->opt("paperSite");
        } else {
            $a = preg_split('/\s*,\s*/', $args);
            foreach ($a as &$t) {
                $t = $m->expand($t, "urlpart");
                $t = preg_replace('/\&(?=\&|\z)/', "", $t);
            }
            if (!isset($a[1])) {
                $a[1] = "";
            }
            for ($i = 2; isset($a[$i]); ++$i) {
                if ($a[$i] !== "") {
                    if ($a[1] !== "") {
                        $a[1] .= "&" . $a[$i];
                    } else {
                        $a[1] = $a[$i];
                    }
                }
            }
            return $m->conf->hoturl_raw($a[0], $a[1], Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS);
        }
    }

    static function kw_internallogin($args, $isbool, $m) {
        return $isbool ? !$m->conf->external_login() : "";
    }

    static function kw_externallogin($args, $isbool, $m) {
        return $isbool ? $m->conf->external_login() : "";
    }

    static function kw_adminupdate($args, $isbool, $m) {
        if ($m->adminupdate) {
            return "An administrator performed this update. ";
        } else {
            return $m->recipient ? "" : null;
        }
    }

    static function kw_notes($args, $isbool, $m, $uf) {
        $which = strtolower($uf->name);
        $value = $m->$which;
        if ($value !== null || $m->recipient) {
            return (string) $value;
        } else {
            return null;
        }
    }

    static function kw_recipient($args, $isbool, $m, $uf) {
        if ($m->preparation) {
            $m->preparation->preparation_owner = $m->recipient->email;
        }
        return $m->expand_user($m->recipient, $uf->userx);
    }

    static function kw_capability($args, $isbool, $m, $uf) {
        if ($m->capability_token) {
            $m->sensitive = true;
        }
        return $isbool || $m->capability_token ? $m->capability_token : "";
    }

    function kw_login($args, $isbool, $uf) {
        if (!$this->recipient) {
            return $this->conf->external_login() ? false : null;
        }

        $loginparts = "";
        if (!$this->conf->opt("httpAuthLogin")) {
            $loginparts = "email=" . urlencode($this->recipient->email);
        }
        if ($uf->name === "LOGINURL") {
            return $this->conf->opt("paperSite") . "/signin" . ($loginparts ? "/?" . $loginparts : "/");
        } else if ($uf->name === "LOGINURLPARTS") {
            return $loginparts;
        } else {
            return false;
        }
    }

    function kw_needpassword($args, $isbool, $uf) {
        $external_login = $this->conf->external_login();
        if (!$this->recipient) {
            return $external_login ? false : null;
        } else {
            return !$external_login && $this->recipient->password_unset();
        }
    }

    function kw_passwordlink($args, $isbool, $uf) {
        if (!$this->recipient) {
            return $this->conf->external_login() ? false : null;
        } else if ($this->censor === self::CENSOR_ALL) {
            return null;
        }
        $this->sensitive = true;
        $token = $this->censor ? "HIDDEN" : $this->preparation->reset_capability;
        if (!$token) {
            $capinfo = new TokenInfo($this->conf, TokenInfo::RESETPASSWORD);
            if (($cdbu = $this->recipient->contactdb_user())) {
                $capinfo->set_user($cdbu)->set_token_pattern("hcpw1[20]");
            } else {
                $capinfo->set_user($this->recipient)->set_token_pattern("hcpw0[20]");
            }
            $capinfo->set_expires_after(259200);
            $token = $capinfo->create();
        }
        return $this->conf->hoturl_raw("resetpassword", null, Conf::HOTURL_ABSOLUTE | Conf::HOTURL_NO_DEFAULTS) . "/" . urlencode($token);
    }

    /** @param string $what
     * @param bool $isbool
     * @return null|bool|string */
    function expandvar($what, $isbool) {
        if (str_ends_with($what, ")") && ($paren = strpos($what, "("))) {
            $name = substr($what, 0, $paren);
            $args = substr($what, $paren + 1, strlen($what) - $paren - 2);
        } else {
            $name = $what;
            $args = "";
        }

        $mks = $this->conf->mail_keywords($name);
        foreach ($mks as $uf) {
            $uf->input_string = $what;
            $ok = $this->recipient || (isset($uf->global) && $uf->global);

            $xchecks = $uf->expand_if ?? [];
            foreach (is_array($xchecks) ? $xchecks : [$xchecks] as $xf) {
                if (is_string($xf)) {
                    if ($xf[0] === "*") {
                        $ok = $ok && call_user_func([$this, substr($xf, 1)], $uf);
                    } else {
                        $ok = $ok && call_user_func($xf, $this, $uf);
                    }
                } else {
                    $ok = $ok && !!$xf;
                }
            }

            if (!$ok) {
                $x = null;
            } else if ($uf->function[0] === "*") {
                $x = call_user_func([$this, substr($uf->function, 1)], $args, $isbool, $uf);
            } else {
                $x = call_user_func($uf->function, $args, $isbool, $this, $uf);
            }

            if ($x !== null) {
                return $isbool ? $x : (string) $x;
            }
        }

        if ($isbool) {
            return $mks ? null : false;
        } else {
            if (!isset($this->_unexpanded[$what])) {
                $this->_unexpanded[$what] = true;
                $this->unexpanded_warning_at($what);
            }
            return null;
        }
    }


    /** @param list<string> &$ifstack
     * @param string $text */
    private function _pushIf(&$ifstack, $text, $yes) {
        if ($yes !== false && $yes !== true && $yes !== null) {
            $yes = (bool) $yes;
        }
        if ($yes === true || $yes === null) {
            array_push($ifstack, $yes);
        } else {
            array_push($ifstack, $text);
        }
    }

    /** @param list<string> &$ifstack
     * @param string &$text
     * @return ?bool */
    private function _popIf(&$ifstack, &$text) {
        if (count($ifstack) == 0) {
            return null;
        } else if (($pop = array_pop($ifstack)) === true || $pop === null) {
            return $pop;
        } else {
            $text = $pop;
            return false;
        }
    }

    /** @param list<string> &$ifstack
     * @param string &$text */
    private function _handleIf(&$ifstack, &$text, $cond, $haselse) {
        assert($cond || $haselse);
        if ($haselse) {
            $yes = $this->_popIf($ifstack, $text);
            if ($yes !== null) {
                $yes = !$yes;
            }
        } else {
            $yes = true;
        }
        if ($yes && $cond) {
            $yes = $this->expandvar(substr($cond, 1, strlen($cond) - 2), true);
        }
        $this->_pushIf($ifstack, $text, $yes);
        return $yes;
    }


    /** @param string $rest
     * @return string */
    private function _expand_conditionals($rest) {
        $text = "";
        $ifstack = [];

        while (preg_match('/\A(.*?)(%(IF|ELIF|ELSE?IF|ELSE|ENDIF)((?:\(#?[-a-zA-Z0-9!@_:.\/]+(?:\([-a-zA-Z0-9!@_:.\/]*+\))*\))?)%)(.*)\z/s', $rest, $m)) {
            $text .= $m[1];
            $rest = $m[5];

            if ($m[3] === "IF" && $m[4] !== "") {
                $yes = $this->_handleIf($ifstack, $text, $m[4], false);
            } else if (($m[3] === "ELIF" || $m[3] === "ELSIF" || $m[3] === "ELSEIF")
                       && $m[4] !== "") {
                $yes = $this->_handleIf($ifstack, $text, $m[4], true);
            } else if ($m[3] == "ELSE" && $m[3] === "") {
                $yes = $this->_handleIf($ifstack, $text, false, true);
            } else if ($m[3] == "ENDIF" && $m[4] === "") {
                $yes = $this->_popIf($ifstack, $text);
            } else {
                $yes = null;
            }

            if ($yes === null) {
                $text .= $m[2];
            }
        }

        return $text . $rest;
    }

    private function _lineexpand($info, $line, $indent) {
        $text = "";
        while (preg_match('/^(.*?)(%(#?[-a-zA-Z0-9!@_:.\/]+(?:|\([^\)]*\)))%)(.*)$/s', $line, $m)) {
            if (($s = $this->expandvar($m[3], false)) !== null) {
                $text .= $m[1] . $s;
            } else {
                $text .= $m[1] . $m[2];
            }
            $line = $m[4];
        }
        $text .= $line;
        return prefix_word_wrap($info, $text, $indent, $this->width, $this->flowed);
    }

    /** @param string $text
     * @param ?string $field
     * @return string */
    function expand($text, $field = null) {
        // leave early on empty string
        if ($text === "") {
            return "";
        }

        // width, expansion type based on field
        $old_context = $this->context;
        $old_width = $this->width;
        if (isset(self::$email_fields[$field])) {
            $this->context = self::CONTEXT_EMAIL;
            $this->width = 10000000;
        } else if ($field !== "body" && $field != "") {
            $this->context = self::CONTEXT_HEADER;
            $this->width = 10000000;
        } else {
            $this->context = self::CONTEXT_BODY;
        }

        // expand out %IF% and %ELSE% and %ENDIF%.  Need to do this first,
        // or we get confused with wordwrapping.
        $text = $this->_expand_conditionals(cleannl($text));

        // separate text into lines
        $lines = explode("\n", $text);
        if (count($lines) && $lines[count($lines) - 1] === "") {
            array_pop($lines);
        }

        $text = "";
        $textstart = 0;
        for ($i = 0; $i < count($lines); ++$i) {
            $line = rtrim($lines[$i]);
            if ($line == "") {
                $text .= "\n";
            } else if (preg_match('/\A%((?:REVIEWS|COMMENTS)(?:|\(.*\)))%\z/s', $line, $m)) {
                if (($m = $this->expandvar($m[1], false)) != "") {
                    $text .= $m . "\n";
                }
            } else if (strpos($line, "%") === false) {
                $text .= prefix_word_wrap("", $line, 0, $this->width, $this->flowed);
            } else {
                if ($line[0] === " " || $line[0] === "\t") {
                    if (preg_match('/\A([ \t]*)%(\w+(?:|\([^\)]*\)))%(:.*)\z/s', $line, $m)
                        && $this->expandvar($m[2], true)) {
                        $line = $m[1] . $this->expandvar($m[2], false) . $m[3];
                    }
                    if (preg_match('/\A([ \t]*.*?: )(%(\w+(?:|\([^\)]*\)))%|\S+)\s*\z/s', $line, $m)
                        && ($tl = tab_width($m[1], true)) <= 20) {
                        if (str_starts_with($m[3] ?? "", "OPT(")) {
                            $yes = $this->expandvar($m[3], true);
                            if ($yes) {
                                $text .= prefix_word_wrap($m[1], $this->expandvar($m[3], false), $tl, $this->width, $this->flowed);
                            } else if ($yes === null) {
                                $text .= $line . "\n";
                            }
                        } else {
                            $text .= $this->_lineexpand($m[1], $m[2], $tl);
                        }
                        continue;
                    }
                }
                $text .= $this->_lineexpand("", $line, 0);
            }
        }

        // lose newlines on header expansion
        if ($this->context != self::CONTEXT_BODY) {
            $text = rtrim(preg_replace('/[\r\n\f\x0B]+/', ' ', $text));
        }

        $this->context = $old_context;
        $this->width = $old_width;
        return $text;
    }

    /** @param array|object $x
     * @return array<string,string> */
    function expand_all($x) {
        $r = [];
        foreach ((array) $x as $k => $t) {
            if (in_array($k, self::$template_fields))
                $r[$k] = $this->expand($t, $k);
        }
        return $r;
    }

    /** @param string $name
     * @param bool $use_default
     * @return array{body:string,subject:string} */
    function expand_template($name, $use_default = false) {
        return $this->expand_all($this->conf->mail_template($name, $use_default));
    }


    /** @return MailPreparation */
    function prepare($template, $rest = []) {
        assert($this->recipient && $this->recipient->email);
        $prep = new MailPreparation($this->conf, $this->recipient);
        $this->populate_preparation($prep, $template, $rest);
        return $prep;
    }

    function populate_preparation(MailPreparation $prep, $template, $rest = []) {
        // look up template
        if (is_string($template) && $template[0] === "@") {
            $template = (array) $this->conf->mail_template(substr($template, 1));
        }
        if (is_object($template)) {
            $template = (array) $template;
        }
        // add rest fields to template for expansion
        foreach (self::$email_fields as $lcfield => $field) {
            if (isset($rest[$lcfield]))
                $template[$lcfield] = $rest[$lcfield];
        }

        // look up recipient; use preferredEmail if set
        if (!$this->recipient || !$this->recipient->email) {
            throw new Exception("No email in Mailer::send");
        }
        if (!isset($this->recipient->contactId)) {
            error_log("no contactId in recipient\n" . debug_string_backtrace());
        }
        $mimetext = new MimeText($this->eol);

        // expand the template
        $this->preparation = $prep;
        $mail = $this->expand_all($template);
        $this->preparation = null;

        $mail["to"] = $prep->to[0];
        $subject = $mimetext->encode_header("Subject: ", $mail["subject"]);
        $prep->subject = substr($subject, 9);
        $prep->body = $mail["body"];

        // parse headers
        $fromHeader = $this->conf->opt("emailFromHeader");
        if ($fromHeader === null) {
            $fromHeader = $mimetext->encode_email_header("From: ", $this->conf->opt("emailFrom"));
            $this->conf->set_opt("emailFromHeader", $fromHeader);
        }
        $prep->headers = [];
        if ($fromHeader) {
            $prep->headers["from"] = $fromHeader . $this->eol;
        }
        $prep->headers["subject"] = $subject . $this->eol;
        $prep->headers["to"] = "";
        foreach (self::$email_fields as $lcfield => $field) {
            if (($text = $mail[$lcfield] ?? "") !== "" && $text !== "<none>") {
                if (($hdr = $mimetext->encode_email_header($field . ": ", $text))) {
                    $prep->headers[$lcfield] = $hdr . $this->eol;
                } else {
                    $mimetext->mi->field = $lcfield;
                    $mimetext->mi->landmark = "{$field} field";
                    $prep->errors[] = $mimetext->mi;
                    $logmsg = "$lcfield: $text";
                    if (!in_array($logmsg, $this->_errors_reported)) {
                        error_log("mailer error on $logmsg");
                        $this->_errors_reported[] = $logmsg;
                    }
                }
            }
        }
        $prep->headers["mime-version"] = "MIME-Version: 1.0" . $this->eol;
        $prep->headers["content-type"] = "Content-Type: text/plain; charset=utf-8"
            . ($this->flowed ? "; format=flowed" : "") . $this->eol;
        $prep->sensitive = $this->sensitive;
        if (!empty($prep->errors) && !($rest["no_error_quit"] ?? false)) {
            $this->conf->feedback_msg($prep->errors);
        }
    }

    /** @param list<MailPreparation> $preps */
    static function send_combined_preparations($preps) {
        $last_p = null;
        foreach ($preps as $p) {
            if ($last_p && $last_p->can_merge($p)) {
                $last_p->merge($p);
            } else {
                $last_p && $last_p->send();
                $last_p = $p;
            }
        }
        $last_p && $last_p->send();
    }


    /** @return int */
    function message_count() {
        return $this->_ms ? $this->_ms->message_count() : 0;
    }

    /** @return iterable<MessageItem> */
    function message_list() {
        return $this->_ms ? $this->_ms->message_list() : [];
    }

    /** @param ?string $field
     * @param string $message
     * @return MessageItem */
    function warning_at($field, $message) {
        $this->_ms = $this->_ms ?? (new MessageSet)->set_ignore_duplicates(true);
        return $this->_ms->warning_at($field, $message);
    }

    /** @param string $ref */
    function unexpanded_warning_at($ref) {
        if (preg_match('/\A(?:RESET|)PASSWORDLINK/', $ref)) {
            if ($this->conf->external_login()) {
                $this->warning_at($ref, "This site does not use password links.");
            } else if ($this->censor === self::CENSOR_ALL) {
                $this->warning_at($ref, "Password links cannot appear in mails with Cc or Bcc.");
            } else {
                $this->warning_at($ref, "Reference not expanded.");
            }
        } else {
            $this->warning_at($ref, "Reference not expanded.");
        }
    }
}

class MimeText {
    /** @var string */
    public $in;
    /** @var string */
    public $out;
    /** @var int */
    public $linelen;
    /** @var string */
    public $eol;
    /** @var ?MessageItem */
    public $mi;

    /** @param string $eol */
    function __construct($eol = "\r\n") {
        $this->eol = $eol;
    }

    /** @param string $header
     * @param string $str */
    function reset($header, $str) {
        if (preg_match('/[\r\n]/', $str)) {
            $this->in = simplify_whitespace($str);
        } else {
            $this->in = $str;
        }
        $this->mi = null;
        $this->out = $header;
        $this->linelen = strlen($header);
    }

    /// Quote potentially non-ASCII header text a la RFC2047 and/or RFC822.
    /** @param string $str
     * @param int $utf8 */
    private function append($str, $utf8) {
        if ($utf8 > 0) {
            // replace all special characters used by the encoder
            $str = str_replace(array('=',   '_',   '?',   ' '),
                               array('=3D', '=5F', '=3F', '_'), $str);
            // define nonsafe characters
            if ($utf8 > 1) {
                $matcher = '/[^-0-9a-zA-Z!*+\/=_]/';
            } else {
                $matcher = '/[\x80-\xFF]/';
            }
            preg_match_all($matcher, $str, $m, PREG_OFFSET_CAPTURE);
            $xstr = "";
            $last = 0;
            foreach ($m[0] as $mx) {
                $xstr .= substr($str, $last, $mx[1] - $last)
                    . "=" . strtoupper(dechex(ord($mx[0])));
                $last = $mx[1] + 1;
            }
            $xstr .= substr($str, $last);
        } else {
            $xstr = $str;
        }

        // append words to the line
        while ($xstr != "") {
            $z = strlen($xstr);
            assert($z > 0);

            // add a line break
            $maxlinelen = $utf8 > 0 ? 76 - 12 : 78;
            if (($this->linelen + $z > $maxlinelen && $this->linelen > 30)
                || ($utf8 > 0 && substr($this->out, strlen($this->out) - 2) == "?=")) {
                $this->out .= $this->eol . " ";
                $this->linelen = 1;
                while ($utf8 === 0 && $xstr !== "" && ctype_space($xstr[0])) {
                    $xstr = substr($xstr, 1);
                    --$z;
                }
            }

            // if encoding, skip intact UTF-8 characters;
            // otherwise, try to break at a space
            if ($utf8 > 0 && $this->linelen + $z > $maxlinelen) {
                $z = $maxlinelen - $this->linelen;
                if ($xstr[$z - 1] == "=") {
                    $z -= 1;
                } else if ($xstr[$z - 2] == "=") {
                    $z -= 2;
                }
                while ($z > 3
                       && $xstr[$z] == "="
                       && ($chr = hexdec(substr($xstr, $z + 1, 2))) >= 128
                       && $chr < 192) {
                    $z -= 3;
                }
            } else if ($this->linelen + $z > $maxlinelen) {
                $y = strrpos(substr($xstr, 0, $maxlinelen - $this->linelen), " ");
                if ($y > 0) {
                    $z = $y;
                }
            }

            // append
            if ($utf8 > 0) {
                $astr = "=?utf-8?q?" . substr($xstr, 0, $z) . "?=";
            } else {
                $astr = substr($xstr, 0, $z);
            }

            $this->out .= $astr;
            $this->linelen += strlen($astr);

            $xstr = substr($xstr, $z);
        }
    }

    /** @param string $header
     * @param string $str
     * @return false|string */
    function encode_email_header($header, $str) {
        $this->reset($header, $str);
        if (strpos($this->in, chr(0xE2)) !== false) {
            $this->in = str_replace(["“", "”"], ["\"", "\""], $this->in);
        }
        $str = $this->in;
        $inlen = strlen($str);

        // separate $str into emails, quote each separately
        while (true) {
            $str = preg_replace('/\A[,;\s]+/', "", $str);
            if ($str === "") {
                return $this->out;
            }

            // try three types of match in turn:
            // 1. name <email> [RFC 822]
            // 2. name including periods and “\'” but no quotes <email>
            if (preg_match('/\A((?:(?:"(?:[^"\\\\]|\\\\.)*"|[^\s\000-\037()[\\]<>@,;:\\\\".]+)\s*?)*)\s*<\s*(.*?)(\s*>\s*)(.*)\z/s', $str, $m)
                || preg_match('/\A((?:[^\000-\037()[\\]<>@,;:\\\\"]|\\\\\')+?)\s*<\s*(.*?)(\s*>\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[4]) - strlen($m[3]) - strlen($m[2]);
                $name = $m[1];
                $email = $m[2];
                $str = $m[4];
            } else if (preg_match('/\A<\s*([^\s\000-\037()[\\]<>,;:\\\\"]+)(\s*>?\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[3]) - strlen($m[2]) - strlen($m[1]);
                $name = "";
                $email = $m[1];
                $str = $m[3];
            } else if (preg_match('/\A(none|hidden|[^\s\000-\037()[\\]<>,;:\\\\"]+@[^\s\000-\037()[\\]<>,;:\\\\"]+)(\s*)(.*)\z/s', $str, $m)) {
                $emailpos = $inlen - strlen($m[3]) - strlen($m[2]) - strlen($m[1]);
                $name = "";
                $email = $m[1];
                $str = $m[3];
            } else {
                $this->mi = $mi = new MessageItem(null, "", MessageSet::ERROR);
                $mi->pos1 = $inlen - strlen($str);
                $mi->context = $this->in;
                if (preg_match('/[\s<>@]/', $str)) {
                    $mi->message = "Invalid destination (possible quoting problem)";
                } else {
                    $mi->message = "Invalid email address";
                }
                preg_match('/\A[^\s,;]*/', $str, $m);
                $mi->pos2 = $mi->pos1 + strlen($m[0]);
                return false;
            }

            // Validate email
            if (!validate_email($email)
                && $email !== "none"
                && $email !== "hidden") {
                $this->mi = $mi = new MessageItem(null, "Invalid email address", MessageSet::ERROR);
                $mi->pos1 = $emailpos;
                $mi->pos2 = $emailpos + strlen($email);
                $mi->context = $this->in;
                return false;
            }

            // Validate rest of string
            if ($str !== ""
                && $str[0] !== ","
                && $str[0] !== ";") {
                if (!$this->mi) {
                    $this->mi = $mi = new MessageItem(null, "Destinations must be separated with commas", MessageSet::ERROR);
                    $mi->pos1 = $mi->pos2 = $inlen - strlen($str);
                    $mi->context = $this->in;
                }
                if (!preg_match('/\A<?\s*([^\s>]*)/', $str, $m)
                    || !validate_email($m[1])) {
                    return false;
                }
            }

            // Append email
            if ($email === "none" || $email === "hidden") {
                continue;
            }
            if ($this->out !== $header) {
                $this->out .= ", ";
                $this->linelen += 2;
            }

            // unquote any existing UTF-8 encoding
            if ($name !== ""
                && $name[0] === "="
                && strcasecmp(substr($name, 0, 10), "=?utf-8?q?") == 0) {
                $name = self::decode_header($name);
            }

            $utf8 = is_usascii($name) ? 0 : 2;
            if ($name !== ""
                && $name[0] === "\""
                && preg_match("/\\A\"([^\\\\\"]|\\\\.)*\"\\z/s", $name)) {
                if ($utf8 > 0) {
                    $this->append(substr($name, 1, -1), $utf8);
                } else {
                    $this->append($name, 0);
                }
            } else if ($utf8 > 0) {
                $this->append($name, $utf8);
            } else {
                $this->append(rfc2822_words_quote($name), 0);
            }

            if ($name === "") {
                $this->append($email, 0);
            } else {
                $this->append(" <$email>", 0);
            }
        }
    }

    /** @param string $header
     * @param string $str
     * @return string */
    function encode_header($header, $str) {
        $this->reset($header, $str);
        $this->append($str, is_usascii($str) ? 0 : 1);
        return $this->out;
    }


    static function chr_hexdec_callback($m) {
        return chr(hexdec($m[1]));
    }

    /** @param string $text
     * @return string */
    static function decode_header($text) {
        if (strlen($text) > 2 && $text[0] == '=' && $text[1] == '?') {
            $out = '';
            while (preg_match('/\A=\?utf-8\?q\?(.*?)\?=(\r?\n )?/i', $text, $m)) {
                $f = str_replace('_', ' ', $m[1]);
                $out .= preg_replace_callback('/=([0-9A-F][0-9A-F])/',
                                              "MimeText::chr_hexdec_callback",
                                              $f);
                $text = substr($text, strlen($m[0]));
            }
            return $out . $text;
        } else {
            return $text;
        }
    }
}
