<?php
/**
 * REDCap External Module: To-Do List Integration
 * Enable in project to facilitate creation of requests in the Control Center To-Do List from project records
 * - Plugin page for managing completion of custom requests
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
if (is_null($module) || !($module instanceof MCRI\ToDoListIntegration\ToDoListIntegration)) { exit(); }

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$user = $module->getUser();
if (!isset($user) || !$user->isSuperUser()) { redirect(APP_PATH_WEBROOT); }

if (isset($_GET['request_id']) && intval($_GET['request_id']) > 0) {
    $module->page_confirm_complete(intval($_GET['request_id']));
} else {
    echo $module->errorMessage('missing request id');
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';