<?php

use GlpiPlugin\Projectflow\RuleProjectCollection;

Session::checkLoginUser();

$rulecollection = new RuleProjectCollection();

include(GLPI_ROOT . '/front/rule.common.php');
