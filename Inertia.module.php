<?php namespace ProcessWire;

class Inertia extends WireData implements Module {
    // Version will invalidate if they differ 
    protected $version = 'undefined';
    // Root view
    protected $view = 'app.view.php';
    // Options for the $files->render() function
    protected $options = [];
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
        return ($_SERVER["HTTP_X_INERTIA"] == true);
    }

    private function htmlTag ($data) {
        return "<div id='app' data-page='". json_encode($data) . "'></div>";
    }

    // fluent interface
    public function share($event) {
        $key = $event->arguments(0);
        $value = $event->arguments(1);
        $this->shared[$key] = $value;
        $event->return = $this;
    }

    public function version($event) {
        $version = $event->arguments(0);
        $this->version = $version;
        $event->return = $this;
    }

    public function view($event) {
        $file = $event->arguments(0);
        $options = $event->arguments(1);
        $this->view = $file;
        if ($options) {
            $this->options = $options;
        }
        $event->return = $this;
    }

    // Rendering
    public function shared($event) {
        $event->return = $this->shared;
    }

    public function render($event) {
        $page = $event->object;
        $component = $event->arguments(0);
        $props = array_merge($this->shared, $event->arguments(1));

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
            $event->return = json_encode($json);
            return;
        }

        // We must render the view
        $view = wire('files');
        $event->return = $view->render($this->view, [
            'inertia' => (object) [
                'page' => $json,
                'json' => json_encode($json),
                'tag' => $this->htmlTag($json)
            ]
        ], $this->options);
    }
}