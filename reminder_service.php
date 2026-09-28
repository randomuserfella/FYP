<?php

ignore_user_abort(true);
set_time_limit(0);

while (true) {

    echo "[" . date('H:i:s') . "] Checking reminders...\n";

    passthru("php send_reminders.php");

    sleep(1);

}