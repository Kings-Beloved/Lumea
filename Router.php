<?php

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