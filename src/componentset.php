<?php
// src/componentset.php -- HotCRP JSON-based component specifications
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ComponentContext {
    /** @var ?list<mixed> */
    public $args;
    /** @var ?list<callable> */
    public $cleanup;
}

class ComponentSet implements XtContext {
    private $_jall = [];
    private $_potential_members = [];
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    public $root;
    private $_raw = [];
    private $_callables;
    /** @var string|false */
    private $_section_class = "";
    /** @var string */
    private $_title_class = "";
    /** @var bool */
    private $_in_section = false;
    /** @var ?string */
    private $_section_closer;
    /** @var ComponentContext */
    private $_ctx;
    /** @var list<ComponentContext> */
    private $_ctxstack;
    private $_annexes = [];
    /** @var list<callable(string,object,?Contact,Conf):(?bool)> */
    private $_xt_checkers = [];
    static private $next_placeholder;

    function add($fj) {
        if (is_array($fj)) {
            $fja = $fj;
            if (count($fja) < 3 || !is_string($fja[0])) {
                return false;
            }
            $fj = (object) [
                "name" => $fja[0], "order" => $fja[1],
                "__source_order" => ++Conf::$next_xt_source_order
            ];
            if (strpos($fja[2], "::")) {
                $fj->render_function = $fja[2];
            } else {
                $fj->alias = $fja[2];
            }
            if (isset($fja[3]) && is_number($fja[3])) {
                $fj->priority = $fja[3];
            }
        }
        if (!isset($fj->name)) {
            $fj->name = "__" . self::$next_placeholder . "__";
            ++self::$next_placeholder;
        }
        if (!isset($fj->group)) {
            if (($pos = strrpos($fj->name, "/")) !== false) {
                $fj->group = substr($fj->name, 0, $pos);
            } else {
                $fj->group = $fj->name;
            }
        }
        if (!isset($fj->hashid)
            && !str_starts_with($fj->name, "__")
            && ($pos = strpos($fj->name, "/")) !== false) {
            $x = substr($fj->name, $pos + 1);
            $fj->hashid = preg_replace('/\A[^A-Za-z]+|[^A-Za-z0-9_:.]+/', "-", strtolower($x));
        }
        $this->_jall[$fj->name][] = $fj;
        if ($fj->group === $fj->name) {
            assert(strpos($fj->group, "/") === false);
            $this->_potential_members[""][] = $fj->name;
        } else {
            $this->_potential_members[$fj->group][] = $fj->name;
        }
        if (!empty($this->_raw)) {
            $this->_raw = [];
        }
        return true;
    }

    function __construct(Contact $viewer, ...$args) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        self::$next_placeholder = 1;
        foreach ($args as $arg) {
            if ($arg)
                expand_json_includes_callback($arg, [$this, "add"]);
        }
        $this->_ctx = new ComponentContext;
        $this->reset_context();
    }
    function reset_context() {
        assert(empty($this->_ctxstack) && empty($this->_ctx->cleanup));
        $this->root = null;
        $this->_raw = [];
        $this->_callables = ["Conf" => $this->conf];
        $this->_in_section = false;
        $this->_section_closer = null;
    }
    /** @return Contact */
    function viewer() {
        return $this->viewer;
    }
    /** @return ?string */
    function root() {
        return $this->root;
    }

    /** @param callable(string,object,?Contact,Conf):(?bool) $checker */
    function add_xt_checker($checker) {
        $this->_xt_checkers[] = $checker;
    }
    function xt_check_element($str, $xt, $user, Conf $conf) {
        foreach ($this->_xt_checkers as $cf) {
            if (($x = $cf($str, $xt, $user, $conf)) !== null)
                return $x;
        }
        return null;
    }

    /** @param callable(object,ComponentSet):bool $f */
    function apply_filter($f) {
        foreach ($this->_jall as &$jl) {
            $n = count($jl);
            for ($i = 0; $i !== $n; ) {
                if ($f($jl[$i], $this)) {
                    ++$i;
                } else {
                    array_splice($jl, $i, 1);
                    --$n;
                }
            }
        }
        $this->_raw = [];
    }

    /** @param string $key */
    function apply_key_filter($key) {
        $old_context = $this->conf->xt_swap_context($this);
        $this->apply_filter(function ($jx, $gex) use ($key) {
            return !isset($jx->$key) || $this->conf->xt_check($jx->$key, $jx, $this->viewer);
        });
        $this->conf->xt_context = $old_context;
    }

    /** @param string $name
     * @return ?object */
    function get_raw($name) {
        if (!array_key_exists($name, $this->_raw)) {
            $old_context = $this->conf->xt_swap_context($this);
            if (($xt = $this->conf->xt_search_name($this->_jall, $name, $this->viewer, true))
                && Conf::xt_enabled($xt)) {
                $this->_raw[$name] = $xt;
            } else {
                $this->_raw[$name] = null;
            }
            $this->conf->xt_context = $old_context;
        }
        return $this->_raw[$name];
    }
    /** @param string $name
     * @return ?object */
    function get($name) {
        $gj = $this->get_raw($name);
        for ($nalias = 0; $gj && isset($gj->alias) && $nalias < 5; ++$nalias) {
            $gj = $this->get_raw($gj->alias);
        }
        return $gj;
    }
    /** @param string $name
     * @return ?string */
    function canonical_group($name) {
        if (($gj = $this->get($name))) {
            $pos = strpos($gj->group, "/");
            return $pos === false ? $gj->group : substr($gj->group, 0, $pos);
        } else {
            return null;
        }
    }
    /** @param string $name
     * @param ?string $require_key
     * @return list<object> */
    function members($name, $require_key = null) {
        if (($gj = $this->get($name))) {
            $name = $gj->name;
        }
        $r = [];
        $alias = false;
        foreach (array_unique($this->_potential_members[$name] ?? []) as $subname) {
            if (($gj = $this->get_raw($subname))
                && $gj->group === ($name === "" ? $gj->name : $name)
                && $gj->name !== $name
                && (!isset($gj->alias) || isset($gj->order))
                && (!isset($gj->order) || $gj->order !== false)
                && (!$require_key || isset($gj->alias) || isset($gj->$require_key))) {
                $r[] = $gj;
                $alias = $alias || isset($gj->alias);
            }
        }
        usort($r, "Conf::xt_order_compare");
        if ($alias && !empty($r)) {
            $rr = [];
            foreach ($r as $gj) {
                if (!isset($gj->alias)
                    || (($gj = $this->get($gj->alias))
                        && (!$require_key || isset($gj->$require_key)))) {
                    $rr[] = $gj;
                }
            }
            return $rr;
        } else {
            return $r;
        }
    }
    /** @return list<object> */
    function groups() {
        return $this->members("");
    }

    /** @return bool */
    function allowed($allowed, $gj) {
        if (isset($allowed)) {
            $old_context = $this->conf->xt_swap_context($this);
            $ok = $this->conf->xt_check($allowed, $gj, $this->viewer);
            $this->conf->xt_context = $old_context;
            return $ok;
        } else {
            return true;
        }
    }
    /** @template T
     * @param class-string<T> $name
     * @return ?T */
    function callable($name) {
        if (!isset($this->_callables[$name]) && $this->_ctx->args !== null) {
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $this->_callables[$name] = new $name(...$this->_ctx->args);
        }
        return $this->_callables[$name] ?? null;
    }
    /** @param string $name
     * @param callable|mixed $callable
     * @return $this */
    function set_callable($name, $callable) {
        assert(!isset($this->_callables[$name]));
        $this->_callables[$name] = $callable;
        return $this;
    }
    /** @param ?object $gj
     * @param callable $cb */
    function call_function($gj, $cb, ...$args) {
        Conf::xt_resolve_require($gj);
        if (is_string($cb) && $cb[0] === "*") {
            $colons = strpos($cb, ":");
            $cb = [$this->callable(substr($cb, 1, $colons - 1)), substr($cb, $colons + 2)];
        }
        return $cb(...$this->_ctx->args, ...$args);
    }

    /** @param ?string $root
     * @return $this */
    function set_root($root) {
        $this->root = $root;
        return $this;
    }
    /** @param string|false $s
     * @return $this */
    function set_section_class($s) {
        $this->_section_class = $s;
        return $this;
    }
    /** @param string $s
     * @return $this */
    function set_title_class($s) {
        $this->_title_class = $s;
        return $this;
    }
    /** @param list<mixed> $args
     * @return $this */
    function set_context_args($args) {
        $this->_ctx->args = $args;
        return $this;
    }
    /** @return list<mixed> */
    function args() {
        return $this->_ctx->args;
    }
    function arg($i) {
        return $this->_ctx->args[$i] ?? null;
    }

    function start_render() {
        $this->_ctxstack[] = $this->_ctx;
        $this->_ctx = clone $this->_ctx;
        $this->_ctx->cleanup = null;
    }
    function push_render_cleanup($cleaner) {
        assert(!empty($this->_ctxstack));
        $this->_ctx->cleanup[] = $cleaner;
    }
    function end_render() {
        assert(!empty($this->_ctxstack));
        $cleanup = $this->_ctx->cleanup ?? [];
        for ($i = count($cleanup) - 1; $i >= 0; --$i) {
            $cleaner = $cleanup[$i];
            if (is_string($cleaner) && ($gj = $this->get($cleaner))) {
                $this->render($gj);
            } else if (is_callable($cleaner)) {
                $this->call_function(null, $cleaner);
            }
        }
        $this->_ctx = array_pop($this->_ctxstack);
    }

    /** @param ?string $classes
     * @param ?string $id */
    function render_open_section($classes = null, $id = null) {
        $this->render_close_section();
        if ($this->_section_class !== false
            && ($this->_section_class !== "" || ($classes ?? "") !== "")) {
            $klasses = trim("{$this->_section_class} " . ($classes ?? ""));
            if ($klasses !== "" || ($id ?? "") !== "") {
                echo '<div';
                if ($klasses !== "") {
                    echo " class=\"{$klasses}\"";
                }
                if (($id ?? "") !== "") {
                    echo " id=\"", htmlspecialchars($id), "\"";
                }
                echo '>';
                $this->_section_closer = "</div>";
            }
            $this->_in_section = true;
        }
    }
    function push_close_section($html) {
        $this->_section_closer = $html . ($this->_section_closer ?? "");
    }
    function render_close_section() {
        if ($this->_in_section) {
            echo $this->_section_closer ?? "";
            $this->_section_closer = null;
            $this->_in_section = false;
        }
    }
    /** @param string $title
     * @param ?string $hashid */
    function render_title($title, $hashid = null) {
        echo '<h3';
        if ($this->_title_class) {
            echo ' class="', $this->_title_class, '"';
        }
        if ($hashid) {
            echo ' id="', htmlspecialchars($hashid), '"';
        }
        echo '>', $title, "</h3>\n";
    }
    /** @param string $title
     * @param ?string $hashid */
    function render_section($title, $hashid = null) {
        $this->render_open_section();
        if ($title !== "") {
            $this->render_title($title, $hashid);
        }
    }

    /** @param string|object $gj */
    function render($gj) {
        if (is_string($gj) && !($gj = $this->get($gj))) {
            return null;
        }
        if ($this->_section_class !== false
            && $gj->group !== $gj->name) {
            $section = $gj->section ?? null;
            $title = $gj->title ?? "";
            $show_title = $gj->show_title ?? true;
            if ($section
                || ($section === null && $show_title && $title !== "")
                || ($section === null && !$this->_in_section)) {
                $this->render_section($show_title ? $title : "", $gj->hashid ?? null);
            }
        }
        if (isset($gj->render_function)) {
            return $this->call_function($gj, $gj->render_function, $gj);
        } else if (isset($gj->render_html)) {
            echo $gj->render_html;
            return null;
        } else {
            return null;
        }
    }
    /** @param string $name
     * @param bool $top */
    function render_group($name, $top = false) {
        $this->start_render();
        $result = null;
        if ($top && ($gj = $this->get($name))) {
            $result = $this->render($gj);
        }
        foreach ($this->members($name) as $gj) {
            if ($result !== false) {
                $result = $this->render($gj);
            }
        }
        $this->end_render();
        $top && $this->render_close_section();
        return $result;
    }

    /** @param string $name
     * @return bool */
    function has_annex($name) {
        return isset($this->_annexes[$name]);
    }
    /** @param string $name
     * @return mixed */
    function annex($name) {
        $x = null;
        if (array_key_exists($name, $this->_annexes)) {
            $x = $this->_annexes[$name];
        }
        return $x;
    }
    /** @param string $name
     * @param mixed $x */
    function set_annex($name, $x) {
        $this->_annexes[$name] = $x;
    }
}

class_alias("ComponentSet", "GroupedExtensions"); /* XXX backward compat */
