<?php

it('shows the reply desk login', function () {
    $this->get('/login')->assertSuccessful();
});
