<?php

use GlpiPlugin\Contractlifecycle\TicketState;

Session::checkRight('ticket', READ);
if (isset($_POST['realloc'])) {
    TicketState::realloc((int) ($_POST['tickets_id'] ?? 0));
}
Html::back();
