<?php

require_once 'Router.php';
require_once 'Treatment.php';
require_once 'Auth.php';

$router = new Router();
$treatment = new Treatment();
$auth = new Auth();


$router->add('GET', '/treatment/getTreatment', [$treatment, 'get_treatment']);
$router->add('POST', '/auth/createCustomer', [$auth, 'create_customer']);
$router->add('POST', '/auth/verifyPayment', [$auth, 'verify_payment']);
$router->add('GET', '/get-booking', [$auth, 'get_booking']);
$router->add('POST', '/verify-payment', [$auth, 'verify_payment']);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = str_replace('/Lumea', '', $path);

$router->dispatch($method, $path);