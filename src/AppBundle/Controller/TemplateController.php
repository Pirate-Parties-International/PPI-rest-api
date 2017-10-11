<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class TemplateController extends Controller
{
    /**
     * @Route("/social/pictures")
     */
    public function listPicturesAction()
    {
        return $this->render('AppBundle:Template:list_pictures.html.twig', array(
            // ...
        ));
    }

    /**
     * @Route("social/posts")
     */
    public function listPostsAction()
    {
        return $this->render('AppBundle:Template:list_posts.html.twig', array(
            // ...
        ));
    }

}
