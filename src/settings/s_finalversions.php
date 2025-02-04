<?php
// src/settings/s_finalversions.php -- HotCRP settings > final versions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FinalVersions_SettingParser extends SettingParser {
    static function render(SettingValues $sv) {
        if ($sv->oldv("final_soft") === $sv->oldv("final_done")) {
            $sv->set_oldv("final_soft", null);
        }
        echo '<div class="has-fold fold2o">';
        $sv->echo_checkbox('final_open', '<strong>Collect final versions of accepted submissions</strong>', ["class" => "uich js-foldup", "group_class" => "form-g", "group_open" => true]);
        echo '<div class="fx2 mt-3"><div class="form-g">';
        $sv->echo_entry_group("final_soft", "Deadline", ["horizontal" => true]);
        $sv->echo_entry_group("final_done", "Hard deadline", ["horizontal" => true]);
        $sv->echo_entry_group("final_grace", "Grace period", ["horizontal" => true]);
        echo '</div><div class="form-g">';
        $sv->echo_message_minor("final_edit_message", "Instructions");
        echo '</div>';
        Banal_SettingRenderer::render("m1", $sv);
        echo "</div></div></div>\n\n";
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if ($sv->has_interest("final_open")
            && $conf->setting("final_open")
            && ($conf->setting("final_soft") || $conf->setting("final_done"))
            && (!$conf->setting("final_done") || $conf->setting("final_done") > Conf::$now)
            && $conf->setting("seedec") != Conf::SEEDEC_ALL) {
            $sv->warning_at(null, "<5>The system is set to collect final versions, but authors cannot submit final versions until they can see decisions. You may want to update the " . $sv->setting_link("“Who can see decisions” setting", "seedec") . ".");
        }
    }
}
