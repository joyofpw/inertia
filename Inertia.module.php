<?php namespace ProcessWire;

class Inertia extends WireData implements Module {
    // Version will invalidate if they differ
    protected $version = "undefined";
    // Root view
    protected $view = "app.view.php";
    // Shared props
    protected $shared = [];
    // Validation Errors
    protected $errors = [];

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

    // $session->redirect() alternative
    private function goBack($url, $code = 302) {
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
        http_response_code($code);
        header("Location: $url");
        exit(0);
    }

    private function flash() {
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

            // We could do this by using $notice->getArray();
            // but class comes as empty string and contains
            // no id field.
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

    public function flashMessage($message) {
        $this->wire("session")->message($message);
        return $this;
    }

    public function flashError($message) {
        $this->wire("session")->error($message);
        return $this;
    }

    public function flashWarning($message) {
        $this->wire("session")->warning($message);
        return $this;
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

    public function setVersion($version) {
        $this->version = $version;
        return $this;
    }

    public function setView($file) {
        $this->view = $file;
        return $this;
    }

    // Utility
    public function flushShared() {
        $this->shared = [];
    }

    public function flushFlash() {
        $this->wire("session")->removeNotices();
    }

    public function flush($key = null) {
        if (!$key || $key == "shared") {
            $this->flushShared();
        }

        if (!$key || $key == "flash") {
            $this->flushFlash();
        }
    }

    public function json($data, $code = 200) {
        http_response_code($code);
        header("Content-type: application/json; charset=utf-8");
        header("X-Inertia: true");
        header("Vary: Accept");
        return json_encode($data);
    }

    // Combine the json input of inertia with the normal post/get input
    public function input() {

        $json = [];

        try {
            $json = json_decode(
                file_get_contents('php://input'), true
            );

        } catch(\Exception $e) {}

        if (!is_array($json)) {
            $json = [];
        }

        $input = $this->wire("input");

        $post = $input->post->getArray();
        if (!is_array($post)) {
            $post = [];
        }

        $get = $input->get->getArray();
        if (!is_array($get)) {
            $get = [];
        }

        $result = array_merge($post, array_merge($get, $json));

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    // MARK: Getters
    public function shared($key = null) {
        if ($key) {
            return array_get($this->shared, $key);
        }

        if(!is_array($this->shared)) {
            $this->shared = [];
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

        $props = array_merge($this->shared(), $properties);

        // Support flash data
        $flash = $this->flash();
        if(count($flash)) {
            $props["flash"] = $flash;
        }

        // Support CSRF
        $session = $this->wire("session");
        $props["csrf"] = [
            "name" => $session->CSRF->getTokenName(),
            "value" => $session->CSRF->getTokenValue()
        ];

        // Delete all keys from props that are not inside this array
        $only = $session->getFor($this, "only");
        if (is_array($only)) {
            $finalprops = [];
            foreach ($props as $key => $value) {
                if (in_array($key, $only)) {
                  $finalprops[$key] = $value;
                }
            }
            $session->removeFor($this, "only");
            $props = $finalprops;
        }

        // Response with validation message errors
        // Normally filling props.errors to show
        // whitin forms
        $errors = $session->getFor($this, "errors");
        if (is_array($errors)) {
            $response = [
                "component" => $component,
                "props" => array_merge([
                    "errors" => $errors
                ], $props)
            ];
            $session->removeFor($this, "errors");
            return $this->json($response, 400);
        }

        // Response to a Inertia.{get, post, put, delete} request
        // example Inertia.post()
        $data = $session->getFor($this, "data");
        if (is_array($data)) {
            $response = [
                "component" => $component,
                "props" => array_merge($data, $props),
            ];
            $session->removeFor($this, "data");
            return $this->json($response);
        }

        // Change location response
        $location = $session->getFor($this, "location");
        if (is_string($location)) {
            $response = [
                "component" => $component,
                "props" => array_merge($location, $props),
            ];
            $session->removeFor($this, "location");
            return $this->json($response, $location["code"]);
        }

        // Normal Inertia Response

        $page = $this->wire("page");
        $json = (object) [
            "component" => $component,
            "props" => $props,
            "version" => $this->version(),
            "url" => $page->httpUrl
        ];

        // We must return only the json for Inertia
        if ($this->isInertia()) {
            return $this->json($json);
        }

        // We must render the view
        $files = wire("files");
        $out = $files->render($this->view, [
                "inertia" => (object) [
                    "page" => $json,
                    "json" => json_encode($json),
                    "tag" => $this->htmlTag($json)
                ]
            ],
            $options
        );

        $this->flushFlash();
        $session->removeFor($this, "errors");
        $session->removeFor($this, "data");
        $session->removeFor($this, "location");
        $session->removeFor($this, "only");

        return $out;
    }

    // 303 response code
    // Note, when redirecting after a PUT, PATCH or DELETE request you must use a 303 response code,
    // otherwise the subsequent request will not be treated as a GET request.
    // A 303 redirect is the same as a 302 except that the follow-up request is
    // explicitly changed to a GET request.
    public function ___redirect($url, $code = 303) {
        $method = strtolower($_SERVER["REQUEST_METHOD"]);
        if ($method == "get" || $method == "post") {
            $code = 302;
        }
        return $this->goBack($url, $code);
    }

    // 422 response code
    // https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/422
    // https://reinink.ca/articles/introducing-inertia-js
    // https://inertiajs.com/validation
    // When the server returns a 422 response with errors (as JSON),
    // simply update the form errors data attribute to reactively display them.
    // No need to repopulate the form with past values!
    public function ___validation($errors) {
        $this->wire("session")->setFor($this, "errors", $errors);
        return $this;
    }

    // Normal response when accepting a Inertia request
    // For example a form.
    public function ___with($data) {
        $this->wire("session")->setFor($this, "data", $data);
        return $this;
    }

    // Delete all keys from props, except the ones from this array
    public function ___only($keys) {
        $this->wire("session")->setFor($this, "only", $keys);
        return $this;
    }

    // Empty all props
    public function ___empty() {
        return $this->only([]);
    }

    // 409 response code
    // https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/409
    // External redirects
    // This will generate a 409 Conflict response,
    // which includes the destination URL in the X-Inertia-Location header. Client-side,
    // Inertia will detect this response and automatically do a window.location = url visit.
    public function ___location($url, $code = 409) {
        header("X-Inertia-Location: $url");
        $this->wire("session")->setFor($this, "location", ["url" => $url, "code" => $code]);
        return $this->redirect($url);
    }
}
