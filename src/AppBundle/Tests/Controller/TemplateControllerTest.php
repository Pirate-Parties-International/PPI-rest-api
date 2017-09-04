<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TemplateControllerTest extends WebTestCase
{
    public function testListpictures()
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/listPictures');
    }

    public function testListposts()
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/listPosts');
    }

}
