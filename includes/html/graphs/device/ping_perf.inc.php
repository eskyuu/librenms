<?php

/*
 * LibreNMS
 *
 * Copyright (c) 2014 Neil Lathwood <https://github.com/laf/ http://www.lathwood.co.uk/fa>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

$filename = Rrd::name($device['hostname'], 'icmp-perf');

$descr = 'Milliseconds';
$ds = 'avg';
$scale_min = 0;

require 'includes/html/graphs/generic_stats.inc.php';
