<?php

namespace PPI\ApiBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testParties()
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/parties');
    }

}
