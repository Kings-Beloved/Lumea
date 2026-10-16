<?php
// Allow requests from your live Vercel frontend
header("Access-Control-Allow-Origin: https://lumeaa-zhjc.vercel.app");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class Router{
    private $routes =[];

    public function add($method, $path, $callback){
        $this->routes[] = compact('method', 'path', 'callback');
    }

    public function dispatch($method, $path){
        foreach($this->routes as $route){
            if($method ==$route['method'] && $path == $route['path']){
                return call_user_func($route['callback']);
            }
        }

        echo json_encode(['status'=> 400, 'message'=> 'Route not found']);
    }

}