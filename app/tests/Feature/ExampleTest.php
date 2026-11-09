<?php

test('guests are sent to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});
