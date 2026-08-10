<?php
require 'vendor/autoload.php';

Flight::route('/', function(){
    echo '¡Hola desde FlightPHP!';
});

Flight::start();