<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

/**
 * @Route("/api/v1/")
 */
class ApiController extends BaseController
{
    /**
     * Lists data about ALL parties
     * 
     * @Route("parties/", name="ppi_api_parties")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Party",
     *  statusCodes={
     *         200="Returned when successful."
     *     }
     * )
     */
    public function partiesAction()
    {
    	$allData = $this->getAllParties();

        $serializer = $this->get('jms_serializer');
        $allData = $serializer->serialize($allData, 'json');
		return new Response($allData, 200);
    }

    /**
     * List data about ONE party
     * 
     * @Route("parties/{id}", name="ppi_api_parties_id")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=true,
     *  section="Party",
     *  requirements={
     *      {"name"="id", "dataType"="string", "required"=true, "description"="Party code (i.e. 'ppsi')."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partyAction($id) {
    	
        $party = $this->getOneParty($id);

        $social['twitter']  = $this->getTwitterFollowers($id);     
        $social['facebook'] = $this->getFacebookLikes($id);     
        $social['gplus']    = $this->getGooglePlusFollowers($id);
        $social['youtube']   = $this->getYoutubeStatistics($id);

        $party->socialData = $social;

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($party, 'json');

    	if ($data === null) {
    		return new JsonResponse(array("error"=>"Party with this ID does not exsist"), 404);
    	}

    	return new Response($data, 200);
    }

    /**
     * Shows history accross all social networks for one party. Supports JSON and CSV
     *
     * Meaning of codes:<br />
     * - fb-L: Facebook likes<br />
     * - tw-F: Twitter followers<br />
     * - tw-T: Twitter tweets<br />
     * - g+-F: Google Plus Followers<br />
     * - yt-S: Youtube Subscribers<br />
     * - yt-V: Youtube Views<br />
     * - yt-M: Youtube Videos
     * 
     * @Route("history/party/{id}", name="ppi_api_history_party")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=true,
     *  section="History",
     *  requirements={
     *      {"name"="id", "dataType"="string", "required"=true, "description"="Party code (i.e. 'ppsi')."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function showHistoryPartyViewAction($id) {
        
        $request = $this->getRequest();
        $format  = $request->query->get('_format');

        $stats = $this->getDoctrine()
        ->getRepository('AppBundle:Statistic')
        ->findByCode($id);

        if ($stats === null) {
            return new JsonResponse(array("error"=>"No stats found for this party ID"), 404);
        }

        $payload = [];
        foreach ($stats as $stat) {
            $date = $stat->getTimestamp()->format('Y-m-d');
            $payload[$date][$stat->getType() . "-" . $stat->getSubType()] = $stat->getValue();
        }

        switch ($format) {
            case 'csv':
                $out = "Date;fb-L;tw-F;tw-T;g+-F;yt-S;yt-V;yt-M" . PHP_EOL;
                foreach ($payload as $date => $s) {
                    $out .= sprintf('%s;%s;%s;%s;%s;%s;%s;%s',
                        $date,
                        $s['fb-L'],
                        $s['tw-F'],
                        $s['tw-T'],
                        $s['g+-F'],
                        $s['yt-S'],
                        $s['yt-V'],
                        $s['yt-M']
                    ) . PHP_EOL;
                }
                $format = "text/csv";
                break;
            
            default:
                $out = json_encode($payload);
                $format = "application/json";
                break;
        }

        return new Response($out, Response::HTTP_OK, array('content-type' => $format));
    }

    /**
     * Shows history accross of one dimension for all parties. Supports JSON and CSV
     *
     * Meaning of codes:<br />
     * - fb-L: Facebook likes<br />
     * - tw-F: Twitter followers<br />
     * - tw-T: Twitter tweets<br />
     * - g+-F: Google Plus Followers<br />
     * - yt-S: Youtube Subscribers<br />
     * - yt-V: Youtube Views<br />
     * - yt-M: Youtube Videos
     * 
     * @Route("history/dimension/{view}", name="ppi_api_history_dimension")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=true,
     *  section="History",
     *  requirements={
     *      {"name"="view", "dataType"="string", "required"=true, "pattern"="fb-L|tw-F|tw-T|g+-F|yt-S|yt-V|yt-M", "description"="Dimension of interest"}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function showHistoryDimensionViewAction($view) {
        
        $request = $this->getRequest();
        $format  = $request->query->get('_format');

        list($type, $subType) = explode("-", $view);

        $stats = $this->getDoctrine()
        ->getRepository('AppBundle:Statistic')
        ->findBy(["type" =>$type, "subType" => $subType]);

        if ($stats === null) {
            return new JsonResponse(array("error"=>"No stats found for this dimension"), 404);
        }

        $payload = [];
        $partyList = [];
        $i = 0;
        foreach ($stats as $stat) {
            $date = $stat->getTimestamp()->format('Y-m-d');
            $payload[$date][$stat->getCode()] = $stat->getValue();
            
            if (!isset($partyList[$stat->getCode()])) {
                $partyList[$stat->getCode()] = $i;
                $i++;
            }
        }
        $partyList = array_flip($partyList);

        switch ($format) {
            case 'csv':
                $out = "Date;" . implode(";", $partyList) . PHP_EOL;
                foreach ($payload as $date => $s) {
                    $line = $date;
                    foreach ($partyList as $code) {
                        $line .= ";" . $s[$code];
                    }
                    $out .= $line . PHP_EOL;
                }
                $format = "text/csv";
                break;
            
            default:
                $out = json_encode($payload);
                $format = "application/json";
                break;
        }

        return new Response($out, Response::HTTP_OK, array('content-type' => $format));
    }



}
