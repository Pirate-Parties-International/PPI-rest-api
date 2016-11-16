<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends BaseController
{
    /**
     * @Route("/")
     * @Template()
     */
    public function indexAction()
    {
    	$parties = $this->getAllParties();

        // Yes, I know this is stupid and slow but it's late 
        // and I'm gonna do file caching anyway :/
        foreach ($parties as $code => $party) {
            $social = [];

            $social['twitter']  = $this->getTwitterFollowers($code);     
            $social['facebook'] = $this->getFacebookLikes($code);     
            $social['gplus']    = $this->getGooglePlusFollowers($code);

            $social;

            $max = max($social); 

            $party->socialReach = [
                'value' => $max,
                'type'  => array_search($max, $social)
            ];

        }

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
            "party"      => $party,
            "parentOrg"  => $parentOrg,
            "cover"      => $this->getCoverImage($party->getCode()),
            "facebook"   => $this->getFacebookData($party->getCode()),
            "twitter"    => $this->getTwitterData($party->getCode()),
            "youtube"    => $this->getYoutubeData($party->getCode()),
            "googlePlus" => [
                'followers' => $this->getGooglePlusFollowers($party->getCode())
            ]
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
