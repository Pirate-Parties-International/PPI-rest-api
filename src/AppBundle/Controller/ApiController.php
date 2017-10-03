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
class ApiController extends DataController
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
     *  filters={
     *      {"name"="show_defunct", "dataType"="bool", "required"="false", "description"="List defunct parties."},
     *      {"name"="int_membership", "dataType"="string", "required"="false", "description"="List only members of an international organization. Use slug of the org (i.e. 'ppi', 'ppeu')"},
     *      {"name"="sort_results", "dataType"="string", "pattern"="name|code|country", "required"="false", "description"="List results in a set order."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         400="Returned upon bad request.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partiesAction() {

        $showDefunct      = isset($_GET['show_defunct'])   ? $_GET['show_defunct']   : null;
        $membershipFilter = isset($_GET['int_membership']) ? $_GET['int_membership'] : null;
        $orderBy          = isset($_GET['sort_results'])   ? $_GET['sort_results']   : null;

        switch($showDefunct) {
            case null:
                break;
            case "true":
                $showDefunct = true;
                break;
            case "false":
                $showDefunct = false;
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: invalid parameter for the field 'show_defunct' (boolean expected)."), 400);
        }

        switch ($orderBy) {
            case null:
            case 'name':
            case 'code':
            case 'country':
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$orderBy."' is not a valid parameter for the field 'sort_results'."), 400);
        }

        // run through BaseController
        $allData = $this->getAllParties($showDefunct, $membershipFilter, $orderBy);

        if (empty($allData)) {
            return new JsonResponse(array("error"=>"Search returned no results."), 404);
        }

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
     *         400="Returned unpon bad request.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partyAction($id) {

        if ($id == "{id}") { // if null (should not apply outside of doc page)
            return new JsonResponse(array("error"=>"Bad request: No Party ID entered."), 400);
        }

        $party = $this->getOneParty($id);

    	if (empty($party)) {
    		return new JsonResponse(array("error"=>"Party with the ID '".$id."' does not exist."), 404);
    	}

        $social['twitter']            = $this->getTwitterStats($id);
        $social['facebook']           = $this->getFacebookStats($id);
        $social['gplus']['followers'] = $this->getGooglePlusFollowers($id);
        $social['youtube']            = $this->getYoutubeStats($id);

        $party->socialData = $social;

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($party, 'json');

    	return new Response($data, 200);
    }


    /**
     * List data about social media
     *
     * @Route("social/", name="ppi_api_social")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Social",
     *  filters={
     *      {"name"="code", "dataType"="string", "required"="false", "description"="Get only posts from one party (by code, i.e. ppsi, ppse)"},
     *      {"name"="type", "dataType"="string", "required"="false", "description"="Get only Facebook, Twitter or YouTube posts", "pattern"="fb | tw | yt"},
     *      {"name"="sub_type", "dataType"="string", "required"="false", "description"="Get only text posts, images, videos or events", "pattern"="t | i | v | e"},
     *      {"name"="fields", "dataType"="string", "requied"="false", "description"="Choose specific fields to be returned, separated by commas (e.g. 'text,time,img_source')",
     *          "pattern"="time | updated | text | description | image | img_source | album | link | url | likes | reactions | comments | shares | views | place | address | attending | interested"},
     *      {"name"="order_by", "dataType"="string", "required"="false", "description"="Order to return results", "pattern"="code | likes | date"},
     *      {"name"="limit", "dataType"="int", "required"="false", "description"="Number of results to return (default 100)"},
     *      {"name"="offset", "dataType"="int", "required"="false", "description"="Start point of results"}
     *  },
     *  statusCodes={
     *          200="Returned when successful.",
     *          400="Returned upon bad request.",
     *          404="Returned when not found."
     *  }
     * )
     */
    public function socialAction() {

        $code    = isset($_GET['code'])     ? $_GET['code']     : null;
        $type    = isset($_GET['type'])     ? $_GET['type']     : null;
        $subType = isset($_GET['sub_type']) ? $_GET['sub_type'] : null;
        $fields  = isset($_GET['fields'])   ? $_GET['fields']   : null;
        $orderBy = isset($_GET['order_by']) ? $_GET['order_by'] : null;
        $limit   = isset($_GET['limit'])    ? $_GET['limit']    : 100;
        $offset  = isset($_GET['offset'])   ? $_GET['offset']   : 0;

        switch ($orderBy) {
            case null:
                // break;
            case 'time':
            case 'date':
                $orderBy = 'postTime';
                break;
            case 'likes':
                $orderBy = 'postLikes';
                break;
            case 'code':
                $orderBy = 'code';
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$orderBy."' is not a valid parameter for the field 'order_by'."), 400);
        }

        // run through BaseController
        $data = $this->getAllSocial($code, $type, $subType, $fields, $orderBy, $limit, $offset);

        if (empty($data)) {
            return new JsonResponse(array("error"=>"No data found."), 404);
        }

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($data, 'json');

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
            $payload[$date]['date'] = $date;
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
