<?php
namespace AppBundle\Services\Facebook;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;
use AppBundle\Entity\Statistic;

class FbEventService extends FacebookService
{
    private   $container;
    protected $log;
    protected $connect;
    protected $db;
    protected $images;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler([$this->connect, 'exception_handler']);
    }


    /**
     * Processes events
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getEvents($partyCode, $fbPageId, $fb, $scrapeFull = false) {
        $requestFields = 'events{start_time,updated_time,name,cover,description,place,attending_count,interested_count,comments.limit(0).summary(true)}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('events'))) {
            $this->log->notice("    - Facebook events not found for " . $partyCode);
            return false;
        }

        $this->log->info("    + Getting event details...");
        $fdEvents  = $graphNode->getField('events');

        $pageCount = 0;
        $eveCount  = 0;
        $loopCount = 0;
        $temp      = [];

        $timeLimit = $this->db->getTimeLimit($partyCode, 'fb', 'E', $scrapeFull);

        do { // process current page of results
            $this->log->debug("       + Page " . $pageCount);

            foreach ($fdEvents as $key => $event) {
                $id = $event->getField('id');

                if (in_array($id, $temp, true)) {
                    // if event was already scraped this session
                    $loopCount++;
                    continue;
                }

                $temp[$id] = $this->getEventDetails($partyCode, $event);
                $eveCount++;
            }

            $timeCheck = $event->getField('updated_time')->getTimestamp(); // check time of last scraped post
            $pageCount++;

        } while ($timeCheck > $timeLimit && $fdEvents = $fb->next($fdEvents));
        // while next page is not null and within our time limit

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook event scraping for " . $partyCode . " looped " . $loopCount . " times");
        }

        $this->db->processSocialMedia($temp);

        $out['eventCount'] = $eveCount;
        $out['events']     = true;
        $this->log->info("      + " . $out['eventCount'] . " events found and processed");

        return $out;
    }


    /**
     * Retrieves the details of an event
     * @param  object $event
     * @return null
     */
    public function getEventDetails($partyCode, $event) {
        $place = $event->getField('place');

        if (!empty($place)) { // must be checked in advance, else will break if null
            $placeName = $place->getField('name');
            $location  = $place->getField('location');
        } else $placeName = null;

        if (!empty($location)) { // must be checked in advance, else will break if null
            $placeAddress = [
                'street'    => $location->getField('street'),
                'city'      => $location->getField('city'),
                'zip'       => $location->getField('zip'),
                'country'   => $location->getField('country'),
                'longitude' => $location->getField('longitude'),
                'latitude'  => $location->getField('latitude')
                ];
        } else $placeAddress = null;

        $commentCount = $this->getStatCount($event->getField('comments'));
        $coverData    = json_decode($event->getField('cover'), true);

        $imgId  = $coverData['id'];
        $imgSrc = $coverData['source'];
        $img    = $imgSrc ? $this->images->saveImage('fb', $partyCode, $imgSrc, $imgId) : null;

        $allData = [
            'id'          => $event->getField('id'),
            'start_time'  => $event->getField('start_time')->format('Y-m-d H:i:s'), // string
            'updated'     => $event->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'        => $event->getField('name'),
            'description' => $event->getField('description'),
            'image'       => $img,
            'img_source'  => $imgSrc,
            'place'       => $placeName,
            'address'     => $placeAddress,
            'url'         => 'https://www.facebook.com/events/' . $event->getField('id'),
            'attending'   => $event->getField('attending_count'),
            'interested'  => $event->getField('interested_count'),
            'comments'    => $commentCount
        ];

        $out = [
            'code'    => $partyCode,
            'type'    => SocialMedia::TYPE_FACEBOOK,
            'subtype' => SocialMedia::SUBTYPE_EVENT,
            'id'      => $event->getField('id'),
            'time'    => $event->getField('updated_time'), // DateTime
            'text'    => $event->getField('name'),
            'img'     => $img,
            'likes'   => $event->getField('interested_count'),
            'allData' => $allData
            ];

        return $out;
    }


    /**
     * Event count for stats only
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @return int
     */
    public function getEventCount($partyCode, $fbPageId, $fb) {
        $requestFields = 'events{id}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('events'))) {
            $this->log->notice("    - Error while counting Facebook events for " . $partyCode);
            return false;
        }

        $this->log->info("    + Counting events...");
        $fdEvents  = $graphNode->getField('events');
        $pageCount = 0;
        $loopCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);
            foreach ($fdEvents as $key => $event) {
                if (in_array($event->getField('id'), $temp, true)) {
                    // if event was already counted this session
                    $loopCount++;
                    continue;
                }
                $temp['events'][] = $event->getField('id');
            }
            $pageCount++;
        } while ($fdEvents = $fb->next($fdEvents)); // while next page is not null

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook event counting for " . $partyCode . " looped " . $loopCount . " times");
        }

        $eventCount = isset($temp['events']) ? count($temp['events']) : 0;
        if ($eventCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_IMAGES,
            $eventCount
        );

        $this->log->info("      + Total " . $eventCount . " events found");
        return true;
    }

}