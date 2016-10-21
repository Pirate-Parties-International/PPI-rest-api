<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

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

    /**
     * @Route("party/{id}/social", name="papi_social_show")
     * @Template()
     */
    public function socialAction($id, Request $request)
    {
        $social_media = $this->getOneSocial($id);

        $form = $this->createFormBuilder($social_media)
            ->add('display', ChoiceType::class, [
                'choices' => [
                    'All data'       => 'all',
                    'All text posts' => 'txt',
                    'All images'     => 'img',
                    'All videos'     => 'vid',
                    'Facebook' => [
                        'All FB posts'       => 'fba',
                        'FB text posts only' => 'fbt',
                        'FB images only'     => 'fbi',
                        'FB events only'     => 'fbe',
                        ],
                    'Twitter' => [
                        'All tweets'        => 'twa',
                        'Text tweets only'  => 'twt',
                        'Image tweets only' => 'twi',
                        'Video tweets only' => 'twv',
                        ],
                    'Youtube' => [
                        'Videos only' => 'ytv',
                        ],
                    ],
                'choices_as_values' => true,
                ]
            )
            ->add('submit', SubmitType::class, array('label' => 'Submit'))
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $data['value'] = $form['display']->getData();

            $party = $this->getDoctrine()
                ->getRepository('AppBundle:SocialMedia');

            switch ($data['value']) {
                case 'all': // all data
                    $social_media = $this->getOneSocial($id);
                    break;
                case 'txt': // all text
                    $social_media = $party->findBy(['code' => $id, 'subType' => 'T']);
                    break;
                case 'img': // all images
                    $social_media = $party->findBy(['code' => $id, 'subType' => 'I']);
                    break;
                case 'vid': // all videos
                    $social_media = $party->findBy(['code' => $id, 'subType' => 'V']);
                    break;
                case 'fba': // FB all
                    $social_media = $party->findBy(['code' => $id, 'type' => 'fb']);
                    break;
                case 'fbt': // FB text
                    $social_media = $party->findBy(['code' => $id, 'type' => 'fb', 'subType' => 'T']);
                    break;
                case 'fbi': // FB images
                    $social_media = $party->findBy(['code' => $id, 'type' => 'fb', 'subType' => 'I']);
                    break;
                case 'fbe': // FB events
                    $social_media = $party->findBy(['code' => $id, 'type' => 'fb', 'subType' => 'E']);
                    break;
                case 'twa': // TW all
                    $social_media = $party->findBy(['code' => $id, 'type' => 'tw']);
                    break;
                case 'twt': // TW text
                    $social_media = $party->findBy(['code' => $id, 'type' => 'tw', 'subType' => 'T']);
                    break;
                case 'twi': // TW images
                    $social_media = $party->findBy(['code' => $id, 'type' => 'tw', 'subType' => 'I']);
                    break;
                case 'twv': //TW videos
                    $social_media = $party->findBy(['code' => $id, 'type' => 'tw', 'subType' => 'V']);
                case 'ytv': // YT videos
                    $social_media = $party->findBy(['code' => $id, 'type' => 'yt']);
                    break;
            }
        }

        if (empty($social_media)) {
            $empty = true;
        } else $empty = false;

        return $this->render(
            'AppBundle:Default:social.html.twig',
            array(
                'social_media' => $social_media,
                'empty' => $empty,
                'form' => $form->createView()
            )
        );
    }

}
