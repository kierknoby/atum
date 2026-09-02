<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('ATUM_IS_AUTH')) { die('No direct script access allowed'); }
$view = strtolower((string) ($_GET['view'] ?? 'overview'));
echo Atum::Discovery()->showPage($view);
