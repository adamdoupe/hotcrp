<?php
// index.php -- HotCRP home page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once("lib/navigation.php");

/** @param Contact $user
 * @param Qrequest $qreq
 * @param string $group
 * @param ComponentSet $gx */
function gx_call_requests($user, $qreq, $group, $gx) {
    $gx->add_xt_checker([$qreq, "xt_allow"]);
    $reqgj = [];
    $not_allowed = false;
    foreach ($gx->members($group, "request_function") as $gj) {
        if ($gx->allowed($gj->allow_request_if ?? null, $gj)) {
            $reqgj[] = $gj;
        } else {
            $not_allowed = true;
        }
    }
    if ($not_allowed && $qreq->is_post() && !$qreq->valid_token()) {
        $user->conf->msg($user->conf->_i("badpost"), 2);
    }
    foreach ($reqgj as $gj) {
        if ($gx->call_function($gj, $gj->request_function, $gj) === false) {
            break;
        }
    }
}

/** @param Contact $user
 * @param Qrequest $qreq
 * @param NavigationState $nav */
function gx_go($user, $qreq, $nav) {
    try {
        $gx = $user->conf->page_partials($user);
        $pagej = $gx->get($nav->page);
        if (!$pagej || str_starts_with($pagej->name, "__")) {
            Multiconference::fail(404, "Page not found.");
        } else if ($user->is_disabled() && !($pagej->allow_disabled ?? false)) {
            Multiconference::fail(403, "Your account is disabled.");
        } else if (isset($pagej->render_php)) {
            return $pagej->render_php;
        } else {
            $gx->set_root($pagej->group)->set_context_args([$user, $qreq, $gx]);
            gx_call_requests($user, $qreq, $pagej->group, $gx);
            $gx->render_group($pagej->group, true);
        }
    } catch (Redirection $redir) {
        $user->conf->redirect($redir->url);
    } catch (JsonCompletion $jc) {
        $jc->result->emit($qreq->valid_token());
    } catch (PageCompletion $pc) {
    }
}

$nav = Navigation::get();

// handle `/u/USERINDEX/`
if ($nav->page === "u") {
    $unum = $nav->path_component(0);
    if ($unum !== false && ctype_digit($unum)) {
        if (!$nav->shift_path_components(2)) {
            // redirect `/u/USERINDEX` => `/u/USERINDEX/`
            Navigation::redirect_absolute("{$nav->server}{$nav->base_path}u/{$unum}/{$nav->query}");
        }
    } else {
        // redirect `/u/XXXX` => `/`
        Navigation::redirect_absolute("{$nav->server}{$nav->base_path}{$nav->query}");
    }
}

// handle special pages
if ($nav->page === "api") {
    require_once("src/init.php");
    API_Page::go_nav($nav, Conf::$main);
} else if ($nav->page === "images" || $nav->page === "scripts" || $nav->page === "stylesheets") {
    $_GET["file"] = $nav->page . $nav->path;
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "cacheable") {
    include("src/pages/p_cacheable.php");
    Cacheable_Page::go_nav($nav);
} else if ($nav->page === "scorechart") {
    include("src/pages/p_scorechart.php");
    Scorechart_Page::go_param($_GET);
} else {
    require_once("src/init.php");
    initialize_request();
    if (($s = gx_go($Me, $Qreq, $nav))) {
        include($s);
    }
}
