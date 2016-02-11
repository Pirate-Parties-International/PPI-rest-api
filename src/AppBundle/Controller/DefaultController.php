<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class DefaultController extends BaseController
{
    /**
     * @Route("/")
     * @Template()
     */
    public function indexAction()
    {
    	$parties = $this->getAllParties();

        return array("parties" => $parties);
    }

    /**
     * @Route("party/{id}", name="papi_party_show")
     * @Template()
     */
    public function partyAction($id)
    {
    	$party = $this->getOneParty($id);

        $parentOrg = [];
        if ($party->getParentParty()) {
            $parentOrg = $this->getDoctrine()
                ->getRepository('AppBundle:Party')
                ->findOneByCode($party->getParentParty());
        }

        return array(
            "party"     => $party,
            "parentOrg" => $parentOrg,
            "facebookLikes" => $this->getFacebookLikes($party->getCode()),
            "cover" => $this->getCoverImage($party->getCode())
        );
    }

    /**
     * @Route("/{id}", name="papi_page_show")
     * @Template()
     */
    public function pageAction($id)
    {   
        $pagesPath = __DIR__ . '/../Resources/staticContent';
        
        $data = @file_get_contents(sprintf('%s/%s.md', $pagesPath, $id));

        if ($data === false) {
            throw $this->createNotFoundException(
                'Page not found.'
            );
        }

        return ['page' => $data];
    } 

}
