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

class FbImageService extends FacebookService
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
     * Processes images
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getImages($partyCode, $fbPageId, $fb, $scrapeFull = false) {
        $requestFields = 'albums{id,name,photo_count,photos{created_time,updated_time,picture,source,link,name,likes.limit(0).summary(true),reactions.limit(0).summary(true),comments.limit(0).summary(true),sharedposts.limit(0).summary(true)}}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('albums'))) {
            $this->log->notice("    - Facebook images not found for " . $partyCode);
            return false;
        }

        $this->log->info("    + Getting image details...");
        $fdAlbums  = $graphNode->getField('albums');

        $pageCount = 0;
        $imgCount  = 0;
        $loopCount = 0;
        $temp      = [];

        $timeLimit = $this->db->getTimeLimit($partyCode, 'fb', 'I', $scrapeFull);

        foreach ($fdAlbums as $key => $album) {
            $photoCount[] = $album->getField('photo_count');
            $fdPhotos     = $album->getField('photos');

            if (empty($fdPhotos)) {
                continue;
            }

            do {
                $this->log->debug("       + Page " . $pageCount);

                foreach ($fdPhotos as $key => $photo) {
                    $id = $photo->getField('picture');

                    if (in_array($id, $temp, true)) {
                        // if image was already scraped this session
                        $loopCount++;
                        continue;
                    }

                    $temp[$id] = $this->getImageDetails($partyCode, $photo, $album);
                    $imgCount++;
                }

                $timeCheck = $photo->getField('updated_time')->getTimestamp(); // check time of last scraped post
                $pageCount++;
                $this->connect->getFbRateLimit();

            } while ($timeCheck > $timeLimit && $fdPhotos = $fb->next($fdPhotos));
            // while next page is not null and within our time limit
        }

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook image scraping for " . $partyCode . " looped " . $loopCount . " times");
        }

        $this->db->processSocialMedia($temp);

        $out['imageCount'] = array_sum($photoCount);
        $out['images']     = $imgCount;
        $this->log->info("      + " . $out['imageCount'] . " images found, " . $imgCount . " since " . date('d/m/Y', $timeCheck) . " processed");

        return $out;
    }


    /**
     * Retrieves the details of an image
     * @param  string $partyCode
     * @param  object $photo
     * @param  object $album
     * @return null
     */
    public function getImageDetails($partyCode, $photo, $album) {
        $imgSrc = $this->images->getFbImageSource($photo->getField('id')); // ~480x480 (or closest)
        $imgBkp = $photo->getField('picture'); // 130x130 thumbnail
        $img    = $this->images->saveImage('fb', $partyCode, $imgSrc, $photo->getField('id'), $imgBkp);

        $likeCount     = $this->getStatCount($photo->getField('likes'));
        $reactionCount = $this->getStatCount($photo->getField('reactions'));
        $commentCount  = $this->getStatCount($photo->getField('comments'));
        $shareCount    = count(json_decode($photo->getField('sharedposts'), true));

        $allData = [
            'id'         => $photo->getField('id'),
            'posted'     => $photo->getField('created_time')->format('Y-m-d H:i:s'), // string
            'updated'    => $photo->getField('updated_time')->format('Y-m-d H:i:s'), // string
            'text'       => $photo->getField('name'),
            'image'      => $img,
            'img_source' => $imgSrc,
            'url'        => $photo->getField('link'),
            'album'      => [
                'name'       => $album->getField('name'),
                'id'         => $album->getField('id'),
                ],
            'likes'      => $likeCount,
            'reactions'  => $reactionCount,
            'comments'   => $commentCount,
            'shares'     => $shareCount
        ];

        $out = [
            'code'    => $partyCode,
            'type'    => SocialMedia::TYPE_FACEBOOK,
            'subtype' => SocialMedia::SUBTYPE_IMAGE,
            'id'      => $photo->getField('id'),
            'time'    => $photo->getField('updated_time'), // DateTime
            'text'    => $photo->getField('name'),
            'img'     => $img,
            'likes'   => $reactionCount,
            'allData' => $allData
            ];

        return $out;
    }


    /**
     * Image count for stats only
     * @param  string $partyCode
     * @param  string $fbPageId
     * @return int
     */
    public function getImageCount($partyCode, $fbPageId) {
        $requestFields = 'albums{id,count}';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('albums'))) {
            $this->log->notice("    - Error while counting Facebook images for " . $partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $this->log->info("    + Counting images...");
        $fdAlbums   = $graphNode->getField('albums');
        $pageCount  = 0;
        $loopCount  = 0;
        $photoCount = [];
        $temp       = [];

        foreach ($fdAlbums as $key => $album) {
            if (in_array($album->getField('id'), $temp, true)) {
                // if album was already counted this session
                $loopCount++;
                continue;
            }
            $temp[] = $album->getField('id');

            $this->log->debug("       + Page " . $pageCount);
            $photoCount[] = $album->getField('count');
            $pageCount++;
            $this->connect->getFbRateLimit();
        }

        if ($loopCount > 0) {
            $this->log->warning("     - Facebook image counting for " . $partyCode . " looped " . $loopCount . " times");
        }

        $imageCount = array_sum($photoCount);
        if ($imageCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_IMAGES,
            $imageCount
        );

        $this->log->info("      + Total " . $imageCount . " images found");
        return true;
    }

}