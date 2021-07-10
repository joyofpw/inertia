<?php namespace ProcessWire;

class Inertia extends WireData implements Module {
    // Version will invalidate if they differ
    protected $version = "undefined";
    // Root view
    protected $view = "app.view.php";
    // Shared props
    protected $shared = [];

    public static function getModuleInfo() {
        return [
            "title" => "Inertia ProcessWire Adapter",
            "summary" => "An Inertia.js adapter for ProcessWire",
            "version" => 1,
            "autoload" => false,
            "author" => "CLSource",
            "href" => "https://github.com/joyofpw/inertia/",
            "icon" => "bolt",
            "singular" => true,
            "requires" => [
                "ProcessWire>=3.0.0"
            ],
        ];
    }

    // MARK: Helpers
    private function isInertia() {
        return (isset($_SERVER) &&
        isset($_SERVER["HTTP_X_INERTIA"]) &&
        $_SERVER["HTTP_X_INERTIA"] == true);
    }

    private function htmlTag ($data) {
        return "<div id='app' data-page='". htmlentities(json_encode($data)) . "'></div>";
    }

    private function getFlashData() {
        // Docs at https://github.com/processwire/processwire/blob/master/wire/core/Notices.php
        // https://processwire.com/api/ref/session/message/
        // https://processwire.com/api/ref/session/error/
        // https://processwire.com/api/ref/session/warning/
        // https://processwire.com/api/ref/session/remove-notices/
        $notices = $this->wire("notices");
        $flash = [];
        foreach($notices as $notice) {
            $key = strtolower($notice->getName());

            if(!isset($flash[$key])) {
                $flash[$key] = [];
            }

            $type = str_replace("Notice", "", $notice->className());

            $flash[$key][] = [
                "id" => $notice->idStr,
                "text" => $notice->text,
                "timestamp" => $notice->timestamp,
                "class" => $notice->className(),
                "flags" => $notice->flags,
                "count" => $notice->qty,
                "icon" => $notice->icon,
                "type" => $type,
                "key" => $key
            ];
        }
        return $flash;
    }

    // MARK: Fluent interface setters
    public function share($key, $value = null)
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
        } else {
            array_set($this->shared, $key, $value);
        }
        return $this;
    }

    public function flushShared() {
        $this->shared = [];
    }

    public function flushFlash() {
        $this->wire('session')->removeNotices();
    }

    public function setVersion($version) {
        $this->version = $version;
        return $this;
    }

    public function setView($file) {
        $this->view = $file;
        return $this;
    }

    // MARK: Getters
    public function shared($key = null) {
        if ($key) {
            return array_get($this->shared, $key);
        }

        return $this->shared;
    }

    public function version() {
        return $this->version;
    }

    public function view() {
        return $this->view;
    }

    // MARK: Inertia Methods

    // Hookable
    // Options for the $files->render() function
    public function ___render($component, $properties = [], $options = []) {

        $props = array_merge($this->shared, $properties);

        // Support flash data
        $flash = $this->getFlashData();
        if(count($flash)) {
            $props["flash"] = $flash;
        }

        // Support CSRF
        $session = $this->wire("session");
        $props["csrf"] = [
            "name" => $session->CSRF->getTokenName(),
            "value" => $session->CSRF->getTokenValue()
        ];

        $page = $this->wire("page");
        $json = (object) [
            "component" => $component,
            "props" => $props,
            "version" => $this->version,
            "url" => $page->httpUrl
        ];

        // Render the Inertia Response

        // We must return only the json for Inertia
        if ($this->isInertia()) {
            http_response_code(200);
            header("Content-type: application/json; charset=utf-8");
            header("X-Inertia: true");
            header("Vary: Accept");
            return json_encode($json);
        }

        // We must render the view
        $files = wire("files");
        return $files->render($this->view, [
                "inertia" => (object) [
                    "page" => $json,
                    "json" => json_encode($json),
                    "tag" => $this->htmlTag($json)
                ]
            ],
            $options
        );
    }

    // 303 response code
    // Note, when redirecting after a PUT, PATCH or DELETE request you must use a 303 response code,
    // otherwise the subsequent request will not be treated as a GET request.
    // A 303 redirect is the same as a 302 except that the follow-up request is
    // explicitly changed to a GET request.
    public function ___redirect($url) {

        $method = strtolower($_SERVER["REQUEST_METHOD"]);
        $code = 303;
        if ($method == "get" || $method == "post") {
            $code = 302;
        }

        // We had to copy the https://github.com/processwire/processwire/blob/master/wire/core/Session.php#L1153
        // code here because the method currently does not allow 303 redirects
        // TODO: Change it when the method allows that param.

        // if there are notices, then queue them so that they aren't lost
        $notices = $this->wire("notices");
        if(count($notices)) {
            $session = $this->wire("session");

            foreach($notices as $notice) {
                $type = $notice->getName();
                $items = $session->getFor("_notices", $type);
                if(is_null($items)) $items = [];
                $items[] = $notice->getArray();
                $session->setFor("_notices", $type, $items);
            }
        }

        // Perform the redirect
        $page = $this->wire("page");
        if($page) {
            // Ensure ProcessPageView is properly closed down
            $process = $this->wire("modules")->get("ProcessPageView");
            $process->setResponseType(ProcessPageView::responseTypeRedirect);
            $process->finished();
        }

        $statusData = ["redirectUrl" => $url, "redirectType" => $code];
        $this->wire()->setStatus(ProcessWire::statusFinished, $statusData);
        header("Location: $url");
        exit(0);
    }

    // External redirects
    // This will generate a 409 Conflict response,
    // which includes the destination URL in the X-Inertia-Location header. Client-side,
    // Inertia will detect this response and automatically do a window.location = url visit.
    public function __location($url) {
        http_response_code(409);
        header("X-Inertia: true");
        header("Vary: Accept");
        header("X-Inertia-Location: $url");
        echo '';
        return $url;
    }
}
