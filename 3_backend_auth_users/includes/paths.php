<?php
// Single source of truth for where things live. Required by bootstrap.php and by CLI scripts.
declare(strict_types=1);

if (!defined('EXAMPRO_ROOT')) define('EXAMPRO_ROOT', dirname(__DIR__, 2));   // folder that holds the five project folders

const DIR_FE_ADMIN   = '1_frontend_admin';
const DIR_FE_STUDENT = '2_frontend_student';
const DIR_BE_AUTH    = '3_backend_auth_users';
const DIR_BE_EXAM    = '4_backend_exam_logic';
const DIR_DB_DEPLOY  = '5_database_deployment';
