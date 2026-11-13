<?php
use CodeIgniter\Router\RouteCollection;
/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::authenticate');
$routes->post('logout', 'Auth::logout', ['filter' => 'auth:password']);
$routes->get('change-password', 'Auth::password', ['filter' => 'auth:password']);
$routes->post('change-password', 'Auth::updatePassword', ['filter' => 'auth:password']);
$routes->get('/', 'Agenda::index', ['filter' => 'auth']);
$routes->group('api', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('bootstrap', 'Api::bootstrap');
    $routes->get('updates', 'Api::updates');
    $routes->post('lessons', 'Api::saveLesson');
    $routes->put('lessons/(:num)', 'Api::saveLesson/$1');
    $routes->delete('lessons/(:num)', 'Api::deleteLesson/$1');
    $routes->patch('lessons/(:num)/content', 'Api::saveContent/$1');
    $routes->post('course-plans', 'Api::createCoursePlans');
    $routes->post('course-schedules', 'Api::scheduleCourseInstructor');
    $routes->delete('course-plans/(:num)', 'Api::deleteCoursePlan/$1');
    $routes->post('hours', 'Api::requestHours');
    $routes->patch('hours/(:num)/review', 'Api::reviewHours/$1');
    $routes->post('schedule-blocks', 'Api::saveScheduleBlock');
    $routes->patch('schedule-blocks/(:num)/review', 'Api::reviewScheduleBlock/$1');
    $routes->post('trips', 'Api::saveTrip');
    $routes->put('trips/(:num)', 'Api::saveTrip/$1');
    $routes->delete('trips/(:num)', 'Api::deleteTrip/$1');
    $routes->post('reservations', 'Api::saveReservation');
    $routes->delete('reservations/(:num)', 'Api::deleteReservation/$1');
    $routes->post('catalog/(:segment)', 'Api::saveCatalog/$1');
    $routes->put('catalog/(:segment)/(:num)', 'Api::saveCatalog/$1/$2');
    $routes->post('users', 'Api::saveUser');
    $routes->put('users/(:num)', 'Api::saveUser/$1');
    $routes->post('notifications/read', 'Api::readNotifications');
});
