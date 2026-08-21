<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('redirects guests to login', function () {
    $this->get('/')
        ->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('exposes the Laravel health endpoint', function () {
    $this->get('/up')->assertOk();
});
