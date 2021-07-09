<?php namespace ProcessWire;

class Inertia extends WireData implements Module {
    // Version will invalidate if they differ
    protected $version = 'undefined';
    // Root view
    protected $view = 'app.view.php';
    // Shared props
    protected $shared = [];

    public static function getModuleInfo() {
        return [
            'title' => 'Inertia ProcessWire Adapter',
            'summary' => 'An Inertia.js adapter for ProcessWire',
            'version' => 1,
            'autoload' => false,
            'author' => 'CLSource',
            'href' => 'https://github.com/joyofpw/inertia/',
            'icon' => 'bolt',
            'singular' => true,
            'requires' => [
                'ProcessWire>=3.0.0'
            ],
        ];
    }

    private function isInertia() {
        return (isset($_SERVER) &&
        isset($_SERVER["HTTP_X_INERTIA"]) &&
        $_SERVER["HTTP_X_INERTIA"] == true);
    }

    private function htmlTag ($data) {
        return "<div id='app' data-page='". htmlentities(json_encode($data)) . "'></div>";
    }

    // fluent interface
    public function share($key, $value) {
        $this->shared[$key] = $value;
        return $this;
    }

    public function shareMap($map) {
        $this->shared = $map;
        return $this;
    }

    public function version($version) {
        $this->version = $version;
        return $this;
    }

    public function view($file) {
        $this->view = $file;
        return $this;
    }

    // Rendering
    public function shared() {
        return $this->shared;
    }

    // Options for the $files->render() function
    public function render($component, $properties, $options = []) {
        $page = wire('page');
        $props = array_merge($this->shared, $properties);

        $json = (object) [
            "component" => $component,
            "props" => $props,
            "version" => $this->version,
            "url" => $page->httpUrl
        ];

        // We must return only the json for Inertia
        if ($this->isInertia()) {
            http_response_code(200);
            header("Content-type: application/json; charset=utf-8");
            header("X-Inertia: true");
            header("Vary: Accept");
            return json_encode($json);
        }

        // We must render the view
        $files = wire('files');
        return $files->render($this->view, [
                'inertia' => (object) [
                    'page' => $json,
                    'json' => json_encode($json),
                    'tag' => $this->htmlTag($json)
                ]
            ],
            $options
        );
    }
}
