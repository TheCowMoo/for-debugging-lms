<?php
/**
 * Course Runner — SCORM Player Configuration
 * 
 * Returns player configuration — the actual proxy handles content delivery.
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$config = [
    'player' => [
        'width' => '100%',
        'height' => '100%',
        'scale' => 'exactfit',
        'forceScale' => false,
        'showTitles' => false,
        'showNavBar' => true,
        'showNavButtons' => true,
        'showProgressBar' => true,
        'showCourseStructure' => false,
        'showExitButton' => true,
        'preventRightClick' => false,
        'exitUrl' => buildUrl('dashboard/'),
        'launchUrl' => buildUrl('course-runner/'),
    ],
];

echo json_encode($config);