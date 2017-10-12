<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class TemplateController extends Controller
{
    /**
     * @Route("/social/pictures", name="papi_social_pictures")
     */
    public function listPicturesAction()
    {
        return $this->render('AppBundle:Template:list_pictures.html.twig', array(
            // ...
        ));
    }

    /**
     * @Route("social/posts", name="papi_social_posts")
     */
    public function listPostsAction()
    {
        return $this->render('AppBundle:Template:list_posts.html.twig', array(
            // ...
        ));
    }

    /**
     * @Route("template/{id}")
     */
    public function redirectAction($id)
    {
        switch ($id) {
            case 'listPictures':
                return $this->redirectToRoute('papi_social_pictures');
            case 'listPosts':
                return $this->redirectToRoute('papi_social_posts');
            }
    }

}
