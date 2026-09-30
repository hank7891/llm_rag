<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_redirects_to_document_list(): void
    {
        $this->get('/')->assertRedirect('/document');
    }
}
