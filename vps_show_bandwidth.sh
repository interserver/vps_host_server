#!/bin/bash
echo '<?php echo str_replace(array("\n", ")", "Array(", "Array        (         ", "   )"), array("", ")\n", "", "", ""), print_r(unserialize(file_get_contents("/root/.traffic.last")),true)); ?>' | php
