<?php

it('redirects the home page to the admin login', function () {
    $response = $this->get('/');

    $response->assertRedirect('/admin/login');
});
