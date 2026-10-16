<?php

require_once 'Config.php';

class Router{
    private $routes =[];

    public function add($method, $path, $callback){
        $path = '/' . trim($path, '/');
        $this->routes[] = compact('method', 'path', 'callback');
    }

    public function dispatch($method, $path){
        $path = '/' . trim($path, '/');
        foreach($this->routes as $route){
            if($method ==$route['method'] && $path == $route['path']){
                return call_user_func($route['callback']);
            }
        }

        echo json_encode(['status'=> 400, 'message'=> 'Route not found']);
    }

}